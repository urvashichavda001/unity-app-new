<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\BaseApiController;
use App\Http\Resources\FileResource;
use App\Models\File;
use App\Services\Media\FileUploadService;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Exceptions\MediaProcessingException;
use Illuminate\Support\Facades\Log;

class FileController extends BaseApiController
{
    public function __construct(
        private readonly FileUploadService $fileUploadService
    ) {
    }

    /**
     * Serve a file by its UUID.
     */
    public function show(Request $request, string $id)
    {
        try {
            $file = File::find($id);

            if (!$file) {
                Log::warning("File API lookup failed: Database record not found for UUID: {$id}", [
                    'uuid' => $id,
                    'ip' => $request->ip(),
                    'user_id' => auth()->id() ?? auth('admin')->id(),
                ]);
                abort(404, 'File not found');
            }

            $disk = config('filesystems.default', 'public');

            if (!$file->s3_key || !Storage::disk($disk)->exists($file->s3_key)) {
                if (!$file->is_orphaned) {
                    $file->is_orphaned = true;
                    $file->save();
                }

                Log::warning("File API lookup failed: Physical file missing in storage for UUID: {$id}", [
                    'uuid' => $id,
                    's3_key' => $file->s3_key,
                    'disk' => $disk,
                    'ip' => $request->ip(),
                    'user_id' => auth()->id() ?? auth('admin')->id(),
                ]);
                abort(404, 'File not found');
            }

            $mime = $file->mime_type
                ?: Storage::disk($disk)->mimeType($file->s3_key)
                ?: 'application/octet-stream';

            if ($request->isMethod('HEAD')) {
                return response('', 200, [
                    'Content-Type'  => $mime,
                    'Content-Length' => $file->size_bytes ?: Storage::disk($disk)->size($file->s3_key),
                    'Cache-Control' => 'public, max-age=31536000',
                ]);
            }

            return Storage::disk($disk)->response(
                $file->s3_key,
                null,
                [
                    'Content-Type'  => $mime,
                    'Cache-Control' => 'public, max-age=31536000',
                ]
            );
        } catch (\Throwable $e) {
            Log::error("File API error for UUID {$id}: " . $e->getMessage(), [
                'uuid' => $id,
                'exception' => $e,
            ]);
            throw $e;
        }
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
            $model = $this->fileUploadService->store($file, $request->user());
        } catch (MediaProcessingException $e) {
            return $this->error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Log::error('File upload failed', ['error' => $e->getMessage()]);

            return $this->error('File upload failed. Please try again.', 500);
        }

        return new FileResource($model);
    }

}
