<?php

namespace App\Services\Leadership;

use App\Models\Circle;
use App\Models\CircleMember;
use App\Models\LeadershipGroupMessage;
use App\Models\User;
use App\Services\Notifications\NotifyUserService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class LeadershipGroupChatService
{
    public function __construct(private readonly NotifyUserService $notifyUserService)
    {
    }

    private const CHAT_ALLOWED_ROLES = [
        'founder',
        'director',
        'chair',
        'vice_chair',
        'secretary',
    ];

    private const ROLE_TITLES = [
        'founder' => 'Founder',
        'director' => 'Director',
        'chair' => 'Chair',
        'vice_chair' => 'Vice Chair',
        'secretary' => 'Secretary',
    ];

    public function deleteForMe(Circle $circle, User $user, LeadershipGroupMessage $message): string
    {
        if (! $this->ensureUserCanAccessCircleLeadershipChat($user, $circle)) {
            throw new HttpException(403, 'Forbidden.');
        }

        if ((string) $message->circle_id !== (string) $circle->id) {
            throw new HttpException(404, 'Message not found.');
        }

        if ($message->deleted_at !== null) {
            throw new HttpException(422, 'Message already deleted for everyone.');
        }

        $now = now();

        DB::table('leadership_group_message_deletions')->upsert(
            [[
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'message_id' => $message->id,
                'user_id' => $user->id,
                'deleted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['message_id', 'user_id'],
            ['deleted_at', 'updated_at']
        );

        return (string) $message->id;
    }

    public function deleteForEveryone(Circle $circle, User $user, LeadershipGroupMessage $message): string
    {
        if (! $this->ensureUserCanAccessCircleLeadershipChat($user, $circle)) {
            throw new HttpException(403, 'Forbidden.');
        }

        if ((string) $message->circle_id !== (string) $circle->id) {
            throw new HttpException(404, 'Message not found.');
        }

        if ((string) $message->sender_user_id !== (string) $user->id) {
            throw new HttpException(403, 'Only sender can delete this message for everyone.');
        }

        $message->forceFill([
            'deleted_at' => now(),
            'updated_at' => now(),
        ])->save();

        return (string) $message->id;
    }

    public function markMessagesRead(Circle $circle, User $user, array $messageIds): ?int
    {
        if (! $this->ensureUserCanAccessCircleLeadershipChat($user, $circle)) {
            return null;
        }

        $validMessageIds = LeadershipGroupMessage::query()
            ->where('circle_id', $circle->id)
            ->whereNull('deleted_at')
            ->where('sender_user_id', '!=', $user->id)
            ->whereDoesntHave('deletions', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->whereIn('id', $messageIds)
            ->pluck('id')
            ->all();

        if (empty($validMessageIds)) {
            return 0;
        }

        $now = now();
        $rows = collect($validMessageIds)
            ->map(function (string $messageId) use ($user, $now): array {
                return [
                    'message_id' => $messageId,
                    'user_id' => $user->id,
                    'read_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->all();

        DB::table('leadership_group_message_reads')->upsert(
            $rows,
            ['message_id', 'user_id'],
            ['read_at', 'updated_at']
        );

        return count($validMessageIds);
    }

    public function getMessages(Circle $circle, User $user, int $perPage = 20): ?LengthAwarePaginator
    {
        if (! $this->ensureUserCanAccessCircleLeadershipChat($user, $circle)) {
            return null;
        }

        $perPage = max(1, min($perPage, 100));

        $paginator = LeadershipGroupMessage::query()
            ->where('circle_id', $circle->id)
            ->whereNull('deleted_at')
            ->whereDoesntHave('deletions', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->with([
                'sender',
                'reads' => function ($query) use ($user): void {
                    $query->where('user_id', $user->id);
                },
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        $replyIds = collect($paginator->items())
            ->pluck('reply_to_message_id')
            ->filter()
            ->unique()
            ->values();

        $replyMessages = $replyIds->isEmpty()
            ? collect()
            : LeadershipGroupMessage::query()
                ->where('circle_id', $circle->id)
                ->whereNull('deleted_at')
                ->whereIn('id', $replyIds)
                ->with('sender')
                ->get()
                ->keyBy('id');

        foreach ($paginator->items() as $message) {
            $message->setRelation('replyTo', $replyMessages->get($message->reply_to_message_id));
        }

        return $paginator;
    }

    public function sendMessage(Circle $circle, User $user, array $data, ?UploadedFile $attachment): ?LeadershipGroupMessage
    {
        if (! $this->ensureUserCanAccessCircleLeadershipChat($user, $circle)) {
            return null;
        }

        if (! empty($data['reply_to_message_id'])) {
            $isValidReplyMessage = LeadershipGroupMessage::query()
                ->where('id', $data['reply_to_message_id'])
                ->where('circle_id', $circle->id)
                ->whereNull('deleted_at')
                ->exists();

            if (! $isValidReplyMessage) {
                throw new HttpException(422, 'The reply message must belong to this circle.');
            }
        }

        $message = DB::transaction(function () use ($circle, $user, $data, $attachment): LeadershipGroupMessage {
            $attachmentPayload = null;

            if ($attachment) {
                $attachmentPayload = $this->storeAttachment($attachment);
            }

            $messageType = (string) ($data['message_type'] ?? $this->resolveMessageType($attachment));

            return LeadershipGroupMessage::query()->create([
                'circle_id' => $circle->id,
                'sender_user_id' => $user->id,
                'message_type' => $messageType,
                'message_text' => $data['message_text'] ?? null,
                'reply_to_message_id' => $data['reply_to_message_id'] ?? null,
                'meta' => [
                    'attachment' => $attachmentPayload,
                ],
            ]);
        });

        $message->load('sender');

        if (! empty($message->reply_to_message_id)) {
            $replyMessage = LeadershipGroupMessage::query()
                ->where('circle_id', $circle->id)
                ->whereNull('deleted_at')
                ->where('id', $message->reply_to_message_id)
                ->with('sender')
                ->first();

            $message->setRelation('replyTo', $replyMessage);
        }
        $this->notifyLeadershipParticipants($circle, $user, $message);

        return $message;
    }

    public function getMembersPayload(Circle $circle, User $user): ?array
    {
        if (! $this->ensureUserCanAccessCircleLeadershipChat($user, $circle)) {
            return null;
        }

        $members = $this->getLeadershipChatParticipants($circle);

        $totalMessages = LeadershipGroupMessage::query()
            ->where('circle_id', $circle->id)
            ->whereNull('deleted_at')
            ->count();

        $unreadCount = LeadershipGroupMessage::query()
            ->where('circle_id', $circle->id)
            ->whereNull('deleted_at')
            ->whereDoesntHave('deletions', function ($query) use ($user): void {
                $query->where('user_id', $user->id);
            })
            ->where('sender_user_id', '!=', $user->id)
            ->whereNotExists(function (Builder $query) use ($user): void {
                $query->selectRaw('1')
                    ->from('leadership_group_message_reads')
                    ->whereColumn('leadership_group_message_reads.message_id', 'leadership_group_messages.id')
                    ->where('leadership_group_message_reads.user_id', $user->id);
            })
            ->count();

        return [
            'circle' => [
                'id' => $circle->id,
                'name' => $circle->name,
                'slug' => $circle->slug,
            ],
            'chat' => [
                'type' => 'leadership_group',
                'circle_id' => $circle->id,
                'total_members' => $members->count(),
                'total_messages' => $totalMessages,
                'unread_count' => $unreadCount,
            ],
            'current_user' => [
                'id' => $user->id,
                'is_leadership_member' => true,
                'can_send_message' => true,
            ],
            'members' => $members,
        ];
    }

    public function getLeadershipChatParticipants(Circle $circle): Collection
    {
        $participants = collect();

        if (! blank($circle->founder_user_id)) {
            $participants->push([
                'user_id' => (string) $circle->founder_user_id,
                'leader_role' => 'founder',
            ]);
        }

        if (! blank($circle->director_user_id)) {
            $participants->push([
                'user_id' => (string) $circle->director_user_id,
                'leader_role' => 'director',
            ]);
        }

        $roleMembers = CircleMember::query()
            ->where('circle_id', $circle->id)
            ->whereIn('role', ['chair', 'vice_chair', 'secretary'])
            ->whereNull('deleted_at')
            ->whereNull('left_at')
            ->where(function ($query): void {
                $query->whereNull('status')
                    ->orWhereIn('status', CircleMember::activeStatuses());
            })
            ->orderByRaw("CASE role
                WHEN 'chair' THEN 1
                WHEN 'vice_chair' THEN 2
                WHEN 'secretary' THEN 3
                ELSE 4
            END")
            ->orderBy('created_at')
            ->get(['user_id', 'role']);

        foreach ($roleMembers as $member) {
            $participants->push([
                'user_id' => (string) $member->user_id,
                'leader_role' => (string) $member->role,
            ]);
        }

        $seenUserIds = [];
        $uniqueParticipants = $participants->filter(function (array $participant) use (&$seenUserIds): bool {
            $userId = (string) $participant['user_id'];

            if (isset($seenUserIds[$userId])) {
                return false;
            }

            $seenUserIds[$userId] = true;

            return true;
        })->values();

        $users = User::query()
            ->whereIn('id', $uniqueParticipants->pluck('user_id')->all())
            ->get()
            ->keyBy('id');

        return $uniqueParticipants
            ->map(function (array $participant) use ($users, $circle): ?array {
                $userId = (string) $participant['user_id'];
                $user = $users->get($userId);

                if (! $user) {
                    return null;
                }

                $role = (string) $participant['leader_role'];

                return [
                    'id' => $circle->id . ':' . $role . ':' . $userId,
                    'user_id' => $userId,
                    'leader_role' => $role,
                    'title' => self::ROLE_TITLES[$role] ?? ucfirst(str_replace('_', ' ', $role)),
                    'user' => $user,
                ];
            })
            ->filter()
            ->values();
    }


    private function storeAttachment(UploadedFile $file): array
    {
        $disk = config('filesystems.default', 'public');
        $folder = 'uploads/circle-chat/' . now()->format('Y/m/d');
        $safeName = preg_replace('/[^A-Za-z0-9\.\-_]/', '_', $file->getClientOriginalName()) ?: 'attachment';
        $storedPath = $file->storeAs($folder, (string) Str::uuid() . '_' . $safeName, $disk);

        return [
            'id' => null,
            'type' => $this->resolveMessageType($file),
            'url' => Storage::disk($disk)->url($storedPath),
            'mime' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size' => $file->getSize(),
            'name' => $file->getClientOriginalName(),
            'path' => $storedPath,
        ];
    }

    private function resolveMessageType(?UploadedFile $attachment): string
    {
        if (! $attachment) {
            return 'text';
        }

        $mime = (string) ($attachment->getMimeType() ?: $attachment->getClientMimeType());

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }

        return 'file';
    }

    private function ensureUserCanAccessCircleLeadershipChat(User $user, Circle $circle): bool
    {
        return $this->getLeadershipChatParticipants($circle)
            ->contains(fn (array $participant): bool => (string) $participant['user_id'] === (string) $user->id);
    }

    private function notifyLeadershipParticipants(Circle $circle, User $sender, LeadershipGroupMessage $message): void
    {
        $participants = $this->getLeadershipChatParticipants($circle);
        $senderId = (string) $sender->id;
        $senderName = trim((string) ($sender->display_name ?: trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? ''))));
        $senderName = $senderName !== '' ? $senderName : 'Peer';
        $preview = Str::limit((string) $message->message_text, 120);
        $body = $senderName . ': ' . $preview;

        $data = [
            'title' => 'New leadership group message',
            'body' => $body,
            'chat_type' => 'leadership_group',
            'circle_id' => (string) $circle->id,
            'circle_name' => (string) $circle->name,
            'message_id' => (string) $message->id,
            'sender_user_id' => $senderId,
        ];

        foreach ($participants as $participant) {
            $recipient = data_get($participant, 'user');

            if (! $recipient instanceof User) {
                continue;
            }

            if ((string) $recipient->id === $senderId) {
                continue;
            }

            $this->notifyUserService->notifyUser(
                $recipient,
                $sender,
                'new_message',
                $data,
                $message
            );
        }
    }
}
