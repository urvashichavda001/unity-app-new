<?php

namespace App\Models;

use App\Support\MediaFileUrl;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventGalleryMedia extends Model
{
    use HasFactory;
    use HasUuids;

    protected $table = 'event_gallery_media';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'event_gallery_id',
        'media_type',
        'file_id',
        'thumbnail_file_id',
        'caption',
        'sort_order',
        'created_by_admin_id',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(EventGallery::class, 'event_gallery_id');
    }

    public function getUrlAttribute(): ?string
    {
        if (! $this->file_id) {
            return null;
        }

        return MediaFileUrl::fileUrl($this->file_id);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        if (! $this->thumbnail_file_id) {
            return null;
        }

        return MediaFileUrl::fileUrl($this->thumbnail_file_id);
    }
}
