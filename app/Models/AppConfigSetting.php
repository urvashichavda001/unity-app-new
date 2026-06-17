<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppConfigSetting extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'app_config_settings';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'app_instance_id',
        'app_key',
        'app_name',
        'app_logo_url',
        'splash_logo_url',
        'logo_url_light',
        'logo_url_dark',
        'logo_url_splash',
        'primary_color',
        'primary_dark_color',
        'primary_light_color',
        'primary_ultra_light_color',
        'secondary_color',
        'secondary_light_color',
        'background_color',
        'background_light_color',
        'background_secondary_color',
        'background_dark_color',
        'card_background_color',
        'card_border_color',
        'text_primary_color',
        'text_secondary_color',
        'accent_color',
        'splash_bg_color',
        'button_color',
        'text_color',
        'playstore_url',
        'appstore_url',
        'website_url',
        'support_email',
        'support_phone',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
