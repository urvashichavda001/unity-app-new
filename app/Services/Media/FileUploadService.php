<?php

namespace App\Services\Media;

use App\Exceptions\MediaProcessingException;
use App\Models\FileModel;
use App\Models\User;
use App\Support\Media\Probe;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FileUploadService
{
    public function __construct(
        private readonly MediaProcessor $mediaProcessor,
        private readonly Probe $probe
    ) {
    }

    public function store(UploadedFile $file, ?User $user = null): FileModel
    {
        $disk = config('filesystems.default', 'public');
        $tempOriginal = $this->storeTemporary($file);
        $optimizedTemp = null;
        $type = null;

        try {
            $detectedMimeType = $this->probe->mimeType($tempOriginal);
            $clientMimeType = $file->getClientMimeType();
            $mimeType = $detectedMimeType ?: $clientMimeType;

            if ($this->probe->isImageMime($detectedMimeType) || $this->probe->isImageMime($clientMimeType)) {
                $type = 'image';
                if (! $this->probe->imagickAvailable() && ! $this->probe->gdAvailable()) {
                    throw new MediaProcessingException('Image optimization requires GD or Imagick. Upload rejected.');
                }
            } elseif ($this->probe->isVideoMime($detectedMimeType) || $this->probe->isVideoMime($clientMimeType)) {
                $type = 'video';
                if (! $this->probe->ffmpegAvailable()) {
                    throw new MediaProcessingException('Video optimization requires FFmpeg. Upload rejected.');
                }
            } elseif ($this->probe->isPdfMime($detectedMimeType) || $this->probe->isPdfMime($clientMimeType)) {
                $type = 'document';
                $mimeType = 'application/pdf';
            }

            if (! $type) {
                throw new MediaProcessingException('Unsupported file type.');
            }

            $optimized = $this->mediaProcessor->optimize($tempOriginal, $type, $mimeType);
            $optimizedTemp = $optimized['path'];

            $model = new FileModel();
            $model->uploader_user_id = $user?->id;
            $model->s3_key = $this->storeOptimized($optimizedTemp, $disk);
            $model->mime_type = $optimized['mime_type'];
            $model->size_bytes = $optimized['size_bytes'];
            $model->width = $optimized['width'] ?? null;
            $model->height = $optimized['height'] ?? null;
            $model->duration = $optimized['duration'] ?? null;
            $model->save();

            return $model->refresh();
        } finally {
            $this->cleanupTemp($tempOriginal);
            if ($optimizedTemp && file_exists($optimizedTemp)) {
                @unlink($optimizedTemp);
            }
        }
    }

    public function delete(FileModel $file): void
    {
        $disk = config('filesystems.default', 'public');

        if ($file->s3_key) {
            Storage::disk($disk)->delete($file->s3_key);
        }

        $file->delete();
    }

    private function storeTemporary(UploadedFile $file): string
    {
        $tempDir = storage_path('app/tmp/uploads/' . now()->format('Y/m/d'));
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $filename = (string) Str::uuid() . '_' . preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $file->getClientOriginalName());
        $path = $tempDir . '/' . $filename;
        $file->move($tempDir, $filename);

        return $path;
    }

    private function storeOptimized(string $optimizedTempPath, string $disk): string
    {
        $folder = 'uploads/' . now()->format('Y/m/d');
        $extension = pathinfo($optimizedTempPath, PATHINFO_EXTENSION);
        $filename = (string) Str::uuid() . ($extension ? '.' . $extension : '');
        $finalPath = $folder . '/' . $filename;

        $stream = fopen($optimizedTempPath, 'r');
        $stored = Storage::disk($disk)->put($finalPath, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        if (! $stored) {
            throw new MediaProcessingException('Failed to store optimized file.');
        }

        return $finalPath;
    }

    private function cleanupTemp(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}
