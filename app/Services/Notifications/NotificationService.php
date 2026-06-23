<?php

namespace App\Services\Notifications;

use App\Jobs\Notifications\SendNotificationChannelJob;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationCampaign;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Models\Notifications\NotificationPreference;
use App\Models\CircleMember;
use App\Models\Connection;
use App\Models\Notifications\NotificationSuppressionLog;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class NotificationService
{
    public function sendToUser(User $user, string $type, string $title, string $body, array $data = [], array $options = []): ?AppNotification
    {
        $actor = $options['actor_user_id'] ?? $options['actor_id'] ?? null;
        if ($actor && (string) $actor === (string) $user->id && empty($options['send_to_actor'])) {
            return null;
        }

        $campaign = $options['campaign'] ?? null;
        $dedupe = $options['dedupe_key'] ?? ($data['dedupe_key'] ?? $this->dedupeKey($type, $actor, $user->id, $options['reference_type'] ?? ($data['reference_type'] ?? null), $options['reference_id'] ?? ($data['reference_id'] ?? null)));

        if (! $this->shouldSendToUser($user, $type, $dedupe, $campaign)) {
            return null;
        }

        return DB::transaction(function () use ($user, $type, $title, $body, $data, $options, $campaign, $dedupe): AppNotification {
            $notification = $this->createInAppNotification($user, $type, $title, $body, $data, array_merge($options, ['dedupe_key' => $dedupe]));

            NotificationDeliveryLog::create([
                'notification_id' => $notification->id,
                'campaign_id' => $notification->campaign_id,
                'user_id' => $notification->user_id,
                'channel' => 'in_app',
                'provider' => 'database',
                'status' => $notification->read_at ? 'read' : 'sent',
                'request_payload' => $notification->dataPayload(),
                'response_payload' => ['notification_id' => (string) $notification->id],
                'attempted_at' => now(),
                'delivered_at' => now(),
            ]);

            foreach ($this->deliveryChannels($notification->channel) as $channel) {
                SendNotificationChannelJob::dispatch($notification->id, $channel);
            }

            $log = NotificationSuppressionLog::firstOrNew(['user_id' => $user->id, 'type' => $type, 'dedupe_key' => $dedupe]);
            $log->campaign_id = $campaign?->id;
            $log->last_sent_at = now();
            $log->send_count = ((int) $log->send_count ?: 0) + 1;
            $log->save();

            return $notification;
        });
    }


    public function sendPostPublishedNotification(Post $post, bool $force = false): array
    {
        $post->loadMissing('user', 'circle');

        $existingCount = AppNotification::query()
            ->where('type', 'new_post')
            ->where('reference_type', 'post')
            ->where('reference_id', (string) $post->id)
            ->count();

        if ($existingCount > 0 && ! $force) {
            return [
                'recipients_count' => 0,
                'in_app_created' => 0,
                'push_sent' => 0,
                'push_failed' => 0,
                'push_skipped' => 0,
                'already_exists_count' => $existingCount,
                'reason' => 'Notification already exists',
            ];
        }

        $author = $post->user ?: User::find($post->user_id);
        if (! $author) {
            return [
                'recipients_count' => 0,
                'in_app_created' => 0,
                'push_sent' => 0,
                'push_failed' => 0,
                'push_skipped' => 0,
                'already_exists_count' => $existingCount,
                'reason' => 'Post author not found',
            ];
        }

        $recipients = $this->postNotificationRecipients($post);
        if ($recipients->isEmpty()) {
            return [
                'recipients_count' => 0,
                'in_app_created' => 0,
                'push_sent' => 0,
                'push_failed' => 0,
                'push_skipped' => 0,
                'already_exists_count' => $existingCount,
                'reason' => 'No eligible recipients found',
            ];
        }

        $authorName = $this->displayName($author);
        $body = $this->postPreview($post) ?: 'A new post has been published';
        $dedupeKey = 'new_post:' . $post->id . ($force ? ':force:' . now()->timestamp : '');

        $notifications = $this->sendToUsers(
            $recipients,
            'new_post',
            'New post by ' . $authorName,
            $body,
            [
                'post_id' => (string) $post->id,
                'actor_id' => (string) $post->user_id,
                'screen' => 'post_detail',
                'tap_destination' => 'post_detail',
                'reference_type' => 'post',
                'reference_id' => (string) $post->id,
            ],
            [
                'actor_id' => (string) $post->user_id,
                'channel' => 'push',
                'reference_type' => 'post',
                'reference_id' => (string) $post->id,
                'dedupe_key' => $dedupeKey,
            ]
        );

        $logs = NotificationDeliveryLog::query()->whereIn('notification_id', $notifications->pluck('id'))->get();

        return [
            'recipients_count' => $recipients->count(),
            'in_app_created' => $notifications->count(),
            'push_sent' => $logs->where('channel', 'push')->whereIn('status', ['sent', 'delivered'])->count(),
            'push_failed' => $logs->where('channel', 'push')->where('status', 'failed')->count(),
            'push_skipped' => $logs->where('channel', 'push')->where('status', 'skipped')->count(),
            'already_exists_count' => $existingCount,
            'reason' => $notifications->isEmpty() ? 'Notification trigger missing' : 'Notification sent',
        ];
    }

    public function postNotificationRecipients(Post $post): Collection
    {
        $authorId = (string) $post->user_id;

        $connectionIds = Connection::query()
            ->where('is_approved', true)
            ->where(fn ($query) => $query->where('requester_id', $authorId)->orWhere('addressee_id', $authorId))
            ->get()
            ->flatMap(fn (Connection $connection) => [(string) $connection->requester_id, (string) $connection->addressee_id]);

        $circleIds = CircleMember::query()
            ->where('user_id', $authorId)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('status')->orWhereIn('status', CircleMember::activeStatuses()))
            ->pluck('circle_id');

        $circleUserIds = CircleMember::query()
            ->whereIn('circle_id', $circleIds)
            ->whereNull('deleted_at')
            ->where(fn ($query) => $query->whereNull('status')->orWhereIn('status', CircleMember::activeStatuses()))
            ->pluck('user_id');

        $ids = $connectionIds
            ->merge($circleUserIds)
            ->filter()
            ->unique()
            ->reject(fn ($id) => (string) $id === $authorId)
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $ids)
            ->when(\Illuminate\Support\Facades\Schema::hasColumn('users', 'status'), fn ($query) => $query->where(fn ($userQuery) => $userQuery->whereNull('status')->orWhere('status', 'active')))
            ->get();
    }

    private function postPreview(Post $post): string
    {
        return \Illuminate\Support\Str::limit(trim((string) $post->content_text), 120) ?: 'A new post has been published';
    }

    private function displayName(User $user): string
    {
        return trim((string) ($user->display_name ?? ''))
            ?: trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? '')))
            ?: (string) ($user->name ?? 'A member');
    }

    public function sendToUsers(Collection|array $users, string $type, string $title, string $body, array $data = [], array $options = []): Collection
    {
        $actor = $options['actor_user_id'] ?? $options['actor_id'] ?? null;

        return collect($users)
            ->filter()
            ->unique(fn ($user) => (string) ($user instanceof User ? $user->id : $user))
            ->reject(fn ($user) => $actor && (string) ($user instanceof User ? $user->id : $user) === (string) $actor && empty($options['send_to_actor']))
            ->map(function ($user) use ($type, $title, $body, $data, $options): ?AppNotification {
                $model = $user instanceof User ? $user : User::find($user);
                return $model ? $this->sendToUser($model, $type, $title, $body, $data, $options) : null;
            })
            ->filter()
            ->values();
    }

    public function createInAppNotification(User $user, string $type, string $title, string $body, array $data = [], array $options = []): AppNotification
    {
        $screen = $options['screen'] ?? ($data['screen'] ?? $data['tap_destination'] ?? 'home');
        $referenceId = $options['reference_id'] ?? ($data['reference_id'] ?? null);
        $referenceType = $options['reference_type'] ?? ($data['reference_type'] ?? null);
        $payload = array_merge($data, [
            'type' => $type,
            'screen' => $screen,
            'tap_destination' => $data['tap_destination'] ?? $screen,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
        ]);

        return AppNotification::create([
            'user_id' => $user->id,
            'campaign_id' => $options['campaign']?->id ?? ($options['campaign_id'] ?? null),
            'type' => $type,
            'category' => $options['category'] ?? null,
            'title' => $title,
            'body' => $body,
            'channel' => $options['channel'] ?? 'push',
            'priority' => $options['priority'] ?? 'medium',
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'screen' => $screen,
            'data' => $payload,
            'dedupe_key' => $options['dedupe_key'] ?? ($data['dedupe_key'] ?? null),
            'status' => 'pending',
        ]);
    }

    public function sendPushNotification(AppNotification $n): void { SendNotificationChannelJob::dispatch($n->id, 'push'); }
    public function sendEmailNotification(AppNotification $n): void { SendNotificationChannelJob::dispatch($n->id, 'email'); }

    public function renderTemplate(string $template, array $placeholders): string
    {
        foreach ($placeholders as $k => $v) {
            $template = str_replace(['{{'.$k.'}}', '{'.$k.'}'], (string) $v, $template);
        }
        return $template;
    }

    public function shouldSendToUser(User $user, string $type, ?string $dedupeKey, ?NotificationCampaign $campaign): bool
    {
        if ($campaign && ! $campaign->is_active) return false;
        $p = NotificationPreference::firstOrCreate(['user_id' => $user->id]);
        if (! $p->push_enabled && ! $p->email_enabled) return false;
        if ($campaign && ! $p->campaign_enabled) return false;
        if ($type === 'chat_message' && ! $p->chat_enabled) return false;
        if ($dedupeKey && AppNotification::where('user_id', $user->id)->where('dedupe_key', $dedupeKey)->where('created_at', '>=', now()->subDay())->exists()) return false;
        $priority = $campaign?->priority ?? 'medium';
        if ($priority === 'urgent') return true;
        $limit = ['high' => 5, 'medium' => 3, 'low' => 1][$priority] ?? 3;
        return AppNotification::where('user_id', $user->id)->where('priority', $priority)->whereDate('created_at', today())->count() < ($campaign?->daily_limit ?? $limit);
    }

    public function markAsRead(User $user, string $notificationId): AppNotification
    {
        $n = AppNotification::where('user_id', $user->id)->findOrFail($notificationId);
        if (! $n->read_at) {
            $n->update(['read_at' => now(), 'status' => $n->status === 'pending' ? 'sent' : $n->status]);
        }
        return $n->refresh();
    }

    public function markAllAsRead(User $user): int
    {
        return AppNotification::where('user_id', $user->id)->whereNull('read_at')->update(['read_at' => now()]);
    }

    public function recordClick(User $user, string $notificationId): AppNotification
    {
        $n = AppNotification::where('user_id', $user->id)->findOrFail($notificationId);
        $n->update(['clicked_at' => now(), 'read_at' => $n->read_at ?? now()]);
        return $n->refresh();
    }

    private function deliveryChannels(string $channel): array
    {
        return collect(explode(',', str_replace(['both', 'push_email', 'in_app_only'], ['push,email', 'push,email', ''], $channel)))
            ->map(fn ($ch) => trim($ch))
            ->filter(fn ($ch) => in_array($ch, ['push', 'email'], true))
            ->unique()
            ->values()
            ->all();
    }

    private function dedupeKey(string $type, mixed $actorId, mixed $userId, mixed $referenceType, mixed $referenceId): string
    {
        return implode(':', array_map(fn ($part) => (string) ($part ?: 'none'), [$type, $actorId, $userId, $referenceType, $referenceId]));
    }
}
