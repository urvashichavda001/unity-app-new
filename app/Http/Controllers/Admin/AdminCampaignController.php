<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminCampaign;
use App\Models\CampaignEmailTemplate;
use App\Models\CampaignPamphlet;
use App\Services\AdminCampaigns\CampaignAudienceImportService;
use App\Services\AdminCampaigns\CampaignEmailTemplateRenderer;
use App\Services\AdminCampaigns\CampaignRecipientResolverService;
use App\Services\AdminCampaigns\CampaignSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class AdminCampaignController extends Controller
{
    private const CAMPAIGN_TYPES = ['email_only', 'notification_only', 'email_and_notification'];
    private const AUDIENCE_TYPES = ['all_members', 'city', 'circle', 'company', 'category', 'membership_status', 'specific_members', 'custom_filter'];

    public function __construct(
        private readonly CampaignRecipientResolverService $recipientResolver,
        private readonly CampaignSendService $sendService,
        private readonly CampaignAudienceImportService $audienceImportService,
        private readonly CampaignEmailTemplateRenderer $emailTemplateRenderer,
    ) {
    }

    public function index(Request $request): View
    {
        $campaigns = AdminCampaign::query()->latest('created_at')->paginate(20);

        $stats = [
            'total' => AdminCampaign::query()->count(),
            'draft' => AdminCampaign::query()->where('status', 'draft')->count(),
            'sent' => AdminCampaign::query()->where('status', 'sent')->count(),
            'failed' => AdminCampaign::query()->where('status', 'failed')->count(),
            'emails_sent' => AdminCampaign::query()->sum('total_email_sent'),
            'notifications_sent' => AdminCampaign::query()->sum('total_notification_sent'),
        ];

        return view('admin.campaigns.index', compact('campaigns', 'stats'));
    }

    public function create(): View
    {
        return view('admin.campaigns.form', [
            'campaign' => new AdminCampaign(['campaign_type' => 'email_only', 'audience_type' => 'all_members', 'filters' => []]),
            'filterOptions' => $this->recipientResolver->filterOptions(),
            'mode' => 'create',
            'emailTemplates' => $this->emailTemplates(),
            'defaultEmailTemplate' => $this->emailTemplateRenderer->defaultTemplate(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedCampaignData($request);
        $data['status'] = AdminCampaign::STATUS_DRAFT;
        $data['created_by'] = optional($request->user('admin'))->id;
        $data['updated_by'] = optional($request->user('admin'))->id;

        $campaign = AdminCampaign::query()->create($data);

        if ($request->input('action') === 'send') {
            return $this->send($campaign);
        }

        return redirect()->route('admin.campaigns.show', $campaign)->with('success', 'Campaign draft saved.');
    }

    public function show(AdminCampaign $campaign): View
    {
        $campaign->load(['recipients.user', 'emailTemplate']);
        $recipients = $campaign->recipients()->with('user')->latest('created_at')->paginate(50);

        $filterSummary = $this->recipientResolver->describeFilters($campaign->filters);

        return view('admin.campaigns.show', compact('campaign', 'recipients', 'filterSummary'));
    }

    public function edit(AdminCampaign $campaign): View|RedirectResponse
    {
        if (! $campaign->isEditable()) {
            return redirect()->route('admin.campaigns.show', $campaign)->with('error', 'Sent campaigns cannot be edited.');
        }

        return view('admin.campaigns.form', [
            'campaign' => $campaign,
            'filterOptions' => $this->recipientResolver->filterOptions(),
            'mode' => 'edit',
            'emailTemplates' => $this->emailTemplates(),
            'defaultEmailTemplate' => $this->emailTemplateRenderer->defaultTemplate(),
        ]);
    }

    public function update(Request $request, AdminCampaign $campaign): RedirectResponse
    {
        if (! $campaign->isEditable()) {
            return redirect()->route('admin.campaigns.show', $campaign)->with('error', 'Sent campaigns cannot be edited.');
        }

        $data = $this->validatedCampaignData($request);
        $data['updated_by'] = optional($request->user('admin'))->id;
        $campaign->update($data);

        if ($request->input('action') === 'send') {
            return $this->send($campaign->refresh());
        }

        return redirect()->route('admin.campaigns.show', $campaign)->with('success', 'Campaign draft updated.');
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
        $recipients = $this->recipientResolver->preview($data['audience_type'], $filters, $requiresEmail);
        $total = $this->recipientResolver->count($data['audience_type'], $filters, $requiresEmail);

        return response()->json([
            'total' => $total,
            'recipients' => $recipients,
            'debug' => [
                'selected_business_category_ids' => $this->recipientResolver->businessCategoryIdsFromFilters($filters),
                'matched_users_count' => $total,
            ],
        ]);
    }

    public function send(AdminCampaign $campaign): RedirectResponse
    {
        try {
            $this->sendService->send($campaign);
            return redirect()->route('admin.campaigns.show', $campaign)->with('success', 'Campaign sent successfully.');
        } catch (RuntimeException $exception) {
            return redirect()->route('admin.campaigns.show', $campaign)->with('error', $exception->getMessage());
        }
    }

    public function filterOptions(): JsonResponse
    {
        return response()->json($this->recipientResolver->filterOptions());
    }

    public function memberSearch(Request $request): JsonResponse
    {
        return response()->json(['items' => $this->recipientResolver->searchMembers((string) $request->query('search', ''))]);
    }

    public function importAudience(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'max:10240', 'mimes:csv,txt,xlsx,xls'],
            'audience_type' => ['required', Rule::in(self::AUDIENCE_TYPES)],
        ]);

        try {
            $import = $this->audienceImportService->import($data['file'], $data['audience_type']);
        } catch (RuntimeException|\InvalidArgumentException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $this->importMessage($data['audience_type'], $import['count']),
            'data' => $import,
        ]);
    }

    public function downloadAudienceSample(string $audienceType): Response
    {
        abort_unless(array_key_exists($audienceType, $this->sampleColumns()), 404);

        $columns = $this->sampleColumns()[$audienceType];
        $rows = [
            $columns,
            $this->sampleValues($audienceType, $columns, 0),
            $this->sampleValues($audienceType, $columns, 1),
            $this->sampleValues($audienceType, $columns, 2),
        ];

        $csv = collect($rows)->map(fn (array $row): string => collect($row)->map(fn (string $value): string => '"' . str_replace('"', '""', $value) . '"')->implode(','))->implode("\n");

        return response($csv . "\n", 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="campaign-' . str_replace('_', '-', $audienceType) . '-sample.csv"',
        ]);
    }

    private function emailTemplates(): array
    {
        return CampaignEmailTemplate::query()
            ->where('status', CampaignEmailTemplate::STATUS_ACTIVE)
            ->orderByRaw("CASE slug WHEN 'blank-template' THEN 1 WHEN 'simple-text' THEN 2 WHEN '1-column' THEN 3 WHEN '1-2-1-2-column' THEN 4 WHEN '1-2-column' THEN 5 WHEN '1-2-column-alternate' THEN 6 WHEN '1-3-column' THEN 7 ELSE 99 END")
            ->orderBy('name')
            ->get()
            ->map(fn (CampaignEmailTemplate $template): array => $template->snapshot())
            ->values()
            ->all();
    }

    private function importMessage(string $audienceType, int $count): string
    {
        $label = match ($audienceType) {
            'city' => 'cities',
            'company' => 'companies',
            'membership_status' => 'membership statuses',
            'specific_members' => 'members',
            'category' => 'business categories',
            'circle' => 'circles',
            'custom_filter' => 'audience values',
            default => 'audience values',
        };

        return $count . ' ' . $label . ' imported successfully.';
    }

    private function sampleColumns(): array
    {
        return [
            'city' => ['city'],
            'company' => ['company_name'],
            'membership_status' => ['membership_status'],
            'specific_members' => ['email', 'phone', 'id'],
            'category' => ['business_category'],
            'circle' => ['circle_name'],
            'custom_filter' => ['city', 'company_name', 'membership_status', 'email', 'business_category', 'circle_name'],
        ];
    }

    private function sampleValues(string $audienceType, array $columns, int $index): array
    {
        $samples = [
            'city' => [['Ahmedabad'], ['Pune'], ['Mumbai']],
            'company' => [['Acme Industries'], ['Unity Ventures'], ['Growth Labs']],
            'membership_status' => [['active'], ['trial'], ['expired']],
            'specific_members' => [['member1@example.com', '9876543210', ''], ['member2@example.com', '', ''], ['', '9876500000', '']],
            'category' => [['Manufacturing'], ['Technology'], ['Consulting']],
            'circle' => [['Ahmedabad Circle'], ['Pune Circle'], ['Mumbai Circle']],
            'custom_filter' => [['Ahmedabad', 'Acme Industries', 'active', 'member1@example.com', 'Manufacturing', 'Ahmedabad Circle'], ['Pune', 'Unity Ventures', 'trial', 'member2@example.com', 'Technology', 'Pune Circle'], ['Mumbai', 'Growth Labs', 'expired', '', 'Consulting', 'Mumbai Circle']],
        ];

        return array_pad($samples[$audienceType][$index] ?? [], count($columns), '');
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

        if (! in_array($data['campaign_type'], ['email_only', 'email_and_notification'], true)) {
            $data['subject'] = null;
            $data['email_body'] = null;
        }
        if (! in_array($data['campaign_type'], ['notification_only', 'email_and_notification'], true)) {
            $data['notification_title'] = null;
            $data['notification_message'] = null;
        }

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
            $filters = [];
        }

        $normalized = collect($filters)->map(function ($value) {
            if (is_array($value)) {
                return collect($value)->filter(fn ($item) => filled($item))->values()->all();
            }

            return $value;
        })->all();

        if (isset($normalized['category_ids'])) {
            $normalized['business_category_ids'] = collect($normalized['business_category_ids'] ?? [])
                ->merge($normalized['category_ids'])
                ->filter(fn ($item) => filled($item))
                ->unique()
                ->values()
                ->all();
            unset($normalized['category_ids']);
        }

        return $normalized;
    }
}
