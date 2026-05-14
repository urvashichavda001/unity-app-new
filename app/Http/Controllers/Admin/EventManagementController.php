<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Circle;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Services\Events\EventOccurrenceGeneratorService;
use App\Services\Events\EventService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class EventManagementController extends Controller
{
    public function __construct(
        private readonly EventOccurrenceGeneratorService $occurrences,
        private readonly EventService $events,
    ) {}

    public function index(Request $request): View
    {
        $events = Event::query()
            ->with('circle')
            ->withCount(['registrations as registered_count' => fn ($q) => $q->where('status', '!=', 'cancelled')])
            ->withCount(['registrations as checked_in_count' => fn ($q) => $q->where('checkin_status', 'checked_in')])
            ->when($request->event_type, fn ($q, $v) => $q->where('event_type', $v))
            ->when($request->circle_id, fn ($q, $v) => $q->where('circle_id', $v))
            ->when($request->mode, fn ($q, $v) => $q->where('mode', $v))
            ->when($request->date_from, fn ($q, $v) => $q->where('start_at', '>=', $v))
            ->when($request->date_to, fn ($q, $v) => $q->where('start_at', '<=', $v))
            ->when($request->search, fn ($q, $v) => $q->where('title', 'ilike', '%'.$v.'%'))
            ->latest('start_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.events.index', ['events' => $events, 'circles' => Circle::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function create(): View
    {
        return view('admin.events.create', ['circles' => Circle::query()->orderBy('name')->get(['id', 'name'])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $event = DB::transaction(function () use ($data): Event {
            $event = Event::query()->create($this->filterColumns($this->withDefaults($data)));
            $this->occurrences->generate($event);

            return $event;
        });

        return redirect()->route('admin.events.show', $event)->with('success', 'Event created successfully.');
    }

    public function show(string $id): View
    {
        $event = Event::query()->with(['circle', 'occurrences' => fn ($q) => $q->orderBy('start_at')])->findOrFail($id);

        return view('admin.events.show', compact('event'));
    }

    public function attendance(Request $request, string $id): View
    {
        $event = Event::query()->findOrFail($id);
        $report = $this->events->attendanceReport($event, $request->only(['occurrence_id', 'status', 'checkin_status', 'attendee_type', 'search']));

        return view('admin.events.attendance', compact('event', 'report'));
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'event_type' => ['required', 'string', 'in:circle_meeting,global_event,public_event'],
            'event_category' => ['nullable', 'string', 'max:100'],
            'circle_id' => ['nullable', 'uuid', 'exists:circles,id'],
            'mode' => ['required', 'string', 'in:offline,online,hybrid'],
            'location_text' => ['nullable', 'string'],
            'online_meeting_url' => ['nullable', 'string', 'max:2000'],
            'start_at' => ['required', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'recurrence_type' => ['required', 'string', 'in:none,weekly,monthly,yearly'],
            'recurrence_interval' => ['nullable', 'integer', 'min:1'],
            'recurrence_week_of_month' => ['nullable', 'integer', 'min:1', 'max:5'],
            'recurrence_day_of_week' => ['nullable', 'integer', 'min:0', 'max:6'],
            'recurrence_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
            'recurrence_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'recurrence_ends_at' => ['nullable', 'date'],
            'registration_limit' => ['nullable', 'integer', 'min:1'],
            'is_paid' => ['nullable', 'boolean'],
            'ticket_price' => ['nullable', 'numeric', 'min:0'],
            'qr_checkin_enabled' => ['nullable', 'boolean'],
            'visitor_registration_enabled' => ['nullable', 'boolean'],
            'member_registration_enabled' => ['nullable', 'boolean'],
            'zoho_form_url' => ['nullable', 'string', 'max:2000'],
        ]);
    }

    private function withDefaults(array $data): array
    {
        foreach (['is_paid', 'qr_checkin_enabled', 'visitor_registration_enabled', 'member_registration_enabled'] as $key) {
            $data[$key] = (bool) ($data[$key] ?? false);
        }
        $data['member_registration_enabled'] = (bool) ($data['member_registration_enabled'] ?? true);
        $data['visibility'] = 'public';
        $data['is_virtual'] = in_array($data['mode'] ?? 'offline', ['online', 'hybrid'], true);
        $data['is_public'] = ($data['event_type'] ?? null) === 'public_event';

        return $data;
    }

    private function filterColumns(array $data): array
    {
        return array_filter($data, fn ($value, $key) => Schema::hasColumn('events', $key), ARRAY_FILTER_USE_BOTH);
    }
}
