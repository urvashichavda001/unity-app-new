<?php

namespace App\Console\Commands;

use App\Events\UserNotificationCreated;
use App\Jobs\SendFcmNotificationJob;
use App\Models\Ad;
use App\Models\Category;
use App\Models\Circle;
use App\Models\CollaborationPost;
use App\Models\DailyNotificationReminder;
use App\Models\Event;
use App\Models\Notification;
use App\Models\User;
use App\Models\Referral;
use App\Models\BusinessDeal;
use App\Models\Testimonial;
use App\Models\LifeImpactHistory;
use App\Models\LeaderInterestSubmission;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SendDailyEngagementReminders extends Command
{
    protected $signature = 'app:send-daily-engagement-reminders {--user-id= : Target a specific user for manual trigger} {--all : Bypass all time and frequency checks to run immediately}';

    protected $description = 'Fetch dynamic templates from daily_notifications_reminder and dispatch engagement notifications to targeted users.';

    public function handle(): int
    {
        Log::info('Daily engagement reminders command started');
        $this->info('Daily engagement reminders command started.');

        $targetUserId = $this->option('user-id');
        $now = now()->tz('Asia/Kolkata');

        // Fetch all reminder templates and key by activity to prevent feature duplication overlap
        $reminders = DailyNotificationReminder::all()->keyBy('activity');

        if ($reminders->isEmpty()) {
            $this->error('No reminder templates found in daily_notifications_reminder. Please seed the table.');
            return self::FAILURE;
        }

        // Cache some shared lookup objects to prevent N+1 queries during placeholder replacement
        $randomPeer = User::query()->where('status', 'active')->where('membership_status', 'visitor')->inRandomOrder()->first()
            ?? User::query()->where('status', 'active')->inRandomOrder()->first();

        // 1. App-Wide: User hasn't opened the app today
        $activity = "User hasn't opened the app today";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->where(function ($q) {
                        $q->whereNull('last_login_at')
                          ->orWhere('last_login_at', '<', today());
                    });
                });
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 2. Peers: Daily peer discovery suggestion
        $activity = "Daily peer discovery suggestion";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                foreach ($users as $user) {
                    $peer = User::query()->where('id', '!=', $user->id)->where('status', 'active')->inRandomOrder()->first() ?? $randomPeer;
                    $this->dispatchSingleReminder($user, $reminder, [
                        '{Suggested Peer Name}' => $peer ? ($peer->first_name . ' ' . $peer->last_name) : 'A Peer',
                        '{Industry}' => $peer?->designation ?: 'Business',
                    ]);
                }
            }
        }

        // 3. Circles: Trending circle highlight
        $activity = "Trending circle highlight";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                // Calculate trending circle
                $trendingCircleId = DB::table('circle_members')
                    ->where('status', 'approved')
                    ->where('joined_at', '>=', now()->subDays(7))
                    ->groupBy('circle_id')
                    ->select('circle_id', DB::raw('count(*) as member_count'))
                    ->orderByDesc('member_count')
                    ->first()?->circle_id;

                $circle = $trendingCircleId ? Circle::find($trendingCircleId) : Circle::query()->where('status', 'active')->inRandomOrder()->first();
                $circleName = $circle?->name ?? 'Peers Global Circle';

                $this->dispatchReminders($users, $reminder, [
                    '{Circle Name}' => $circleName,
                ]);
            }
        }

        // 4. Leaderboard: Daily leaderboard teaser
        $activity = "Daily leaderboard teaser";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 5. Coins: Reminder of unused wallet balance
        $activity = "Reminder of unused wallet balance";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->where('coins_balance', '>', 0)
                          ->whereNotExists(function ($sub) {
                              $sub->select(DB::raw(1))
                                  ->from('coins_ledger')
                                  ->whereColumn('coins_ledger.user_id', 'users.id')
                                  ->where('created_at', '>', now()->subDays(3));
                          });
                });
                foreach ($users as $user) {
                    $this->dispatchSingleReminder($user, $reminder, [
                        '{X}' => (string) $user->coins_balance,
                    ]);
                }
            }
        }

        // 6. Referral Report: Encouragement to refer more peers
        $activity = "Encouragement to refer more peers";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('referrals')
                            ->whereColumn('referrals.from_user_id', 'users.id')
                            ->where('created_at', '>', now()->subDays(7));
                    });
                });
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 7. Events Management: Highlight upcoming events nearby
        $activity = "Highlight upcoming events nearby";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $eventCount = Event::query()
                    ->where('start_at', '>=', now())
                    ->where('start_at', '<=', now()->addDays(7))
                    ->count();
                if ($eventCount === 0) {
                    $eventCount = 3;
                }
                $this->dispatchReminders($users, $reminder, [
                    '{X}' => (string) $eventCount,
                ]);
            }
        }

        // 8. Business Deals: Inspire users to log a business deal
        $activity = "Inspire users to log a business deal";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('business_deals')
                            ->whereColumn('business_deals.from_user_id', 'users.id')
                            ->where('created_at', '>', now()->subDays(30));
                    });
                });
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 9. Testimonials: Prompt to give a testimonial
        $activity = "Prompt to give a testimonial";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('testimonials')
                            ->whereColumn('testimonials.from_user_id', 'users.id')
                            ->where('created_at', '>', now()->subDays(14));
                    });
                });
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 10. Life Impact: Inspire users to share their story
        $activity = "Inspire users to share their story";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from((new LifeImpactHistory)->getTable())
                            ->whereColumn('user_id', 'users.id')
                            ->where('created_at', '>', now()->subDays(30));
                    });
                });
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 11. Activities Summary: Daily activity digest
        $activity = "Daily activity digest";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 12. Find & Build Collaboration: Highlight open collaboration opportunities
        $activity = "Highlight open collaboration opportunities";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $activeCollabCount = CollaborationPost::query()->where('status', 'active')->count();
                if ($activeCollabCount === 0) {
                    $activeCollabCount = 5;
                }
                $this->dispatchReminders($users, $reminder, [
                    '{X}' => (string) $activeCollabCount,
                ]);
            }
        }

        // 13. Industries: Industry-specific trending news/tip
        $activity = "Industry-specific trending news/tip";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $insights = [
                    'Networking boosts local trade opportunities up to 40%.',
                    'Collaborative marketing can reduce customer acquisition costs by 30%.',
                    'Regular updates with peers improve collaboration success rates.',
                    'Cross-industry partnerships are the key source of 2026 innovation.'
                ];
                foreach ($users as $user) {
                    $insight = $insights[array_rand($insights)];
                    $this->dispatchSingleReminder($user, $reminder, [
                        '{Industry}' => $user->designation ?: 'Professional Development',
                        '{Insight Snippet}' => $insight,
                    ]);
                }
            }
        }

        // 14. Wallet & Finance: Reward redemption nudge
        $activity = "Reward redemption nudge";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->where('coins_balance', '>=', 100);
                });
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 15. Event Gallery: Throwback to a past event photo
        $activity = "Throwback to a past event photo";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $pastEvent = Event::query()->where('start_at', '<', now())->inRandomOrder()->first();
                $this->dispatchReminders($users, $reminder, [
                    '{Event Name}' => $pastEvent?->title ?? 'Peers Business Meetup',
                ]);
            }
        }

        // 16. Leadership: Inspire users to apply for leadership
        $activity = "Inspire users to apply for leadership";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->where(function ($sub) {
                        $sub->whereNull('leadership_roles')
                            ->orWhere('leadership_roles', '[]')
                            ->orWhere('leadership_roles', '{}');
                    })
                    ->whereNotExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('leader_interest_submissions')
                            ->whereColumn('leader_interest_submissions.user_id', 'users.id');
                    });
                });
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 17. Notifications & Email: Weekly community newsletter teaser
        $activity = "Weekly community newsletter teaser";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 18. App-Wide: Streak/engagement reminder
        $activity = "Streak/engagement reminder";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                // Fetch active users who logged in recently
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->whereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('user_login_histories')
                            ->whereColumn('user_login_histories.user_id', 'users.id')
                            ->where('logged_in_at', '>=', now()->subDays(5));
                    });
                });
                foreach ($users as $user) {
                    $streak = $this->calculateStreak($user);
                    if ($streak >= 2 || $targetUserId) {
                        $this->dispatchSingleReminder($user, $reminder, [
                            '{X}' => (string) ($streak ?: 3),
                        ]);
                    }
                }
            }
        }

        // 19. Become A Leader: Showcase a leader success story
        $activity = "Showcase a leader success story";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $leader = User::query()
                    ->whereNotNull('leadership_roles')
                    ->where('membership_status', 'premium')
                    ->inRandomOrder()
                    ->first();
                $this->dispatchReminders($users, $reminder, [
                    '{Leader Name}' => $leader ? ($leader->first_name . ' ' . $leader->last_name) : 'Anjali Sharma',
                ]);
            }
        }

        // 20. Ads: Daily curated offer/deal highlight
        $activity = "Daily curated offer/deal highlight";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $ad = Ad::query()->inRandomOrder()->first();
                $this->dispatchReminders($users, $reminder, [
                    '{Advertiser Name}' => $ad?->title ?? 'Certified Peers Sponsor',
                ]);
            }
        }

        // 21. Circle Categories: Explore new category prompt
        $activity = "Explore new category prompt";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $category = Category::query()->inRandomOrder()->first();
                $this->dispatchReminders($users, $reminder, [
                    '{Category Name}' => $category?->name ?? 'Entrepreneurship',
                ]);
            }
        }

        // 22. Impact Cycles: Cycle progress reminder
        $activity = "Cycle progress reminder";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $progress = (int) (now()->day / now()->daysInMonth * 100);
                $this->dispatchReminders($users, $reminder, [
                    '{X}' => (string) $progress,
                ]);
            }
        }

        // 23. App-Wide: Re-engagement after prolonged inactivity
        $activity = "Re-engagement after prolonged inactivity";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing, function ($query) {
                    $query->whereBetween('last_login_at', [now()->subDays(10), now()->subDays(5)]);
                });
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        // 24. Recommend A Peer: Prompt to recommend someone
        $activity = "Prompt to recommend someone";
        if ($reminder = $reminders->get($activity)) {
            if ($this->shouldRunTiming($reminder->action_trigger_timing, $now)) {
                $users = $this->getTargetUsers($targetUserId, $activity, $reminder->action_trigger_timing);
                $this->dispatchReminders($users, $reminder, []);
            }
        }

        Log::info('Daily engagement reminders command completed');
        $this->info('Daily engagement reminders command completed successfully.');

        return self::SUCCESS;
    }

    /**
     * Fetch users eligible for receiving notifications.
     */
    protected function getTargetUsers(?string $targetUserId, string $activity, string $timing, ?callable $additionalConstraints = null): \Illuminate\Support\Collection
    {
        $query = User::query()->where('status', 'active');

        if ($targetUserId) {
            $query->where('id', $targetUserId);
        } else {
            // Apply frequency restriction to avoid spamming users
            $frequencyDays = 0;
            $timingLower = strtolower($timing);
            if (str_contains($timingLower, 'once every 2 days')) {
                $frequencyDays = 2;
            } elseif (str_contains($timingLower, 'once every 3 days')) {
                $frequencyDays = 3;
            } elseif (str_contains($timingLower, 'once every 4 days')) {
                $frequencyDays = 4;
            } elseif (str_contains($timingLower, 'once every 5 days')) {
                $frequencyDays = 5;
            } elseif (str_contains($timingLower, 'once weekly') || str_contains($timingLower, 'weekly')) {
                $frequencyDays = 7;
            }

            if ($frequencyDays > 0) {
                $query->whereNotExists(function ($sub) use ($activity, $frequencyDays) {
                    $sub->select(DB::raw(1))
                        ->from('notifications')
                        ->whereColumn('notifications.user_id', 'users.id')
                        ->where('type', 'system')
                        ->where('payload->activity', $activity)
                        ->where('created_at', '>', now()->subDays($frequencyDays));
                });
            }
        }

        if ($additionalConstraints) {
            $additionalConstraints($query);
        }

        return $query->get();
    }

    /**
     * Dispatch reminders to a collection of users.
     */
    protected function dispatchReminders(\Illuminate\Support\Collection $users, DailyNotificationReminder $reminder, array $placeholders): void
    {
        foreach ($users as $user) {
            $this->dispatchSingleReminder($user, $reminder, $placeholders);
        }
    }

    /**
     * Dispatch a single reminder to a user.
     */
    protected function dispatchSingleReminder(User $user, DailyNotificationReminder $reminder, array $placeholders): void
    {
        $title = $this->replacePlaceholders($reminder->notification_title, $placeholders);
        $body = $this->replacePlaceholders($reminder->notification_body, $placeholders);

        try {
            // Write to database notifications table
            $dbNotification = Notification::forceCreate([
                'id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'type' => 'system',
                'payload' => [
                    'notification_type' => 'engagement_reminder',
                    'title' => $title,
                    'body' => $body,
                    'feature' => $reminder->feature,
                    'activity' => $reminder->activity,
                ],
                'is_read' => false,
                'created_at' => now(),
                'read_at' => null,
            ]);

            // Broadcast real-time notifications event
            event(new UserNotificationCreated((string) $user->id, [
                'id' => (string) $dbNotification->id,
                'type' => (string) $dbNotification->type,
                'payload' => $dbNotification->payload,
                'created_at' => optional($dbNotification->created_at)->toISOString(),
            ]));

            // Dispatch async FCM push notification delivery job
            SendFcmNotificationJob::dispatch(
                (string) $user->id,
                $title,
                $body,
                [
                    'notification_type' => 'engagement_reminder',
                    'notification_id' => (string) $dbNotification->id,
                ]
            );
        } catch (Throwable $e) {
            Log::error('failed_to_dispatch_engagement_reminder', [
                'user_id' => $user->id,
                'reminder_id' => $reminder->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Helper to check if a specific timing matches the current slot.
     */
    protected function shouldRunTiming(string $timing, Carbon $now): bool
    {
        // If it's a manual trigger, we bypass timing checks
        if ($this->option('all') || $this->option('user-id')) {
            return true;
        }

        $timing = strtolower($timing);

        // Monday morning check
        if (str_contains($timing, 'monday morning')) {
            return $now->isMonday() && $now->hour === 9;
        }

        // Weekend slot check
        if (str_contains($timing, 'weekend')) {
            return ($now->isSaturday() || $now->isSunday()) && $now->hour === 10;
        }

        // Slot checks
        if (str_contains($timing, 'morning slot')) {
            return $now->hour === 9;
        }
        if (str_contains($timing, 'midday slot')) {
            return $now->hour === 13;
        }
        if (str_contains($timing, 'afternoon slot')) {
            return $now->hour === 16;
        }
        if (str_contains($timing, 'evening slot')) {
            return $now->hour === 18;
        }
        if (str_contains($timing, '7–8 pm') || str_contains($timing, '7-8 pm')) {
            return $now->hour === 19;
        }
        if (str_contains($timing, '8–9 pm') || str_contains($timing, '8-9 pm') || str_contains($timing, 'end of day')) {
            return $now->hour === 20;
        }

        // Default: Once daily check run in the evening slot
        return $now->hour === 18;
    }

    /**
     * Calculate user streak from login history.
     */
    protected function calculateStreak(User $user): int
    {
        $dates = DB::table('user_login_histories')
            ->where('user_id', $user->id)
            ->where('logged_in_at', '>=', now()->subDays(10))
            ->orderByDesc('logged_in_at')
            ->pluck('logged_in_at')
            ->map(fn($t) => Carbon::parse($t)->toDateString())
            ->unique()
            ->values();

        if ($dates->isEmpty()) {
            return 0;
        }

        $streak = 0;
        $currentDate = today();

        if ($dates->first() === $currentDate->toDateString()) {
            $streak = 1;
        } elseif ($dates->first() === $currentDate->copy()->subDay()->toDateString()) {
            $streak = 1;
            $currentDate = $currentDate->copy()->subDay();
        } else {
            return 0;
        }

        for ($i = 1; $i < $dates->count(); $i++) {
            $expectedDate = $currentDate->copy()->subDays($i)->toDateString();
            if ($dates->get($i) === $expectedDate) {
                $streak++;
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Helper to replace curly brace placeholders in string content.
     */
    protected function replacePlaceholders(string $text, array $placeholders): string
    {
        return str_replace(array_keys($placeholders), array_values($placeholders), $text);
    }
}
