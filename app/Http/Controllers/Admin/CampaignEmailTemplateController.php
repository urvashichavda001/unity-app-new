<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CampaignEmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class CampaignEmailTemplateController extends Controller
{
    public function index(): View
    {
        return view('admin.campaign_email_templates.index', [
            'templates' => $this->templates()->get(),
        ]);
    }

    public function list(): JsonResponse
    {
        return response()->json($this->templates()->get()->map(fn (CampaignEmailTemplate $template): array => [
            'id' => (string) $template->id,
            'name' => $template->name,
            'slug' => $template->slug,
            'category' => $template->category,
            'preview_image_url' => $template->preview_image_url,
            'html_structure' => $template->html_structure,
            'css_styles' => $template->css_styles,
            'template_type' => $template->template_type,
        ])->values());
    }

    private function templates()
    {
        return CampaignEmailTemplate::query()
            ->where('status', CampaignEmailTemplate::STATUS_ACTIVE)
            ->orderByRaw("CASE slug WHEN 'blank-template' THEN 1 WHEN 'simple-text' THEN 2 WHEN '1-column' THEN 3 WHEN '1-2-1-2-column' THEN 4 WHEN '1-2-column' THEN 5 WHEN '1-2-column-alternate' THEN 6 WHEN '1-3-column' THEN 7 ELSE 99 END")
            ->orderBy('name');
    }
}
