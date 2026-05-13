# App Update Reminder Notifications

The `app:update-reminder-notifications` command sends an app update reminder push notification to each saved device token whose recorded `app_version` is older than the active backend app version returned by `GET /api/v1/app/version`.

The scheduler runs the command hourly, while the command enforces a per-device 14-hour cooldown using `user_push_tokens.last_update_notification_sent_at`.

## Local testing checklist

1. Set the backend app latest version to `1.7.2` through the existing admin app version API.
2. Save an authenticated user push token with `app_version` set to `1.7.0`:
   ```http
   POST /api/v1/user/push-token
   {
     "fcm_token": "DEVICE_TOKEN",
     "platform": "android",
     "device_id": "optional-device-id",
     "app_version": "1.7.0"
   }
   ```
3. Run:
   ```bash
   php artisan app:update-reminder-notifications
   ```
4. Confirm the `notifications` table has an `app_update` row for the user.
5. Confirm `user_push_tokens.last_update_notification_sent_at` is updated for the token.
6. Run the command again immediately and confirm a duplicate notification is not sent because the token is still inside the 14-hour cooldown.
7. Change `last_update_notification_sent_at` to more than 15 hours ago and run the command again; another notification should be sent.
8. Set the token `app_version` to `1.7.2` and run the command again; no update reminder should be sent.
9. Set the token `app_version` to `null` and run the command again; no update reminder should be sent because the backend cannot confirm the installed app is outdated.

## Payload notes

All push notifications are sent through the global FCM service with top-level `notification`, string-only `data`, `apns`, and `android` blocks so existing Android behavior continues and iOS receives a proper APNS alert payload.
