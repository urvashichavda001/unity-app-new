<?php

namespace App\Services;

use App\Models\BusinessDeal;
use App\Models\LifeImpactHistory;
use App\Models\P2pMeeting;
use App\Models\Referral;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PeerMonthlyImpactScriptService
{
    private const CHECKLIST_DEFINITIONS = [
        'qualified_referrals_given' => 'Qualified referrals given',
        'mentoring_given' => 'Mentoring given',
        'collaboration_connections' => 'Collaboration connections',
        'knowledge_shared' => 'Knowledge shared',
        'business_challenge_help' => 'Business challenge help',
        'vendor_or_service_help' => 'Vendor or service help',
        'funding_or_capital_help' => 'Funding or capital help',
        'media_or_recognition_help' => 'Media or recognition help',
        'personal_or_business_support' => 'Personal or business support',
        'helped_get_things_done' => 'Helped get things done',
    ];

    private const REFERRAL_ACTION_ALIASES = [
        'qualified_referrals_given',
        'qualified_referral',
        'referral',
        'referrals',
        'pass_referral',
        'passed_referral',
        'gave_referral',
        'vendor_referrals',
    ];

    private const HISTORY_ACTION_ALIASES = [
        'mentoring_given' => ['mentoring_given', 'mentor', 'mentoring', 'mentorship', 'guided_peer', 'coaching'],
        'collaboration_connections' => ['collaboration_connections', 'collaboration_connection', 'collaboration', 'p2p_meeting', 'p2p', 'connection'],
        'knowledge_shared' => ['knowledge_shared', 'knowledge_share', 'knowledge', 'shared_knowledge', 'education', 'training'],
        'business_challenge_help' => ['business_challenge_help', 'business_challenge', 'challenge_help', 'problem_solving'],
        'vendor_or_service_help' => ['vendor_or_service_help', 'vendor_help', 'service_help', 'vendor', 'service_provider'],
        'funding_or_capital_help' => ['funding_or_capital_help', 'funding_help', 'capital_help', 'funding', 'capital'],
        'media_or_recognition_help' => ['media_or_recognition_help', 'media_help', 'recognition_help', 'media', 'recognition'],
        'personal_or_business_support' => ['personal_or_business_support', 'personal_support', 'business_support', 'support'],
        'helped_get_things_done' => ['helped_get_things_done', 'execution_help', 'got_things_done', 'things_done'],
    ];

    public function buildForUser(User $user): array
    {
        $now = Carbon::now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd = $now->copy()->endOfMonth();

        $userId = (string) $user->id;

        Log::info('peer_monthly_script.build_started', [
            'user_id' => $userId,
            'month_start' => $monthStart->toDateString(),
            'month_end' => $monthEnd->toDateString(),
        ]);
        $monthlyLivesImpacted = $this->totalLivesImpacted($userId, $monthStart, $monthEnd);
        $lifetimeLivesImpacted = $this->lifetimeLivesImpacted($user, $userId);
        $businessDeals = $this->businessDealsThisMonth($userId, $monthStart, $monthEnd);
        $checklistItems = $this->checklistItems($userId, $monthStart, $monthEnd);

        Log::info('peer_monthly_script.final_checklist_counts', [
            'user_id' => $userId,
            'counts' => collect($checklistItems)->mapWithKeys(fn (array $item): array => [
                (string) $item['key'] => (int) $item['count'],
            ])->all(),
        ]);

        $userInfo = $this->userInfo($user);
        $summary = [
            'total_lives_impacted_this_month' => $monthlyLivesImpacted,
            'total_business_done_with_peers_this_month' => $businessDeals['total_amount'],
            'lifetime_total_lives_impacted' => $lifetimeLivesImpacted,
            'lifetime_total_business_done_with_peers' => $this->lifetimeBusinessDoneWithPeers($userId),
        ];

        return [
            'user' => $userInfo,
            'period' => [
                'month' => $now->format('F'),
                'year' => (int) $now->year,
                'month_start_date' => $monthStart->toDateString(),
                'month_end_date' => $monthEnd->toDateString(),
                'total_lives_impacted_this_month' => $monthlyLivesImpacted,
                'total_business_done_with_peers_this_month' => $businessDeals['total_amount'],
            ],
            'summary' => $summary,
            'checklist_items' => $checklistItems,
            'business_deals_this_month' => $businessDeals,
            'form_fields' => [
                'meaningful_progress_this_month' => null,
                'goal_for_next_month' => null,
                'experience_or_story_optional' => null,
            ],
            'script' => $this->script($userInfo, $summary, $businessDeals, $checklistItems),
        ];
    }

    private function userInfo(User $user): array
    {
        $displayName = $this->displayName($user);
        $companyName = $this->stringOrNull($user->company_name ?? null);
        $industryTags = $this->normalizeArray($user->industry_tags ?? []);
        $category = $this->stringOrNull($user->getAttribute('category'))
            ?? $this->stringOrNull($user->business_type ?? null)
            ?? ($industryTags[0] ?? null)
            ?? 'your category';

        return [
            'user_id' => (string) $user->id,
            'display_name' => $displayName,
            'first_name' => $this->stringOrNull($user->first_name ?? null),
            'last_name' => $this->stringOrNull($user->last_name ?? null),
            'business_name' => $companyName,
            'company_name' => $companyName,
            'category' => $category,
            'business_type' => $this->stringOrNull($user->business_type ?? null),
            'industry_tags' => $industryTags,
            'profile_photo_url' => $this->profilePhotoUrl($user),
        ];
    }

    private function totalLivesImpacted(string $userId, Carbon $start, Carbon $end): int
    {
        if (Schema::hasTable('life_impact_histories')) {
            $table = (new LifeImpactHistory())->getTable();
            $query = DB::table($table)->where('user_id', $userId);
            $this->applyHistoryCountableFilters($query, $table);
            $this->applyDateRange($query, $table, ['created_at'], $start, $end);

            return (int) $query->sum(DB::raw($this->lifeImpactSumExpression($table)));
        }

        if (! Schema::hasTable('impacts')) {
            return 0;
        }

        $query = DB::table('impacts')->where('user_id', $userId);
        if (Schema::hasColumn('impacts', 'status')) {
            $query->where('status', 'approved');
        }
        $this->applyDateRange($query, 'impacts', ['impact_date', 'approved_at', 'created_at'], $start, $end);

        return (int) $query->sum(DB::raw(Schema::hasColumn('impacts', 'life_impacted') ? 'COALESCE(life_impacted, 1)' : '1'));
    }

    private function lifetimeLivesImpacted(User $user, string $userId): int
    {
        $existingTotal = (int) ($user->life_impacted_count ?? 0);
        if ($existingTotal > 0) {
            return $existingTotal;
        }

        if (! Schema::hasTable('life_impact_histories')) {
            return 0;
        }

        $table = (new LifeImpactHistory())->getTable();
        $query = DB::table($table)->where('user_id', $userId);
        $this->applyHistoryCountableFilters($query, $table);

        return (int) $query->sum(DB::raw($this->lifeImpactSumExpression($table)));
    }

    private function businessDealsThisMonth(string $userId, Carbon $start, Carbon $end): array
    {
        if (! Schema::hasTable('business_deals')) {
            return [
                'total_amount' => 0.0,
                'deals_count' => 0,
                'peers' => [],
            ];
        }

        $query = $this->businessDealBaseQuery($userId);
        $this->applyDateRange($query, 'business_deals', ['deal_date', 'created_at'], $start, $end);

        $deals = $query
            ->with([
                'fromUser:id,display_name,first_name,last_name,company_name',
                'toUser:id,display_name,first_name,last_name,company_name',
            ])
            ->orderByDesc('deal_date')
            ->orderByDesc('created_at')
            ->get();

        Log::info('peer_monthly_script.business_deals_found', [
            'user_id' => $userId,
            'count' => $deals->count(),
            'total_amount' => round((float) $deals->sum(fn (BusinessDeal $deal): float => (float) ($deal->deal_amount ?? 0)), 2),
        ]);

        $peers = $deals->map(fn (BusinessDeal $deal): array => $this->businessDealPeer($deal, $userId))->values()->all();

        return [
            'total_amount' => round((float) $deals->sum(fn (BusinessDeal $deal): float => (float) ($deal->deal_amount ?? 0)), 2),
            'deals_count' => count($peers),
            'peers' => $peers,
        ];
    }

    private function lifetimeBusinessDoneWithPeers(string $userId): float
    {
        if (! Schema::hasTable('business_deals')) {
            return 0.0;
        }

        $query = $this->businessDealBaseQuery($userId)->toBase();

        return round((float) $query->sum(DB::raw('COALESCE(deal_amount, 0)')), 2);
    }

    private function checklistItems(string $userId, Carbon $start, Carbon $end): array
    {
        $items = collect(self::CHECKLIST_DEFINITIONS)
            ->map(fn (string $label, string $key): array => $this->emptyChecklistItem($key, $label))
            ->all();

        $items['qualified_referrals_given'] = $this->referralChecklistItem($userId, $start, $end);
        $items['collaboration_connections'] = $this->collaborationChecklistItem($userId, $start, $end, $items['collaboration_connections']);
        $items = $this->mergeHistoryChecklistItems($items, $userId, $start, $end);
        $items = $this->mergeActivityChecklistItems($items, $userId, $start, $end);

        return array_values($items);
    }

    private function referralChecklistItem(string $userId, Carbon $start, Carbon $end): array
    {
        $label = self::CHECKLIST_DEFINITIONS['qualified_referrals_given'];
        $relatedItems = collect();

        $referralItems = $this->safeChecklistSource(
            'referrals',
            $userId,
            fn (): Collection => $this->referralTableRelatedItems($userId, $start, $end)
        );
        $relatedItems = $relatedItems->merge($referralItems);

        $historyItems = $this->safeChecklistSource(
            'life_impact_histories',
            $userId,
            fn (): Collection => $this->referralHistoryRelatedItems($userId, $start, $end)
        );
        $relatedItems = $relatedItems->merge($historyItems);

        $activityItems = $this->safeChecklistSource(
            'activities',
            $userId,
            fn (): Collection => $this->referralActivityRelatedItems($userId, $start, $end)
        );
        $relatedItems = $relatedItems->merge($activityItems);

        return $this->checklistItem(
            'qualified_referrals_given',
            $label,
            $this->uniqueRelatedItems($relatedItems)->values()->all()
        );
    }

    private function collaborationChecklistItem(string $userId, Carbon $start, Carbon $end, array $default): array
    {
        if (! Schema::hasTable('p2p_meetings')) {
            return $default;
        }

        $query = P2pMeeting::query()
            ->with([
                'initiator:id,display_name,first_name,last_name,company_name',
                'peer:id,display_name,first_name,last_name,company_name',
            ])
            ->where('initiator_user_id', $userId);
        $this->applySoftDeleteFilters($query, 'p2p_meetings');
        $this->applyDateRange($query, 'p2p_meetings', ['meeting_date', 'created_at'], $start, $end);

        $meetings = $query->orderByDesc('meeting_date')->orderByDesc('created_at')->get();
        if ($meetings->isEmpty()) {
            return $default;
        }

        $relatedItems = $meetings
            ->map(fn (P2pMeeting $meeting): array => $this->collaborationMeetingRelatedItem($meeting))
            ->values()
            ->all();

        return $this->checklistItem('collaboration_connections', self::CHECKLIST_DEFINITIONS['collaboration_connections'], $relatedItems);
    }

    private function mergeHistoryChecklistItems(array $items, string $userId, Carbon $start, Carbon $end): array
    {
        try {
            if (! Schema::hasTable('life_impact_histories')) {
                return $items;
            }

            $table = (new LifeImpactHistory())->getTable();
            $query = LifeImpactHistory::query()->where('user_id', $userId);
            $this->applyHistoryCountableFilters($query, $table);
            $this->applyDateRange($query, $table, ['created_at'], $start, $end);

            $histories = $query->orderByDesc('created_at')->get();
            $relatedUsers = $this->historyRelatedUsers($histories);

            Log::info('peer_monthly_script.impact_history_source_count', [
                'user_id' => $userId,
                'source' => 'life_impact_histories',
                'count' => $histories->count(),
            ]);

            foreach (self::HISTORY_ACTION_ALIASES as $checklistKey => $aliases) {
                $matches = $histories->filter(fn (LifeImpactHistory $history): bool => $this->historyMatches($history, $aliases));
                if ($matches->isEmpty()) {
                    continue;
                }

                $existingRelatedItems = collect($items[$checklistKey]['related_items'] ?? []);
                $historyRelatedItems = $matches
                    ->map(fn (LifeImpactHistory $history): array => $this->historyRelatedItem($checklistKey, $history, $relatedUsers))
                    ->values();

                $items[$checklistKey] = $this->checklistItem(
                    $checklistKey,
                    self::CHECKLIST_DEFINITIONS[$checklistKey],
                    $existingRelatedItems->merge($historyRelatedItems)->values()->all()
                );
            }
        } catch (\Throwable $e) {
            Log::warning('peer_monthly_script.impact_history_source_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    private function referralTableRelatedItems(string $userId, Carbon $start, Carbon $end): Collection
    {
        if (! Schema::hasTable('referrals')) {
            return collect();
        }

        $actorColumn = $this->firstExistingColumn('referrals', [
            'from_user_id',
            'user_id',
            'referrer_user_id',
            'given_by_user_id',
            'created_by',
            'created_by_user_id',
        ]);

        if ($actorColumn === null) {
            return collect();
        }

        $query = DB::table('referrals')->where($actorColumn, $userId);
        $this->applySoftDeleteFilters($query, 'referrals');
        $this->applyStatusFilter($query, 'referrals');
        $this->applyDateRange($query, 'referrals', ['referral_date', 'date', 'created_at'], $start, $end);

        $rows = $query
            ->orderByDesc($this->firstExistingColumn('referrals', ['referral_date', 'date', 'created_at']) ?? 'id')
            ->get();

        $peerIdColumn = $this->firstExistingColumn('referrals', [
            'to_user_id',
            'peer_user_id',
            'referred_user_id',
            'related_user_id',
            'impacted_peer_id',
            'member_user_id',
        ]);
        $peerIds = $rows
            ->map(fn ($row): ?string => $peerIdColumn ? $this->stringOrNull($row->{$peerIdColumn} ?? null) : null)
            ->filter()
            ->unique()
            ->values();
        $peers = $this->usersByIds($peerIds);

        return $rows->map(fn ($row): array => $this->referralRowRelatedItem($row, $peerIdColumn, $peers));
    }

    private function referralHistoryRelatedItems(string $userId, Carbon $start, Carbon $end): Collection
    {
        if (! Schema::hasTable('life_impact_histories')) {
            return collect();
        }

        $table = (new LifeImpactHistory())->getTable();
        $query = LifeImpactHistory::query()->where('user_id', $userId);
        $this->applyHistoryCountableFilters($query, $table);
        $this->applyDateRange($query, $table, ['created_at'], $start, $end);

        $histories = $query->orderByDesc('created_at')->get()
            ->filter(fn (LifeImpactHistory $history): bool => $this->historyMatches($history, self::REFERRAL_ACTION_ALIASES));
        $relatedUsers = $this->historyRelatedUsers($histories);

        return $histories
            ->map(fn (LifeImpactHistory $history): array => $this->referralHistoryRelatedItem($history, $relatedUsers))
            ->values();
    }

    private function referralActivityRelatedItems(string $userId, Carbon $start, Carbon $end): Collection
    {
        return $this->activityRelatedItemsForKey('qualified_referrals_given', self::REFERRAL_ACTION_ALIASES, $userId, $start, $end);
    }

    private function mergeActivityChecklistItems(array $items, string $userId, Carbon $start, Carbon $end): array
    {
        try {
            if (! Schema::hasTable('activities')) {
                Log::info('peer_monthly_script.activity_source_count', [
                    'user_id' => $userId,
                    'source' => 'activities',
                    'count' => 0,
                    'reason' => 'missing_table',
                ]);

                return $items;
            }

            $totalActivityMatches = 0;

            foreach (self::HISTORY_ACTION_ALIASES as $checklistKey => $aliases) {
                $activityItems = $this->activityRelatedItemsForKey($checklistKey, $aliases, $userId, $start, $end);
                $totalActivityMatches += $activityItems->count();

                if ($activityItems->isEmpty()) {
                    continue;
                }

                $existingRelatedItems = collect($items[$checklistKey]['related_items'] ?? []);
                $items[$checklistKey] = $this->checklistItem(
                    $checklistKey,
                    self::CHECKLIST_DEFINITIONS[$checklistKey],
                    $this->uniqueRelatedItems($existingRelatedItems->merge($activityItems))->values()->all()
                );
            }

            Log::info('peer_monthly_script.activity_source_count', [
                'user_id' => $userId,
                'source' => 'activities',
                'count' => $totalActivityMatches,
            ]);
        } catch (\Throwable $e) {
            Log::warning('peer_monthly_script.activity_source_failed', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    private function activityRelatedItemsForKey(string $checklistKey, array $aliases, string $userId, Carbon $start, Carbon $end): Collection
    {
        if (! Schema::hasTable('activities')) {
            return collect();
        }

        $actorColumn = $this->firstExistingColumn('activities', ['user_id', 'created_by', 'created_by_user_id']);
        if ($actorColumn === null) {
            return collect();
        }

        $query = DB::table('activities')->where($actorColumn, $userId);
        $this->applyStatusFilter($query, 'activities');
        $this->applyDateRange($query, 'activities', ['activity_date', 'verified_at', 'created_at'], $start, $end);

        $rows = $query->orderByDesc($this->firstExistingColumn('activities', ['activity_date', 'verified_at', 'created_at']) ?? 'id')->get();
        $matches = $rows->filter(fn ($row): bool => $this->activityRowMatchesAliases($row, $aliases));

        if ($matches->isEmpty()) {
            return collect();
        }

        $relatedUserColumn = $this->firstExistingColumn('activities', ['related_user_id', 'to_user_id', 'peer_user_id']);
        $peerIds = $matches
            ->map(fn ($row): ?string => $relatedUserColumn ? $this->stringOrNull($row->{$relatedUserColumn} ?? null) : null)
            ->filter()
            ->unique()
            ->values();
        $peers = $this->usersByIds($peerIds);

        return $matches
            ->map(fn ($row): array => $this->activityRowRelatedItem($checklistKey, $row, $relatedUserColumn, $peers))
            ->values();
    }

    private function referralRowRelatedItem(object $row, ?string $peerIdColumn, Collection $peers): array
    {
        $peerId = $peerIdColumn ? $this->stringOrNull($row->{$peerIdColumn} ?? null) : null;
        $peer = $peerId ? $peers->get($peerId) : null;
        $peerName = $this->firstFilled([
            $row->peer_name ?? null,
            $row->to_user_name ?? null,
            $row->referred_user_name ?? null,
            $this->displayName($peer),
            'Peer',
        ]);
        $connectedWithName = $this->firstFilled([
            $row->referral_of ?? null,
            $row->connected_with_name ?? null,
            $row->connected_with_business_name ?? null,
            $row->business_name ?? null,
            $row->company_name ?? null,
            $row->remarks ?? null,
            'a business opportunity',
        ]);
        $date = $this->formatDate($row->referral_date ?? $row->date ?? $row->created_at ?? null);

        return [
            'source' => 'referrals',
            'id' => (string) ($row->id ?? ''),
            'date' => $date,
            'peer_id' => $peerId,
            'peer_name' => $peerName,
            'peer_company_name' => $this->stringOrNull($peer?->company_name),
            'connected_with_name' => $connectedWithName,
            'connected_with_business_name' => $connectedWithName,
            'display_text' => "I gave a qualified referral to {$peerName} — connecting them with {$connectedWithName}",
        ];
    }

    private function referralHistoryRelatedItem(LifeImpactHistory $history, Collection $relatedUsers): array
    {
        $meta = $this->historyMeta($history);
        $peerId = $this->historyRelatedUserId($meta, ['peer_id', 'to_user_id', 'affected_user_id', 'referred_user_id']);
        $peer = $peerId ? $relatedUsers->get($peerId) : null;
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name', 'referred_user_name'], ['peer_id', 'to_user_id', 'affected_user_id', 'referred_user_id']) ?? 'Peer';
        $connectedWithName = $this->firstFilled([
            $meta['connected_with_name'] ?? null,
            $meta['connected_with_business_name'] ?? null,
            $meta['referral_of'] ?? null,
            $meta['business_name'] ?? null,
            $history->description ?? null,
            $history->title ?? null,
            'a business opportunity',
        ]);

        return [
            'source' => 'life_impact_histories',
            'id' => (string) $history->id,
            'date' => $this->formatDate($history->created_at ?? null),
            'peer_id' => $peerId,
            'peer_name' => $peerName,
            'peer_company_name' => $this->stringOrNull($peer?->company_name),
            'connected_with_name' => $connectedWithName,
            'connected_with_business_name' => $connectedWithName,
            'display_text' => "I gave a qualified referral to {$peerName} — connecting them with {$connectedWithName}",
        ];
    }

    private function activityRowRelatedItem(string $checklistKey, object $row, ?string $relatedUserColumn, Collection $peers): array
    {
        $peerId = $relatedUserColumn ? $this->stringOrNull($row->{$relatedUserColumn} ?? null) : null;
        $peer = $peerId ? $peers->get($peerId) : null;
        $peerName = $this->displayName($peer) ?? 'Peer';
        $description = $this->firstFilled([$row->description ?? null, $row->type ?? null, 'a Peers activity']);
        $base = [
            'source' => 'activities',
            'id' => (string) ($row->id ?? ''),
            'date' => $this->formatDate($row->activity_date ?? $row->verified_at ?? $row->created_at ?? null),
        ];

        if ($checklistKey === 'qualified_referrals_given') {
            return $base + [
                'peer_id' => $peerId,
                'peer_name' => $peerName,
                'peer_company_name' => $this->stringOrNull($peer?->company_name),
                'connected_with_name' => $description,
                'connected_with_business_name' => $description,
                'display_text' => "I gave a qualified referral to {$peerName} — connecting them with {$description}",
            ];
        }

        return $base + $this->activityDetailsForChecklistKey($checklistKey, $peerName, $description);
    }

    private function activityDetailsForChecklistKey(string $checklistKey, string $peerName, string $description): array
    {
        return match ($checklistKey) {
            'mentoring_given' => [
                'peer_name' => $peerName,
                'subject_or_area' => $description,
                'display_text' => "I mentored {$peerName} with guidance on {$description}",
            ],
            'collaboration_connections' => [
                'peer_one_name' => 'Me',
                'peer_two_name' => $peerName,
                'area' => $description,
                'display_text' => "I connected Me and {$peerName} for a collaboration opportunity in {$description}",
            ],
            'knowledge_shared' => [
                'peer_name' => $peerName,
                'subject' => $description,
                'display_text' => "I shared knowledge with {$peerName} on the topic of {$description}",
            ],
            'business_challenge_help' => [
                'peer_name' => $peerName,
                'description' => $description,
                'display_text' => "I helped {$peerName} overcome a business challenge related to {$description}",
            ],
            'vendor_or_service_help' => [
                'peer_name' => $peerName,
                'need' => $description,
                'display_text' => "I helped {$peerName} find the right vendor or service for {$description}",
            ],
            'funding_or_capital_help' => [
                'peer_name' => $peerName,
                'source_or_connection' => $description,
                'display_text' => "I helped {$peerName} access funding or capital through {$description}",
            ],
            'media_or_recognition_help' => [
                'peer_name' => $peerName,
                'media_name' => $description,
                'display_text' => "I helped {$peerName} get featured or recognised on {$description}",
            ],
            'personal_or_business_support' => [
                'peer_name' => $peerName,
                'situation' => $description,
                'display_text' => "I supported {$peerName} through a personal or business challenge during {$description}",
            ],
            'helped_get_things_done' => [
                'peer_name' => $peerName,
                'what_you_did' => $description,
                'display_text' => "I helped {$peerName} get the right things done by {$description}",
            ],
            default => [
                'display_text' => $description,
            ],
        };
    }

    private function referralRelatedItem(Referral $referral): array
    {
        $peerName = $this->displayName($referral->toUser) ?? 'Peer';
        $connectedWithName = $this->stringOrNull($referral->referral_of ?? null) ?? 'a business opportunity';

        return [
            'id' => (string) $referral->id,
            'peer_id' => $referral->to_user_id ? (string) $referral->to_user_id : null,
            'peer_name' => $peerName,
            'company_name' => $this->stringOrNull($referral->toUser?->company_name),
            'peer_company_name' => $this->stringOrNull($referral->toUser?->company_name),
            'connected_with_name' => $connectedWithName,
            'connected_with_business_name' => $connectedWithName,
            'referral_of' => $this->stringOrNull($referral->referral_of ?? null),
            'date' => $this->formatDate($referral->referral_date ?? $referral->created_at ?? null),
            'referral_date' => $this->formatDate($referral->referral_date ?? null),
            'display_text' => "I gave a qualified referral to {$peerName} — connecting them with {$connectedWithName}",
        ];
    }

    private function collaborationMeetingRelatedItem(P2pMeeting $meeting): array
    {
        $peerOneName = $this->displayName($meeting->initiator) ?? 'one peer';
        $peerTwoName = $this->displayName($meeting->peer) ?? 'another peer';
        $area = $this->firstFilled([
            $meeting->remarks ?? null,
            $meeting->meeting_place ?? null,
            'a collaboration opportunity',
        ]);

        return [
            'id' => (string) $meeting->id,
            'peer_id' => $meeting->peer_user_id ? (string) $meeting->peer_user_id : null,
            'peer_name' => $this->displayName($meeting->peer),
            'company_name' => $this->stringOrNull($meeting->peer?->company_name),
            'meeting_date' => $this->formatDate($meeting->meeting_date ?? null),
            'peer_one_name' => $peerOneName,
            'peer_two_name' => $peerTwoName,
            'area' => $area,
            'display_text' => "I connected {$peerOneName} and {$peerTwoName} for a collaboration opportunity in {$area}",
        ];
    }

    private function historyRelatedUsers(Collection $histories): Collection
    {
        if (! Schema::hasTable('users')) {
            return collect();
        }

        $ids = $histories
            ->flatMap(function (LifeImpactHistory $history): array {
                $meta = $this->historyMeta($history);

                return [
                    $meta['peer_id'] ?? null,
                    $meta['to_user_id'] ?? null,
                    $meta['affected_user_id'] ?? null,
                    $meta['peer_one_id'] ?? null,
                    $meta['peer_two_id'] ?? null,
                    $meta['connected_user_id'] ?? null,
                ];
            })
            ->map(fn ($id): ?string => $this->stringOrNull($id))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return User::query()
            ->select(['id', 'display_name', 'first_name', 'last_name', 'company_name'])
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy(fn (User $user): string => (string) $user->id);
    }

    private function historyRelatedItem(string $checklistKey, LifeImpactHistory $history, Collection $relatedUsers): array
    {
        $meta = $this->historyMeta($history);
        $base = [
            'id' => (string) $history->id,
            'activity_type' => $this->stringOrNull($history->activity_type ?? null),
            'activity_id' => $this->stringOrNull($history->activity_id ?? null),
            'title' => $this->stringOrNull($history->title ?? null),
            'description' => $this->stringOrNull($history->description ?? null),
            'impact_value' => $history->resolveImpactValue(),
            'created_at' => $this->formatDate($history->created_at ?? null),
        ];

        $details = match ($checklistKey) {
            'mentoring_given' => $this->mentoringHistoryDetails($history, $meta, $relatedUsers),
            'collaboration_connections' => $this->collaborationHistoryDetails($history, $meta, $relatedUsers),
            'knowledge_shared' => $this->knowledgeHistoryDetails($history, $meta, $relatedUsers),
            'business_challenge_help' => $this->businessChallengeHistoryDetails($history, $meta, $relatedUsers),
            'vendor_or_service_help' => $this->vendorHelpHistoryDetails($history, $meta, $relatedUsers),
            'funding_or_capital_help' => $this->fundingHelpHistoryDetails($history, $meta, $relatedUsers),
            'media_or_recognition_help' => $this->mediaHelpHistoryDetails($history, $meta, $relatedUsers),
            'personal_or_business_support' => $this->supportHistoryDetails($history, $meta, $relatedUsers),
            'helped_get_things_done' => $this->thingsDoneHistoryDetails($history, $meta, $relatedUsers),
            default => [
                'display_text' => $this->firstFilled([$history->description ?? null, $history->title ?? null, 'I helped a peer through Peers.']),
            ],
        };

        return array_merge($base, $details);
    }

    private function mentoringHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerId = $this->historyRelatedUserId($meta, ['peer_id', 'to_user_id', 'affected_user_id']);
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name'], ['peer_id', 'to_user_id', 'affected_user_id']) ?? 'a peer';
        $subject = $this->firstFilled([$meta['subject_or_area'] ?? null, $meta['subject'] ?? null, $meta['area'] ?? null, $history->title ?? null, $history->description ?? null, 'a key area']);

        return [
            'peer_id' => $peerId,
            'peer_name' => $peerName,
            'subject_or_area' => $subject,
            'display_text' => "I mentored {$peerName} with guidance on {$subject}",
        ];
    }

    private function collaborationHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerOneName = $this->historyPersonName($meta, $relatedUsers, ['peer_one_name', 'from_peer_name', 'peer_name'], ['peer_one_id', 'from_user_id', 'peer_id']) ?? 'one peer';
        $peerTwoName = $this->historyPersonName($meta, $relatedUsers, ['peer_two_name', 'to_peer_name', 'connected_peer_name'], ['peer_two_id', 'to_user_id', 'connected_user_id']) ?? 'another peer';
        $area = $this->firstFilled([$meta['area'] ?? null, $meta['subject_or_area'] ?? null, $meta['collaboration_area'] ?? null, $history->title ?? null, $history->description ?? null, 'a collaboration opportunity']);

        return [
            'peer_one_name' => $peerOneName,
            'peer_two_name' => $peerTwoName,
            'area' => $area,
            'display_text' => "I connected {$peerOneName} and {$peerTwoName} for a collaboration opportunity in {$area}",
        ];
    }

    private function knowledgeHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name'], ['peer_id', 'to_user_id', 'affected_user_id']) ?? 'a peer';
        $subject = $this->firstFilled([$meta['subject'] ?? null, $meta['topic'] ?? null, $meta['subject_or_area'] ?? null, $history->title ?? null, $history->description ?? null, 'a useful topic']);

        return [
            'peer_name' => $peerName,
            'subject' => $subject,
            'display_text' => "I shared knowledge with {$peerName} on the topic of {$subject}",
        ];
    }

    private function businessChallengeHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name'], ['peer_id', 'to_user_id', 'affected_user_id']) ?? 'a peer';
        $description = $this->firstFilled([$meta['description'] ?? null, $meta['challenge'] ?? null, $meta['business_challenge'] ?? null, $history->description ?? null, $history->title ?? null, 'a business challenge']);

        return [
            'peer_name' => $peerName,
            'description' => $description,
            'display_text' => "I helped {$peerName} overcome a business challenge related to {$description}",
        ];
    }

    private function vendorHelpHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name'], ['peer_id', 'to_user_id', 'affected_user_id']) ?? 'a peer';
        $need = $this->firstFilled([$meta['need'] ?? null, $meta['vendor_need'] ?? null, $meta['service_need'] ?? null, $history->description ?? null, $history->title ?? null, 'their need']);

        return [
            'peer_name' => $peerName,
            'need' => $need,
            'display_text' => "I helped {$peerName} find the right vendor or service for {$need}",
        ];
    }

    private function fundingHelpHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name'], ['peer_id', 'to_user_id', 'affected_user_id']) ?? 'a peer';
        $source = $this->firstFilled([$meta['source_or_connection'] ?? null, $meta['source'] ?? null, $meta['connection'] ?? null, $meta['funding_source'] ?? null, $history->description ?? null, $history->title ?? null, 'a funding connection']);

        return [
            'peer_name' => $peerName,
            'source_or_connection' => $source,
            'display_text' => "I helped {$peerName} access funding or capital through {$source}",
        ];
    }

    private function mediaHelpHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name'], ['peer_id', 'to_user_id', 'affected_user_id']) ?? 'a peer';
        $mediaName = $this->firstFilled([$meta['media_name'] ?? null, $meta['publication'] ?? null, $meta['platform'] ?? null, $history->description ?? null, $history->title ?? null, 'a media or recognition platform']);

        return [
            'peer_name' => $peerName,
            'media_name' => $mediaName,
            'display_text' => "I helped {$peerName} get featured or recognised on {$mediaName}",
        ];
    }

    private function supportHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name'], ['peer_id', 'to_user_id', 'affected_user_id']) ?? 'a peer';
        $situation = $this->firstFilled([$meta['situation'] ?? null, $meta['support_situation'] ?? null, $history->description ?? null, $history->title ?? null, 'a challenging situation']);

        return [
            'peer_name' => $peerName,
            'situation' => $situation,
            'display_text' => "I supported {$peerName} through a personal or business challenge during {$situation}",
        ];
    }

    private function thingsDoneHistoryDetails(LifeImpactHistory $history, array $meta, Collection $relatedUsers): array
    {
        $peerName = $this->historyPersonName($meta, $relatedUsers, ['peer_name', 'to_user_name', 'affected_user_name'], ['peer_id', 'to_user_id', 'affected_user_id']) ?? 'a peer';
        $whatYouDid = $this->firstFilled([$meta['what_you_did'] ?? null, $meta['action_taken'] ?? null, $meta['help_provided'] ?? null, $history->description ?? null, $history->title ?? null, 'helping with execution']);

        return [
            'peer_name' => $peerName,
            'what_you_did' => $whatYouDid,
            'display_text' => "I helped {$peerName} get the right things done by {$whatYouDid}",
        ];
    }

    private function historyMeta(LifeImpactHistory $history): array
    {
        return is_array($history->meta) ? $history->meta : [];
    }

    private function historyRelatedUserId(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            $id = $this->stringOrNull($meta[$key] ?? null);
            if ($id !== null) {
                return $id;
            }
        }

        return null;
    }

    private function historyPersonName(array $meta, Collection $relatedUsers, array $nameKeys, array $idKeys): ?string
    {
        foreach ($nameKeys as $key) {
            $name = $this->stringOrNull($meta[$key] ?? null);
            if ($name !== null) {
                return $name;
            }
        }

        $id = $this->historyRelatedUserId($meta, $idKeys);
        if ($id !== null && $relatedUsers->has($id)) {
            return $this->displayName($relatedUsers->get($id));
        }

        return null;
    }

    private function firstFilled(array $values): string
    {
        foreach ($values as $value) {
            $string = $this->stringOrNull($value);
            if ($string !== null) {
                return $string;
            }
        }

        return '';
    }

    private function businessDealBaseQuery(string $userId)
    {
        $query = BusinessDeal::query()
            ->where(function ($subQuery) use ($userId): void {
                $subQuery->where('from_user_id', $userId)
                    ->orWhere('to_user_id', $userId);
            });

        $this->applySoftDeleteFilters($query, 'business_deals');

        if (Schema::hasColumn('business_deals', 'status')) {
            $query->whereIn('status', ['approved', 'completed']);
        }

        return $query;
    }

    private function businessDealPeer(BusinessDeal $deal, string $userId): array
    {
        $peer = (string) ($deal->from_user_id ?? '') === $userId ? $deal->toUser : $deal->fromUser;

        $peerName = $this->displayName($peer) ?? 'Peer';
        $amount = $deal->deal_amount !== null ? (float) $deal->deal_amount : null;

        return [
            'peer_id' => $peer?->id ? (string) $peer->id : null,
            'peer_name' => $peerName,
            'company_name' => $this->stringOrNull($peer?->company_name),
            'amount' => $amount,
            'deal_date' => $this->formatDate($deal->deal_date ?? null),
            'display_text' => 'I completed business worth ' . $this->formatCurrencyAmount((float) ($amount ?? 0)) . ' with ' . $peerName,
        ];
    }

    private function script(array $userInfo, array $summary, array $businessDeals, array $checklistItems): array
    {
        $displayName = $userInfo['display_name'] ?: 'Peer';
        $companyName = $userInfo['company_name'] ?: 'my business';
        $category = $userInfo['category'] ?: 'your category';

        $categoryPhrase = $category === 'your category'
            ? 'your category'
            : 'the ' . $category . ' category';

        return [
            'greeting_text' => 'Hello Peers,',
            'introduction_text' => "My name is {$displayName}. I run {$companyName} in {$categoryPhrase}.",
            'monthly_lives_impacted_text' => 'This month I impacted ' . (int) $summary['total_lives_impacted_this_month'] . ' lives through Peers activities.',
            'monthly_business_done_text' => 'This month I did business worth ' . $this->formatCurrencyAmount((float) $summary['total_business_done_with_peers_this_month']) . ' with Peers.',
            'checklist_items' => $checklistItems,
            'lifetime_impact_text' => 'My lifetime lives impacted count is ' . (int) $summary['lifetime_total_lives_impacted'] . '.',
            'business_deals_text' => 'I recorded ' . (int) $businessDeals['deals_count'] . ' business deal(s) this month totalling ' . $this->formatCurrencyAmount((float) $businessDeals['total_amount']) . '.',
            'meaningful_progress_label' => 'Meaningful progress I made this month',
            'next_month_goal_label' => 'My goal for next month',
            'story_label' => 'Experience or story I would like to share (optional)',
            'closing_text' => 'Thank you Peers for the support, referrals, collaboration, and opportunities.',
        ];
    }

    private function emptyChecklistItem(string $key, string $label): array
    {
        return $this->checklistItem($key, $label, []);
    }

    private function checklistItem(string $key, string $label, array $relatedItems): array
    {
        $count = count($relatedItems);
        $isAvailable = $count > 0;

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'related_items' => $relatedItems,
            'display_text' => $count > 0 ? $label . ': ' . $count : $label,
            'is_available' => $isAvailable,
        ];
    }

    private function safeChecklistSource(string $source, string $userId, callable $callback): Collection
    {
        try {
            $items = $callback();

            Log::info('peer_monthly_script.referral_source_count', [
                'user_id' => $userId,
                'source' => $source,
                'count' => $items->count(),
            ]);

            return $items;
        } catch (\Throwable $e) {
            Log::warning('peer_monthly_script.referral_source_failed', [
                'user_id' => $userId,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    private function firstExistingColumn(string $table, array $columns): ?string
    {
        foreach ($columns as $column) {
            if (Schema::hasColumn($table, $column)) {
                return $column;
            }
        }

        return null;
    }

    private function applyStatusFilter($query, string $table): void
    {
        if (! Schema::hasColumn($table, 'status')) {
            return;
        }

        if ($table === 'activities') {
            $query->where('status', 'approved');

            return;
        }

        $query->whereIn('status', [
            'approved',
            'completed',
            'complete',
            'active',
            'accepted',
            'converted',
            'success',
            'successful',
        ]);
    }

    private function usersByIds(Collection $ids): Collection
    {
        if ($ids->isEmpty() || ! Schema::hasTable('users')) {
            return collect();
        }

        return User::query()
            ->select(['id', 'display_name', 'first_name', 'last_name', 'company_name'])
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy(fn (User $user): string => (string) $user->id);
    }

    private function activityRowMatchesAliases(object $row, array $aliases): bool
    {
        $values = [
            $row->type ?? null,
            $row->activity_type ?? null,
            $row->action_key ?? null,
            $row->action ?? null,
            $row->name ?? null,
            $row->description ?? null,
        ];
        $normalizedAliases = collect($aliases)->map(fn (string $alias): string => $this->normalizeKey($alias))->all();

        foreach ($values as $value) {
            $normalizedValue = $this->normalizeKey($value);
            if ($normalizedValue === '') {
                continue;
            }

            foreach ($normalizedAliases as $alias) {
                if ($normalizedValue === $alias || Str::contains($normalizedValue, $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function uniqueRelatedItems(Collection $items): Collection
    {
        return $items
            ->filter(fn ($item): bool => is_array($item))
            ->unique(function (array $item): string {
                $source = (string) ($item['source'] ?? '');
                $id = (string) ($item['id'] ?? '');

                if ($id !== '') {
                    return $source . ':' . $id;
                }

                return md5((string) ($item['display_text'] ?? json_encode($item)));
            })
            ->values();
    }

    private function historyMatches(LifeImpactHistory $history, array $aliases): bool
    {
        $values = [
            $history->action_key ?? null,
            $history->action_label ?? null,
            $history->activity_type ?? null,
            $history->impact_category ?? null,
            $history->title ?? null,
        ];

        $normalizedAliases = collect($aliases)->map(fn (string $alias): string => $this->normalizeKey($alias))->all();

        foreach ($values as $value) {
            $normalizedValue = $this->normalizeKey($value);
            if ($normalizedValue === '') {
                continue;
            }

            foreach ($normalizedAliases as $alias) {
                if ($normalizedValue === $alias || Str::contains($normalizedValue, $alias)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function applyHistoryCountableFilters($query, string $table): void
    {
        if (Schema::hasColumn($table, 'status')) {
            $query->where('status', 'approved');
        }

        if (Schema::hasColumn($table, 'counted_in_total')) {
            $query->where(function ($subQuery): void {
                $subQuery->where('counted_in_total', true)->orWhereNull('counted_in_total');
            });
        }
    }

    private function applySoftDeleteFilters($query, string $table): void
    {
        if (Schema::hasColumn($table, 'is_deleted')) {
            $query->where(function ($subQuery): void {
                $subQuery->where('is_deleted', false)->orWhereNull('is_deleted');
            });
        }

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }
    }

    private function applyDateRange($query, string $table, array $columns, Carbon $start, Carbon $end): void
    {
        $dateColumns = collect($columns)
            ->filter(fn (string $column): bool => Schema::hasColumn($table, $column))
            ->values();

        if ($dateColumns->isEmpty()) {
            return;
        }

        $query->where(function ($dateQuery) use ($dateColumns, $start, $end): void {
            foreach ($dateColumns as $index => $column) {
                $fallbackColumns = $dateColumns->take($index)->all();

                $dateQuery->orWhere(function ($columnQuery) use ($column, $fallbackColumns, $start, $end): void {
                    foreach ($fallbackColumns as $fallbackColumn) {
                        $columnQuery->whereNull($fallbackColumn);
                    }

                    $columnQuery->whereDate($column, '>=', $start->toDateString())
                        ->whereDate($column, '<=', $end->toDateString());
                });
            }
        });
    }

    private function lifeImpactSumExpression(string $table): string
    {
        if (Schema::hasColumn($table, 'life_impacted') && Schema::hasColumn($table, 'impact_value')) {
            return 'COALESCE(life_impacted, impact_value, 0)';
        }

        if (Schema::hasColumn($table, 'life_impacted')) {
            return 'COALESCE(life_impacted, 0)';
        }

        if (Schema::hasColumn($table, 'impact_value')) {
            return 'COALESCE(impact_value, 0)';
        }

        return '0';
    }

    private function profilePhotoUrl(User $user): ?string
    {
        $fileId = $this->stringOrNull($user->getAttribute('profile_photo_file_id'))
            ?? $this->stringOrNull($user->getAttribute('profile_photo_id'));

        if ($fileId !== null) {
            return url('/api/v1/files/' . $fileId);
        }

        $photoUrl = $this->stringOrNull($user->profile_photo_url ?? null);
        if ($photoUrl === null) {
            return null;
        }

        if (Str::startsWith($photoUrl, ['http://', 'https://'])) {
            return $photoUrl;
        }

        return url('/' . ltrim($photoUrl, '/'));
    }

    private function displayName(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        $displayName = $this->stringOrNull($user->display_name ?? null);
        if ($displayName) {
            return $displayName;
        }

        $fullName = trim((string) ($user->first_name ?? '') . ' ' . (string) ($user->last_name ?? ''));

        return $fullName !== '' ? $fullName : null;
    }

    private function normalizeArray(mixed $value): array
    {
        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->map(fn ($item): string => trim((string) $item))
            ->filter(fn (string $item): bool => $item !== '')
            ->values()
            ->all();
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function formatCurrencyAmount(float $amount): string
    {
        return '₹ ' . number_format($amount, 2, '.', '');
    }

    private function normalizeKey(mixed $value): string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return '';
        }

        return Str::of((string) $value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '_')
            ->trim('_')
            ->toString();
    }
}
