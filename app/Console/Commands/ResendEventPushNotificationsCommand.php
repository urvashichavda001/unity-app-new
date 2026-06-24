<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Services\Notifications\FcmService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ResendEventPushNotificationsCommand extends Command
{
    protected $signature = "notifications:resend-event-push
                            {event_id : The UUID of the event to re-send push notifications for}
                            {--dry-run : Preview what would be sent without actually sending}
                            {--force : Force re-send even if push logs already exist}";

    protected $description = "Re-send FCM push notifications for an event where in-app notifications were created but push was not sent";

    public function handle(FcmService $fcmService): int
    {
        $eventId = $this->argument("event_id");
        $dryRun = $this->option("dry-run");
        $force = $this->option("force");

        $event = Event::find($eventId);
        if (!$event) {
            $this->error("Event not found: {$eventId}");
            return self::FAILURE;
        }

        $this->info("Event: {$event->title} (ID: {$event->id})");
        if ($dryRun) {
            $this->warn("DRY RUN MODE — no notifications will actually be sent.");
        }

        // Find all in-app notifications for this event
        $notifications = AppNotification::whereIn("type", ["event", "event_created"])
            ->where(function ($q) use ($event) {
                $q->whereJsonContains("data->event_id", (string) $event->id)
                  ->orWhere("reference_id", (string) $event->id);
            })
            ->get();

        $this->info("Found {$notifications->count()} in-app notifications for this event.");

        // Resolve banner URL
        $bannerUrl = $event->banner_url;
        if (is_string($bannerUrl) && trim($bannerUrl) !== "") {
            $bannerUrl = trim($bannerUrl);
            if (!str_starts_with($bannerUrl, "http://") && !str_starts_with($bannerUrl, "https://") && !str_starts_with($bannerUrl, "/")) {
                $bannerUrl = url("/api/v1/files/" . $bannerUrl);
            }
        } else {
            $bannerUrl = null;
        }

        $title = "New Event Created";
        $body = "A new event has been added. Tap to view event details.";

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $noToken = 0;

        $bar = $this->output->createProgressBar($notifications->count());
        $bar->start();

        foreach ($notifications as $notification) {
            $bar->advance();

            try {
                // Check if push already sent for this notification (unless --force)
                if (!$force) {
                    try {
                        $pushAlreadySent = NotificationDeliveryLog::where("notification_id", $notification->id)
                            ->where("channel", "push")
                            ->whereIn("status", ["sent", "failed", "pending"])
                            ->exists();

                        if ($pushAlreadySent) {
                            $skipped++;
                            continue;
                        }
                    } catch (Throwable $e) {
                        // notification_delivery_logs table might not exist, continue anyway
                    }
                }

                // Get active tokens for this user
                $tokens = $fcmService->activeTokensForUser($notification->user_id);

                if ($tokens->isEmpty()) {
                    $noToken++;
                    continue;
                }

                if ($dryRun) {
                    $sent += $tokens->count();
                    continue;
                }

                // Build data payload from the notification
                $data = $notification->dataPayload();

                // Ensure banner image is included
                if ($bannerUrl) {
                    $data["event_banner"] = $bannerUrl;
                    $data["image_url"] = $bannerUrl;
                }

                // Send push to each active token
                foreach ($tokens as $token) {
                    try {
                        $result = $fcmService->sendToToken($token, $title, $body, $data, $notification, $bannerUrl);
                        if ($result["success"] ?? false) {
                            $sent++;
                        } else {
                            $failed++;
                            Log::warning("ResendEventPush: FCM send failed", [
                                "user_id" => $notification->user_id,
                                "error" => $result["error"] ?? "Unknown",
                            ]);
                        }
                    } catch (Throwable $e) {
                        $failed++;
                        Log::error("ResendEventPush: Exception sending FCM", [
                            "user_id" => $notification->user_id,
                            "error" => $e->getMessage(),
                        ]);
                    }
                }
            } catch (Throwable $e) {
                $failed++;
                Log::error("ResendEventPush: Exception processing notification", [
                    "notification_id" => $notification->id,
                    "error" => $e->getMessage(),
                ]);
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(["Metric", "Count"], [
            ["Push Sent" . ($dryRun ? " (dry run)" : ""), $sent],
            ["Skipped (already sent)", $skipped],
            ["No Active Token", $noToken],
            ["Failed", $failed],
        ]);

        if ($dryRun) {
            $this->warn("Dry run complete. Run without --dry-run to actually send.");
        } else {
            $this->info("Re-send complete. Sent: {$sent}, Failed: {$failed}, No token: {$noToken}");
        }

        Log::info("ResendEventPushNotificationsCommand completed", [
            "event_id" => $eventId,
            "dry_run" => $dryRun,
            "sent" => $sent,
            "skipped" => $skipped,
            "no_token" => $noToken,
            "failed" => $failed,
        ]);

        return self::SUCCESS;
    }
}
