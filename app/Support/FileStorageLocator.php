<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

class FileStorageLocator
{
    public static function locate($file): ?array
    {
        if (! $file || ! is_string($file->s3_key ?? null) || trim($file->s3_key) === '') {
            return null;
        }

        foreach (self::diskCandidates() as $disk) {
            foreach (self::pathCandidates($file->s3_key) as $path) {
                try {
                    if (Storage::disk($disk)->exists($path)) {
                        return [
                            'disk' => $disk,
                            'path' => $path,
                        ];
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    private static function diskCandidates(): array
    {
        $configuredDisks = array_keys((array) config('filesystems.disks', []));
        $preferredDisks = array_filter([
            (string) config('filesystems.default', 'public'),
            'public',
            'local',
        ]);

        return array_values(array_unique(array_filter(
            $preferredDisks,
            fn (string $disk): bool => in_array($disk, $configuredDisks, true)
        )));
    }

    private static function pathCandidates(string $storedPath): array
    {
        $storedPath = trim($storedPath);
        $candidatePaths = [$storedPath];

        $urlPath = parse_url($storedPath, PHP_URL_PATH);
        if (is_string($urlPath) && $urlPath !== '') {
            $candidatePaths[] = $urlPath;
        }

        foreach ($candidatePaths as $path) {
            $normalized = ltrim($path, '/');

            if (str_starts_with($normalized, 'public/storage/')) {
                $candidatePaths[] = substr($normalized, strlen('public/storage/'));
            }

            if (str_starts_with($normalized, 'storage/')) {
                $candidatePaths[] = substr($normalized, strlen('storage/'));
            }

            if (str_starts_with($normalized, 'public/')) {
                $candidatePaths[] = substr($normalized, strlen('public/'));
            } else {
                $candidatePaths[] = 'public/' . $normalized;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($path): string => ltrim((string) $path, '/'),
            $candidatePaths
        ))));
    }
}
