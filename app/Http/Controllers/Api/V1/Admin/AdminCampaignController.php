<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\AdminCampaign;
use App\Models\CampaignEmailTemplate;
use App\Models\CampaignPamphlet;
use App\Services\AdminCampaigns\CampaignEmailTemplateRenderer;
use App\Services\AdminCampaigns\CampaignRecipientResolverService;
use App\Services\AdminCampaigns\CampaignSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;

class AdminCampaignController extends BaseApiController
{
    private const CAMPAIGN_TYPES = ['email_only', 'notification_only', 'email_and_notification'];
    private const AUDIENCE_TYPES = ['all_members', 'city', 'circle', 'company', 'category', 'membership_status', 'specific_members', 'custom_filter'];

    public function __construct(
        private readonly CampaignRecipientResolverService $recipientResolver,
        private readonly CampaignSendService $sendService,
        private readonly CampaignEmailTemplateRenderer $emailTemplateRenderer,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->success(AdminCampaign::query()->latest('created_at')->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedCampaignData($request);
        $data['status'] = AdminCampaign::STATUS_DRAFT;
        $data['created_by'] = optional($request->user())->id;
        $data['updated_by'] = optional($request->user())->id;

        return $this->success(AdminCampaign::query()->create($data), 'Campaign draft saved', 201);
    }

    public function show(AdminCampaign $campaign): JsonResponse
    {
        return $this->success($campaign->load(['recipients.user', 'emailTemplate']));
    }

    public function previewRecipients(Request $request): JsonResponse
    {
        $data = $request->validate([
            'campaign_type' => ['required', Rule::in(self::CAMPAIGN_TYPES)],
            'audience_type' => ['required', Rule::in(self::AUDIENCE_TYPES)],
            'filters' => ['nullable'],
        ]);

        $filters = $this->normalizeFilters($request);
        $requiresEmail = in_array($data['campaign_type'], ['email_only', 'email_and_notification'], true);

        $total = $this->recipientResolver->count($data['audience_type'], $filters, $requiresEmail);

        return $this->success([
            'total' => $total,
            'recipients' => $this->recipientResolver->preview($data['audience_type'], $filters, $requiresEmail),
            'debug' => [
                'selected_business_category_ids' => $this->recipientResolver->businessCategoryIdsFromFilters($filters),
                'matched_users_count' => $total,
            ],
        ]);
    }

    public function send(AdminCampaign $campaign): JsonResponse
    {
        try {
            return $this->success($this->sendService->send($campaign), 'Campaign sent successfully');
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }
    }

    public function filterOptions(): JsonResponse
    {
        return $this->success($this->recipientResolver->filterOptions());
    }

    public function memberSearch(Request $request): JsonResponse
    {
        return $this->success($this->recipientResolver->searchMembers((string) $request->query('search', '')));
    }

    private function validatedCampaignData(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'campaign_type' => ['required', Rule::in(self::CAMPAIGN_TYPES)],
            'subject' => ['nullable', 'string', 'max:255', 'required_if:campaign_type,email_only,email_and_notification'],
            'email_body' => ['nullable', 'string', 'required_if:campaign_type,email_only,email_and_notification'],
            'notification_title' => ['nullable', 'string', 'max:255', 'required_if:campaign_type,notification_only,email_and_notification'],
            'notification_message' => ['nullable', 'string', 'required_if:campaign_type,notification_only,email_and_notification'],
            'audience_type' => ['required', Rule::in(self::AUDIENCE_TYPES)],
            'filters' => ['nullable'],
            'pamphlet_id' => ['nullable', 'uuid', 'exists:campaign_pamphlets,id'],
            'email_template_id' => ['nullable', 'uuid', 'exists:campaign_email_templates,id'],
        ]);
        $data['filters'] = $this->normalizeFilters($request);
        $pamphlet = filled($data['pamphlet_id'] ?? null)
            ? CampaignPamphlet::query()->where('id', $data['pamphlet_id'])->first()
            : null;
        $data['pamphlet_snapshot'] = $pamphlet?->snapshot();
        $emailTemplate = filled($data['email_template_id'] ?? null)
            ? CampaignEmailTemplate::query()->where('id', $data['email_template_id'])->first()
            : $this->emailTemplateRenderer->defaultTemplate();
        $data['email_template_id'] = $emailTemplate?->id;
        $data['email_template_snapshot'] = $emailTemplate?->snapshot();

        return $data;
    }

    private function normalizeFilters(Request $request): array
    {
        $filters = $request->input('filters', []);
        if (is_string($filters)) {
            $decoded = json_decode($filters, true);
            $filters = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($filters)) {
            return [];
        }

        if (isset($filters['category_ids'])) {
            $filters['business_category_ids'] = collect($filters['business_category_ids'] ?? [])
                ->merge($filters['category_ids'])
                ->filter(fn ($item) => filled($item))
                ->unique()
                ->values()
                ->all();
            unset($filters['category_ids']);
        }

        return $filters;
    }
}
