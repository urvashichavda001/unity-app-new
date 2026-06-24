<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDailyNotificationReminderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'feature' => ['required', 'string', 'max:255'],
            'activity' => ['required', 'string'],
            'notification_title' => ['required', 'string', 'max:255'],
            'notification_body' => ['required', 'string'],
            'action_trigger_timing' => ['required', 'string', 'max:255'],
        ];
    }
}
