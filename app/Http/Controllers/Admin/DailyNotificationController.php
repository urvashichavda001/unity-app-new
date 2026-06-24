<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDailyNotificationReminderRequest;
use App\Services\DailyNotificationReminderService;
use App\Models\DailyNotificationReminder;
use App\Models\User;
use App\Models\Notification;
use App\Models\Ad;
use App\Models\Category;
use App\Models\Circle;
use App\Models\CollaborationPost;
use App\Models\Event;
use App\Events\UserNotificationCreated;
use App\Jobs\SendFcmNotificationJob;
use App\Models\LifeImpactHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Throwable;

class DailyNotificationController extends Controller
{
    protected DailyNotificationReminderService $service;

    public function __construct(DailyNotificationReminderService $service)
    {
        $this->service = $service;
    }

    /**
     * Display a listing of daily notification reminders with stats.
     */
    public function index(Request $request): View
    {
        $reminders = $this->service->getAllReminders();
        $counts = $this->calculateEligibleCounts();

        return view('admin.daily-notifications.index', compact('reminders', 'counts'));
    }

    /**
     * Update the specified daily notification reminder in storage.
     */
    public function update(UpdateDailyNotificationReminderRequest $request, string $id): RedirectResponse
    {
        try {
            $this->service->updateReminder($id, $request->validated());

            return redirect()->route('admin.daily-notifications.index')
                ->with('success', 'Daily notification reminder updated successfully.');
        } catch (Throwable $e) {
            Log::error('failed_to_update_daily_notification_reminder', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()
                ->withInput()
                ->with('error', 'Failed to update daily notification reminder: ' . $e->getMessage());
        }
    }

    /**
     * Return JSON array of eligible users for a given template activity.
     */
    public function eligibleUsers(string $id): JsonResponse
    {
        try {
            $reminder = DailyNotificationReminder::findOrFail($id);
            $query = $this->getEligibleUsersQuery($reminder->activity)->with(['city', 'businessCategory']);

            $usersCollection = $query->get();

            if ($reminder->activity === "Streak/engagement reminder") {
                $usersCollection = $usersCollection->filter(function ($user) {
                    return $this->calculateStreak($user) >= 2;
                });
            }

            $users = $usersCollection->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'company_name' => $user->company_name ?? 'N/A',
                    'city' => $user->city?->name ?? 'N/A',
                    'business_category' => $user->businessCategory?->name ?? $user->business_type ?? 'N/A',
                ];
            })->values();

