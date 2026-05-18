<?php

namespace App\Services\AdminCampaigns;

use App\Models\AdminCampaign;
use App\Models\CampaignEmailTemplate;
use Illuminate\Support\Str;

class CampaignEmailTemplateRenderer
{
    public function render(AdminCampaign $campaign): string
    {
        $snapshot = $this->templateSnapshot($campaign);

        return $this->renderSnapshot($snapshot, (string) $campaign->email_body, (string) data_get($campaign->pamphlet_snapshot, 'image_url', ''));
    }

    public function snapshotForCampaign(AdminCampaign $campaign, bool $includeRenderedHtml = false): array
    {
        $snapshot = $this->templateSnapshot($campaign);

        if ($includeRenderedHtml) {
            $snapshot['rendered_html'] = $this->renderSnapshot($snapshot, (string) $campaign->email_body, (string) data_get($campaign->pamphlet_snapshot, 'image_url', ''));
            $snapshot['rendered_at'] = now()->toISOString();
        }

        return $snapshot;
    }

    public function defaultTemplate(): ?CampaignEmailTemplate
    {
        return CampaignEmailTemplate::query()
            ->where('slug', 'simple-text')
            ->where('status', CampaignEmailTemplate::STATUS_ACTIVE)
            ->first();
    }

    private function templateSnapshot(AdminCampaign $campaign): array
    {
        if (is_array($campaign->email_template_snapshot) && ! empty($campaign->email_template_snapshot['html_structure'])) {
            return $campaign->email_template_snapshot;
        }

        if ($campaign->emailTemplate) {
            return $campaign->emailTemplate->snapshot();
        }

        if ($template = $this->defaultTemplate()) {
            return $template->snapshot();
        }

        return $this->builtInSimpleTextTemplate();
    }

    private function renderSnapshot(array $template, string $content, string $imageUrl = ''): string
    {
        $content = trim($content) !== '' ? $content : '<p>Add your campaign content here.</p>';
        $imageHtml = $imageUrl !== ''
            ? '<img src="' . e($imageUrl) . '" alt="Campaign image" style="max-width:100%;height:auto;border-radius:12px;display:block;">'
            : '<div style="background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:12px;padding:28px;text-align:center;color:#64748b;">Image / visual block</div>';

        [$left, $right] = $this->splitContent($content, 2);
        [$cardOne, $cardTwo, $cardThree] = $this->splitContent($content, 3);

        $html = (string) ($template['html_structure'] ?? $this->builtInSimpleTextTemplate()['html_structure']);
        $replacements = [
            '{{content}}' => $content,
            '{{image}}' => $imageHtml,
            '{{content_left}}' => $left,
            '{{content_right}}' => trim($right) !== '' ? $right : $content,
            '{{card_1}}' => $cardOne,
            '{{card_2}}' => trim($cardTwo) !== '' ? $cardTwo : $cardOne,
            '{{card_3}}' => trim($cardThree) !== '' ? $cardThree : $cardOne,
        ];

        $html = str_replace(array_keys($replacements), array_values($replacements), $html);
        $css = trim((string) ($template['css_styles'] ?? ''));

        return $css !== '' ? '<style>' . $css . '</style>' . $html : $html;
    }

    private function splitContent(string $content, int $parts): array
    {
        $blocks = preg_split('/(?=<p\b|<h[1-6]\b|<ul\b|<ol\b|<div\b)/i', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [$content];
        $chunks = array_fill(0, $parts, '');

        foreach (array_values($blocks) as $index => $block) {
            $chunks[$index % $parts] .= $block;
        }

        return array_map(fn (string $chunk): string => trim($chunk), $chunks);
    }

    private function builtInSimpleTextTemplate(): array
    {
        return [
            'id' => null,
            'name' => 'Simple Text',
            'slug' => 'simple-text',
            'category' => CampaignEmailTemplate::CATEGORY_BASIC,
            'preview_image_url' => null,
            'html_structure' => '<div style="max-width:560px;margin:0 auto;text-align:left;line-height:1.65;">{{content}}</div>',
            'css_styles' => '',
            'template_type' => 'simple_text',
            'status' => CampaignEmailTemplate::STATUS_ACTIVE,
        ];
    }
}
