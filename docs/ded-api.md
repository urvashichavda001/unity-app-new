# DED REST API

Base URL: `{{base_url}}/api/v1/ded`

Use the DED OTP authentication endpoints to obtain `{{token}}`. These endpoints authenticate against `admin_users` and do not use `/api/v1/auth/login`, so existing user/password login remains unchanged.

Authentication headers for protected endpoints:

```http
Authorization: Bearer {{token}}
Accept: application/json
Content-Type: application/json
```

All protected endpoints require Sanctum authentication plus DED role validation. The API maps the authenticated user to `admin_users.email`, verifies the `ded` role, loads the assigned district, and returns `403` if the user is not DED or has no district assignment.

## Response Envelope

Success:

```json
{"success": true, "message": "...", "data": {}, "meta": {}}
```

Error:

```json
{"success": false, "message": "...", "errors": {}}
```

## Endpoints


### DED OTP Authentication
- `POST /auth/request-otp` body: `{"email":"dhruv99h@gmail.com"}` — verifies the email belongs to a DED admin, stores an OTP in the existing admin OTP table, and sends it using the existing mail flow.
- `POST /auth/verify-otp` body: `{"email":"dhruv99h@gmail.com","otp":"1234"}` — verifies the OTP, checks the DED role and assigned district, then returns a Sanctum token for the DED API.

Successful verify response:

```json
{
  "success": true,
  "message": "DED login successful.",
  "data": {
    "token": "SANCTUM_TOKEN",
    "admin": {
      "id": "...",
      "email": "dhruv99h@gmail.com",
      "role": "ded",
      "district": "Ahmedabad"
    }
  },
  "meta": {}
}
```

### Context and Dashboard
- `GET /me` — DED profile, assigned state/district, permissions, modules.
- `GET /dashboard?circle_id=&date_from=&date_to=` — district dashboard stats, optional circle/date filters.
- `GET /dashboard/circles?search=&status=&per_page=` — district circles for the dashboard circle filter.
- `GET /circles?search=&status=&per_page=` — backwards-compatible alias for dashboard circle options.

### Peers
- `GET /peers?search=&circle_id=&membership_status=&status=&per_page=` — district peers.
- `GET /peers/{id}` — district peer detail; outside-district IDs return `403`.

### Activities
- `GET /activities/summary?circle_id=&date_from=&date_to=&search=&per_page=` — peer activity summary and top five district peers.
- `GET /activities/testimonials?search=&circle_id=&date_from=&date_to=&has_media=&per_page=`
- `GET /activities/testimonials/{id}`
- `GET /activities/requirements?search=&circle_id=&status=&category=&date_from=&date_to=&per_page=`
- `GET /activities/requirements/{id}`
- `GET /activities/referrals?search=&circle_id=&referral_type=&date_from=&date_to=&per_page=`
- `GET /activities/referrals/{id}`
- `GET /activities/p2p-meetings?search=&circle_id=&date_from=&date_to=&per_page=`
- `GET /activities/p2p-meetings/{id}`
- `GET /activities/business-deals?search=&circle_id=&date_from=&date_to=&per_page=`
- `GET /activities/business-deals/{id}`
- `GET /activities/become-a-leader?search=&circle_id=&date_from=&date_to=&per_page=`
- `GET /activities/recommend-a-peer?search=&circle_id=&date_from=&date_to=&per_page=`
- `GET /activities/find-build-collaborations?search=&circle_id=&date_from=&date_to=&per_page=`
- `GET /activities/register-a-visitor?search=&circle_id=&date_from=&date_to=&per_page=`

### Referral Report
- `GET /referral-report?date_from=&date_to=&per_page=` — district referral report.

### Coins
- `GET /coins?search=&circle_id=&per_page=` — district coin balances.
- `GET /coins/history?user_id=&date_from=&date_to=&per_page=` — district coin ledger.

### Life Impact
- `GET /life-impact?search=&circle_id=&activity_type=&date_from=&date_to=&per_page=` — district life impact history.

### Pending Requests
- `GET /pending-requests/summary`
- `GET /pending-requests/visitor-registrations?search=&status=&date_from=&date_to=&per_page=`
- `GET /pending-requests/visitor-registrations/{id}`
- `POST /pending-requests/visitor-registrations/{id}/approve`
- `POST /pending-requests/visitor-registrations/{id}/reject`
- `GET /pending-requests/event-joining-requests?search=&status=&event_id=&date_from=&date_to=&per_page=`
- `GET /pending-requests/event-joining-requests/{id}`
- `POST /pending-requests/event-joining-requests/{id}/approve` body: `{"admin_note":"Approved"}`
- `POST /pending-requests/event-joining-requests/{id}/reject` body: `{"admin_note":"Reason"}`
- `GET /pending-requests/coin-claims?search=&status=&date_from=&date_to=&per_page=`
- `GET /pending-requests/coin-claims/{id}`
- `POST /pending-requests/coin-claims/{id}/approve` body: `{"admin_notes":"Approved"}`
- `POST /pending-requests/coin-claims/{id}/reject` body: `{"admin_notes":"Reason"}`
- `GET /pending-requests/circle-joining-requests?search=&status=&circle_id=&date_from=&date_to=&per_page=`
- `GET /pending-requests/circle-joining-requests/{id}`
- `POST /pending-requests/circle-joining-requests/{id}/ded-approve`
- `POST /pending-requests/circle-joining-requests/{id}/reject`
- `GET /pending-requests/pending-impacts?search=&status=&date_from=&date_to=&per_page=`
- `GET /pending-requests/pending-impacts/{id}`
- `POST /pending-requests/pending-impacts/{id}/approve` body: `{"review_remarks":"Approved"}`
- `POST /pending-requests/pending-impacts/{id}/reject` body: `{"review_remarks":"Reason"}`

### Reports
- `GET /reports/referrals?date_from=&date_to=&per_page=`
- `GET /reports/activities?date_from=&date_to=`
- `GET /reports/coins?date_from=&date_to=&per_page=`
- `GET /reports/pending-requests`

## Security Checklist

- Every list query applies the existing DED district scope before search, filters, and pagination.
- Every detail/action endpoint re-queries through a district-scoped query or asserts the related user/circle belongs to the DED district.
- Event Joining Requests use the existing request table when present and fall back to `event_registrations`; no endpoint directly hardcodes a missing `event_registration_requests` query.
- No Laravel migrations are required for these APIs. If circle join DED approval columns are absent, run the existing manual SQL in `database/manual/ded_circle_join_approval_setup.sql`.
