<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Services\Impacts\ImpactActionService;

class Impact extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'impacts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'impacted_peer_id',
        'impact_date',
        'action',
        'story_to_share',
        'life_impacted',
        'additional_remarks',
        'requires_leadership_approval',
        'status',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'review_remarks',
        'timeline_posted_at',
    ];

    protected $casts = [
        'impact_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'timeline_posted_at' => 'datetime',
        'requires_leadership_approval' => 'boolean',
        'life_impacted' => 'integer',
    ];

    public static function availableActions(): array
    {
        return app(ImpactActionService::class)->availableActions();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function impactedPeer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impacted_peer_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }
}
