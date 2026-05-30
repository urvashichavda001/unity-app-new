<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventAttendance extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_CHECKED_IN = 'checked_in';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'attendee_user_id',
        'event_registration_id',
        'qr_token',
        'checked_in_by_user_id',
        'checked_in_at',
        'status',
        'scan_meta',
    ];

    protected $casts = [
        'checked_in_at' => 'datetime',
        'scan_meta' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function attendee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'attendee_user_id');
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(EventRegistration::class, 'event_registration_id');
    }

    public function checkedInBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_in_by_user_id');
    }
}
