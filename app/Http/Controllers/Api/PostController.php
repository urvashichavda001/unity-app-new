<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Post\StorePostCommentRequest;
use App\Http\Requests\Post\StorePostRequest;
use App\Http\Resources\PostResource;
use App\Http\Resources\PostCommentResource;
use App\Models\ActivityCreative;
use App\Models\Circle;
use App\Models\File;
use App\Models\FileModel;
use App\Models\P2pMeeting;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\User;
use App\Services\AdFeedService;
use App\Services\Notifications\NotifyUserService;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Notifications\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PostController extends BaseApiController
{
    public function feed(Request $request, AdFeedService $adFeedService)
    {
        $user = $request->user();
        $perPage = max(1, min((int) $request->integer('per_page', 20), 50));
        $page = LengthAwarePaginator::resolveCurrentPage();

        $postRows = DB::table('posts')
            ->leftJoin('collaboration_posts as feed_collaboration_posts', function ($join): void {
                $join->on('feed_collaboration_posts.id', '=', 'posts.source_id')
                    ->where('posts.source_type', 'collaboration_post')
                    ->where('posts.source_event', 'completed');
            })
            ->leftJoin('users as accepted_peers', 'accepted_peers.id', '=', 'feed_collaboration_posts.accepted_by_user_id')
            ->selectRaw('posts.id as id')
            ->selectRaw('posts.user_id as author_id')
            ->selectRaw('posts.circle_id as circle_id')
            ->selectRaw('posts.content_text as content_text')
            ->selectRaw('posts.media as media')
            ->selectRaw('posts.tags as tags')
            ->selectRaw('posts.visibility as visibility')
            ->selectRaw('posts.moderation_status as moderation_status')
            ->selectRaw('(SELECT COUNT(*) FROM post_likes WHERE post_likes.post_id = posts.id) as likes_count')
            ->selectRaw('(SELECT COUNT(*) FROM post_comments WHERE post_comments.post_id = posts.id AND post_comments.deleted_at IS NULL) as comments_count')
            ->selectRaw('(SELECT COUNT(*) FROM post_saves WHERE post_saves.post_id = posts.id) as saves_count')
            ->selectRaw('EXISTS(SELECT 1 FROM post_likes WHERE post_likes.post_id = posts.id AND post_likes.user_id = ?) as is_liked_by_me', [$user->id])
            ->selectRaw('EXISTS(SELECT 1 FROM post_saves WHERE post_saves.post_id = posts.id AND post_saves.user_id = ?) as is_saved_by_me', [$user->id])
            ->selectRaw('posts.created_at as created_at')
            ->selectRaw('posts.updated_at as updated_at')
            ->selectRaw('posts.created_at as sort_at')
            ->selectRaw("'post' as source_type")
            ->selectRaw('posts.source_type as post_source_type')
            ->selectRaw('posts.source_id as post_source_id')
            ->selectRaw('posts.source_event as post_source_event')
            ->selectRaw('accepted_peers.id as accepted_by_id')
            ->selectRaw('accepted_peers.display_name as accepted_by_display_name')
            ->selectRaw('accepted_peers.first_name as accepted_by_first_name')
            ->selectRaw('accepted_peers.last_name as accepted_by_last_name')
            ->selectRaw('accepted_peers.company_name as accepted_by_company_name')
            ->selectRaw('accepted_peers.city as accepted_by_city')
            ->selectRaw('NULL::uuid as impacted_peer_id')
            ->selectRaw('NULL::date as impact_date')
            ->selectRaw('NULL::text as impact_action')
            ->selectRaw('NULL::integer as life_impacted')
            ->where('posts.visibility', 'public')
            ->where('posts.is_deleted', false)
            ->whereNull('posts.deleted_at');

        $impactRows = DB::table('impacts')
            ->selectRaw('impacts.id as id')
            ->selectRaw('impacts.user_id as author_id')
            ->selectRaw('NULL::uuid as circle_id')
            ->selectRaw('impacts.story_to_share as content_text')
            ->selectRaw("'[]'::jsonb as media")
            ->selectRaw("'[]'::jsonb as tags")
            ->selectRaw("'public' as visibility")
            ->selectRaw("'approved' as moderation_status")
            ->selectRaw('0 as likes_count')
            ->selectRaw('0 as comments_count')
            ->selectRaw('0 as saves_count')
            ->selectRaw('false as is_liked_by_me')
            ->selectRaw('false as is_saved_by_me')
            ->selectRaw('impacts.created_at as created_at')
            ->selectRaw('impacts.updated_at as updated_at')
            ->selectRaw('impacts.timeline_posted_at as sort_at')
            ->selectRaw("'impact' as source_type")
            ->selectRaw('NULL::text as post_source_type')
            ->selectRaw('NULL::uuid as post_source_id')
            ->selectRaw('NULL::text as post_source_event')
            ->selectRaw('NULL::uuid as accepted_by_id')
            ->selectRaw('NULL::text as accepted_by_display_name')
            ->selectRaw('NULL::text as accepted_by_first_name')
            ->selectRaw('NULL::text as accepted_by_last_name')
            ->selectRaw('NULL::text as accepted_by_company_name')
            ->selectRaw('NULL::text as accepted_by_city')
            ->selectRaw('impacts.impacted_peer_id as impacted_peer_id')
            ->selectRaw('impacts.impact_date as impact_date')
            ->selectRaw('impacts.action as impact_action')
            ->selectRaw('COALESCE(impacts.life_impacted, 1) as life_impacted')
            ->where('impacts.status', 'approved')
            ->whereNotNull('impacts.timeline_posted_at');

        $union = $postRows->unionAll($impactRows);
        $orderedRows = DB::query()->fromSub($union, 'feed_rows')->orderByDesc('sort_at');

        $total = (clone $orderedRows)->count();
        $pageRows = collect((clone $orderedRows)->forPage($page, $perPage)->get());

        $authorIds = $pageRows->pluck('author_id')->filter()->unique()->values()->all();
        $circleIds = $pageRows->pluck('circle_id')->filter()->unique()->values()->all();
        $impactedPeerIds = $pageRows->pluck('impacted_peer_id')->filter()->unique()->values()->all();
        $postIds = $pageRows
            ->filter(fn ($row) => (string) ($row->source_type ?? '') === 'post')
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        $activityCreativesByPostId = ActivityCreative::query()
            ->whereIn('post_id', $postIds)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->latest('created_at')
            ->get(['id', 'post_id', 'activity_type', 'activity_id', 'title', 'description', 'creative_file_id', 'creative_url'])
            ->groupBy(fn (ActivityCreative $creative): string => (string) $creative->post_id)
            ->map(fn ($creatives) => $creatives->first());

        $authors = User::query()
            ->whereIn('id', $authorIds)
            ->get(['id', 'display_name', 'first_name', 'last_name', 'profile_photo_file_id'])
            ->keyBy(fn (User $author) => (string) $author->id);

        $circles = Circle::query()
            ->whereIn('id', $circleIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        $impactedPeers = User::query()
            ->whereIn('id', $impactedPeerIds)
            ->get(['id', 'display_name', 'first_name', 'last_name'])
            ->keyBy(fn (User $peer) => (string) $peer->id);

        $p2pMeetingsById = collect();
        $fallbackP2pMeetingIdByPostId = [];

        $p2pMeetingSourceIds = $pageRows
            ->filter(fn ($row) => (string) ($row->source_type ?? '') === 'post' && $this->isP2pMeetingPostRow($row))
            ->pluck('post_source_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($p2pMeetingSourceIds !== []) {
            $p2pMeetingsById = P2pMeeting::query()
                ->whereIn('id', $p2pMeetingSourceIds)
                ->get(['id', 'media'])
                ->keyBy('id');
        }

        $p2pPostsWithoutSource = $pageRows->filter(function ($row): bool {
            return (string) ($row->source_type ?? '') === 'post'
                && $this->isP2pMeetingPostRow($row)
                && empty($row->post_source_id);
        })->values();

        if ($p2pPostsWithoutSource->isNotEmpty()) {
            $fallbackAuthorIds = $p2pPostsWithoutSource->pluck('author_id')->filter()->map(fn ($id) => (string) $id)->unique()->values()->all();
            $postCreatedAt = $p2pPostsWithoutSource
                ->map(fn ($row) => Carbon::parse((string) $row->created_at))
                ->sortBy(fn (Carbon $value) => $value->getTimestamp())
                ->values();

            if ($fallbackAuthorIds !== [] && $postCreatedAt->isNotEmpty()) {
                $windowStart = $postCreatedAt->first()->copy()->subMinutes(2);
                $windowEnd = $postCreatedAt->last()->copy()->addMinutes(2);

                $candidateMeetings = P2pMeeting::query()
                    ->whereIn('initiator_user_id', $fallbackAuthorIds)
                    ->whereBetween('created_at', [$windowStart, $windowEnd])
                    ->get(['id', 'initiator_user_id', 'created_at', 'media'])
                    ->groupBy(fn (P2pMeeting $meeting): string => (string) $meeting->initiator_user_id);

                foreach ($p2pPostsWithoutSource as $row) {
                    $authorId = (string) ($row->author_id ?? '');
                    $rowCreatedAt = Carbon::parse((string) $row->created_at);
                    $authorMeetings = $candidateMeetings->get($authorId, collect());

                    $bestMeeting = $authorMeetings
                        ->filter(function (P2pMeeting $meeting) use ($rowCreatedAt): bool {
                            $diff = abs($meeting->created_at->getTimestamp() - $rowCreatedAt->getTimestamp());
                            return $diff <= 120;
                        })
                        ->sortBy(function (P2pMeeting $meeting) use ($rowCreatedAt): int {
                            return abs($meeting->created_at->getTimestamp() - $rowCreatedAt->getTimestamp());
                        })
                        ->first();

                    if ($bestMeeting) {
                        $fallbackP2pMeetingIdByPostId[(string) $row->id] = (string) $bestMeeting->id;
                        $p2pMeetingsById->put((string) $bestMeeting->id, $bestMeeting);
                    }
                }
            }
        }

        $postItems = $pageRows->map(function ($row) use ($authors, $circles, $impactedPeers, $p2pMeetingsById, $fallbackP2pMeetingIdByPostId, $activityCreativesByPostId) {
            $author = $authors->get((string) $row->author_id);
            $circle = $row->circle_id ? $circles->get((string) $row->circle_id) : null;
            $activityCreative = (string) ($row->source_type ?? '') === 'post'
                ? $activityCreativesByPostId->get((string) $row->id)
                : null;

            $item = [
                'type' => (string) $row->source_type,
                'id' => (string) $row->id,
                'content_text' => (string) ($row->content_text ?? ''),
                'media' => $this->buildFeedMedia($row, $p2pMeetingsById, $fallbackP2pMeetingIdByPostId),
                'tags' => $this->decodeJsonColumn($row->tags),
                'visibility' => (string) $row->visibility,
                'moderation_status' => (string) $row->moderation_status,
                'activity_creative' => $this->formatActivityCreative($activityCreative),
                'author' => $author ? [
                    'id' => (string) $author->id,
                    'display_name' => $author->display_name,
                    'first_name' => $author->first_name,
                    'last_name' => $author->last_name,
                    'profile_photo_url' => $author->profile_photo_file_id
                        ? url('/api/v1/files/' . $author->profile_photo_file_id)
                        : null,
                ] : null,
                'circle' => $circle ? [
                    'id' => (string) $circle->id,
                    'name' => $circle->name,
                ] : null,
                'likes_count' => (int) $row->likes_count,
                'comments_count' => (int) $row->comments_count,
                'is_liked_by_me' => (bool) $row->is_liked_by_me,
                'saves_count' => (int) $row->saves_count,
                'is_saved' => (bool) $row->is_saved_by_me,
                'created_at' => $this->formatToIstDateTime($row->created_at),
                'updated_at' => $this->formatToIstDateTime($row->updated_at),
            ];

            if (
                (string) $row->source_type === 'post'
                && (string) ($row->post_source_type ?? '') === 'collaboration_post'
                && (string) ($row->post_source_event ?? '') === 'completed'
            ) {
                $acceptedByName = trim((string) ($row->accepted_by_display_name
                    ?: trim(((string) ($row->accepted_by_first_name ?? '')) . ' ' . ((string) ($row->accepted_by_last_name ?? '')))));

                $item['accepted_by'] = $row->accepted_by_id ? [
                    'id' => (string) $row->accepted_by_id,
                    'name' => $acceptedByName !== '' ? $acceptedByName : null,
                    'company_name' => $row->accepted_by_company_name,
                    'city' => $row->accepted_by_city,
                ] : null;
            }

            if ((string) $row->source_type === 'impact') {
                $impactedPeer = $row->impacted_peer_id ? $impactedPeers->get((string) $row->impacted_peer_id) : null;

                $item['impact'] = [
                    'action' => $row->impact_action,
                    'impact_date' => $row->impact_date,
                    'life_impacted' => (int) ($row->life_impacted ?? 1),
                    'impacted_peer' => $impactedPeer ? [
                        'id' => (string) $impactedPeer->id,
                        'display_name' => $impactedPeer->display_name,
                        'first_name' => $impactedPeer->first_name,
                        'last_name' => $impactedPeer->last_name,
                    ] : null,
                ];
            }

            return $item;
        })->values();

        $posts = new LengthAwarePaginator(
            $postItems,
            $total,
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        $timelineAds = $adFeedService->timelineAds();
        $items = $adFeedService->mergeTimelineFeed($postItems, $timelineAds, (int) $posts->currentPage());

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    private function formatActivityCreative(?ActivityCreative $creative): ?array
    {
        if (! $creative) {
            return null;
        }

        return [
            'id' => (string) $creative->id,
            'activity_type' => $creative->activity_type,
            'activity_id' => $creative->activity_id,
            'title' => $creative->title,
            'description' => $creative->description,
            'creative_file_id' => $creative->creative_file_id,
            'creative_url' => $creative->creative_url,
        ];
    }

    private function formatToIstDateTime(mixed $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        return Carbon::parse((string) $value)
            ->timezone('Asia/Kolkata')
            ->format('Y-m-d H:i:s');
    }

    private function buildFeedMedia(object $row, $p2pMeetingsById, array $fallbackP2pMeetingIdByPostId): array
    {
        $isP2pPost = (string) ($row->source_type ?? '') === 'post' && $this->isP2pMeetingPostRow($row);

        if ($isP2pPost && ! empty($row->post_source_id)) {
            $meeting = $p2pMeetingsById->get((string) $row->post_source_id);
            if ($meeting) {
                return $this->expandP2pMedia($meeting->media);
            }
        }

        if ($isP2pPost) {
            $fallbackMeetingId = $fallbackP2pMeetingIdByPostId[(string) $row->id] ?? null;
            if ($fallbackMeetingId) {
                $meeting = $p2pMeetingsById->get($fallbackMeetingId);
                if ($meeting) {
                    return $this->expandP2pMedia($meeting->media);
                }
            }

            return [];
        }

        return $this->decodeJsonColumn($row->media);
    }

    private function isP2pMeetingPostRow(object $row): bool
    {
        $sourceType = (string) ($row->post_source_type ?? '');
        if ($sourceType === 'p2p_meeting') {
            return true;
        }

        $tags = $this->decodeJsonColumn($row->tags ?? null);
        return in_array('p2p_meeting', $tags, true);
    }

    /**
     * @param  mixed  $rawMedia
     * @return array<int, array<string, mixed>>
     */
    private function expandP2pMedia(mixed $rawMedia): array
    {
        $media = $this->normalizeMediaPayload($rawMedia);
        if ($media === []) {
            return [];
        }

        $fileIds = collect($media)
            ->map(fn ($item): ?string => is_array($item) ? ($item['file_id'] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $files = FileModel::query()->whereIn('id', $fileIds)->get()->keyBy('id');

        return collect($media)
            ->map(function ($item) use ($files): array {
                $fileId = is_array($item) ? ($item['file_id'] ?? null) : null;
                $mediaType = is_array($item) ? ($item['media_type'] ?? null) : null;
                $file = is_string($fileId) && $fileId !== '' ? $files->get($fileId) : null;

                return [
                    'file_id' => $fileId,
                    'media_type' => $mediaType,
                    'url' => is_string($fileId) && $fileId !== '' ? url('/api/v1/files/' . $fileId) : null,
                    'mime_type' => $file->mime_type ?? $file->mime ?? $file->type ?? null,
                    'original_name' => $file->original_name ?? $file->original_filename ?? $file->name ?? null,
                    'size' => $file->size ?? $file->size_bytes ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMediaPayload(mixed $rawMedia): array
    {
        if (is_string($rawMedia)) {
            $decoded = json_decode($rawMedia, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($rawMedia) ? $rawMedia : [];
    }

    public function userPosts(Request $request, string $userId)
    {
        if (! Str::isUuid($userId)) {
            return $this->error('User not found', 404);
        }

        $user = User::query()->find($userId);

        if (! $user) {
            return $this->error('User not found', 404);
        }

        $authUser = $request->user();
        $perPage = max(1, min((int) $request->integer('per_page', 10), 50));

        $posts = Post::query()
            ->where('user_id', $user->id)
            ->where('posts.is_deleted', false)
            ->whereNull('posts.deleted_at')
            ->with([
                'author:id,display_name,first_name,last_name,profile_photo_file_id',
                'circle:id,name',
            ])
            ->withCount(['likes', 'comments', 'saves'])
            ->when($authUser, function ($query) use ($authUser): void {
                $query->withExists([
                    'likes as is_liked_by_me' => fn ($likeQuery) => $likeQuery->where('user_id', $authUser->id),
                    'saves as is_saved_by_me' => fn ($saveQuery) => $saveQuery->where('user_id', $authUser->id),
                ]);
            })
            ->latest('created_at')
            ->paginate($perPage);

        return $this->success([
            'user_id' => (string) $user->id,
            'total' => $posts->total(),
            'current_page' => $posts->currentPage(),
            'per_page' => $posts->perPage(),
            'last_page' => $posts->lastPage(),
            'items' => PostResource::collection($posts->items()),
        ], 'User posts fetched successfully.');
    }

    private function decodeJsonColumn(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return [];
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    public function store(StorePostRequest $request, NotificationDispatchService $notifications, NotificationService $notificationService)
    {
        $user = Auth::user();

        $data = $request->validate([
            'content_text'   => ['required', 'string', 'max:5000'],
            'media'          => ['nullable', 'array'],
            'media.*.id'     => ['required_with:media', 'uuid', 'exists:files,id'],
            'media.*.type'   => ['required_with:media', 'string', 'max:50'],
            'tags'           => ['nullable', 'array'],
            'tags.*'         => ['string', 'max:100'],
            'visibility'     => ['required', 'in:public,connections,members,circle,private'],
            'circle_id'      => ['nullable', 'uuid'],
        ]);

        $mediaItems = [];

        if (! empty($data['media'])) {
            $fileIds = collect($data['media'])->pluck('id')->all();

            $files = File::whereIn('id', $fileIds)->get()->keyBy('id');

            foreach ($data['media'] as $item) {
                $file = $files->get($item['id']);
                if (! $file) {
                    continue;
                }

                $mediaItems[] = [
                    'id'   => $file->id,
                    'type' => $item['type'],
                    'url'  => url("/api/v1/files/{$file->id}"),
                ];
            }
        }

        $post = Post::create([
            'user_id'           => $user->id,
            'circle_id'         => $data['circle_id'] ?? null,
            'content_text'      => $data['content_text'],
            'media'             => $mediaItems ?: [],
            'tags'              => $data['tags'] ?? [],
            'visibility'        => $data['visibility'],
            'moderation_status' => 'pending',
            'sponsored'         => false,
            'is_deleted'        => false,
        ]);

        $this->dispatchNewPostNotifications($notificationService, $post);
        $this->dispatchMentionNotifications($notifications, $post, $user, $post->content_text, null);

        return response()->json([
            'success' => true,
            'message' => 'Post created successfully',
            'data' => [
                'id' => $post->id,
                'user_id' => $post->user_id,
                'circle_id' => $post->circle_id,
                'content_text' => $post->content_text,
                'media' => $post->media ?? [],
                'tags' => $post->tags ?? [],
                'visibility' => $post->visibility,
                'moderation_status' => $post->moderation_status,
                'sponsored' => $post->sponsored,
                'is_deleted' => $post->is_deleted,
                'created_at' => $post->created_at,
                'updated_at' => $post->updated_at,
            ],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $post = Post::with(['user', 'circle'])
            ->withCount(['likes', 'comments', 'saves'])
            ->withExists([
                'likes as is_liked_by_me' => fn ($query) => $query->where('user_id', $request->user()->id),
                'saves as is_saved_by_me' => fn ($query) => $query->where('user_id', $request->user()->id),
            ])
            ->where('id', $id)
            ->where('posts.is_deleted', false)
            ->whereNull('posts.deleted_at')
            ->first();

        if (! $post) {
            return $this->error('Post not found', 404);
        }

        return $this->success([
            'id'                => $post->id,
            'content_text'      => $post->content_text,
            'media'             => $post->media ?? [],
            'tags'              => $post->tags ?? [],
            'visibility'        => $post->visibility,
            'moderation_status' => $post->moderation_status,
            'author'            => $post->relationLoaded('user') && $post->user ? [
                'id'               => $post->user->id,
                'display_name'     => $post->user->display_name,
                'first_name'       => $post->user->first_name,
                'last_name'        => $post->user->last_name,
                'profile_photo_url'=> $post->user->profile_photo_url,
            ] : null,
            'circle'            => $post->relationLoaded('circle') && $post->circle ? [
                'id'   => $post->circle->id,
                'name' => $post->circle->name,
            ] : null,
            'likes_count'       => isset($post->likes_count) ? (int) $post->likes_count : 0,
            'comments_count'    => isset($post->comments_count) ? (int) $post->comments_count : 0,
            'is_liked_by_me'    => (bool) ($post->is_liked_by_me ?? false),
            'saves_count'       => isset($post->saves_count) ? (int) $post->saves_count : 0,
            'is_saved'          => (bool) ($post->is_saved_by_me ?? false),
            'created_at'        => $post->created_at,
            'updated_at'        => $post->updated_at,
        ]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();

        // User can delete ONLY their own posts
        $post = Post::where('id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (! $post) {
            return $this->error('Post not found or you are not allowed to delete it', 404);
        }

        $post->delete(); // respects SoftDeletes if used on the model

        return $this->success(null, 'Post deleted successfully');
    }

    public function like(Request $request, string $id, NotifyUserService $notifyUserService, NotificationService $notifications)
    {
        $authUser = $request->user();

        $post = Post::where('id', $id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->first();

        if (! $post) {
            return $this->error('Post not found', 404);
        }

        $like = PostLike::firstOrCreate([
            'post_id' => $post->id,
            'user_id' => $authUser->id,
        ]);

        if ($like->wasRecentlyCreated && (string) $post->user_id !== (string) $authUser->id) {
            try {
                if ($postOwner = User::find($post->user_id)) {
                    $likerName = $this->displayName($authUser);
                    $notifications->sendToUser(
                        $postOwner,
                        'post_like',
                        $likerName . ' liked your post',
                        $likerName . ' liked your post',
                        [
                            'post_id' => (string) $post->id,
                            'like_id' => (string) $like->id,
                            'actor_id' => (string) $authUser->id,
                            'screen' => 'post_detail',
                            'tap_destination' => 'post_detail',
                            'reference_type' => 'post',
                            'reference_id' => (string) $post->id,
                        ],
                        [
                            'actor_id' => (string) $authUser->id,
                            'channel' => 'push',
                            'reference_type' => 'post',
                            'reference_id' => (string) $post->id,
                            'dedupe_key' => 'post_like:' . $post->id . ':' . $authUser->id,
                        ]
                    );
                }
            } catch (Throwable $e) {
                Log::warning('Post like notification failed', ['post_id' => (string) $post->id, 'liker_user_id' => (string) $authUser->id, 'error' => $e->getMessage()]);
            }
        }

        $likeCount = PostLike::where('post_id', $post->id)->count();

        return $this->success(['like_count' => $likeCount], 'Post liked');
    }

    public function unlike(Request $request, string $id)
    {
        $authUser = $request->user();

        $post = Post::where('id', $id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->first();

        if (! $post) {
            return $this->error('Post not found', 404);
        }

        PostLike::where('post_id', $post->id)
            ->where('user_id', $authUser->id)
            ->delete();

        $likeCount = PostLike::where('post_id', $post->id)->count();

        return $this->success(['like_count' => $likeCount], 'Post unliked');
    }

    public function storeComment(StorePostCommentRequest $request, string $id, NotifyUserService $notifyUserService, NotificationService $notifications, NotificationDispatchService $mentionNotifications)
    {
        $authUser = $request->user();

        $post = Post::where('id', $id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->first();

        if (! $post) {
            return $this->error('Post not found', 404);
        }

        $data = $request->validated();

        $comment = new PostComment();
        $comment->post_id = $post->id;
        $comment->user_id = $authUser->id;
        $comment->content = $data['content'];
        $comment->parent_id = $data['parent_id'] ?? null;
        $comment->save();

        if ((string) $post->user_id !== (string) $authUser->id) {
            try {
                if ($postOwner = User::find($post->user_id)) {
                    $commenterName = $this->displayName($authUser);
                    $preview = Str::limit(trim((string) $comment->content), 120) ?: ($commenterName . ' commented on your post');
                    $notifications->sendToUser(
                        $postOwner,
                        'post_comment',
                        $commenterName . ' commented on your post',
                        $preview,
                        [
                            'post_id' => (string) $post->id,
                            'comment_id' => (string) $comment->id,
                            'actor_id' => (string) $authUser->id,
                            'screen' => 'post_detail',
                            'tap_destination' => 'post_detail',
                            'reference_type' => 'post',
                            'reference_id' => (string) $post->id,
                        ],
                        [
                            'actor_id' => (string) $authUser->id,
                            'channel' => 'push',
                            'reference_type' => 'post',
                            'reference_id' => (string) $post->id,
                            'dedupe_key' => 'post_comment:' . $comment->id,
                        ]
                    );
                }
            } catch (Throwable $e) {
                Log::warning('Post comment notification failed', ['post_id' => (string) $post->id, 'comment_id' => (string) $comment->id, 'error' => $e->getMessage()]);
            }
        }

        $this->dispatchMentionNotifications($mentionNotifications, $post, $authUser, $comment->content, $comment);

        $comment->load('user');

        return $this->success(new PostCommentResource($comment), 'Comment added', 201);
    }

    public function listComments(Request $request, string $id)
    {
        $post = Post::where('id', $id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->first();

        if (! $post) {
            return $this->error('Post not found', 404);
        }

        $perPage = (int) $request->input('per_page', 50);
        $perPage = max(1, min($perPage, 100));

        $paginator = PostComment::with('user')
            ->where('post_id', $post->id)
            ->orderBy('created_at', 'asc')
            ->paginate($perPage);

        $data = [
            'items' => PostCommentResource::collection($paginator),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];

        return $this->success($data);
    }

    private function dispatchNewPostNotifications(NotificationService $notifications, Post $post): void
    {
        try {
            $notifications->sendPostPublishedNotification($post);
        } catch (Throwable $e) {
            Log::warning('New post notification failed', ['post_id' => (string) $post->id, 'error' => $e->getMessage()]);
        }
    }

    private function dispatchMentionNotifications(NotificationDispatchService $notifications, Post $post, User $actor, string $text, ?PostComment $comment): void
    {
        $mentionedUsers = $this->mentionedUsers($text)->reject(fn (User $user) => (string) $user->id === (string) $actor->id)->values();
        if ($mentionedUsers->isEmpty()) {
            return;
        }
        try {
            $notifications->sendCampaignNotification(
                'user_mention_notification',
                $mentionedUsers,
                ['person' => $this->displayName($actor), 'post_preview_content' => Str::limit($text, 120), 'comment_preview_content' => Str::limit($text, 120)],
                ['screen' => 'post_details', 'post_id' => (string) $post->id, 'comment_id' => $comment?->id, 'mentioned_by' => (string) $actor->id, 'type' => 'mention'],
                $actor,
                $comment ?: $post,
                ['type' => 'mention', 'reference_type' => $comment ? 'post_comment' : 'post', 'reference_id' => (string) ($comment?->id ?? $post->id), 'dedupe_key' => 'mention:' . ($comment?->id ?? $post->id)]
            );
        } catch (Throwable $e) {
            Log::warning('Mention notification failed', ['post_id' => (string) $post->id, 'error' => $e->getMessage()]);
        }
    }

    private function mentionedUsers(string $text): \Illuminate\Support\Collection
    {
        preg_match_all('/@([A-Za-z0-9_.-]{2,50})/', $text, $matches);
        $handles = collect($matches[1] ?? [])->filter()->unique()->values();
        if ($handles->isEmpty()) {
            return collect();
        }

        return User::query()->where(function ($query) use ($handles): void {
            foreach ($handles as $handle) {
                $query->orWhere('display_name', 'ilike', $handle)->orWhere('name', 'ilike', $handle)->orWhere('email', 'ilike', $handle . '@%');
            }
        })->get();
    }

    private function postPreview(Post $post): string
    {
        return Str::limit(trim((string) $post->content_text), 120) ?: 'New post';
    }

    private function displayName(User $user): string
    {
        return trim((string) ($user->display_name ?? '')) ?: trim(((string) ($user->first_name ?? '')) . ' ' . ((string) ($user->last_name ?? ''))) ?: (string) ($user->name ?? 'A member');
    }

}
