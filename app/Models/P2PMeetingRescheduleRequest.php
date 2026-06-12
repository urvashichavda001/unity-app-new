<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class P2PMeetingRescheduleRequest extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'p2p_meeting_reschedule_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'p2p_meeting_request_id',
        'requested_by_user_id',
        'requested_to_user_id',
        'old_scheduled_at',
        'new_scheduled_at',
        'old_place',
        'new_place',
        'reason',
        'status',
        'approved_at',
        'rejected_at',
        'responded_by_user_id',
    ];

    protected $casts = [
        'old_scheduled_at' => 'datetime',
        'new_scheduled_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function meetingRequest(): BelongsTo
    {
        return $this->belongsTo(P2PMeetingRequest::class, 'p2p_meeting_request_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function requestedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_to_user_id');
    }

    public function respondedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responded_by_user_id');
    }
}
