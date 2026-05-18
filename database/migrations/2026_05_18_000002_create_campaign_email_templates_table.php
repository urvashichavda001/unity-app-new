<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');

        DB::statement(<<<'SQL'
CREATE TABLE IF NOT EXISTS campaign_email_templates (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) UNIQUE NOT NULL,
    category VARCHAR(100) DEFAULT 'basic',
    preview_image_url TEXT NULL,
    html_structure TEXT NULL,
    css_styles TEXT NULL,
    template_type VARCHAR(100) NULL,
    status VARCHAR(50) DEFAULT 'active',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
)
SQL);

        DB::statement('CREATE INDEX IF NOT EXISTS idx_campaign_email_templates_status ON campaign_email_templates(status)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_campaign_email_templates_category ON campaign_email_templates(category)');
        DB::statement('ALTER TABLE admin_campaigns ADD COLUMN IF NOT EXISTS email_template_id UUID NULL');
        DB::statement('ALTER TABLE admin_campaigns ADD COLUMN IF NOT EXISTS email_template_snapshot JSONB NULL');

        foreach ($this->templates() as $template) {
            DB::table('campaign_email_templates')->updateOrInsert(
                ['slug' => $template['slug']],
                array_merge($template, ['updated_at' => now(), 'created_at' => now()])
            );
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE admin_campaigns DROP COLUMN IF EXISTS email_template_snapshot');
        DB::statement('ALTER TABLE admin_campaigns DROP COLUMN IF EXISTS email_template_id');
        DB::statement('DROP INDEX IF EXISTS idx_campaign_email_templates_category');
        DB::statement('DROP INDEX IF EXISTS idx_campaign_email_templates_status');
        DB::statement('DROP TABLE IF EXISTS campaign_email_templates');
    }

    private function templates(): array
    {
        $css = '.campaign-template-table{width:100%;border-collapse:collapse}.campaign-template-column{vertical-align:top}.campaign-template-button{display:inline-block;background:#240e5c;color:#ffffff!important;text-decoration:none;border-radius:999px;padding:10px 18px}@media only screen and (max-width:600px){.campaign-template-column{display:block!important;width:100%!important;margin-bottom:16px!important}}';

        $templates = [
            [
                'name' => 'Blank Template',
                'slug' => 'blank-template',
                'category' => 'basic',
                'preview_image_url' => null,
                'html_structure' => '{{content}}',
                'css_styles' => $css,
                'template_type' => 'blank',
                'status' => 'active',
            ],
            [
                'name' => 'Simple Text',
                'slug' => 'simple-text',
                'category' => 'basic',
                'preview_image_url' => null,
                'html_structure' => '<div style="max-width:560px;margin:0 auto;text-align:left;line-height:1.65;">{{content}}</div>',
                'css_styles' => $css,
                'template_type' => 'simple_text',
                'status' => 'active',
            ],
            [
                'name' => '1 Column',
                'slug' => '1-column',
                'category' => 'basic',
                'preview_image_url' => null,
                'html_structure' => '<table class="campaign-template-table" role="presentation"><tr><td style="padding:24px;border:1px solid #e5e7eb;border-radius:14px;background:#ffffff;line-height:1.65;">{{image}}<div style="margin-top:18px;">{{content}}</div><p style="margin-top:20px;"><a href="#" class="campaign-template-button">Learn More</a></p></td></tr></table>',
                'css_styles' => $css,
                'template_type' => 'single_column',
                'status' => 'active',
            ],
            [
                'name' => '1:2:1:2 Column',
                'slug' => '1-2-1-2-column',
                'category' => 'basic',
                'preview_image_url' => null,
                'html_structure' => '<table class="campaign-template-table" role="presentation"><tr><td style="padding:20px;background:#f8fafc;border-radius:14px;line-height:1.65;">{{content}}</td></tr></table><table class="campaign-template-table" role="presentation" style="margin-top:16px;"><tr><td class="campaign-template-column" width="50%" style="padding:12px;">{{image}}</td><td class="campaign-template-column" width="50%" style="padding:12px;line-height:1.65;">{{content_right}}</td></tr></table><table class="campaign-template-table" role="presentation" style="margin-top:16px;"><tr><td style="padding:20px;background:#eef2ff;border-radius:14px;line-height:1.65;">{{content_left}}</td></tr></table><table class="campaign-template-table" role="presentation" style="margin-top:16px;"><tr><td class="campaign-template-column" width="50%" style="padding:12px;line-height:1.65;">{{card_1}}</td><td class="campaign-template-column" width="50%" style="padding:12px;line-height:1.65;">{{card_2}}</td></tr></table>',
                'css_styles' => $css,
                'template_type' => 'one_two_one_two_column',
                'status' => 'active',
            ],
            [
                'name' => '1:2 Column',
                'slug' => '1-2-column',
                'category' => 'basic',
                'preview_image_url' => null,
                'html_structure' => '<table class="campaign-template-table" role="presentation"><tr><td style="padding:22px;background:#f8fafc;border-radius:14px;line-height:1.65;">{{content}}</td></tr></table><table class="campaign-template-table" role="presentation" style="margin-top:18px;"><tr><td class="campaign-template-column" width="50%" style="padding:12px;">{{image}}</td><td class="campaign-template-column" width="50%" style="padding:12px;line-height:1.65;">{{content_right}}</td></tr></table>',
                'css_styles' => $css,
                'template_type' => 'one_two_column',
                'status' => 'active',
            ],
            [
                'name' => '1:2 Column Alternate',
                'slug' => '1-2-column-alternate',
                'category' => 'basic',
                'preview_image_url' => null,
                'html_structure' => '<table class="campaign-template-table" role="presentation"><tr><td style="padding:22px;background:#fff7ed;border-radius:14px;line-height:1.65;">{{content}}</td></tr></table><table class="campaign-template-table" role="presentation" style="margin-top:18px;"><tr><td class="campaign-template-column" width="50%" style="padding:12px;line-height:1.65;">{{content_left}}</td><td class="campaign-template-column" width="50%" style="padding:12px;">{{image}}</td></tr></table><table class="campaign-template-table" role="presentation" style="margin-top:18px;"><tr><td class="campaign-template-column" width="50%" style="padding:12px;">{{image}}</td><td class="campaign-template-column" width="50%" style="padding:12px;line-height:1.65;">{{content_right}}</td></tr></table>',
                'css_styles' => $css,
                'template_type' => 'one_two_column_alternate',
                'status' => 'active',
            ],
            [
                'name' => '1:3 Column',
                'slug' => '1-3-column',
                'category' => 'basic',
                'preview_image_url' => null,
                'html_structure' => '<table class="campaign-template-table" role="presentation"><tr><td style="padding:22px;background:#f8fafc;border-radius:14px;line-height:1.65;">{{content}}</td></tr></table><table class="campaign-template-table" role="presentation" style="margin-top:18px;"><tr><td class="campaign-template-column" width="33.33%" style="padding:10px;line-height:1.55;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">{{card_1}}</td><td class="campaign-template-column" width="33.33%" style="padding:10px;line-height:1.55;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">{{card_2}}</td><td class="campaign-template-column" width="33.33%" style="padding:10px;line-height:1.55;background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;">{{card_3}}</td></tr></table>',
                'css_styles' => $css,
                'template_type' => 'one_three_column',
                'status' => 'active',
            ],
        ];

        return array_map(function (array $template): array {
            $template['preview_image_url'] = $this->previewImage($template['template_type']);

            return $template;
        }, $templates);
    }

    private function previewImage(string $type): string
    {
        $columns = match ($type) {
            'one_three_column' => '<rect x="26" y="82" width="46" height="58" rx="8" fill="#dbeafe"/><rect x="82" y="82" width="46" height="58" rx="8" fill="#dbeafe"/><rect x="138" y="82" width="46" height="58" rx="8" fill="#dbeafe"/>',
            'one_two_column', 'one_two_column_alternate' => '<rect x="26" y="82" width="74" height="58" rx="8" fill="#dbeafe"/><rect x="110" y="82" width="74" height="58" rx="8" fill="#dbeafe"/>',
            'one_two_one_two_column' => '<rect x="26" y="64" width="158" height="28" rx="8" fill="#e0e7ff"/><rect x="26" y="102" width="74" height="38" rx="8" fill="#dbeafe"/><rect x="110" y="102" width="74" height="38" rx="8" fill="#dbeafe"/>',
            'blank' => '<rect x="26" y="40" width="158" height="100" rx="10" fill="#ffffff" stroke="#dbe3ef" stroke-dasharray="6 6"/>',
            default => '<rect x="26" y="48" width="158" height="28" rx="8" fill="#e0e7ff"/><rect x="26" y="88" width="158" height="22" rx="8" fill="#dbeafe"/><rect x="26" y="120" width="158" height="20" rx="8" fill="#dbeafe"/>',
        };

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="210" height="170" viewBox="0 0 210 170"><rect width="210" height="170" rx="18" fill="#f8fafc"/><rect x="18" y="18" width="174" height="134" rx="14" fill="#ffffff" stroke="#dbe3ef"/>'.$columns.'</svg>';

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }
};
