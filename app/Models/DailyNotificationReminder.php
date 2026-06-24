<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DailyNotificationReminder extends Model
{
    use HasFactory;

    protected $table = 'daily_notifications_reminder';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'feature',
        'activity',
        'notification_title',
        'notification_body',
        'action_trigger_timing',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }
}
