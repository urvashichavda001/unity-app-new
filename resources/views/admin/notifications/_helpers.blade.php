@once
    @php
        if (! function_exists('notification_admin_user_name')) {
            function notification_admin_user_name($user): string {
                if (! $user) { return 'Unknown User'; }
                $displayName = trim((string) ($user->display_name ?? '')) ?: trim(((string) ($user->first_name ?? '')).' '.((string) ($user->last_name ?? '')));
                return $displayName !== '' ? $displayName : (string) ($user->name ?? $user->email ?? $user->phone ?? 'Unknown User');
            }
        }
        if (! function_exists('notification_admin_status_badge')) {
            function notification_admin_status_badge($status): string {
                return match ((string) $status) { 'delivered', 'sent', 'completed' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'queued' => 'primary', 'pending', 'running', 'skipped' => 'secondary', default => 'info' };
            }
        }
        if (! function_exists('notification_admin_priority_badge')) {
            function notification_admin_priority_badge($priority): string {
                return match ((string) $priority) { 'urgent' => 'danger', 'high' => 'warning', 'medium' => 'primary', 'low' => 'secondary', default => 'secondary' };
            }
        }

        if (! function_exists('notification_admin_label')) {
            function notification_admin_label($value): string {
                if ($value === null || $value === '') { return '—'; }
                return \Illuminate\Support\Str::of((string) $value)->replace(['_', '-'], ' ')->title()->toString();
            }
        }
        if (! function_exists('notification_admin_channel_label')) {
            function notification_admin_channel_label($value): string {
                return match ((string) $value) { 'push' => 'Push', 'email' => 'Email', 'push_email', 'both' => 'Push + Email', 'in_app_only', 'in_app' => 'In-App', default => notification_admin_label($value) };
            }
        }
        if (! function_exists('notification_admin_error_summary')) {
            function notification_admin_error_summary($error): string {
                $error = (string) ($error ?: '—');
                $lower = strtolower($error);
                if (str_contains($lower, 'invalid') || str_contains($lower, 'unregistered')) { return 'Invalid or expired Firebase token. Ask user to reopen the app and allow notifications.'; }
                if (str_contains($lower, 'no valid firebase') || str_contains($lower, 'no valid') || str_contains($lower, 'no device token')) { return 'No push token found. User can still receive in-app notifications.'; }
                if (str_contains($lower, 'no active push token')) { return 'User has no active device available for push notification.'; }
                if (str_contains($lower, 'credential')) { return 'Firebase credentials missing or not readable.'; }
                return \Illuminate\Support\Str::limit($error, 90);
            }
        }
        if (! function_exists('notification_admin_json')) {
            function notification_admin_json($value): string {
                return json_encode($value ?: new stdClass(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            }
        }
    @endphp
@endonce
