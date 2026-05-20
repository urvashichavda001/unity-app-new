<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    use HasFactory;

    protected $table = 'support_tickets';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'ticket_number',
        'user_id',
        'contact_name',
        'email',
        'subject',
        'description',
        'media_file_id',
        'media_type',
        'media_url',
        'status',
        'priority',
        'admin_note',
        'resolved_at',
    ];

    protected $appends = ['media'];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }


    public function getMediaAttribute(): ?array
    {
        if (! $this->media_file_id || ! $this->media_url) {
            return null;
        }

        return [
            'file_id' => $this->media_file_id,
            'type' => $this->media_type,
            'url' => $this->media_url,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
