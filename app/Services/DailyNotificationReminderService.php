<?php

namespace App\Services;

use App\Models\DailyNotificationReminder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DailyNotificationReminderService
{
    /**
     * Get all daily notification reminders.
     */
    public function getAllReminders(): Collection
    {
        return DailyNotificationReminder::query()
            ->orderBy('feature')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Update a specific daily notification reminder inside a database transaction.
     */
    public function updateReminder(string $id, array $data): DailyNotificationReminder
    {
        return DB::transaction(function () use ($id, $data) {
            $reminder = DailyNotificationReminder::query()->findOrFail($id);
            $reminder->update($data);
            return $reminder;
        });
    }
}
