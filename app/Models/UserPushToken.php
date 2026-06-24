<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPushToken extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'user_push_tokens';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'user_id',
        'usr_id',
        'token',
        'platform',
        'device_id',
        'app_version',
        'last_seen_at',
        'last_used_at',
        'is_active',
        'last_update_notification_sent_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
        'last_update_notification_sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public static function getUserIdColumn(): string
    {
        static $column = null;
        if ($column === null) {
            $column = \Illuminate\Support\Facades\Schema::hasColumn('user_push_tokens', 'usr_id') ? 'usr_id' : 'user_id';
        }
        return $column;
    }

    public function getUserIdAttribute()
    {
        $col = self::getUserIdColumn();
        return $this->attributes[$col] ?? null;
    }

    public function setUserIdAttribute($value)
    {
        $col = self::getUserIdColumn();
        $this->attributes[$col] = $value;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, self::getUserIdColumn());
    }
}
