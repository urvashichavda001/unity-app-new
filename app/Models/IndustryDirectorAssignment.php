<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class IndustryDirectorAssignment extends Model
{
    protected $table = 'industry_director_assignments';

    protected $guarded = [];

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = true;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (IndustryDirectorAssignment $assignment): void {
            if (empty($assignment->id)) {
                $assignment->id = (string) Str::uuid();
            }
        });
    }
}
