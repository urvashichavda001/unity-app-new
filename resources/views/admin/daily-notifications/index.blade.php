@extends('admin.layouts.app')
@section('title', 'Daily Notification Reminders')

@section('content')
<div class="container-fluid">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1 text-gray-800">Daily Notification Reminders</h1>
            <p class="text-muted mb-0">Manage and edit the 24 daily engagement notifications and schedules sent to members.</p>
        </div>
    </div>

    <!-- Alert Messages -->
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle-fill me-2"></i>{{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>{{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endif

    <!-- Search Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-body">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="bi bi-search text-muted"></i>
                        </span>
                        <input type="text" id="tableSearch" class="form-control border-start-0 ps-0" placeholder="Search by feature, activity, title, body or timing...">
                    </div>
                </div>
                <div class="col-md-6 text-end">
                    <span class="badge bg-secondary py-2 px-3">Total Reminders: {{ $reminders->count() }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Data Table Card -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-striped mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th scope="col" class="ps-4" style="width: 15%;">Feature</th>
                            <th scope="col" style="width: 20%;">Activity</th>
                            <th scope="col" style="width: 30%;">Notification (Title & Body)</th>
                            <th scope="col" style="width: 15%;">Action / Trigger Timing</th>
                            <th scope="col" style="width: 10%;">Eligible Users</th>
                            <th scope="col" class="text-end pe-4" style="width: 10%;">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="reminderTableBody">
                        @forelse($reminders as $reminder)
                            <tr>
                                <td class="ps-4">
                                    <span class="badge bg-primary bg-opacity-10 text-primary px-3 py-2 fw-semibold">
                                        {{ $reminder->feature }}
                                    </span>
                                </td>
                                <td class="text-wrap">
                                    <div class="fw-medium text-dark">{{ $reminder->activity }}</div>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark mb-1">{{ $reminder->notification_title }}</div>
                                    <div class="text-muted small text-wrap" style="max-width: 450px;">
                                        {{ $reminder->notification_body }}
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <i class="bi bi-clock text-info me-2"></i>
                                        <span class="fw-semibold text-secondary">{{ $reminder->action_trigger_timing }}</span>
                                    </div>
                                </td>
                                <td>
                                    <button type="button" 
                                            class="btn btn-link p-0 border-0 fw-semibold view-eligible-users-btn"
                                            data-id="{{ $reminder->id }}"
                                            data-activity="{{ $reminder->activity }}"
                                            style="text-decoration: none;">
                                        <span class="badge bg-success bg-opacity-10 text-success px-3 py-2">
                                            <i class="bi bi-people-fill me-1"></i>{{ $counts[$reminder->activity] ?? 0 }}
                                        </span>
                                    </button>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="d-inline-flex gap-2">
                                        <form method="POST" action="{{ route('admin.daily-notifications.send', $reminder->id) }}" onsubmit="return confirm('Are you sure you want to send this notification to all eligible users immediately?');">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-success">
                                                <i class="bi bi-send-fill me-1"></i>Send
                                            </button>
                                        </form>
                                        <button type="button" 
                                                class="btn btn-sm btn-outline-primary edit-reminder-btn" 
                                                data-bs-toggle="modal" 
                                                data-bs-target="#editReminderModal"
                                                data-id="{{ $reminder->id }}"
                                                data-feature="{{ $reminder->feature }}"
                                                data-activity="{{ $reminder->activity }}"
                                                data-notification_title="{{ $reminder->notification_title }}"
                                                data-notification_body="{{ $reminder->notification_body }}"
                                                data-action_trigger_timing="{{ $reminder->action_trigger_timing }}">
                                            <i class="bi bi-pencil-square me-1"></i>Edit
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-5 text-muted">
                                    <i class="bi bi-info-circle display-4 mb-3 d-block text-gray-400"></i>
                                    No reminders found in database. Run the seeder to populate.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Edit Reminder Modal -->
<div class="modal fade" id="editReminderModal" tabindex="-1" aria-labelledby="editReminderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="editReminderModalLabel">
                    <i class="bi bi-pencil-square me-2"></i>Edit Notification Reminder
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="edit_reminder_form" method="POST" action="">
                @csrf
                @method('PUT')
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Feature -->
                        <div class="col-md-6">
                            <label for="edit_feature" class="form-label fw-semibold">Feature</label>
                            <input type="text" class="form-control @error('feature') is-invalid @enderror" id="edit_feature" name="feature" required>
                            @error('feature')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Action / Trigger Timing -->
                        <div class="col-md-6">
                            <label for="edit_action_trigger_timing" class="form-label fw-semibold">Action / Trigger Timing</label>
                            <input type="text" class="form-control @error('action_trigger_timing') is-invalid @enderror" id="edit_action_trigger_timing" name="action_trigger_timing" required>
                            @error('action_trigger_timing')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Activity -->
                        <div class="col-12">
                            <label for="edit_activity" class="form-label fw-semibold">Activity</label>
                            <textarea class="form-control @error('activity') is-invalid @enderror" id="edit_activity" name="activity" rows="2" required></textarea>
                            @error('activity')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Notification Title -->
                        <div class="col-12">
                            <label for="edit_notification_title" class="form-label fw-semibold">Notification Title</label>
                            <input type="text" class="form-control @error('notification_title') is-invalid @enderror" id="edit_notification_title" name="notification_title" required>
                            @error('notification_title')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <!-- Notification Body -->
                        <div class="col-12">
                            <label for="edit_notification_body" class="form-label fw-semibold">Notification Body</label>
                            <textarea class="form-control @error('notification_body') is-invalid @enderror" id="edit_notification_body" name="notification_body" rows="4" required></textarea>
                            @error('notification_body')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light p-3">
                    <button type="button" class="btn btn-outline-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary px-4">
                        <i class="bi bi-save me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Eligible Users Modal -->
<div class="modal fade" id="eligibleUsersModal" tabindex="-1" aria-labelledby="eligibleUsersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title" id="eligibleUsersModalLabel">
                    <i class="bi bi-people-fill me-2"></i>Eligible Users List (<span id="modalUsersCount">0</span>)
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="mb-3">
                    <span class="fw-semibold text-secondary">Activity: </span>
                    <span id="modalActivityName" class="text-dark"></span>
                </div>
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto !important;">
                    <table class="table table-hover align-middle">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Name</th>
                                <th>Company Name</th>
                                <th>City</th>
                                <th>Business Category</th>
                            </tr>
                        </thead>
                        <tbody id="eligibleUsersTableBody">
                            <tr>
                                <td colspan="4" class="text-center py-4 text-muted">
                                    <div class="spinner-border spinner-border-sm text-success me-2" role="status"></div>
                                    Loading eligible users...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer bg-light p-3">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Table Search Logic
        const searchInput = document.getElementById('tableSearch');
        if (searchInput) {
            searchInput.addEventListener('keyup', function() {
                const query = this.value.toLowerCase();
                const rows = document.querySelectorAll('#reminderTableBody tr');
                
                rows.forEach(row => {
                    // Ignore empty state row
                    if (row.querySelector('td[colspan]')) return;
                    
                    const text = row.textContent.toLowerCase();
                    row.style.display = text.includes(query) ? '' : 'none';
                });
            });
        }

        // Modal Data Prefill Logic
        const editButtons = document.querySelectorAll('.edit-reminder-btn');
        const editForm = document.getElementById('edit_reminder_form');
        
        editButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-id');
                const feature = btn.getAttribute('data-feature');
                const activity = btn.getAttribute('data-activity');
                const title = btn.getAttribute('data-notification_title');
                const body = btn.getAttribute('data-notification_body');
                const timing = btn.getAttribute('data-action_trigger_timing');

                if (editForm) {
                    editForm.action = `/admin/daily-notifications/${id}`;
                }
                
                document.getElementById('edit_feature').value = feature;
                document.getElementById('edit_activity').value = activity;
                document.getElementById('edit_notification_title').value = title;
                document.getElementById('edit_notification_body').value = body;
                document.getElementById('edit_action_trigger_timing').value = timing;
            });
        });

        // View Eligible Users AJAX Modal Logic
        const viewUsersButtons = document.querySelectorAll('.view-eligible-users-btn');
        const eligibleUsersModal = new bootstrap.Modal(document.getElementById('eligibleUsersModal'));
        const usersTableBody = document.getElementById('eligibleUsersTableBody');
        const modalActivityName = document.getElementById('modalActivityName');
        const modalUsersCount = document.getElementById('modalUsersCount');

        viewUsersButtons.forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.getAttribute('data-id');
                const activity = btn.getAttribute('data-activity');
                
                modalActivityName.textContent = activity;
                modalUsersCount.textContent = '0';
                usersTableBody.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center py-4 text-muted">
                            <div class="spinner-border spinner-border-sm text-success me-2" role="status"></div>
                            Loading eligible users...
                        </td>
                    </tr>
                `;
                
                eligibleUsersModal.show();

                fetch(`/admin/daily-notifications/${id}/eligible-users`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.users.length > 0) {
                            modalUsersCount.textContent = data.users.length;
                            let rowsHtml = '';
                            data.users.forEach(user => {
                                rowsHtml += `
                                    <tr>
                                        <td><div class="fw-semibold text-dark">${user.name}</div></td>
                                        <td>${user.company_name}</td>
                                        <td><span class="badge bg-secondary bg-opacity-10 text-secondary">${user.city}</span></td>
                                        <td>${user.business_category}</td>
                                    </tr>
                                `;
                            });
                            usersTableBody.innerHTML = rowsHtml;
                        } else if (data.success) {
                            modalUsersCount.textContent = '0';
                            usersTableBody.innerHTML = `
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-muted">
                                        <i class="bi bi-info-circle me-1"></i>No users match this criteria currently.
                                    </td>
                                </tr>
                            `;
                        } else {
                            modalUsersCount.textContent = '0';
                            usersTableBody.innerHTML = `
                                <tr>
                                    <td colspan="4" class="text-center py-4 text-danger">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>${data.error || 'Failed to load users.'}
                                    </td>
                                </tr>
                            `;
                        }
                    })
                    .catch(error => {
                        modalUsersCount.textContent = '0';
                        usersTableBody.innerHTML = `
                            <tr>
                                <td colspan="4" class="text-center py-4 text-danger">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>Error fetching details.
                                </td>
                            </tr>
                        `;
                    });
            });
        });
    });
</script>
@endpush
@endsection
