<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AppMembershipLabel extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'app_membership_labels';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'membership_key',
        'display_label',
        'description',
        'is_enabled',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
    ];
}
