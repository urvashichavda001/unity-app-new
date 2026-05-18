<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class CampaignPamphlet extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'campaign_pamphlets';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'title',
        'content',
        'short_message',
        'image_file_id',
        'image_url',
        'status',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CampaignPamphlet $pamphlet): void {
            if (empty($pamphlet->id)) {
                $pamphlet->id = (string) Str::uuid();
            }
        });
    }

    public function snapshot(): array
    {
        return [
            'id' => (string) $this->id,
            'title' => $this->title,
            'content' => $this->content,
            'short_message' => $this->short_message,
            'image_url' => $this->image_url,
        ];
    }

    public function toSelectArray(): array
    {
        return $this->snapshot();
    }
}
