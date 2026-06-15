<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppNavigationItem extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'app_navigation_items';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'app_instance_id',
        'menu_type',
        'item_key',
        'label_key',
        'display_label',
        'icon',
        'route_name',
        'feature_key',
        'is_enabled',
        'sort_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];
}
