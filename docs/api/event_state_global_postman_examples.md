# Event Global/State Multi-Circle Postman Examples

Use the existing admin/member bearer tokens and replace UUID placeholders with real IDs.

## 1. Create Global Event with multiple circle_ids

`POST /api/v1/admin/events`

```json
{
  "title": "Test Global Event",
  "event_type": "global_event",
  "event_category": "test",
  "circle_id": "{{circle_uuid_1}}",
  "circle_ids": ["{{circle_uuid_1}}", "{{circle_uuid_2}}", "{{circle_uuid_3}}"],
  "mode": "offline",
  "description": "Global event description",
  "start_at": "2026-06-15T10:00:00+05:30",
  "end_at": "2026-06-15T12:00:00+05:30",
  "ticket_price": 500,
  "is_paid": true
}
```

## 2. Create State Event with state_name and multiple circle_ids

`POST /api/v1/admin/events`

```json
{
  "title": "Gujarat State Event",
  "event_type": "state_event",
  "state_name": "Gujarat",
  "event_category": "test",
  "circle_id": "{{ahmedabad_circle_uuid}}",
  "circle_ids": ["{{ahmedabad_circle_uuid}}", "{{surat_circle_uuid}}", "{{vadodara_circle_uuid}}"],
  "mode": "offline",
  "description": "State event description",
  "start_at": "2026-06-15T10:00:00+05:30",
  "end_at": "2026-06-15T12:00:00+05:30",
  "ticket_price": 500,
  "is_paid": true
}
```

## 3. Register selected-circle peer for Global Event

`POST /api/v1/events/{{global_event_id}}/occurrences/{{occurrence_id}}/register`

Expected: normal member registration with `payment_required: false` and QR data after successful registration.

## 4. Register non-selected-circle peer for Global Event

`POST /api/v1/events/{{global_event_id}}/occurrences/{{occurrence_id}}/register`

Expected: existing cross-circle request/payment flow. After approval, response should include `registration_type: cross_circle_member`, `payment_required: true`, pending payment status, amount, currency, and a payment URL when configured.

## 5. Register selected-circle peer for State Event

`POST /api/v1/events/{{state_event_id}}/occurrences/{{occurrence_id}}/register`

Expected: normal member registration with `payment_required: false`.

## 6. Register non-selected-circle peer for State Event

`POST /api/v1/events/{{state_event_id}}/occurrences/{{occurrence_id}}/register`

Expected: user is treated as cross-circle even if they are in the same state but not in a selected circle.

## 7. Event details include circles array

`GET /api/v1/events/{{event_id}}`

Verify `data.circles` and `data.circle_ids` are present without removing `data.circle` or `data.circle_id`.

## 8. Event list includes circles array

`GET /api/v1/events`

Verify each occurrence item includes `circles` and `circle_ids`.
