<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventScannerAuthorization extends Model
{
    use HasFactory;
    use HasUuids;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'event_id',
        'scanner_user_id',
        'assigned_by_user_id',
        'status',
        'assigned_at',
        'revoked_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanner_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_user_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE)->whereNull('revoked_at');
    }
}
