<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CampaignEmailTemplate extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const CATEGORY_BASIC = 'basic';

    protected $table = 'campaign_email_templates';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name', 'slug', 'category', 'preview_image_url', 'html_structure', 'css_styles', 'template_type', 'status',
    ];

    protected static function booted(): void
    {
        static::creating(function (CampaignEmailTemplate $template): void {
            if (empty($template->id)) {
                $template->id = (string) Str::uuid();
            }
        });
    }

    public function snapshot(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'category' => $this->category,
            'preview_image_url' => $this->preview_image_url,
            'html_structure' => $this->html_structure,
            'css_styles' => $this->css_styles,
            'template_type' => $this->template_type,
            'status' => $this->status,
        ];
    }
}
