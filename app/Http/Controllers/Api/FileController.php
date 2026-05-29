<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\MediaProcessingException;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Models\FileModel;
use App\Services\Media\MediaProcessor;
use App\Support\FileStorageLocator;
use App\Support\Media\Probe;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FileController extends BaseApiController
{
    public function __construct(
        private readonly MediaProcessor $mediaProcessor,
        private readonly Probe $probe
    ) {
    }

    /**
     * Serve a file by its UUID.
     */
    public function show(string $id)
    {
        $file = File::query()->find($id);

        if (! $file) {
            throw new NotFoundHttpException('File not found.');
        }

        $location = FileStorageLocator::locate($file);

        if (! $location) {
            throw new NotFoundHttpException('File record exists, but the uploaded file is missing from storage.');
        }

        $storage = Storage::disk($location['disk']);
        $path = $location['path'];
        $mime = $file->mime_type
            ?: $storage->mimeType($path)
            ?: 'application/octet-stream';

        return $storage->response(
            $path,
            null,
            [
                'Content-Type'  => $mime,
                'Cache-Control' => 'public, max-age=31536000',
            ]
        );
    }

    public function upload(Request $request)
    {
        $filesInput = $request->file('file');

        if (is_array($filesInput)) {
            $request->validate([
                'file' => ['required', 'array'],
                'file.*' => ['file', 'max:51200'],
            ]);

            $uploaded = [];

            foreach ($filesInput as $file) {
                if (! $file instanceof UploadedFile || ! $file->isValid()) {
                    continue;
                }

                $result = $this->processSingleUpload($file, $request);

                if ($result instanceof \Illuminate\Http\JsonResponse) {
                    return $result;
                }

                $uploaded[] = $result;
            }

            return $this->success($uploaded, 'Files uploaded successfully.', 201);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:51200'],
        ]);

        if (! $filesInput instanceof UploadedFile) {
            return $this->error('Invalid file uploaded.', 422);
        }

        $resource = $this->processSingleUpload($filesInput, $request);

        return $this->success($resource, 'File uploaded successfully', 201);
    }

    private function processSingleUpload(UploadedFile $file, Request $request)
    {
        try {
            $model = $this->storeUploadedFile($file, $request->user());
        } catch (MediaProcessingException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('File upload failed', ['error' => $e->getMessage()]);

            return $this->error('File upload failed. Please try again.', 500);
        }

        return new FileResource($model);
    }

    private function storeUploadedFile(UploadedFile $file, $user): FileModel
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

            $finalPath = $this->storeOptimized($optimizedTemp, $disk);

            $model = new FileModel();
            $model->uploader_user_id = $user ? $user->id : null;
            $model->s3_key = $finalPath;
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
