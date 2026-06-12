<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class P2PMeetingRequest extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'p2p_meeting_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'requester_id',
        'invitee_id',
        'scheduled_at',
        'place',
        'message',
        'status',
        'responded_at',
        'accepted_at',
        'completed_by_from_user_at',
        'completed_by_to_user_at',
        'completed_at',
        'completion_post_id',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'responded_at' => 'datetime',
        'accepted_at' => 'datetime',
        'completed_by_from_user_at' => 'datetime',
        'completed_by_to_user_at' => 'datetime',
        'completed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function invitee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitee_id');
    }

    public function completionPost(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'completion_post_id');
    }

    public function rescheduleRequests(): HasMany
    {
        return $this->hasMany(P2PMeetingRescheduleRequest::class, 'p2p_meeting_request_id');
    }
}
