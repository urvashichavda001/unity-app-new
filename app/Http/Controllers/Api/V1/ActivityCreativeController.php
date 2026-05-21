<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\ActivityCreative;
use App\Models\BusinessDeal;
use App\Models\CollaborationPost;
use App\Models\P2PMeetingRequest;
use App\Models\Referral;
use App\Models\Requirement;
use App\Models\RequirementInterest;
use App\Models\Testimonial;
use App\Services\ActivityCreativeService;
use Illuminate\Http\Request;
use Throwable;

class ActivityCreativeController extends BaseApiController
{
    public function __construct(private readonly ActivityCreativeService $creativeService) {}

    public function download(Request $request, string $activityType, string $activityId)
    {
        try {
            $type = $this->creativeService->normalizeActivityType($activityType);
            $creative = ActivityCreative::where('activity_type', $type)->where('activity_id', $activityId)->first();

            if (! $creative) {
                $model = $this->resolveActivityModel($type, $activityId);
                if (! $model) {
                    return $this->error('Creative not found', 404);
                }
                $creative = $this->creativeService->createOrUpdateCreative($type, $activityId, (string) $request->user()->id, $this->creativeService->buildCreativePayload($type, $model));
            }

            $creative->increment('downloaded_count');
            $creative->last_downloaded_at = now();
            $creative->save();

            $html = view('creatives.activity', [
                'brand' => 'Peers Global Unity',
                'activityTypeTitle' => $creative->creative_title,
                'creativeText' => $creative->creative_text,
                'activityDate' => now()->format('d M Y'),
                'userName' => $request->user()->display_name ?? $request->user()->name,
                'userCompany' => $request->user()->company_name,
                'userCity' => $request->user()->city,
            ])->render();

            return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$type.'-'.$activityId.'-creative.html"']);
        } catch (Throwable $e) {
            return $this->error('Creative not found', 404);
        }
    }

    private function resolveActivityModel(string $type, string $id): mixed
    {
        return match ($type) {
            'p2p_meeting_request' => P2PMeetingRequest::find($id),
            'referral' => Referral::find($id),
            'collaboration', 'collaboration_accept' => CollaborationPost::find($id),
            'requirement' => Requirement::find($id),
            'requirement_interest' => RequirementInterest::find($id) ?? RequirementInterest::where('requirement_id', $id)->latest('created_at')->first(),
            'business_deal' => BusinessDeal::find($id),
            'testimonial' => Testimonial::find($id),
            default => null,
        };
    }
}
