<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Requirements\InterestRequirementRequest;
use App\Models\Requirement;
use App\Models\RequirementInterest;
use App\Services\Blocks\PeerBlockService;
use App\Services\ActivityCreativeService;
use App\Services\Requirements\RequirementNotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequirementInterestController extends Controller
{
    public function __construct(private readonly RequirementNotificationService $requirementNotificationService,
        private readonly PeerBlockService $peerBlockService,
        private readonly ActivityCreativeService $activityCreativeService)
    {
    }

    public function store(InterestRequirementRequest $request, Requirement $requirement): JsonResponse
    {

        $requirement->loadMissing('user');
        $ownerId = (string) data_get($requirement, 'user.id');
        $authId = (string) $request->user()->id;

        if ($ownerId !== '' && $ownerId !== $authId && $this->peerBlockService->isBlockedEitherWay($authId, $ownerId)) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot interact with this peer.',
                'data' => null,
                'meta' => null,
            ], 422);
        }

        if ($requirement->status !== 'open') {
            return response()->json([
                'status' => false,
                'message' => 'Interest is allowed only for open requirements.',
                'data' => null,
                'meta' => null,
            ], 422);
        }

        $interest = RequirementInterest::query()->updateOrCreate(
            [
                'requirement_id' => $requirement->id,
                'user_id' => $request->user()->id,
            ],
            [
                'source' => $request->input('source', 'interest_button'),
                'comment' => $request->input('comment'),
            ]
        );

        try {
            $this->requirementNotificationService->notifyRequirementInterest(
                $requirement,
                $request->user(),
                $interest->comment
            );
        } catch (Throwable $exception) {
            Log::warning('Interest saved but creator notification failed.', [
                'requirement_id' => (string) $requirement->id,
                'user_id' => (string) $request->user()->id,
                'error' => $exception->getMessage(),
            ]);
        }

        $creativeActivityId = (string) ($interest->id ?: $requirement->id);
        $this->activityCreativeService->createOrUpdateCreative('requirement_interest', $creativeActivityId, (string) $request->user()->id, $this->activityCreativeService->buildCreativePayload('requirement_interest', $interest));

        return response()->json([
            'status' => true,
            'message' => 'Interest registered successfully.',
            'data' => [
                'id' => $interest->id,
                'requirement_id' => $interest->requirement_id,
                'user_id' => $interest->user_id,
                'source' => $interest->source,
                'comment' => $interest->comment,
                'created_at' => optional($interest->created_at)?->toISOString(),
                'creative' => [
                    'available' => true,
                    'download_url' => $this->activityCreativeService->buildDownloadUrl('requirement-interest', $creativeActivityId),
                ],
            ],
            'meta' => null,
        ]);
    }
}
