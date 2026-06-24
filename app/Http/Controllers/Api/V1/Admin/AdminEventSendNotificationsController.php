<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Jobs\SendEventCreatedNotificationJob;
use App\Models\Event;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Services\Notifications\FcmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminEventSendNotificationsController extends BaseApiController
{
    /**
     * POST /api/v1/admin/events/{event}/send-notifications
     *
     * Sends event notifications synchronously — no queue worker needed.
     * Use this to fix events where notifications were never sent (e.g. queue not running).
     *
     * Query params:
     *   ?force=1    — re-send push even if push log already exists
     *   ?dry_run=1  — preview counts only, don't actually send
     */
    public function send(Request $request, string $eventId, FcmService $fcmService): JsonResponse
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->error('Event not found', 404);
        }

        $force   = (bool) $request->query('force', false);
        $dryRun  = (bool) $request->query('dry_run', false);

        Log::info('AdminEventSendNotifications: started', [
            'event_id' => $event->id,
            'force'    => $force,
            'dry_run'  => $dryRun,
        ]);

        // ── Resolve banner URL ──────────────────────────────────────────────
        $bannerUrl = $event->banner_url;
        if (is_string($bannerUrl) && trim($bannerUrl) !== '') {
            $bannerUrl = trim($bannerUrl);
            if (!str_starts_with($bannerUrl, 'http://') && !str_starts_with($bannerUrl, 'https://') && !str_starts_with($bannerUrl, '/')) {
                $bannerUrl = url('/api/v1/files/' . $bannerUrl);
            }
        } else {
            $bannerUrl = null;
        }

        $title = 'New Event Created';
        $body  = 'A new event has been added. Tap to view event details.';

        $notificationData = [
            'type'           => 'event',
            'event_id'       => (string) $event->id,
            'event_title'    => (string) $event->title,
            'event_date'     => $event->start_at ? $event->start_at->toDateString() : '',
            'event_banner'   => $bannerUrl,
            'image_url'      => $bannerUrl,
            'screen'         => 'event_detail',
            'tap_destination'=> 'event_detail',
            'reference_type' => 'event',
            'reference_id'   => (string) $event->id,
        ];

        // ── Get existing in-app notifications for this event ────────────────
        $existingNotifications = AppNotification::whereIn('type', ['event', 'event_created'])
            ->where(function ($q) use ($event) {
                $q->whereJsonContains('data->event_id', (string) $event->id)
                  ->orWhere('reference_id', (string) $event->id);
            })
            ->get()
            ->keyBy('user_id');

        // ── Get all active users ────────────────────────────────────────────
        $userQuery = \App\Models\User::query()
            ->when(Schema::hasColumn('users', 'deleted_at'), fn ($q) => $q->whereNull('users.deleted_at'))
            ->when(Schema::hasColumn('users', 'gdpr_deleted_at'), fn ($q) => $q->whereNull('users.gdpr_deleted_at'))
            ->when(Schema::hasColumn('users', 'status'), fn ($q) => $q->where(fn ($sq) =>
                $sq->whereNull('users.status')
                   ->orWhereRaw("LOWER(users.status::text) NOT IN ('inactive','suspended','blocked','banned','deleted','rejected')")))
            ->when(Schema::hasColumn('users', 'membership_status'), fn ($q) => $q->where(fn ($sq) =>
                $sq->whereNull('users.membership_status')
                   ->orWhere('users.membership_status', '!=', 'suspended')));

        $totalUsers        = 0;
        $inAppCreated      = 0;
        $inAppAlreadyExist = 0;
        $pushSent          = 0;
        $pushFailed        = 0;
        $pushSkipped       = 0;
        $noTokenUsers      = 0;
        $errors            = [];

        $userQuery->chunkById(100, function ($users) use (
            $event, $title, $body, $notificationData, $bannerUrl, $fcmService,
            $force, $dryRun, $existingNotifications,
            &$totalUsers, &$inAppCreated, &$inAppAlreadyExist,
            &$pushSent, &$pushFailed, &$pushSkipped, &$noTokenUsers, &$errors
        ) {
            foreach ($users as $user) {
                $totalUsers++;

                try {
                    // ── Step 1: Create in-app notification if not exists ──
                    $notification = $existingNotifications->get($user->id);

                    if ($notification) {
                        $inAppAlreadyExist++;
                    } else {
                        if (!$dryRun) {
                            $notification = AppNotification::create([
                                'user_id'        => $user->id,
                                'type'           => 'event',
                                'category'       => 'event',
                                'title'          => $title,
                                'body'           => $body,
                                'channel'        => 'push',
                                'priority'       => 'high',
                                'reference_type' => 'event',
                                'reference_id'   => $event->id,
                                'screen'         => 'event_detail',
                                'data'           => $notificationData,
                                'status'         => 'pending',
                            ]);
                        }
                        $inAppCreated++;
                    }

                    if (!$notification && $dryRun) {
                        // In dry run, still count push tokens
                        $tokens = $fcmService->activeTokensForUser($user->id);
                        if ($tokens->isNotEmpty()) {
                            $pushSent += $tokens->count();
                        } else {
                            $noTokenUsers++;
                        }
                        continue;
                    }

                    if (!$notification) {
                        continue;
                    }

                    // ── Step 2: Check if push already sent (unless force) ──
                    if (!$force && !$dryRun) {
                        try {
                            $pushExists = NotificationDeliveryLog::where('notification_id', $notification->id)
                                ->where('channel', 'push')
                                ->whereIn('status', ['sent', 'failed', 'pending'])
                                ->exists();

                            if ($pushExists) {
                                $pushSkipped++;
                                continue;
                            }
                        } catch (Throwable $e) {
                            // notification_delivery_logs table might not exist — continue anyway
                        }
                    }

                    // ── Step 3: Get active FCM tokens ──────────────────────
                    $tokens = $fcmService->activeTokensForUser($user->id);

                    if ($tokens->isEmpty()) {
                        $noTokenUsers++;
                        if (!$dryRun) {
                            // Mark skipped
                            try {
                                NotificationDeliveryLog::create([
                                    'notification_id' => $notification->id,
                                    'user_id'         => $user->id,
                                    'channel'         => 'push',
                                    'provider'        => 'firebase',
                                    'status'          => 'skipped',
                                    'error_message'   => 'No active push token',
                                    'attempted_at'    => now(),
                                ]);
                            } catch (Throwable $e) {
                                // ignore
                            }
                        }
                        continue;
                    }

                    if ($dryRun) {
                        $pushSent += $tokens->count();
                        continue;
                    }

                    // ── Step 4: Send FCM push ──────────────────────────────
                    $data = $notification->dataPayload();
                    if ($bannerUrl) {
                        $data['event_banner'] = $bannerUrl;
                        $data['image_url']    = $bannerUrl;
                    }

                    foreach ($tokens as $token) {
                        try {
                            $result = $fcmService->sendToToken($token, $title, $body, $data, $notification, $bannerUrl);
                            if ($result['success'] ?? false) {
                                $pushSent++;
                            } else {
                                $pushFailed++;
                                $errors[] = [
                                    'user_id' => $user->id,
                                    'error'   => $result['error'] ?? 'Unknown FCM error',
                                ];
                            }
                        } catch (Throwable $e) {
                            $pushFailed++;
                            $errors[] = [
                                'user_id' => $user->id,
                                'error'   => $e->getMessage(),
                            ];
                        }
                    }

                } catch (Throwable $e) {
                    $errors[] = [
                        'user_id' => $user->id,
                        'error'   => $e->getMessage(),
                    ];
                    Log::error('AdminEventSendNotifications: user error', [
                        'event_id' => $event->id,
                        'user_id'  => $user->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        });

        $result = [
            'event_id'                 => (string) $event->id,
            'event_title'              => (string) $event->title,
            'dry_run'                  => $dryRun,
            'force'                    => $force,
            'total_users_processed'    => $totalUsers,
            'in_app_created_now'       => $inAppCreated,
            'in_app_already_existed'   => $inAppAlreadyExist,
            'push_sent_successfully'   => $pushSent,
            'push_failed'              => $pushFailed,
            'push_skipped_already_sent'=> $pushSkipped,
            'users_with_no_token'      => $noTokenUsers,
            'errors'                   => array_slice($errors, 0, 20), // max 20 error samples
        ];

        Log::info('AdminEventSendNotifications: completed', array_merge($result, ['total_errors' => count($errors)]));

        $message = $dryRun
            ? "Dry run complete. Would send to {$pushSent} tokens for {$totalUsers} users."
            : "Notifications sent. Push: {$pushSent} sent, {$pushFailed} failed, {$pushSkipped} skipped.";

        return $this->success($result, $message);
    }

    /**
     * POST /api/v1/admin/events/{event}/dispatch-notification-job
     *
     * Re-dispatches the SendEventCreatedNotificationJob.
     * Use this if QUEUE_CONNECTION=sync or if you have a running queue worker.
     */
    public function dispatch(string $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (!$event) {
            return $this->error('Event not found', 404);
        }

        SendEventCreatedNotificationJob::dispatch($event->id)->afterResponse();

        return $this->success([
            'event_id'    => (string) $event->id,
            'event_title' => (string) $event->title,
            'dispatched'  => true,
        ], 'Notification job dispatched. It will run when queue worker processes it.');
    }
}
