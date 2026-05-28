<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistrationRequest extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'event_id','occurrence_id','user_id','user_circle_id','event_circle_id','status','request_reason','admin_note',
        'approved_by_user_id','approved_at','rejected_by_user_id','rejected_at','registration_id','metadata',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class, 'event_id');
    }

    public function occurrence(): BelongsTo
    {
        return $this->belongsTo(EventOccurrence::class, 'occurrence_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }


    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'registration_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by_user_id');
    }

}
