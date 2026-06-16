<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppLabel extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'app_labels';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'app_instance_id',
        'label_key',
        'label_value',
        'group_name',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];
}
