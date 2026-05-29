<?php

namespace App\Support;

use App\Models\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaFileUrl
{
    public static function first($media): ?string
    {
        foreach (self::normalize($media) as $item) {
            $url = self::resolve($item);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public static function all($media): array
    {
        $urls = [];

        foreach (self::normalize($media) as $item) {
            $url = self::resolve($item);

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    public static function resolve($item): ?string
    {
        if (! $item) {
            return null;
        }

        if (is_object($item)) {
            $item = (array) $item;
        }

        if (is_array($item)) {
            foreach (['url', 'file_url', 'media_url', 'download_url', 'path_url'] as $key) {
                $url = self::directUrl($item[$key] ?? null);

                if ($url !== null) {
                    return $url;
                }
            }

            foreach (['file_id', 'fileId', 'uploaded_file_id', 'uploadedFileId', 'media_file_id', 'attachment_file_id', 'image_file_id', 'photo_file_id'] as $key) {
                $url = self::fileUrl($item[$key] ?? null);

                if ($url !== null) {
                    return $url;
                }
            }

            foreach (['file', 'media', 'attachment', 'image', 'photo'] as $key) {
                if (array_key_exists($key, $item)) {
                    $url = self::resolve($item[$key]);

                    if ($url !== null) {
                        return $url;
                    }
                }
            }

            return self::fileUrl($item['id'] ?? null) ?: self::pathUrl($item['path'] ?? $item['s3_key'] ?? null);
        }

        return self::directUrl($item) ?: self::fileUrl($item) ?: self::pathUrl($item);
    }

    public static function normalize($media): array
    {
        if (! $media) {
            return [];
        }

        if (is_string($media)) {
            $decoded = json_decode($media, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $media = $decoded;
            } else {
                return [$media];
            }
        }

        if (is_object($media)) {
            $media = (array) $media;
        }

        if (is_array($media)) {
            return array_is_list($media) ? $media : [$media];
        }

        return [$media];
    }

    private static function directUrl($value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
            return $value;
        }

        return null;
    }

    public static function fileUrl($value): ?string
    {
        if (! is_string($value) || ! Str::isUuid($value)) {
            return null;
        }

        $file = File::query()->find($value);

        if (! $file || ! FileStorageLocator::locate($file)) {
            return null;
        }

        return url('/api/v1/files/' . $value);
    }

    private static function pathUrl($value): ?string
    {
        if (! is_string($value) || trim($value) === '' || Str::isUuid($value)) {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '/')) {
            return $value;
        }

        return Storage::url($value);
    }
}
