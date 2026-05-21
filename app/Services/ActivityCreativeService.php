<?php

namespace App\Services;

use App\Models\ActivityCreative;
use App\Models\BusinessDeal;
use App\Models\CollaborationPost;
use App\Models\P2PMeetingRequest;
use App\Models\Referral;
use App\Models\Requirement;
use App\Models\RequirementInterest;
use App\Models\Testimonial;
use App\Models\User;

class ActivityCreativeService
{
    public function createOrUpdateCreative(string $activityType, string $activityId, string $userId, array $data): ActivityCreative
    {
        $normalizedType = $this->normalizeActivityType($activityType);

        return ActivityCreative::updateOrCreate(
            [
                'activity_type' => $normalizedType,
                'activity_id' => $activityId,
                'user_id' => $userId,
            ],
            $data
        );
    }

    public function buildDownloadUrl(string $activityType, string $activityId): string
    {
        $type = str_replace('_', '-', $this->normalizeActivityType($activityType));

        return url('/api/v1/activities/' . $type . '/' . $activityId . '/creative/download');
    }

    public function normalizeActivityType(string $activityType): string
    {
        $type = str_replace('-', '_', strtolower(trim($activityType)));

        return match ($type) {
            'p2p_meeting_request', 'p2p_meeting_requests' => 'p2p_meeting_request',
            'referral', 'referrals' => 'referral',
            'collaboration', 'collaborations' => 'collaboration',
            'collaboration_accept', 'collaboration_accepts' => 'collaboration_accept',
            'requirement', 'requirements' => 'requirement',
            'requirement_interest', 'requirement_interests' => 'requirement_interest',
            'business_deal', 'business_deals' => 'business_deal',
            'testimonial', 'testimonials' => 'testimonial',
            default => $type,
        };
    }

    public function buildCreativePayload(string $activityType, $activityModel): array
    {
        $type = $this->normalizeActivityType($activityType);

        return match ($type) {
            'p2p_meeting_request' => $this->payloadP2pMeetingRequest($activityModel),
            'referral' => $this->payloadReferral($activityModel),
            'collaboration' => $this->payloadCollaboration($activityModel),
            'collaboration_accept' => $this->payloadCollaborationAccept($activityModel),
            'requirement' => $this->payloadRequirement($activityModel),
            'requirement_interest' => $this->payloadRequirementInterest($activityModel),
            'business_deal' => $this->payloadBusinessDeal($activityModel),
            'testimonial' => $this->payloadTestimonial($activityModel),
            default => ['creative_title' => 'Activity Creative', 'creative_text' => 'Activity details unavailable.', 'creative_image_path' => null, 'creative_image_url' => null],
        };
    }

    private function payloadP2pMeetingRequest(P2PMeetingRequest $m): array { $m->loadMissing(['requester','invitee']); return ['creative_title'=>'P2P Meeting Request','creative_text'=>"Requester: {$this->name($m->requester)}\nReceiver: {$this->name($m->invitee)}\nMeeting: ".optional($m->scheduled_at)->format('d M Y h:i A')."\nPlace: ".($m->place??'N/A')."\nMessage: ".($m->message??'N/A'),'creative_image_path'=>null,'creative_image_url'=>null];}
    private function payloadReferral(Referral $r): array { $r->loadMissing(['toUser']); return ['creative_title'=>'Referral Shared','creative_text'=>"To: {$this->name($r->toUser)} ({$this->company($r->toUser)})\nReferral Of: ".($r->referral_of??'N/A')."\nType: ".($r->referral_type??'N/A')."\nPhone: ".($r->phone??'N/A')."\nEmail: ".($r->email??'N/A')."\nRemarks: ".($r->remarks??'N/A'),'creative_image_path'=>null,'creative_image_url'=>null];}
    private function payloadCollaboration(CollaborationPost $c): array { $c->loadMissing(['user']); return ['creative_title'=>'Collaboration Opportunity','creative_text'=>"Title: ".($c->title??'N/A')."\nContent: ".($c->description??'N/A')."\nCreator: {$this->name($c->user)} ({$this->company($c->user)})\nCity: {$this->city($c->user)}",'creative_image_path'=>null,'creative_image_url'=>null];}
    private function payloadCollaborationAccept(CollaborationPost $c): array { $c->loadMissing(['acceptedByUser']); return ['creative_title'=>'Collaboration Accepted','creative_text'=>"Accepted By: {$this->name($c->acceptedByUser)} ({$this->company($c->acceptedByUser)})\nCity: {$this->city($c->acceptedByUser)}\nTitle: ".($c->title??'N/A')."\nContent: ".($c->description??'N/A'),'creative_image_path'=>null,'creative_image_url'=>null];}
    private function payloadRequirement(Requirement $r): array { return ['creative_title'=>'Business Requirement Posted','creative_text'=>"Title: ".($r->subject??'N/A')."\nContent: ".($r->description??'N/A')."\nCategory: ".(implode(', ', $r->category_filter ?? []) ?: 'N/A')."\nBudget: N/A",'creative_image_path'=>null,'creative_image_url'=>null];}
    private function payloadRequirementInterest(RequirementInterest $ri): array { $ri->loadMissing(['user','requirement']); return ['creative_title'=>'Interest Shown in Requirement','creative_text'=>"Interested Peer: {$this->name($ri->user)} ({$this->company($ri->user)})\nCity: {$this->city($ri->user)}\nRequirement: ".($ri->requirement?->subject??'N/A')."\nContent: ".($ri->requirement?->description??'N/A')."\nComment: ".($ri->comment??'N/A'),'creative_image_path'=>null,'creative_image_url'=>null];}
    private function payloadBusinessDeal(BusinessDeal $d): array { $peer = User::find($d->to_user_id); return ['creative_title'=>'Business Deal Completed','creative_text'=>"Amount: ".($d->deal_amount??'N/A')."\nPeer: {$this->name($peer)} ({$this->company($peer)})\nComment: ".($d->comment??'N/A'),'creative_image_path'=>null,'creative_image_url'=>null];}
    private function payloadTestimonial(Testimonial $t): array { $u=User::find($t->to_user_id); return ['creative_title'=>'Testimonial Shared','creative_text'=>"Message: ".($t->content??'N/A')."\nFor: {$this->name($u)} ({$this->company($u)})",'creative_image_path'=>null,'creative_image_url'=>null];}
    private function name(?User $u): string { return trim((string)($u?->display_name ?: $u?->name ?: (($u?->first_name ?? '').' '.($u?->last_name ?? '')))) ?: 'N/A'; }
    private function company(?User $u): string { return trim((string)($u?->company_name ?? '')) ?: 'N/A'; }
    private function city(?User $u): string { return trim((string)($u?->city ?? '')) ?: 'N/A'; }
}
