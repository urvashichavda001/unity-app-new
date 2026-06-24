<?php

namespace App\Services\Membership;

use App\Mail\MembershipWelcomeMail;
use App\Models\User;
use App\Services\EmailLogs\EmailLogService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class MembershipWelcomeEmailService
{
    public function __construct(private readonly EmailLogService $emailLogService)
    {
    }

    public function sendIfEligible(User $user): array
    {
        $freshUser = User::query()->find($user->id);

        if (! $freshUser) {
            Log::warning('membership.welcome_email.user_not_found', [
                'user_id' => (string) $user->id,
            ]);

            return ['sent' => false, 'reason' => 'user_not_found'];
        }

        Log::info('membership.welcome_email.check_started', [
            'user_id' => (string) $freshUser->id,
            'membership_status' => (string) ($freshUser->membership_status ?? ''),
            'zoho_plan_code' => (string) ($freshUser->zoho_plan_code ?? ''),
        ]);

        if (! config('membership_welcome.enabled', true)) {
            Log::info('membership.welcome_email.skipped', [
                'user_id' => (string) $freshUser->id,
                'reason' => 'disabled',
            ]);

            return ['sent' => false, 'reason' => 'disabled'];
        }

        if (filled($freshUser->welcome_membership_email_sent_at)) {
            Log::info('membership.welcome_email.skipped', [
                'user_id' => (string) $freshUser->id,
                'reason' => 'already_sent',
            ]);

            return ['sent' => false, 'reason' => 'already_sent'];
        }

        $email = trim((string) ($freshUser->email ?? ''));
        if ($email === '') {
            Log::warning('membership.welcome_email.skipped', [
                'user_id' => (string) $freshUser->id,
                'reason' => 'missing_email',
            ]);

            return ['sent' => false, 'reason' => 'missing_email'];
        }

        if (! $this->isEligiblePaidMembershipUser($freshUser)) {
            Log::info('membership.welcome_email.skipped', [
                'user_id' => (string) $freshUser->id,
                'reason' => 'not_paid',
                'membership_status' => (string) ($freshUser->membership_status ?? ''),
            ]);

            return ['sent' => false, 'reason' => 'not_paid'];
        }

        $attachments = $this->resolveAttachments();
        $mailable = new MembershipWelcomeMail($freshUser, $attachments);

        try {
            Mail::to($email)->send($mailable);

            $freshUser->forceFill([
                'welcome_membership_email_sent_at' => now(),
                'welcome_membership_email_status' => 'sent',
                'welcome_membership_email_error' => null,
                'welcome_membership_email_plan_code' => $freshUser->zoho_plan_code,
            ])->save();

            $this->emailLogService->logMailableSent($mailable, [
                'user_id' => (string) $freshUser->id,
                'to_email' => $email,
                'to_name' => (string) ($freshUser->display_name ?: trim(($freshUser->first_name ?? '') . ' ' . ($freshUser->last_name ?? ''))),
                'template_key' => 'membership_welcome',
                'source_module' => 'membership',
                'related_type' => 'user',
                'related_id' => (string) $freshUser->id,
                'payload' => [
                    'flow' => 'zoho_membership_activation',
                    'membership_status' => (string) ($freshUser->membership_status ?? ''),
                    'zoho_plan_code' => (string) ($freshUser->zoho_plan_code ?? ''),
                    'attachments_count' => count($attachments),
                ],
            ]);

            Log::info('membership.welcome_email.sent', [
                'user_id' => (string) $freshUser->id,
                'attachments_count' => count($attachments),
            ]);

            return ['sent' => true, 'reason' => 'sent'];
        } catch (Throwable $throwable) {
            $message = Str::limit($throwable->getMessage(), 2000, '');

            $freshUser->forceFill([
                'welcome_membership_email_status' => 'failed',
                'welcome_membership_email_error' => $message,
                'welcome_membership_email_plan_code' => $freshUser->zoho_plan_code,
            ])->save();

            $this->emailLogService->logMailableFailed($mailable, [
                'user_id' => (string) $freshUser->id,
                'to_email' => $email,
                'to_name' => (string) ($freshUser->display_name ?: trim(($freshUser->first_name ?? '') . ' ' . ($freshUser->last_name ?? ''))),
                'template_key' => 'membership_welcome',
                'source_module' => 'membership',
                'related_type' => 'user',
                'related_id' => (string) $freshUser->id,
                'payload' => [
                    'flow' => 'zoho_membership_activation',
                    'membership_status' => (string) ($freshUser->membership_status ?? ''),
                    'zoho_plan_code' => (string) ($freshUser->zoho_plan_code ?? ''),
                    'attachments_count' => count($attachments),
                ],
            ], $throwable);

            Log::warning('membership.welcome_email.failed', [
                'user_id' => (string) $freshUser->id,
                'message' => $throwable->getMessage(),
            ]);

            return ['sent' => false, 'reason' => 'failed'];
        }
    }

    private function isEligiblePaidMembershipUser(User $user): bool
    {
        if (in_array((string) $user->effective_membership_status, [User::STATUS_FREE_TRIAL, User::STATUS_FREE], true)) {
            return false;
        }

        if (! $user->isPaidMember()) {
            return false;
        }

        return filled($user->zoho_subscription_id)
            || filled($user->zoho_plan_code)
            || filled($user->membership_starts_at)
            || filled($user->membership_ends_at);
    }

    private function resolveAttachments(): array
    {
        $attachmentConfigs = [
            [
                'path' => (string) config('membership_welcome.attachment_1_path', ''),
                'name' => (string) config('membership_welcome.attachment_1_name', ''),
            ],
            [
                'path' => (string) config('membership_welcome.attachment_2_path', ''),
                'name' => (string) config('membership_welcome.attachment_2_name', ''),
            ],
        ];

        $attachments = [];

        foreach ($attachmentConfigs as $index => $attachmentConfig) {
            $path = trim((string) Arr::get($attachmentConfig, 'path', ''));
            $name = trim((string) Arr::get($attachmentConfig, 'name', ''));

            if ($path === '') {
                Log::warning('membership.welcome_email.attachment_missing_path', [
                    'slot' => $index + 1,
                ]);

                continue;
            }

            if (! is_file($path)) {
                Log::warning('membership.welcome_email.attachment_not_found', [
                    'slot' => $index + 1,
                    'path' => $path,
                ]);

                continue;
            }

            $attachments[] = [
                'path' => $path,
                'name' => $name !== '' ? $name : basename($path),
            ];
        }

        return $attachments;
    }
}
