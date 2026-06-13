<?php

namespace App\Http\Requests\Event;

use App\Models\Circle;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertAdminEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'circle_id' => [Rule::requiredIf(fn () => $this->input('event_type') === 'circle_meeting'), 'nullable', 'uuid', 'exists:circles,id'],
            'circle_ids' => [Rule::requiredIf(fn () => in_array($this->input('event_type'), ['global_event', 'state_event'], true)), 'array', 'min:1'],
            'circle_ids.*' => ['uuid', 'distinct', 'exists:circles,id'],
            'state_name' => [Rule::requiredIf(fn () => $this->input('event_type') === 'state_event'), 'nullable', 'string', 'max:255'],
            'district_id' => ['nullable', 'uuid'],
            'created_by_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'organizer_user_id' => ['nullable', 'uuid', 'exists:users,id'],
            'title' => [$this->isMethod('post') ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_at' => [$this->isMethod('post') ? 'required' : 'sometimes', 'date'],
            'end_at' => ['nullable', 'date', 'after:start_at'],
            'is_virtual' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', 'in:offline,online,hybrid'],
            'location_text' => ['nullable', 'string'],
            'online_meeting_url' => ['nullable', 'string', 'max:2000'],
            'zoho_form_url' => ['nullable', 'string', 'max:2000'],
            'visitor_registration_enabled' => ['sometimes', 'boolean'],
            'member_registration_enabled' => ['sometimes', 'boolean'],
            'agenda' => ['nullable'],
            'agenda.*.time' => ['nullable', 'string', 'max:100'],
            'agenda.*.title' => ['nullable', 'string', 'max:255'],
            'speakers' => ['nullable', 'array'],
            'speakers.*.name' => ['nullable', 'string', 'max:255'],
            'speakers.*.designation' => ['nullable', 'string', 'max:255'],
            'speakers.*.company' => ['nullable', 'string', 'max:255'],
            'speakers.*.initials' => ['nullable', 'string', 'max:20'],
            'speakers.*.photo_url' => ['nullable', 'string'],
            'banner_url' => ['nullable', 'string', 'max:2000'],
            'what_youll_gain' => ['nullable', 'array'],
            'what_youll_gain.*' => ['nullable', 'string', 'max:500'],
            'organizer_name' => ['nullable', 'string', 'max:255'],
            'organizer_phone' => ['nullable', 'string', 'max:50'],
            'organizer_email' => ['nullable', 'email', 'max:255'],
            'organizer_website' => ['nullable', 'string', 'max:255'],
            'visibility' => ['sometimes', 'string', 'in:public,circle,connections,private'],
            'is_paid' => ['sometimes', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'event_type' => ['sometimes', 'string', 'in:circle_meeting,global_event,state_event,public_event,public_visitor_event,training,training_workshop'],
            'event_category' => ['nullable', 'string', 'max:100'],
            'registration_limit' => ['nullable', 'integer', 'min:1'],
            'ticket_price' => ['nullable', 'numeric', 'min:0'],
            'revenue_target' => ['nullable', 'numeric', 'min:0'],
            'total_revenue' => ['nullable', 'numeric', 'min:0'],
            'total_expenses' => ['nullable', 'numeric', 'min:0'],
            'net_pnl' => ['nullable', 'numeric'],
            'qr_checkin_enabled' => ['sometimes', 'boolean'],
            'is_public' => ['sometimes', 'boolean'],
            'recurrence_type' => ['sometimes', 'string', 'in:none,weekly,monthly,yearly'],
            'recurrence_interval' => ['nullable', 'integer', 'min:1', 'max:24'],
            'recurrence_day_of_week' => ['nullable', 'integer', 'min:1', 'max:7'],
            'recurrence_week_of_month' => ['nullable', 'integer', 'min:1', 'max:5'],
            'recurrence_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'recurrence_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence_ends_at' => ['nullable', 'date', 'after:start_at'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if ($this->input('event_type') !== 'state_event' || ! is_array($this->input('circle_ids'))) {
                return;
            }

            $state = trim((string) $this->input('state_name'));
            if ($state === '') {
                return;
            }

            $circles = Circle::query()->with('cityRef')->whereIn('id', $this->input('circle_ids'))->get();
            foreach ($circles as $circle) {
                $circleState = $circle->state_name ?? $circle->state ?? $circle->cityRef?->state_name ?? $circle->cityRef?->state ?? null;
                if ($circleState && strcasecmp((string) $circleState, $state) !== 0) {
                    $validator->errors()->add('circle_ids', 'Selected circles must belong to the selected state.');
                    return;
                }
            }
        });
    }
}