            return response()->json([
                'success' => true,
                'activity' => $reminder->activity,
                'users' => $users
            ]);
        } catch (Throwable $e) {
            Log::error('failed_to_fetch_eligible_users', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch eligible users: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Immediately dispatch push notifications to all users matching the template's criteria (manual override).
     */
    public function sendReminder(string $id): RedirectResponse
    {
        try {
            $reminder = DailyNotificationReminder::findOrFail($id);
            $query = $this->getEligibleUsersQuery($reminder->activity);

            $users = $query->get();
            if ($reminder->activity === "Streak/engagement reminder") {
                $users = $users->filter(function ($user) {
                    return $this->calculateStreak($user) >= 2;
                });
            }

            if ($users->isEmpty()) {
                return redirect()->route('admin.daily-notifications.index')
                    ->with('error', 'No eligible users found for this notification template right now.');
            }

            // Setup placeholder caches
            $randomPeer = User::query()->where('status', 'active')->where('membership_status', 'visitor')->inRandomOrder()->first()
                ?? User::query()->where('status', 'active')->inRandomOrder()->first();
            $randomCircle = Circle::query()->where('status', 'active')->inRandomOrder()->first();
            $totalUpcomingEvents = Event::query()->where('start_at', '>', now())->count();
            $totalCollaborations = CollaborationPost::query()->where('status', 'active')->count();
            $pastEvent = Event::query()->where('start_at', '<', now())->inRandomOrder()->first();
            $randomAd = Ad::query()->inRandomOrder()->first();
            $randomCategory = Category::query()->inRandomOrder()->first();

            $dispatchedCount = 0;

            foreach ($users as $user) {
                // Determine placeholders
                $placeholders = [];
                $activity = $reminder->activity;

                if ($activity === 'Daily peer discovery suggestion') {
                    $peer = User::query()->where('id', '!=', $user->id)->where('status', 'active')->inRandomOrder()->first() ?? $randomPeer;
                    $placeholders = [
                        '{Suggested Peer Name}' => $peer ? ($peer->first_name . ' ' . $peer->last_name) : 'A Peer',
                        '{Industry}' => $peer?->designation ?: 'Business',
                    ];
                } elseif ($activity === 'Trending circle highlight') {
                    $placeholders = [
                        '{Circle Name}' => $randomCircle?->name ?? 'Peers Global Circle',
                    ];
                } elseif ($activity === 'Reminder of unused wallet balance') {
                    $placeholders = [
                        '{X}' => (string) ($user->coins_balance ?: 250),
                    ];
                } elseif ($activity === 'Highlight upcoming events nearby') {
                    $placeholders = [
                        '{X}' => (string) ($totalUpcomingEvents ?: 3),
                    ];
                } elseif ($activity === 'Highlight open collaboration opportunities') {
                    $placeholders = [
                        '{X}' => (string) ($totalCollaborations ?: 5),
                    ];
                } elseif ($activity === 'Industry-specific trending news/tip') {
                    $insights = [
                        'Networking boosts local trade opportunities up to 40%.',
                        'Collaborative marketing can reduce customer acquisition costs by 30%.',
                        'Regular updates with peers improve collaboration success rates.',
                        'Cross-industry partnerships are the key source of 2026 innovation.'
                    ];
                    $placeholders = [
                        '{Industry}' => $user->designation ?: 'Professional Development',
                        '{Insight Snippet}' => $insights[array_rand($insights)],
                    ];
                } elseif ($activity === 'Throwback to a past event photo') {
                    $placeholders = [
                        '{Event Name}' => $pastEvent?->title ?? 'Peers Business Meetup',
                    ];
                } elseif ($activity === 'Streak/engagement reminder') {
                    $streak = $this->calculateStreak($user);
                    $placeholders = [
                        '{X}' => (string) ($streak ?: 3),
                    ];
                } elseif ($activity === 'Showcase a leader success story') {
                    $leader = User::query()->whereNotNull('leadership_roles')->where('membership_status', 'premium')->inRandomOrder()->first();
                    $placeholders = [
                        '{Leader Name}' => $leader ? ($leader->first_name . ' ' . $leader->last_name) : 'Tan Hars',
                    ];
                } elseif ($activity === 'Daily curated offer/deal highlight') {
                    $placeholders = [
                        '{Advertiser Name}' => $randomAd?->title ?? 'Certified Peers Sponsor',
                    ];
                } elseif ($activity === 'Explore new category prompt') {
                    $placeholders = [
                        '{Category Name}' => $randomCategory?->name ?? 'Entrepreneurship',
                    ];
                } elseif ($activity === 'Cycle progress reminder') {
                    $placeholders = [
                        '{X}' => '60',
                    ];
                }

                $title = $this->replacePlaceholders($reminder->notification_title, $placeholders);
                $body = $this->replacePlaceholders($reminder->notification_body, $placeholders);

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

                $dispatchedCount++;
            }

            return redirect()->route('admin.daily-notifications.index')
                ->with('success', "Engagement reminder notification manually dispatched to {$dispatchedCount} eligible users successfully.");
        } catch (Throwable $e) {
            Log::error('failed_to_manually_dispatch_reminder', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->route('admin.daily-notifications.index')
                ->with('error', 'Failed to manually dispatch reminder: ' . $e->getMessage());
        }
    }

    /**
     * Test route action to instantly trigger all 24 notifications for a specific user ID,
     * bypassing all time, timezone, and frequency checks.
     */
    public function testNotifications(Request $request): JsonResponse
    {
        try {
            $userId = $request->input('user_id');
            $user = null;

            if ($userId) {
                $user = User::query()->find($userId);
            }

            if (!$user) {
                $user = User::query()->where('email', 'missurvashi300@gmail.com')->first();
            }

            if (!$user) {
                $user = User::query()->first();
            }

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active user found in the database to target.'
                ], 404);
            }

            $reminders = DailyNotificationReminder::all();

            if ($reminders->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'error' => 'No reminder templates found in daily_notifications_reminder table.'
                ], 400);
            }

            // Ensure the user has at least one push token for FCM testing
            if (!$user->pushTokens()->exists()) {
                $user->pushTokens()->create([
                    'token' => 'dummy_fcm_token_for_testing_' . Str::random(10),
                    'platform' => 'android',
                    'device_id' => 'device_id_' . Str::random(8),
                    'app_version' => '1.0.0',
                    'last_seen_at' => now(),
                ]);
            }

            // Cache shared assets for placeholder replacements
            $randomPeer = User::query()->where('id', '!=', $user->id)->where('status', 'active')->inRandomOrder()->first()
                ?? User::query()->where('status', 'active')->inRandomOrder()->first();
            $randomCircle = Circle::query()->where('status', 'active')->inRandomOrder()->first();
            $totalUpcomingEvents = Event::query()->where('start_at', '>', now())->count();
            $totalCollaborations = CollaborationPost::query()->where('status', 'active')->count();
            $pastEvent = Event::query()->where('start_at', '<', now())->inRandomOrder()->first();
            $randomAd = Ad::query()->inRandomOrder()->first();
            $randomCategory = Category::query()->inRandomOrder()->first();

            $dispatched = [];

            foreach ($reminders as $reminder) {
                // Determine placeholders dynamically based on activity type
                $placeholders = [];
                $activity = $reminder->activity;

                if ($activity === 'Daily peer discovery suggestion') {
                    $placeholders = [
                        '{Suggested Peer Name}' => $randomPeer ? ($randomPeer->first_name . ' ' . $randomPeer->last_name) : 'A Peer',
                        '{Industry}' => $randomPeer?->designation ?: 'Business',
                    ];
                } elseif ($activity === 'Trending circle highlight') {
                    $placeholders = [
                        '{Circle Name}' => $randomCircle?->name ?? 'Peers Global Circle',
                    ];
                } elseif ($activity === 'Reminder of unused wallet balance') {
                    $placeholders = [
                        '{X}' => (string) ($user->coins_balance ?: 250),
                    ];
                } elseif ($activity === 'Highlight upcoming events nearby') {
                    $placeholders = [
                        '{X}' => (string) ($totalUpcomingEvents ?: 3),
                    ];
                } elseif ($activity === 'Highlight open collaboration opportunities') {
                    $placeholders = [
                        '{X}' => (string) ($totalCollaborations ?: 5),
                    ];
                } elseif ($activity === 'Industry-specific trending news/tip') {
                    $placeholders = [
                        '{Industry}' => $user->designation ?: 'Professional Development',
                        '{Insight Snippet}' => 'Networking boosts local trade opportunities up to 40%.',
                    ];
                } elseif ($activity === 'Throwback to a past event photo') {
                    $placeholders = [
                        '{Event Name}' => $pastEvent?->title ?? 'Peers Business Meetup',
                    ];
                } elseif ($activity === 'Streak/engagement reminder') {
                    $placeholders = [
                        '{X}' => '5',
                    ];
                } elseif ($activity === 'Showcase a leader success story') {
                    $placeholders = [
                        '{Leader Name}' => 'Tan Hars',
                    ];
                } elseif ($activity === 'Daily curated offer/deal highlight') {
                    $placeholders = [
                        '{Advertiser Name}' => $randomAd?->title ?? 'Certified Peers Sponsor',
                    ];
                } elseif ($activity === 'Explore new category prompt') {
                    $placeholders = [
                        '{Category Name}' => $randomCategory?->name ?? 'Entrepreneurship',
                    ];
                } elseif ($activity === 'Cycle progress reminder') {
                    $placeholders = [
                        '{X}' => '60',
                    ];
                }

                $title = $this->replacePlaceholders($reminder->notification_title, $placeholders);
                $body = $this->replacePlaceholders($reminder->notification_body, $placeholders);

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

                $dispatched[] = [
                    'feature' => $reminder->feature,
                    'activity' => $reminder->activity,
                    'title' => $title,
                    'body' => $body,
                    'notification_id' => $dbNotification->id,
                ];
            }

            return response()->json([
                'success' => true,
                'target_user' => [
                    'id' => $user->id,
                    'email' => $user->email,
                    'name' => $user->first_name . ' ' . $user->last_name,
                ],
                'sent_count' => count($dispatched),
                'notifications' => $dispatched,
            ]);

        } catch (Throwable $e) {
            Log::error('failed_to_dispatch_test_notifications', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Failed to trigger test notifications: ' . $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 500);
        }
    }

    /**
     * Calculate user counts matching the 24 Eloquent criteria.
     */
    protected function calculateEligibleCounts(): array
    {
        $counts = [];
        $activities = [
            "User hasn't opened the app today",
            "Daily peer discovery suggestion",
            "Trending circle highlight",
            "Daily leaderboard teaser",
            "Reminder of unused wallet balance",
            "Encouragement to refer more peers",
            "Highlight upcoming events nearby",
            "Inspire users to log a business deal",
            "Prompt to give a testimonial",
            "Inspire users to share their story",
            "Daily activity digest",
            "Highlight open collaboration opportunities",
            "Industry-specific trending news/tip",
            "Reward redemption nudge",
            "Throwback to a past event photo",
            "Inspire users to apply for leadership",
            "Weekly community newsletter teaser",
            "Streak/engagement reminder",
            "Showcase a leader success story",
            "Daily curated offer/deal highlight",
            "Explore new category prompt",
            "Cycle progress reminder",
            "Re-engagement after prolonged inactivity",
            "Prompt to recommend someone"
        ];

        foreach ($activities as $act) {
            $counts[$act] = 0;
        }

        try {
            foreach ($activities as $act) {
                if ($act === "Streak/engagement reminder") {
                    $counts[$act] = $this->getEligibleUsersQuery($act)->get()->filter(function ($user) {
                        return $this->calculateStreak($user) >= 2;
                    })->count();
                } else {
                    $counts[$act] = $this->getEligibleUsersQuery($act)->count();
                }
            }
        } catch (Throwable $e) {
            Log::warning('failed_to_calculate_eligible_notification_counts_defaulting_to_zero', [
                'error' => $e->getMessage()
            ]);
        }

        return $counts;
    }

    /**
     * Helper method to map activity names to their corresponding Eloquent builders.
     */
    protected function getEligibleUsersQuery(string $activity): \Illuminate\Database\Eloquent\Builder
    {
        $query = User::query()->where('status', 'active');

        switch ($activity) {
            case "User hasn't opened the app today":
                $query->where(function ($q) {
                    $q->whereNull('last_login_at')
                      ->orWhere('last_login_at', '<', today());
                });
                break;

            case "Reminder of unused wallet balance":
                $query->where('coins_balance', '>', 0)
                      ->whereNotExists(function ($sub) {
                          $sub->select(DB::raw(1))
                              ->from('coins_ledger')
                              ->whereColumn('coins_ledger.user_id', 'users.id')
                              ->where('created_at', '>', now()->subDays(3));
                      });
                break;

            case "Encouragement to refer more peers":
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('referrals')
                        ->whereColumn('referrals.from_user_id', 'users.id')
                        ->where('created_at', '>', now()->subDays(7));
                });
                break;

            case "Inspire users to log a business deal":
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('business_deals')
                        ->whereColumn('business_deals.from_user_id', 'users.id')
                        ->where('created_at', '>', now()->subDays(30));
                });
                break;

            case "Prompt to give a testimonial":
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('testimonials')
                        ->whereColumn('testimonials.from_user_id', 'users.id')
                        ->where('created_at', '>', now()->subDays(14));
                });
                break;

            case "Inspire users to share their story":
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from((new LifeImpactHistory)->getTable())
                        ->whereColumn('user_id', 'users.id')
                        ->where('created_at', '>', now()->subDays(30));
                });
                break;

            case "Reward redemption nudge":
                $query->where('coins_balance', '>=', 100);
                break;

            case "Inspire users to apply for leadership":
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
                break;

            case "Streak/engagement reminder":
                $query->whereExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('user_login_histories')
                        ->whereColumn('user_login_histories.user_id', 'users.id')
                        ->where('logged_in_at', '>=', now()->subDays(5));
                });
                break;

            case "Re-engagement after prolonged inactivity":
                $query->whereBetween('last_login_at', [now()->subDays(10), now()->subDays(5)]);
                break;

            default:
                // All active users for the other 13 criteria
                break;
        }

        return $query;
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
