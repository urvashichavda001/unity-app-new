<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\Notifications\SendNotificationChannelJob;
use App\Models\Notifications\AppNotification;
use App\Models\Notifications\NotificationCampaign;
use App\Models\Notifications\NotificationCampaignRun;
use App\Models\Notifications\NotificationDeliveryLog;
use App\Models\User;
use App\Models\UserPushToken;
use App\Services\Firebase\FcmService as FirebaseFcmService;
use App\Services\Notifications\CampaignService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class NotificationAdminController extends Controller
{
    private const CHANNELS = ['push', 'email', 'push_email', 'in_app_only'];
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    public function dashboard(): View
    {
        $hasNotifications = Schema::hasTable('app_notifications');
        $hasCampaigns = Schema::hasTable('notification_campaigns');
        $hasPushTokens = Schema::hasTable('user_push_tokens');
        $hasDeliveryLogs = Schema::hasTable('notification_delivery_logs');

        $stats = [
            'total_notifications' => $hasNotifications ? AppNotification::count() : 0,
            'sent_notifications' => $hasNotifications ? AppNotification::where('status', 'sent')->count() : 0,
            'failed_notifications' => $hasNotifications ? AppNotification::where('status', 'failed')->count() : 0,
            'pending_notifications' => $hasNotifications ? AppNotification::where('status', 'pending')->count() : 0,
            'read_notifications' => $hasNotifications ? AppNotification::whereNotNull('read_at')->count() : 0,
            'clicked_notifications' => $hasNotifications ? AppNotification::whereNotNull('clicked_at')->count() : 0,
            'active_campaigns' => $hasCampaigns ? NotificationCampaign::where('is_active', true)->count() : 0,
            'inactive_campaigns' => $hasCampaigns ? NotificationCampaign::where('is_active', false)->count() : 0,
            'total_push_tokens' => $hasPushTokens ? UserPushToken::count() : 0,
            'active_push_tokens' => ($hasPushTokens && Schema::hasColumn('user_push_tokens', 'is_active')) ? UserPushToken::where('is_active', true)->count() : 0,
            'today_sent' => $hasNotifications ? AppNotification::whereDate('sent_at', today())->count() : 0,
            'today_failed' => $hasNotifications ? AppNotification::whereDate('failed_at', today())->count() : 0,
            'today_read' => $hasNotifications ? AppNotification::whereDate('read_at', today())->count() : 0,
            'today_clicked' => $hasNotifications ? AppNotification::whereDate('clicked_at', today())->count() : 0,
        ];

        $recentNotifications = $hasNotifications ? AppNotification::with('user')->latest()->limit(10)->get() : collect();
        $failedLogs = $hasDeliveryLogs ? NotificationDeliveryLog::with(['notification.user', 'user'])
            ->where('status', 'failed')
            ->latest()
            ->limit(10)
            ->get() : collect();

        return view('admin.notifications.dashboard', compact('stats', 'recentNotifications', 'failedLogs'));
    }

    public function campaigns(Request $request): View
    {
        if (! Schema::hasTable('notification_campaigns')) {
            return view('admin.notifications.campaigns.index', [
                'campaigns' => $this->emptyPaginator($request),
                'filters' => $this->campaignFilterOptions(collect()),
                'summary' => $this->emptyCampaignSummary(),
                'categoryTabs' => $this->campaignCategoryTabs(),
            ]);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', 'string', 'max:50'],
            'priority' => ['nullable', Rule::in(self::PRIORITIES)],
            'status' => ['nullable', Rule::in(['active', 'inactive', 'scheduled', 'draft'])],
            'frequency' => ['nullable', 'string', 'max:100'],
        ]);

        $query = NotificationCampaign::query()->withMax('runs as last_sent_at', 'finished_at');

        $query
            ->when(filled($validated['search'] ?? null), function (Builder $query) use ($validated): void {
                $search = '%' . trim((string) $validated['search']) . '%';
                $query->where(function (Builder $q) use ($search): void {
                    $q->where('name', 'ilike', $search)
                        ->orWhere('code', 'ilike', $search)
                        ->orWhere('title_template', 'ilike', $search)
                        ->orWhere('body_template', 'ilike', $search)
                        ->orWhere('audience_type', 'ilike', $search)
                        ->orWhere('trigger_type', 'ilike', $search);
                });
            })
            ->when(filled($validated['category'] ?? null), fn (Builder $q) => $q->where('category', $validated['category']))
            ->when(filled($validated['channel'] ?? null), fn (Builder $q) => $q->where('channel', $validated['channel']))
            ->when(filled($validated['priority'] ?? null), fn (Builder $q) => $q->where('priority', $validated['priority']))
            ->when(filled($validated['frequency'] ?? null), fn (Builder $q) => $q->where('frequency', $validated['frequency']))
            ->when(filled($validated['status'] ?? null), function (Builder $q) use ($validated): void {
                match ($validated['status']) {
                    'active' => $q->where('is_active', true),
                    'inactive', 'draft' => $q->where('is_active', false),
                    'scheduled' => $q->where('is_active', true)->whereNotNull('frequency')->where('frequency', '!=', 'immediate'),
                    default => null,
                };
            });

        $campaigns = $query->latest()->paginate(20)->withQueryString();
        $allCampaigns = NotificationCampaign::query()->get();

        return view('admin.notifications.campaigns.index', [
            'campaigns' => $campaigns,
            'filters' => $this->campaignFilterOptions($allCampaigns),
            'summary' => $this->campaignSummary($allCampaigns),
            'categoryTabs' => $this->campaignCategoryTabs(),
        ]);
    }


    private function campaignFilterOptions($campaigns): array
    {
        return [
            'categories' => $campaigns->pluck('category')->filter()->unique()->sort()->values(),
            'channels' => $campaigns->pluck('channel')->filter()->unique()->sort()->values(),
            'frequencies' => $campaigns->pluck('frequency')->filter()->unique()->sort()->values(),
            'priorities' => self::PRIORITIES,
        ];
    }

    private function campaignSummary($campaigns): array
    {
        return [
            'total' => $campaigns->count(),
            'active' => $campaigns->where('is_active', true)->count(),
            'scheduled' => $campaigns->filter(fn (NotificationCampaign $campaign): bool => $campaign->is_active && filled($campaign->frequency) && $campaign->frequency !== 'immediate')->count(),
            'immediate' => $campaigns->filter(fn (NotificationCampaign $campaign): bool => blank($campaign->frequency) || $campaign->frequency === 'immediate')->count(),
            'push' => $campaigns->filter(fn (NotificationCampaign $campaign): bool => in_array($campaign->channel, ['push', 'push_email', 'both'], true))->count(),
            'high_urgent' => $campaigns->whereIn('priority', ['high', 'urgent'])->count(),
        ];
    }

    private function emptyCampaignSummary(): array
    {
        return ['total' => 0, 'active' => 0, 'scheduled' => 0, 'immediate' => 0, 'push' => 0, 'high_urgent' => 0];
    }

    private function campaignCategoryTabs(): array
    {
        return [
            '' => 'All',
            'feed_social' => 'Feed & Social',
            'circle_community' => 'Circle & Community',
            'events' => 'Events',
            'p2p_meetings' => 'P2P Meetings',
            'business_referrals' => 'Business & Referrals',
            'wallet_coins' => 'Wallet & Coins',
            'retention' => 'Retention',
            'membership' => 'Membership',
            'gamification' => 'Gamification',
        ];
    }

    public function createCampaign(): View
    {
        return view('admin.notifications.campaigns.create', [
            'campaign' => new NotificationCampaign(['channel' => 'push', 'priority' => 'medium', 'is_active' => true, 'config' => []]),
            'screens' => $this->screens(),
        ]);
    }

    public function storeCampaign(Request $request): RedirectResponse
    {
        $campaign = NotificationCampaign::create($this->campaignData($request));

        return $request->input('action') === 'preview'
            ? redirect()->route('admin.notifications.campaigns.edit', $campaign->id)->with('success', 'Campaign saved. Use Preview to render sample content.')
            : redirect()->route('admin.notifications.campaigns')->with('success', 'Campaign created successfully.');
    }

    public function editCampaign(string $id): View
    {
        return view('admin.notifications.campaigns.edit', [
            'campaign' => NotificationCampaign::findOrFail($id),
            'screens' => $this->screens(),
        ]);
    }

    public function updateCampaign(Request $request, string $id): RedirectResponse
    {
        $campaign = NotificationCampaign::findOrFail($id);
        $campaign->update($this->campaignData($request, $campaign->id));

        return redirect()->route('admin.notifications.campaigns.edit', $campaign->id)->with('success', 'Campaign updated successfully.');
    }

    public function toggleCampaign(string $id): RedirectResponse
    {
        $campaign = NotificationCampaign::findOrFail($id);
        $campaign->update(['is_active' => ! $campaign->is_active]);

        return back()->with('success', 'Campaign status updated successfully.');
    }

    public function previewCampaign(Request $request, string $id): RedirectResponse
    {
        $campaign = NotificationCampaign::findOrFail($id);
        $placeholders = $request->validate([
            'person' => ['nullable', 'string'],
            'requirement_title' => ['nullable', 'string'],
            'event_title' => ['nullable', 'string'],
            'circle_name' => ['nullable', 'string'],
            'date' => ['nullable', 'string'],
            'amount' => ['nullable', 'string'],
            'x' => ['nullable', 'string'],
            'status' => ['nullable', 'string'],
            'badge_name' => ['nullable', 'string'],
        ]);

        return back()->with('preview', $this->renderPreview($campaign, $placeholders));
    }

    public function runCampaign(string $id, CampaignService $campaignService): RedirectResponse
    {
        $campaign = NotificationCampaign::findOrFail($id);

        try {
            $run = $campaignService->runCampaign($campaign);
            $run->update(['run_type' => 'manual']);
        } catch (Throwable $throwable) {
            report($throwable);
            NotificationCampaignRun::create([
                'campaign_id' => $campaign->id,
                'run_type' => 'manual',
                'status' => 'queued',
                'started_at' => now(),
                'meta' => ['queued_after_error' => $throwable->getMessage()],
            ]);
        }

        return back()->with('success', 'Campaign run started successfully.');
    }

    public function seedDefaults(): RedirectResponse
    {
        foreach ($this->defaultCampaigns() as $campaign) {
            NotificationCampaign::updateOrCreate(['code' => $campaign['code']], $campaign);
        }

        return back()->with('success', 'Default notification campaigns seeded successfully.');
    }


    public function sendTestForm(FirebaseFcmService $firebase): View
    {
        $recentNotifications = Schema::hasTable('app_notifications')
            ? AppNotification::with(['user', 'deliveryLogs'])->where('category', 'admin_test')->latest()->limit(15)->get()
            : collect();
        $firebaseDiagnostics = $firebase->diagnostics();

        return view('admin.notifications.send-test', compact('recentNotifications', 'firebaseDiagnostics'));
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $page = max((int) $request->query('page', 1), 1);
        $perPage = 20;
        $q = trim((string) $request->query('q', ''));
        $pushTokenFilter = (string) $request->query('push_token_status', 'all');
        $canCountPushTokens = Schema::hasTable('user_push_tokens') && Schema::hasColumn('user_push_tokens', 'is_active');
        $columns = collect(['display_name', 'first_name', 'last_name', 'name', 'email', 'phone', 'mobile'])
            ->filter(fn (string $column): bool => Schema::hasColumn('users', $column))
            ->values();

        $query = User::query()
            ->when($canCountPushTokens, fn (Builder $builder) => $builder->withCount([
                'pushTokens as active_push_tokens_count' => fn (Builder $tokenQuery) => $tokenQuery->where('is_active', true),
                'pushTokens as inactive_push_tokens_count' => fn (Builder $tokenQuery) => $tokenQuery->where('is_active', false),
            ]));

        if (Schema::hasColumn('users', 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if ($canCountPushTokens && in_array($pushTokenFilter, ['with', 'without'], true)) {
            $operator = $pushTokenFilter === 'with' ? '>=' : '=';
            $query->has('pushTokens', $operator, $pushTokenFilter === 'with' ? 1 : 0, 'and', fn (Builder $tokenQuery) => $tokenQuery->where('is_active', true));
        }

        if ($q !== '' && $columns->isNotEmpty()) {
            $needle = '%' . mb_strtolower($q) . '%';
            $query->where(function (Builder $builder) use ($columns, $needle): void {
                foreach ($columns as $column) {
                    $builder->orWhereRaw('LOWER(' . $column . ') LIKE ?', [$needle]);
                }
            });
        }

        foreach (['first_name', 'name', 'email'] as $orderColumn) {
            if (Schema::hasColumn('users', $orderColumn)) {
                $query->orderBy($orderColumn);
            }
        }

        $total = (clone $query)->count();
        $users = $query->forPage($page, $perPage)->get();

        return response()->json([
            'results' => $users->map(function (User $user): array {
                $displayName = trim((string) ($user->display_name ?? '')) ?: trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? '')));
                $displayName = $displayName !== '' ? $displayName : (string) ($user->name ?? $user->email ?? $user->phone ?? 'Unknown User');
                $phone = (string) ($user->phone ?? $user->mobile ?? '');
                return [
                    'id' => (string) $user->id,
                    'text' => $displayName,
                    'name' => $displayName,
                    'email' => (string) ($user->email ?? ''),
                    'phone' => $phone,
                    'active_push_tokens_count' => (int) ($user->active_push_tokens_count ?? $this->activePushTokenCount($user)),
                    'inactive_push_tokens_count' => (int) ($user->inactive_push_tokens_count ?? 0),
                ];
            })->values(),
            'pagination' => ['more' => ($page * $perPage) < $total],
        ]);
    }


    public function pushStatus(string $user): JsonResponse
    {
        $activeTokens = collect();
        $inactiveTokens = collect();

        if (Schema::hasTable('user_push_tokens')) {
            $query = UserPushToken::where('user_id', $user);
            if (Schema::hasColumn('user_push_tokens', 'is_active')) {
                $activeTokens = (clone $query)->where('is_active', true)->get();
                $inactiveTokens = (clone $query)->where('is_active', false)->get();
            } else {
                $activeTokens = $query->get();
            }
        }

        $latestToken = $activeTokens->sortByDesc(fn ($token) => $token->last_used_at ?? $token->updated_at ?? $token->created_at)->first()
            ?: $inactiveTokens->sortByDesc(fn ($token) => $token->last_used_at ?? $token->updated_at ?? $token->created_at)->first();

        $lastFailureReason = null;
        if (Schema::hasTable('notification_delivery_logs')) {
            $lastFailureReason = NotificationDeliveryLog::where('user_id', $user)
                ->where('channel', 'push')
                ->where('status', 'failed')
                ->latest()
                ->value('error_message');
        }

        return response()->json([
            'success' => true,
            'data' => [
                'active_tokens' => $activeTokens->count(),
                'inactive_tokens' => $inactiveTokens->count(),
                'can_send_push' => $activeTokens->isNotEmpty(),
                'latest_platform' => $latestToken?->platform,
                'latest_last_used_at' => optional($latestToken?->last_used_at ?? $latestToken?->updated_at)->toDateTimeString(),
                'last_failure_reason' => $lastFailureReason,
            ],
        ]);
    }

    public function sendTest(Request $request): RedirectResponse
    {
        try {
            $data = $this->testNotificationData($request);
        } catch (\Illuminate\Validation\ValidationException $exception) {
            if ($exception->validator->errors()->has('data')) {
                return back()->withInput()->with('error', 'Data JSON must be valid JSON.');
            }
            throw $exception;
        }

        if (! Schema::hasTable('app_notifications')) {
            return back()->withInput()->with('error', 'The app_notifications table is not available. Please run migrations.');
        }

        $payload = json_decode($data['data'] ?: '{}', true) ?: [];
        $channel = $data['channel'];
        $notification = AppNotification::create([
            'user_id' => $data['user_id'],
            'type' => $data['type'],
            'category' => $data['category'],
            'title' => $data['title'],
            'body' => $data['body'],
            'channel' => $channel,
            'priority' => $data['priority'],
            'reference_type' => $data['reference_type'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'screen' => $data['screen'],
            'data' => array_merge($payload, ['screen' => $data['screen'], 'reference_id' => $data['reference_id'] ?? null]),
            'status' => 'pending',
        ]);

        $results = ['push' => null, 'email' => null, 'in_app_only' => null];

        if ($channel === 'in_app_only') {
            $results['in_app_only'] = $this->recordDelivery($notification, 'in_app_only', true, null, 'database');
            $notification->update(['status' => 'sent', 'sent_at' => now(), 'failed_at' => null, 'failure_reason' => null]);
            return redirect()->route('admin.notifications.send-test')->with('success', 'In-app notification created successfully. The user will see it when the app calls the notification list API.');
        }

        if (in_array($channel, ['push', 'push_email'], true)) {
            $results['push'] = $this->sendPushForTest($notification);
        }

        if (in_array($channel, ['email', 'push_email'], true)) {
            $results['email'] = $this->sendEmailForTest($notification);
        }

        $this->updateNotificationStatusFromResults($notification, $channel, $results);

        if ($channel === 'push_email') {
            if (($results['push']['success'] ?? false) && ($results['email']['success'] ?? false)) {
                return redirect()->route('admin.notifications.send-test')->with('success', 'Push and email sent successfully.');
            }

            if (($results['email']['success'] ?? false) || ($results['push']['success'] ?? false)) {
                return redirect()->route('admin.notifications.send-test')->with('warning', $this->partialWarningMessage($results));
            }
        }

        if (($results[$channel]['success'] ?? false) || ($results['push']['success'] ?? false) || ($results['email']['success'] ?? false)) {
            return redirect()->route('admin.notifications.send-test')->with('success', 'Test notification sent successfully.');
        }

        $error = (string) (($results['push']['error'] ?? null) ?: ($results['email']['error'] ?? 'Unknown error.'));
        return redirect()->route('admin.notifications.send-test')->with('warning', $this->deliveryWarningMessage($error));
    }

    public function logs(Request $request): View
    {
        if (! Schema::hasTable('notification_delivery_logs')) {
            return view('admin.notifications.logs', [
                'logs' => $this->emptyPaginator($request),
                'campaigns' => Schema::hasTable('notification_campaigns') ? NotificationCampaign::orderBy('name')->get() : collect(),
                'summary' => ['total' => 0, 'sent' => 0, 'failed' => 0, 'pending' => 0, 'firebase' => 0, 'in_app' => 0],
            ]);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'channel' => ['nullable', 'string', 'max:50'],
            'provider' => ['nullable', 'string', 'max:100'],
            'user_search' => ['nullable', 'string', 'max:255'],
            'campaign_id' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'error_reason' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $base = NotificationDeliveryLog::query();
        $summary = [
            'total' => (clone $base)->count(),
            'sent' => (clone $base)->whereIn('status', ['sent', 'delivered', 'completed'])->count(),
            'failed' => (clone $base)->where('status', 'failed')->count(),
            'skipped' => (clone $base)->where('status', 'skipped')->count(),
            'pending' => (clone $base)->whereIn('status', ['pending', 'queued', 'running'])->count(),
            'firebase' => (clone $base)->where(function (Builder $q): void { $q->where('provider', 'firebase')->orWhere('channel', 'push'); })->count(),
            'in_app' => Schema::hasTable('app_notifications') ? AppNotification::count() : 0,
        ];

        $logs = NotificationDeliveryLog::with(['notification.user', 'notification.campaign', 'user', 'campaign'])
            ->when(filled($validated['status'] ?? null), fn (Builder $q) => $q->where('status', $validated['status']))
            ->when(filled($validated['channel'] ?? null), fn (Builder $q) => $q->where('channel', $validated['channel']))
            ->when(filled($validated['provider'] ?? null), fn (Builder $q) => $q->where('provider', $validated['provider']))
            ->when(filled($validated['campaign_id'] ?? null), fn (Builder $q) => $q->where(function (Builder $query) use ($validated): void { $query->where('campaign_id', $validated['campaign_id'])->orWhereHas('notification', fn (Builder $n) => $n->where('campaign_id', $validated['campaign_id'])); }))
            ->when(filled($validated['type'] ?? null), fn (Builder $q) => $q->whereHas('notification', fn (Builder $n) => $n->where('type', $validated['type'])))
            ->when(filled($validated['error_reason'] ?? null), fn (Builder $q) => $q->where('error_message', 'ilike', '%' . $validated['error_reason'] . '%'))
            ->when(filled($validated['user_search'] ?? null), function (Builder $q) use ($validated): void {
                $needle = '%' . trim((string) $validated['user_search']) . '%';
                $q->where(function (Builder $query) use ($validated, $needle): void {
                    $query->whereHas('notification.user', fn (Builder $u) => $this->applyUserSearch($u, (string) $validated['user_search']))
                        ->orWhereHas('user', fn (Builder $u) => $this->applyUserSearch($u, (string) $validated['user_search']))
                        ->orWhereHas('notification', fn (Builder $n) => $n->where('title', 'ilike', $needle)->orWhere('body', 'ilike', $needle)->orWhere('type', 'ilike', $needle))
                        ->orWhereHas('campaign', fn (Builder $c) => $c->where('name', 'ilike', $needle)->orWhere('code', 'ilike', $needle))
                        ->orWhere('provider_message_id', 'ilike', $needle);
                });
            })
            ->when(filled($validated['date_from'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '>=', $validated['date_from']))
            ->when(filled($validated['date_to'] ?? null), fn (Builder $q) => $q->whereDate('created_at', '<=', $validated['date_to']))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.notifications.logs', ['logs' => $logs, 'summary' => $summary, 'campaigns' => Schema::hasTable('notification_campaigns') ? NotificationCampaign::orderBy('name')->get() : collect()]);
    }

    public function pushTokens(Request $request): View
    {
        if (! Schema::hasTable('user_push_tokens')) {
            return view('admin.notifications.push-tokens', ['tokens' => $this->emptyPaginator($request), 'summary' => ['total' => 0, 'active' => 0, 'inactive' => 0, 'android' => 0, 'ios' => 0, 'recent' => 0]]);
        }

        $validated = $request->validate([
            'platform' => ['nullable', 'string', 'max:50'],
            'active' => ['nullable', Rule::in(['0', '1'])],
            'user_search' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:100'],
            'last_used_from' => ['nullable', 'date'],
        ]);
        $hasIsActive = Schema::hasColumn('user_push_tokens', 'is_active');
        $base = UserPushToken::query();
        $summary = [
            'total' => (clone $base)->count(),
            'active' => $hasIsActive ? (clone $base)->where('is_active', true)->count() : 0,
            'inactive' => $hasIsActive ? (clone $base)->where('is_active', false)->count() : 0,
            'android' => Schema::hasColumn('user_push_tokens', 'platform') ? (clone $base)->where('platform', 'android')->count() : 0,
            'ios' => Schema::hasColumn('user_push_tokens', 'platform') ? (clone $base)->where('platform', 'ios')->count() : 0,
            'recent' => Schema::hasColumn('user_push_tokens', 'last_used_at') ? (clone $base)->where('last_used_at', '>=', now()->subDays(7))->count() : 0,
        ];

        $tokens = UserPushToken::with('user')
            ->when(filled($validated['platform'] ?? null) && Schema::hasColumn('user_push_tokens', 'platform'), fn (Builder $q) => $q->where('platform', $validated['platform']))
            ->when(filled($validated['active'] ?? null) && $hasIsActive, fn (Builder $q) => $q->where('is_active', $validated['active'] === '1'))
            ->when(filled($validated['app_version'] ?? null) && Schema::hasColumn('user_push_tokens', 'app_version'), fn (Builder $q) => $q->where('app_version', 'ilike', '%' . $validated['app_version'] . '%'))
            ->when(filled($validated['last_used_from'] ?? null) && Schema::hasColumn('user_push_tokens', 'last_used_at'), fn (Builder $q) => $q->whereDate('last_used_at', '>=', $validated['last_used_from']))
            ->when(filled($validated['user_search'] ?? null), function (Builder $q) use ($validated): void {
                $needle = '%' . trim((string) $validated['user_search']) . '%';
                $q->where(fn (Builder $query) => $query->whereHas('user', fn (Builder $u) => $this->applyUserSearch($u, (string) $validated['user_search']))->orWhere('device_id', 'ilike', $needle)->orWhere('token', 'ilike', $needle)->orWhere('app_version', 'ilike', $needle));
            })
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.notifications.push-tokens', compact('tokens', 'summary'));
    }

    public function deactivatePushToken(string $id): RedirectResponse
    {
        if (Schema::hasColumn('user_push_tokens', 'is_active')) {
            UserPushToken::findOrFail($id)->update(['is_active' => false]);
        }

        return back()->with('success', 'Push token deactivated successfully.');
    }

    public function userNotifications(Request $request): View
    {
        if (! Schema::hasTable('app_notifications')) {
            return view('admin.notifications.user-notifications', ['notifications' => $this->emptyPaginator($request), 'summary' => ['total' => 0, 'unread' => 0, 'read' => 0, 'clicked' => 0, 'today' => 0, 'push_attempted' => 0, 'push_failed' => 0], 'campaigns' => collect()]);
        }

        $base = AppNotification::query();
        $summary = [
            'total' => (clone $base)->count(),
            'unread' => (clone $base)->whereNull('read_at')->count(),
            'read' => (clone $base)->whereNotNull('read_at')->count(),
            'clicked' => (clone $base)->whereNotNull('clicked_at')->count(),
            'today' => (clone $base)->whereDate('created_at', today())->count(),
            'push_attempted' => Schema::hasTable('notification_delivery_logs') ? NotificationDeliveryLog::where('channel', 'push')->count() : 0,
            'push_failed' => Schema::hasTable('notification_delivery_logs') ? NotificationDeliveryLog::where('channel', 'push')->where('status', 'failed')->count() : 0,
        ];

        $notifications = AppNotification::with(['user', 'campaign', 'deliveryLogs'])
            ->when($request->filled('search'), function (Builder $q) use ($request): void {
                $like = '%' . $request->string('search')->toString() . '%';
                $q->where(fn (Builder $query) => $query->where('title', 'ilike', $like)->orWhere('body', 'ilike', $like)->orWhere('type', 'ilike', $like)->orWhereHas('user', fn (Builder $u) => $this->applyUserSearch($u, $request->string('search')->toString()))->orWhereHas('campaign', fn (Builder $c) => $c->where('name', 'ilike', $like)->orWhere('code', 'ilike', $like)));
            })
            ->when($request->filled('campaign_id'), fn (Builder $q) => $q->where('campaign_id', $request->campaign_id))
            ->when($request->filled('type'), fn (Builder $q) => $q->where('type', $request->type))
            ->when($request->filled('category'), fn (Builder $q) => $q->where('category', $request->category))
            ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->status))
            ->when($request->filled('priority'), fn (Builder $q) => $q->where('priority', $request->priority))
            ->when($request->filled('read'), fn (Builder $q) => $request->read === 'read' ? $q->whereNotNull('read_at') : $q->whereNull('read_at'))
            ->when($request->filled('clicked'), fn (Builder $q) => $request->clicked === 'clicked' ? $q->whereNotNull('clicked_at') : $q->whereNull('clicked_at'))
            ->when($request->filled('user_search'), fn (Builder $q) => $q->whereHas('user', fn (Builder $u) => $this->applyUserSearch($u, $request->string('user_search')->toString())))
            ->when($request->filled('date_from'), fn (Builder $q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn (Builder $q) => $q->whereDate('created_at', '<=', $request->date_to))
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('admin.notifications.user-notifications', ['notifications' => $notifications, 'summary' => $summary, 'campaigns' => Schema::hasTable('notification_campaigns') ? NotificationCampaign::orderBy('name')->get() : collect()]);
    }

    public function markNotificationRead(string $id): RedirectResponse
    {
        if (Schema::hasTable('app_notifications')) {
            AppNotification::findOrFail($id)->update(['read_at' => now()]);
        }

        return back()->with('success', 'Notification marked as read.');
    }

    public function deleteNotification(string $id): RedirectResponse
    {
        if (Schema::hasTable('app_notifications')) {
            AppNotification::findOrFail($id)->delete();
        }

        return back()->with('success', 'Notification deleted successfully.');
    }

    public function clearUserNotifications(string $userId): RedirectResponse
    {
        if (Schema::hasTable('app_notifications')) {
            AppNotification::where('user_id', $userId)->delete();
        }

        return back()->with('success', 'User notifications cleared successfully.');
    }


    private function sendPushForTest(AppNotification $notification): array
    {
        if (! Schema::hasTable('user_push_tokens') || ! Schema::hasColumn('user_push_tokens', 'is_active')) {
            return $this->recordDelivery($notification, 'push', false, 'No valid Firebase device token found.', 'firebase');
        }

        $tokens = UserPushToken::query()
            ->where('user_id', $notification->user_id)
            ->where('is_active', true)
            ->whereNotNull('token')
            ->get();

        if ($tokens->isEmpty()) {
            return $this->recordDelivery($notification, 'push', false, 'No valid Firebase device token found.', 'firebase');
        }

        $firebase = app(FirebaseFcmService::class);
        if (method_exists($firebase, 'credentialsAvailable') && ! $firebase->credentialsAvailable()) {
            return $this->recordDelivery($notification, 'push', false, 'Firebase credentials file is not available.', 'firebase');
        }

        $sent = 0;
        $failed = 0;
        $lastError = null;

        foreach ($tokens as $token) {
            try {
                $result = $firebase->sendToDevice(
                    $token->token,
                    $notification->title,
                    $notification->body,
                    $notification->dataPayload(),
                    null,
                    1,
                    [
                        'user_id' => $notification->user_id,
                        'notification_type' => $notification->type,
                    ]
                );

                if (($result['success'] ?? false) === true) {
                    $sent++;

                    $this->recordDelivery(
                        $notification,
                        'push',
                        true,
                        null,
                        'firebase',
                        $result,
                        $result['firebase_response']['name'] ?? null
                    );

                    continue;
                }

                $error = (string) ($result['error'] ?? 'Push delivery failed.');
                $failed++;
                $lastError = $error;

                if (
                    str_contains(strtolower($error), 'invalid') ||
                    str_contains(strtolower($error), 'unregistered') ||
                    str_contains(strtolower($error), 'not registered')
                ) {
                    $token->update(['is_active' => false]);
                    $error = 'Invalid or unregistered Firebase device token.';
                    $lastError = $error;
                }

                $this->recordDelivery($notification, 'push', false, $error, 'firebase', $result);
            } catch (Throwable $throwable) {
                report($throwable);

                $failed++;
                $lastError = $throwable->getMessage();

                $this->recordDelivery($notification, 'push', false, $throwable->getMessage(), 'firebase');
            }
        }

        return [
            'success' => $sent > 0,
            'error' => $sent > 0 ? null : ($lastError ?: 'Push delivery failed.'),
            'sent_count' => $sent,
            'failed_count' => $failed,
        ];
    }

    private function sendEmailForTest(AppNotification $notification): array
    {
        try {
            Mail::raw($notification->body, fn ($message) => $message->to($notification->user?->email)->subject($notification->title));
            return $this->recordDelivery($notification, 'email', true, null, 'mail');
        } catch (Throwable $throwable) {
            report($throwable);
            return $this->recordDelivery($notification, 'email', false, $throwable->getMessage(), 'mail');
        }
    }

    private function recordDelivery(AppNotification $notification, string $channel, bool $success, ?string $error = null, ?string $provider = null, array $response = [], ?string $providerMessageId = null): array
    {
        if (Schema::hasTable('notification_delivery_logs')) {
            NotificationDeliveryLog::create([
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
                'campaign_id' => $notification->campaign_id,
                'channel' => $channel,
                'provider' => $provider,
                'provider_message_id' => $providerMessageId,
                'status' => $success ? 'sent' : 'failed',
                'request_payload' => $notification->dataPayload(),
                'response_payload' => $response,
                'error_message' => $error,
                'attempted_at' => now(),
                'delivered_at' => $success ? now() : null,
            ]);
        }

        return ['success' => $success, 'error' => $error];
    }

    private function activePushTokenCount(User $user): int
    {
        if (! Schema::hasTable('user_push_tokens') || ! Schema::hasColumn('user_push_tokens', 'is_active')) {
            return 0;
        }

        return UserPushToken::where('user_id', $user->id)->where('is_active', true)->count();
    }

    private function userSelectText(User $user): string
    {
        $displayName = trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? '')));
        if ($displayName === '') {
            $displayName = (string) ($user->name ?? 'Unknown User');
        }

        $parts = [$displayName];
        if (! empty($user->email)) {
            $parts[] = $user->email;
        }
        if (! empty($user->phone)) {
            $parts[] = $user->phone;
        } elseif (! empty($user->mobile)) {
            $parts[] = $user->mobile;
        }

        $tokenCount = $this->activePushTokenCount($user);
        $parts[] = $tokenCount > 0 ? 'Push ready' : 'No device token';

        return implode(' — ', array_filter($parts));
    }



    private function updateNotificationStatusFromResults(AppNotification $notification, string $channel, array $results): void
    {
        $pushSuccess = (bool) ($results['push']['success'] ?? false);
        $emailSuccess = (bool) ($results['email']['success'] ?? false);
        $pushError = (string) ($results['push']['error'] ?? '');
        $emailError = (string) ($results['email']['error'] ?? '');

        if ($channel === 'push_email') {
            if ($pushSuccess && $emailSuccess) {
                $notification->update(['status' => 'sent', 'sent_at' => now(), 'failed_at' => null, 'failure_reason' => null]);
                return;
            }

            if ($pushSuccess || $emailSuccess) {
                $reason = $pushSuccess ? 'Email failed: ' . ($emailError ?: 'Unknown error.') : 'Push failed: ' . ($pushError ?: 'Unknown error.');
                $notification->update(['status' => 'partial', 'sent_at' => now(), 'failed_at' => null, 'failure_reason' => $reason]);
                return;
            }

            $combined = trim('Push failed: ' . ($pushError ?: 'Unknown error.') . ' Email failed: ' . ($emailError ?: 'Unknown error.'));
            $notification->update(['status' => 'failed', 'sent_at' => null, 'failed_at' => now(), 'failure_reason' => $combined]);
            return;
        }

        $result = $channel === 'push' ? $results['push'] : $results['email'];
        if ($result['success'] ?? false) {
            $notification->update(['status' => 'sent', 'sent_at' => now(), 'failed_at' => null, 'failure_reason' => null]);
            return;
        }

        $notification->update([
            'status' => 'failed',
            'sent_at' => null,
            'failed_at' => now(),
            'failure_reason' => (string) ($result['error'] ?? 'Delivery failed.'),
        ]);
    }

    private function partialWarningMessage(array $results): string
    {
        if ($results['email']['success'] ?? false) {
            return 'Notification partially sent. Email sent, but push failed: ' . ($results['push']['error'] ?? 'Unknown error.');
        }

        return 'Notification partially sent. Push sent, but email failed: ' . ($results['email']['error'] ?? 'Unknown error.');
    }

    private function pushEmailWarningMessage(string $error): string
    {
        if (in_array($error, ['No active push token found.', 'No valid Firebase device token found.'], true)) {
            return 'Email sent, but push failed because selected user has no valid Firebase device token.';
        }

        if ($error === 'Invalid or unregistered Firebase device token.') {
            return 'Email sent, but push failed because the Firebase token was invalid and has been deactivated. Ask user to open the app again so Flutter can register a fresh token.';
        }

        return 'Notification saved. Email sent, but push failed: ' . $error;
    }

    private function deliveryWarningMessage(string $error): string
    {
        if (in_array($error, ['No active push token found.', 'No valid Firebase device token found.'], true)) {
            return 'Notification saved, but push failed because selected user has no valid Firebase device token.';
        }

        if ($error === 'Invalid or unregistered Firebase device token.') {
            return 'Notification saved, but push failed. Token was invalid and has been deactivated. Ask user to open the app again so Flutter can register a fresh token.';
        }

        return 'Notification saved, but delivery failed: ' . $error;
    }

    private function campaignData(Request $request, ?string $ignoreId = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:255', Rule::unique('notification_campaigns', 'code')->ignore($ignoreId)],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'channel' => ['required', Rule::in(['push', 'email', 'push_email'])],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'trigger_type' => ['required', 'string', 'max:255'],
            'frequency' => ['nullable', 'string', 'max:255'],
            'audience_type' => ['nullable', 'string', 'max:255'],
            'title_template' => ['required', 'string', 'max:255'],
            'body_template' => ['required', 'string'],
            'email_subject_template' => ['nullable', 'string', 'max:255'],
            'email_body_template' => ['nullable', 'string'],
            'tap_screen' => ['nullable', 'string', 'max:255'],
            'daily_limit' => ['nullable', 'integer', 'min:0'],
            'cooldown_hours' => ['nullable', 'integer', 'min:0'],
            'stop_rule' => ['nullable', 'string'],
            'config' => ['nullable', 'json'],
        ]);

        $data['is_active'] = $request->boolean('is_active');
        $data['config'] = json_decode($data['config'] ?: '{}', true) ?: [];
        // notification_campaigns.created_by_user_id references app users, while this Blade module is authenticated by the admin guard.
        // Keep it null to avoid cross-guard foreign-key violations.
        $data['created_by_user_id'] = null;

        return $data;
    }

    private function testNotificationData(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'channel' => ['required', Rule::in(self::CHANNELS)],
            'type' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'priority' => ['required', Rule::in(self::PRIORITIES)],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'screen' => ['required', 'string', 'max:255'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'uuid'],
            'data' => ['nullable', 'json'],
        ]);
    }

    private function applyUserSearch(Builder $query, string $search): void
    {
        $columns = collect(['display_name', 'first_name', 'last_name', 'name', 'email', 'phone', 'mobile'])
            ->filter(fn (string $column): bool => Schema::hasColumn('users', $column));

        if ($columns->isEmpty()) {
            return;
        }

        $like = '%' . mb_strtolower($search) . '%';
        $query->where(function (Builder $builder) use ($columns, $like): void {
            foreach ($columns as $column) {
                $builder->orWhereRaw('LOWER(' . $column . ') LIKE ?', [$like]);
            }
        });
    }

    private function renderPreview(NotificationCampaign $campaign, array $placeholders): array
    {
        $map = [
            '<person>' => $placeholders['person'] ?? 'Rajesh Kumar',
            '<date>' => $placeholders['date'] ?? now()->format('d M Y'),
            '[Requirement Title]' => $placeholders['requirement_title'] ?? 'Website Development',
            '[Event Title]' => $placeholders['event_title'] ?? 'Unity Networking Meet',
            '[Circle Name]' => $placeholders['circle_name'] ?? 'Greenpreneur Circle',
            '[Status]' => $placeholders['status'] ?? 'Approved',
            '[Amount]' => $placeholders['amount'] ?? '₹10,000',
            '[X]' => $placeholders['x'] ?? '3',
            '[Badge Name]' => $placeholders['badge_name'] ?? 'Connector',
        ];

        return [
            'push_title' => strtr($campaign->title_template, $map),
            'push_body' => strtr($campaign->body_template, $map),
            'email_subject' => strtr((string) $campaign->email_subject_template, $map),
            'email_body' => strtr((string) $campaign->email_body_template, $map),
            'tap_screen' => $campaign->tap_screen,
        ];
    }

    private function screens(): array
    {
        return ['home','feed','post_details','member_profile','private_chat','chat_details','circle_chat','event_details','live_meeting','event_feedback','circle_join_requests','circle_details','circular_details','announcement_details','p2p_meetings','p2p_outcome_form','business_deals_history','referrals_history','testimonials','write_testimonial','visitor_history','membership_application','requirement_details','suggested_connections','coins_wallet','leaderboard','performance','subscription_plans','renew_subscription','badges'];
    }


    private function emptyPaginator(Request $request): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 30, LengthAwarePaginator::resolveCurrentPage(), [
            'path' => $request->url(),
            'query' => $request->query(),
        ]);
    }

    private function defaultCampaigns(): array
    {
        $rows = [
            ['requirement_lead', 'New requirement / lead available', 'New requirement / lead available', 'requirement_match', 'daily', 'matching_requirements', 'Potential Business Match Found!', '<person> is looking for: "[Requirement Title]"', 'requirement_details'],
            ['pending_requirement_reminder', 'Pending requirement reminder', 'New requirement / lead available', 'requirement_match', 'hourly', 'matching_requirements', 'Reminder: respond to pending requirements', 'You have [X] pending requirement matches.', 'requirement_details'],
            ['new_post_activity_circle', 'New Post / Activity In Circle', 'feed_social', 'post_or_circle_activity', 'real_time_or_digest', 'circle_members_and_connections', 'New post by <person>', '[Post Preview Content]...', 'post_details'],
            ['post_like_received', 'Post Like Received', 'feed_social', 'post_liked', 'immediate', 'post_owner', '<person> liked your post', '<person> liked your post: "[Post Preview Content]"', 'post_details'],
            ['post_comment_received', 'Post Comment Received', 'feed_social', 'post_commented', 'immediate', 'post_owner', '<person> commented on your post', '"[Comment Preview Content]"', 'post_details'],
            ['user_mention_notification', 'User Mention Notification', 'feed_social', 'user_mentioned', 'immediate', 'mentioned_user', '<person> mentioned you!', '<person> mentioned you in a post: "[Post Preview Content]"', 'post_details'],
            ['share_post_alert', 'Share Post Alert', 'feed_social', 'post_shared', 'immediate', 'post_owner', 'Your post was shared!', '<person> shared your post: "[Post Preview Content]"', 'post_details'],
            ['circle_activity', 'New post / activity in circle', 'New post / activity in circle', 'new_post', 'daily', 'same_circle', 'New activity in [Circle Name]', '<person> shared an update in [Circle Name].', 'feed'],
            ['people_to_connect', 'People to connect with', 'People to connect with', 'inactive_connection_nudge', 'daily', 'mutual_connections', 'People you should connect with', 'Meet [X] relevant peers this week.', 'suggested_connections'],
            ['upcoming_event_reminder', 'Upcoming event reminder', 'Upcoming event reminder', 'new_event_announcement', 'every-five-minutes', 'event_attendees', '[Event Title] is coming up', 'Your event starts on <date>.', 'event_details'],
            ['event_starting_now', 'Event starting now', 'Upcoming event reminder', 'event_live_reminder', 'every-five-minutes', 'event_attendees', '[Event Title] is live now', 'Tap to join the live meeting.', 'live_meeting'],
            ['post_event_feedback', 'Post-event feedback request', 'Upcoming event reminder', 'post_event_feedback', 'every-five-minutes', 'event_attendees', 'Share feedback for [Event Title]', 'How was your event experience?', 'event_feedback'],
            ['unclaimed_coins', 'Unclaimed coins reminder', 'Unclaimed coins / reward reminder', 'unclaimed_coins', 'hourly', 'unclaimed_coins', 'You have unclaimed coins', 'Claim [X] coins in your wallet.', 'coins_wallet'],
            ['referral_testimonial_reward', 'Referral / testimonial reward reminder', 'Unclaimed coins / reward reminder', 'testimonial_request_after_deal', 'daily', 'all_members', 'Earn rewards with referrals', 'Complete referral/testimonial actions to unlock rewards.', 'referrals_history'],
            ['weekly_digest', 'Weekly activity digest', 'People to connect with', 'weekly_digest', 'weekly', 'all_members', 'Your Unity weekly digest', 'Here are your top updates from this week.', 'performance'],
        ];

        return collect($rows)->map(fn (array $row): array => [
            'code' => $row[0], 'name' => $row[1], 'category' => $row[2], 'channel' => 'push', 'trigger_type' => $row[3],
            'frequency' => $row[4], 'priority' => $row[0] === 'event_starting_now' ? 'urgent' : 'medium', 'audience_type' => $row[5],
            'title_template' => $row[6], 'body_template' => $row[7], 'tap_screen' => $row[8], 'is_active' => true,
            'daily_limit' => 3, 'cooldown_hours' => 24, 'config' => [],
        ])->all();
    }
}
