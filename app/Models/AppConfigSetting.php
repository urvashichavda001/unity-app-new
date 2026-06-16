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
        'primary_color',
        'secondary_color',
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
