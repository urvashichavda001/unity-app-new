<?php

namespace App\Http\Controllers\Api;

use App\Mail\FeedbackSubmittedMail;
use App\Models\FeedbackCategory;
use App\Models\FeedbackForm;
use App\Models\FeedbackMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class FeedbackController extends BaseApiController
{
    public function categories(): JsonResponse
    {
        $categories = FeedbackCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        return $this->success($categories, 'Feedback categories fetched successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'uuid', 'exists:feedback_categories,id'],
            'question' => ['required', 'string'],
            'media' => ['nullable', 'array', 'max:5'],
            'media.*' => ['file', 'mimes:jpg,jpeg,png,webp,mp4,mov,avi,pdf,doc,docx', 'max:20480'],
        ]);

        $user = $request->user();
        $category = FeedbackCategory::query()->findOrFail($validated['category_id']);
        $mediaResponse = [];

        $feedback = DB::transaction(function () use ($validated, $user, $category, $request, &$mediaResponse) {
            $feedback = FeedbackForm::query()->create([
                'user_id' => $user?->id,
                'category_id' => $category->id,
                'category' => $category->name,
                'subject' => $validated['subject'],
                'question' => $validated['question'],
                'status' => 'submitted',
            ]);

            if ($request->hasFile('media')) {
                foreach ($request->file('media') as $file) {
                    $path = $file->store('feedback-media', 'public');
                    $mimeType = (string) $file->getMimeType();
                    $fileType = str_starts_with($mimeType, 'image/')
                        ? 'image'
                        : (str_starts_with($mimeType, 'video/') ? 'video' : 'file');

                    $media = FeedbackMedia::query()->create([
                        'feedback_form_id' => $feedback->id,
                        'file_path' => $path,
                        'file_url' => asset('storage/' . $path),
                        'file_type' => $fileType,
                        'mime_type' => $mimeType,
                        'original_name' => $file->getClientOriginalName(),
                        'file_size' => $file->getSize(),
                    ]);

                    $mediaResponse[] = [
                        'id' => $media->id,
                        'url' => $media->file_url,
                        'type' => $media->file_type,
                    ];
                }
            }

            return $feedback;
        });

        $feedback->load(['category', 'user']);

        $this->sendFeedbackEmails($feedback);

        return $this->success([
            'id' => $feedback->id,
            'subject' => $feedback->subject,
            'category' => $feedback->category,
            'question' => $feedback->question,
            'status' => $feedback->status,
            'media' => $mediaResponse,
            'created_at' => $feedback->created_at,
        ], 'Thank you for your feedback. Our team will review it and get back to you soon.', 201);
    }

    private function sendFeedbackEmails(FeedbackForm $feedbackForm): void
    {
        if ($feedbackForm->user?->email) {
            try {
                Mail::to($feedbackForm->user->email)->send(new FeedbackSubmittedMail($feedbackForm));
            } catch (\Throwable $e) {
                Log::error('Failed to send feedback thank-you email.', [
                    'feedback_form_id' => $feedbackForm->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $supportEmail = config('mail.support_email') ?: env('SUPPORT_EMAIL') ?: config('mail.from.address');

        if ($supportEmail) {
            try {
                Mail::to($supportEmail)->send(new FeedbackSubmittedMail($feedbackForm));
            } catch (\Throwable $e) {
                Log::error('Failed to send feedback admin notification email.', [
                    'feedback_form_id' => $feedbackForm->id,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }
}
