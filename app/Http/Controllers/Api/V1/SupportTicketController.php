<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Mail\SupportTicketResolvedMail;
use App\Mail\SupportTicketSubmittedMail;
use App\Models\FileModel;
use App\Models\SupportTicket;
use App\Services\EmailLogs\EmailLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SupportTicketController extends BaseApiController
{
    public function __construct(private readonly EmailLogService $emailLogService)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'contact_name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:150'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'media' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,mp4,mov,avi,webm', 'max:51200'],
        ]);

        $mediaPayload = $this->uploadSupportMedia($request);

        $ticket = new SupportTicket($validated);
        $ticket->user_id = optional($request->user())->id;
        $ticket->status = 'open';
        $ticket->priority = 'normal';
        $ticket->ticket_number = $this->generateTicketNumber();
        $ticket->media_file_id = $mediaPayload['file_id'] ?? null;
        $ticket->media_type = $mediaPayload['type'] ?? null;
        $ticket->media_url = $mediaPayload['url'] ?? null;
        $ticket->save();

        $this->sendConfirmationEmail($ticket);

        return $this->success($ticket, 'Support ticket submitted successfully.', 201);
    }

    public function myTickets(Request $request): JsonResponse
    {
        $tickets = SupportTicket::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->success($tickets, 'My support tickets fetched successfully.');
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with('user');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $request->string('search')->toString()) . '%';
            $query->where(function ($q) use ($term): void {
                $q->where('ticket_number', 'ILIKE', $term)
                    ->orWhere('contact_name', 'ILIKE', $term)
                    ->orWhere('email', 'ILIKE', $term)
                    ->orWhere('subject', 'ILIKE', $term);
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->string('date_to')->toString());
        }

        return $this->success($query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 20)));
    }

    public function adminShow(string $id): JsonResponse
    {
        return $this->success(SupportTicket::query()->with('user')->findOrFail($id));
    }

    public function adminUpdate(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:open,in_progress,resolved,closed'],
            'priority' => ['sometimes', 'string', 'in:low,normal,high,urgent'],
            'admin_note' => ['sometimes', 'nullable', 'string'],
        ]);

        $ticket = SupportTicket::query()->findOrFail($id);
        $previousStatus = $ticket->status;
        $ticket->fill($validated);

        if (array_key_exists('status', $validated)) {
            if ($validated['status'] === 'resolved') {
                $ticket->resolved_at = now();
            } elseif ($previousStatus === 'resolved') {
                $ticket->resolved_at = null;
            }
        }

        $shouldSendResolvedEmail = $previousStatus !== 'resolved'
            && (($validated['status'] ?? $ticket->status) === 'resolved');

        $ticket->save();

        if ($shouldSendResolvedEmail) {
            $this->sendResolvedEmail($ticket);
        }

        return $this->success($ticket->fresh('user'), 'Support ticket updated successfully.');
    }


    private function uploadSupportMedia(Request $request): ?array
    {
        if (! $request->hasFile('media')) {
            return null;
        }

        $media = $request->file('media');

        if (! $media instanceof UploadedFile || ! $media->isValid()) {
            abort(response()->json([
                'success' => false,
                'message' => 'Invalid media file uploaded.',
                'errors' => null,
            ], 422));
        }

        try {
            $disk = config('filesystems.default', 'public');
            $path = $media->store('uploads/' . now()->format('Y/m/d'), $disk);

            $file = FileModel::create([
                'uploader_user_id' => optional($request->user())->id,
                's3_key' => $path,
                'mime_type' => $media->getClientMimeType(),
                'size_bytes' => $media->getSize(),
            ]);

            $mime = (string) $media->getClientMimeType();
            $mediaType = str_starts_with($mime, 'image/') ? 'image' : 'video';
            $baseUrl = rtrim((string) config('app.url'), '/');

            return [
                'file_id' => (string) $file->id,
                'type' => $mediaType,
                'url' => $baseUrl . '/api/v1/files/' . $file->id,
            ];
        } catch (\Throwable $e) {
            Log::error('Support media upload failed.', [
                'user_id' => optional($request->user())->id,
                'message' => $e->getMessage(),
            ]);

            abort(response()->json([
                'success' => false,
                'message' => 'Media upload failed. Please try again.',
                'errors' => null,
            ], 422));
        }
    }

    private function generateTicketNumber(): string
    {
        $datePart = now()->format('Ymd');
        $prefix = 'SUP-' . $datePart . '-';

        $latestTicket = SupportTicket::query()
            ->where('ticket_number', 'like', $prefix . '%')
            ->orderByDesc('ticket_number')
            ->first();

        $nextNumber = 1;
        if ($latestTicket) {
            $parts = explode('-', $latestTicket->ticket_number);
            $nextNumber = ((int) end($parts)) + 1;
        }

        return $prefix . str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }

    private function sendResolvedEmail(SupportTicket $ticket): void
    {
        $mail = new SupportTicketResolvedMail($ticket);

        try {
            Mail::to($ticket->email)->send($mail);
            $this->emailLogService->logMailableSent($mail, [
                'to_email' => $ticket->email,
                'to_name' => $ticket->contact_name,
                'template_key' => 'support_ticket_resolved',
                'source_module' => 'support',
                'related_type' => 'support_ticket',
                'related_id' => $ticket->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send support ticket resolved email.', [
                'ticket_id' => $ticket->id,
                'message' => $e->getMessage(),
            ]);

            $this->emailLogService->logMailableFailed($mail, [
                'to_email' => $ticket->email,
                'to_name' => $ticket->contact_name,
                'template_key' => 'support_ticket_resolved',
                'source_module' => 'support',
                'related_type' => 'support_ticket',
                'related_id' => $ticket->id,
            ], $e);
        }
    }

    private function sendConfirmationEmail(SupportTicket $ticket): void
    {
        $mail = new SupportTicketSubmittedMail($ticket);

        try {
            Mail::to($ticket->email)->send($mail);
            $this->emailLogService->logMailableSent($mail, [
                'to_email' => $ticket->email,
                'to_name' => $ticket->contact_name,
                'template_key' => 'support_ticket_submitted',
                'source_module' => 'support',
                'related_type' => 'support_ticket',
                'related_id' => $ticket->id,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send support ticket confirmation email.', [
                'ticket_id' => $ticket->id,
                'message' => $e->getMessage(),
            ]);

            $this->emailLogService->logMailableFailed($mail, [
                'to_email' => $ticket->email,
                'to_name' => $ticket->contact_name,
                'template_key' => 'support_ticket_submitted',
                'source_module' => 'support',
                'related_type' => 'support_ticket',
                'related_id' => $ticket->id,
            ], $e);
        }
    }
}
