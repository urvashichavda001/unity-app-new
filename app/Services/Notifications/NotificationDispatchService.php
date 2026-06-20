<?php

namespace App\Services\Notifications;

use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationCampaign;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class NotificationDispatchService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    public function sendCampaignNotification(
        string $campaignCode,
        array|Collection|User $recipients,
        array $placeholders = [],
        array $data = [],
        ?User $actor = null,
        ?Model $reference = null,
        array $options = []
    ): Collection {
        $campaign = NotificationCampaign::where('code', $campaignCode)->where('is_active', true)->first();
        if (! $campaign) {
            return collect();
        }

        $users = $recipients instanceof User ? collect([$recipients]) : collect($recipients);
        $users = $users->filter(fn ($user) => $user instanceof User)
            ->unique(fn (User $user) => (string) $user->id)
            ->reject(fn (User $user) => $actor && (string) $user->id === (string) $actor->id && empty($options['send_to_actor']))
            ->values();

        $screen = $options['screen'] ?? $data['screen'] ?? $campaign->tap_screen;
        $type = $options['type'] ?? $data['type'] ?? $campaign->code;
        $referenceType = $options['reference_type'] ?? ($reference ? $reference::class : null);
        $referenceId = $options['reference_id'] ?? ($reference ? (string) $reference->getKey() : null);
        $basePlaceholders = array_merge($this->defaultPlaceholders($actor), $placeholders);

        return $users->map(function (User $user) use ($campaign, $basePlaceholders, $data, $actor, $screen, $type, $referenceType, $referenceId, $options): ?AppNotification {
            $rendered = $this->renderCampaign($campaign, $basePlaceholders);
            $dedupeKey = $options['dedupe_key'] ?? $data['dedupe_key'] ?? null;
            if ($dedupeKey) {
                $dedupeKey .= ':' . $user->id;
            }

            return $this->notifications->sendToUser(
                $user,
                $type,
                $rendered['title'],
                $rendered['body'],
                array_merge($data, ['screen' => $screen, 'campaign_id' => $campaign->id]),
                [
                    'campaign' => $campaign,
                    'category' => $campaign->category,
                    'channel' => $options['channel'] ?? $campaign->channel ?? 'push',
                    'priority' => $options['priority'] ?? $campaign->priority ?? 'medium',
                    'screen' => $screen,
                    'actor_user_id' => $actor?->id,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                    'dedupe_key' => $dedupeKey,
                    'send_to_actor' => $options['send_to_actor'] ?? false,
                ]
            );
        })->filter()->values();
    }

    private function renderCampaign(NotificationCampaign $campaign, array $placeholders): array
    {
        return [
            'title' => $this->renderTemplate((string) $campaign->title_template, $placeholders),
            'body' => $this->renderTemplate((string) $campaign->body_template, $placeholders),
        ];
    }

    private function renderTemplate(string $template, array $placeholders): string
    {
        foreach ($placeholders as $key => $value) {
            $value = (string) $value;
            $template = str_replace([
                '{{' . $key . '}}',
                '{' . $key . '}',
                '<' . $key . '>',
                '[' . Str::of($key)->replace('_', ' ')->title() . ']',
            ], $value, $template);
        }

        return $template;
    }

    private function defaultPlaceholders(?User $actor): array
    {
        $name = $actor ? (trim((string) ($actor->display_name ?? '')) ?: trim(((string) ($actor->first_name ?? '')) . ' ' . ((string) ($actor->last_name ?? ''))) ?: (string) ($actor->name ?? 'A member')) : 'A member';

        return ['person' => $name, 'name' => $name];
    }
}
