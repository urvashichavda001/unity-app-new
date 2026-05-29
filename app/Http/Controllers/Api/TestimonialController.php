<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Requests\Activity\StoreTestimonialRequest;
use App\Events\ActivityCreated;
use App\Models\Post;
use App\Models\Testimonial;
use App\Models\User;
use App\Services\Blocks\PeerBlockService;
use App\Services\Coins\CoinsService;
use App\Services\Notifications\NotifyUserService;
use App\Support\MediaFileUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class TestimonialController extends BaseApiController
{
    protected function addUrlsToMedia(?array $media): array
    {
        if (empty($media)) {
            return [];
        }

        return collect($media)->map(function ($item) {
            $id = $item['file_id'] ?? $item['fileId'] ?? $item['id'] ?? null;
            $type = $item['type'] ?? 'image';

            return [
                'id' => $id,
                'type' => $type,
                'url' => MediaFileUrl::resolve($item),
            ];
        })->all();
    }

    /**
     * Create a feed post for a newly created testimonial.
     */
    protected function createPostForTestimonial(Testimonial $testimonial): void
    {
        try {
            $mediaForPost = $this->addUrlsToMedia($testimonial->media ?? []);

            // Optionally include the receiver's name in content
            $toUser = User::find($testimonial->to_user_id);

            $contentText = $this->buildActivityPostMessage('testimonial', $toUser, [
                'testimonial_message' => $testimonial->content ?? '',
            ]);

            Post::create([
                'user_id'           => $testimonial->from_user_id,
                'circle_id'         => null,
                'content_text'      => $contentText,
                'media'             => $mediaForPost,
                'tags'              => ['testimonial'],
                'visibility'        => 'public',
                'moderation_status' => 'pending',
                'sponsored'         => false,
                'is_deleted'        => false,
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to create post for testimonial', [
                'testimonial_id' => $testimonial->id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    public function index(Request $request)
    {
        $authUser = $request->user();
        $filter = $request->input('filter', 'given');

        $query = Testimonial::query()
            ->where('is_deleted', false)
            ->whereNull('deleted_at');

        if ($filter === 'received') {
            $query->where('to_user_id', $authUser->id);
        } elseif ($filter === 'all') {
            $query->where(function ($q) use ($authUser) {
                $q->where('from_user_id', $authUser->id)
                    ->orWhere('to_user_id', $authUser->id);
            });
        } else {
            $query->where('from_user_id', $authUser->id);
        }

        $perPage = (int) $request->input('per_page', 20);
        $perPage = max(1, min($perPage, 100));

        $paginator = $query
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(StoreTestimonialRequest $request, NotifyUserService $notifyUserService, PeerBlockService $peerBlockService)
    {
        $authUser = $request->user();
        $targetUserId = (string) $request->input('to_user_id');

        if ($peerBlockService->isBlockedEitherWay((string) $authUser->id, $targetUserId)) {
            return $this->error('You cannot interact with this peer.', 422);
        }

        $media = null;
        if ($request->filled('media_id')) {
            $media = [[
                'id' => $request->input('media_id'),
                'type' => 'image',
            ]];
        }

        try {
            $testimonial = Testimonial::create([
                'from_user_id' => $authUser->id,
                'to_user_id' => $request->input('to_user_id'),
                'content' => $request->input('content'),
                'media' => $media,
                'is_deleted' => false,
            ]);

            $coinsLedger = app(CoinsService::class)->rewardForActivity(
                $authUser,
                'testimonial',
                null,
                'Activity: testimonial',
                $authUser->id
            );

            if ($coinsLedger) {
                $testimonial->setAttribute('coins', [
                    'earned' => $coinsLedger->amount,
                    'balance_after' => $coinsLedger->balance_after,
                ]);
            }

            $this->createPostForTestimonial($testimonial);

            event(new ActivityCreated(
                'Testimonial',
                $testimonial,
                (string) $authUser->id,
                $testimonial->to_user_id ? (string) $testimonial->to_user_id : null
            ));

            $targetUser = User::find($testimonial->to_user_id);

            if ($targetUser) {
                $notifyUserService->notifyUser(
                    $targetUser,
                    $authUser,
                    'activity_testimonial',
                    [
                        'activity_type' => 'testimonial',
                        'activity_id' => (string) $testimonial->id,
                        'title' => 'New Testimonial',
                        'body' => ($authUser->display_name ?? $authUser->name ?? 'A member') . ' sent you a testimonial',
                    ],
                    $testimonial
                );
            }

            $updatedLifeImpact = $this->increaseLifeImpact(
                (string) $authUser->id,
                5,
                'testimonial',
                'Received a testimonial / review',
                (string) $authUser->id,
                (string) $testimonial->id,
                'Life impact added for testimonial activity.',
                [
                    'content' => $testimonial->content,
                    'media_ids' => collect($testimonial->media ?? [])->pluck('id')->filter()->values()->all(),
                    'from_user_id' => $testimonial->from_user_id ? (string) $testimonial->from_user_id : null,
                    'to_user_id' => $testimonial->to_user_id ? (string) $testimonial->to_user_id : null,
                ]
            );

            // Postman example (testimonial create):
            // {
            //   "to_user_id": "<receiver-user-uuid>",
            //   "content": "Great collaborator!",
            //   "media_id": "<optional-file-uuid>"
            // }
            // Verify SQL:
            // select * from notifications where user_id = '<receiver-user-uuid>' order by created_at desc limit 20;
            // Postman example (requirement create - MUST NOT notify):
            // {
            //   "subject": "Need growth partner",
            //   "description": "Looking for channel partnerships"
            // }

            $data = $testimonial->toArray();
            $data['media'] = $this->addUrlsToMedia($testimonial->media ?? []);

            if ($testimonial->getAttribute('coins')) {
                $data['coins'] = $testimonial->getAttribute('coins');
            }

            $data['life_impacted_count'] = $updatedLifeImpact;

            return $this->success($data, 'Testimonial saved successfully', 201);
        } catch (Throwable $e) {
            return $this->error('Something went wrong', 500);
        }
    }

    public function show(Request $request, string $id)
    {
        $authUser = $request->user();

        $testimonial = Testimonial::where('id', $id)
            ->where('is_deleted', false)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($authUser) {
                $q->where('from_user_id', $authUser->id)
                    ->orWhere('to_user_id', $authUser->id);
            })
            ->first();

        if (! $testimonial) {
            return $this->error('Testimonial not found', 404);
        }

        return $this->success($testimonial);
    }
}
