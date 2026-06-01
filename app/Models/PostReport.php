<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class PostReport extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'post_reports';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'post_id',
        'reporter_user_id',
        'reason_id',
        'reason',
        'note',
        'status',
        'reviewed_by_admin_user_id',
        'reviewed_at',
        'admin_note',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $report): void {
            if (empty($report->id)) {
                $report->id = Str::uuid()->toString();
            }
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class)->withTrashed();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }

    public function reasonOption(): BelongsTo
    {
        return $this->belongsTo(PostReportReason::class, 'reason_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin_user_id');
    }
}
