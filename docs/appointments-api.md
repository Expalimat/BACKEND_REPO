# Appointment and Queue API

The appointment endpoints are additive to the existing Laravel API. They use
the existing Sanctum authentication, `users`/`students` records, and project
roles. Other API routes and response conventions are unchanged.

### Role mapping and service scope

The existing role values are `Student`, `Registrar`, `Department Staff`,
`Cashier`, and `Administrator`.

- Students can use the student booking endpoints. Appointment queries are
  constrained to the authenticated user's linked `studentNumber`; an attempt
  to view or mutate someone else's appointment returns `404`.
- `Registrar` and `Department Staff` are operational staff, but may access
  appointment and queue records only for services explicitly assigned to their
  account. No staff assignments are created automatically. If a requested
  service isn't assigned, direct service/queue operations return `403`; private
  appointment IDs outside the user's scope return `404`. Staff listing routes
  return only appointments, slots, and queue entries within their assignment.
- `Administrator` maps to service administrator. It may administer all
  appointment services, schedules, staff assignments, and reports.
- `Cashier` has no appointment permissions.

The existing service model has no office/department ownership field, so this
implementation uses each appointment service as the narrow authorization
scope. An Administrator manages assignments with:

| Method and path | Purpose |
|---|---|
| `GET /staff/services/{serviceId}/staff` | List Registrar and Department Staff assigned to the service. |
| `PUT /staff/services/{serviceId}/staff` | Replace the assignments. Body: `user_ids`, an array of active Registrar or Department Staff user IDs. An empty array revokes all assignments. |

Service assignments are persisted in `appointment_service_staff` and are
checked by backend queries and mutations, not by frontend visibility alone.
Appointment service and schedule configuration is restricted to
`Administrator`; assigned operational staff can view their schedules and
operate queues and pause/resume ticket intake, but cannot change service
configuration or capacity.

## Conventions

- Base URL: `{API_URL}/api`.
- Protected requests require the authenticated Sanctum session/token used by
  the existing application. Send `Accept: application/json`.
- Dates use `YYYY-MM-DD`; times use 24-hour `HH:mm`. Appointment operations use
  `Asia/Manila`.
- Every successful response in the appointment and queue API uses
  `{"success":true,"message":"...","data":...}`. The `data` value is the
  endpoint's resource, array, or Laravel paginator. Existing camelCase resource
  fields are preserved inside `data` to match this application's naming
  conventions. Staff appointment and schedule paginator fields (`data`, `links`,
  `current_page`, `last_page`, `per_page`, `total`) are nested inside the
  envelope's `data` object.
- Validation errors return HTTP `422` with `message` and field-keyed `errors`.
  Unauthenticated requests return `401`; role failures return `403`; missing
  resources return `404`; disallowed state changes, unavailable capacity, and
  other business conflicts return `409` with a `message`. Error responses keep
  Laravel's existing error shape and are not success-wrapped.
- Successful creates return `201`; successful reads and updates return `200`.
  Appointment references are unique, human-readable identifiers such as
  `PNC-20261020-0042`.
- Capacity and bookability are checked by the server. Student identity is
  always taken from the authenticated account, never from the request body.
- Production deployments must set `APP_DEBUG=false`; Laravel then returns
  generic server-error responses instead of exception details, SQL, or traces.

## Appointment statuses

The stable API values are `PENDING`, `CONFIRMED`, `CHECKED_IN`, `WAITING`,
`SERVING`, `COMPLETED`, `CANCELLED`, `NO_SHOW`, and `RESCHEDULED`.
Booking is confirmed immediately. Check-in creates a separate queue entry and
records `CHECKED_IN` then `WAITING`; calling only changes queue-entry status.
Starting service changes the appointment to `SERVING`. Completion is permitted
only from `SERVING`. Cancellation and rescheduling are limited to eligible
upcoming bookings. No-show is permitted only after the appointment's end plus
its configured grace period. There is no arbitrary status-update endpoint.

Student self-check-in is disabled. An authorized staff member must check the
student in at the office. The legacy `PATCH /appointments/{id}/check-in` path is
staff-only as well; this avoids enabling remote check-in without a configured
university policy.

## Student and service endpoints

| Method and path | Access | Purpose and parameters |
|---|---|---|
| `GET /services` | Student, assigned Registrar/Department Staff, Administrator | Active service catalog for students; staff see only assigned services (active and inactive); Administrator sees all. |
| `GET /appointment-services` | Student, assigned Registrar/Department Staff, Administrator | Compatibility alias for `GET /services`. |
| `GET /appointment-slots` | Student | Bookable slots. Optional `service_id`, `date_from`, `date_to`; aliases `serviceId`, `from`, `to` are accepted. Disabled, closed-date, fully booked, inactive-service, out-of-window, and passed-today slots are not returned. |
| `GET /student/virtual-queue/services` | Student | List active services configured for direct student tickets with current capacity and intake state. Freshman enrollment remains excluded from authenticated student walk-ins. |
| `GET /student/virtual-queue/tickets` | Student | Retrieve the authenticated student's active virtual ticket(s) for today; only tickets owned by that account are returned. |
| `POST /student/virtual-queue/tickets` | Student | Join a virtual queue without an appointment. Body: `serviceId`. Requires a linked student account, enabled/unpaused service, available daily capacity, and no other active ticket today. Returns `201`. |
| `PATCH /student/virtual-queue/tickets/{queueEntryId}/cancel` | Student | Cancel the authenticated student's own ticket while it is waiting. Called or in-service tickets cannot be cancelled by the student. |
| `POST /appointments` | Student | Create an appointment. Body: `appointment_slot_id` (or compatibility alias `slotId`, required), optional `purpose`, optional `documentRequestId`. |
| `GET /appointments/my` | Student | List the authenticated student's appointments. |
| `GET /appointments` | Student, assigned Registrar/Department Staff, Administrator | Compatibility list route. Students see only their own appointments. Staff see assigned-service appointments and can filter with `appointment_date`, `service_id`, `status`, `reference_number`. |
| `GET /appointments/{id}` | Student, assigned Registrar/Department Staff, Administrator | Appointment details; students see only their own, staff only assigned services. |
| `GET /appointments/{id}/history` | Student, assigned Registrar/Department Staff, Administrator | Append-only event history; access scope matches appointment details. |
| `PATCH /appointments/{id}/cancel` | Student, assigned Registrar/Department Staff, Administrator | Cancel an eligible appointment. Optional `reason`; staff cancellation requires it. |
| `PATCH /appointments/{id}/reschedule` | Student | Reschedule an eligible confirmed appointment. Body: `appointment_slot_id` (or `slotId`). A replacement appointment is created and both records are linked. |
| `POST /appointments/{id}/check-in` | Registrar, Administrator | Staff check-in. Optional `overrideReason`; required if staff overrides the configured arrival window. |

### Service response

```json
{
  "success": true,
  "message": "Request completed successfully.",
  "data": [{
    "id": "service-uuid",
    "code": "CTC",
    "name": "CTC Document Claiming",
    "description": "Claim certified true copies",
    "instructions": null,
    "requiredDocuments": [],
    "serviceDurationMinutes": 30,
    "isActive": true
  }]
}
```

### Virtual queue policy

CTC / Document Claiming is configured for direct student tickets. Direct
tickets are authenticated and stored in the same queue-entry table and
per-service Manila-date sequence as appointment check-ins; they do not create
fake appointments or reserve appointment slots. Only students with a linked
student number can join. A student may hold only one active direct ticket at a
time, and the server enforces each service's daily limit on issued virtual
tickets while serializing ticket issuance. Cancelled and completed tickets
still count toward that day's issued-ticket limit; ticket numbers are not
reused. Existing freshman appointments remain separate from queue tickets.

### Incoming-freshman guest queue

The guest API is limited to active services that are both `publicBookable` and
`queueEnabled`; both settings require administrator control. Freshman enrollment
queue intake remains off by default. Guests join with their name, email, and
phone, and receive a one-time 256-bit access code. The database stores only its
SHA-256 hash. Retrieval and cancellation require both the visible ticket number
and the private access code; ticket numbers alone do not authorize access.
Guest email addresses are normalized and prevent duplicate active tickets for
the same service and operating day, but are not a substitute for official
admission or identity verification. Public requests are rate-limited.

| Method and path | Access | Purpose |
|---|---|---|
| `GET /public/virtual-queue/services` | Public, rate-limited | List public-bookable, queue-enabled services and their current intake state. |
| `POST /public/virtual-queue/tickets` | Public, rate-limited | Join an eligible guest queue. Body: `service_id`, `applicant_name`, `applicant_email`, `applicant_phone`. Returns ticket data and one-time `accessToken`. |
| `POST /public/virtual-queue/tickets/retrieve` | Public, rate-limited | Retrieve an existing guest ticket. Body: `ticket_number`, `access_token`. |
| `PATCH /public/virtual-queue/tickets/cancel` | Public, rate-limited | Cancel a waiting guest ticket for today. Body: `ticket_number`, `access_token`. |

An Administrator enables direct queue intake and configures its daily capacity
in **Appointments & Queue**. Assigned operational staff and Administrators
may pause or resume intake without removing tickets already in the queue:
`PATCH /staff/appointment-services/{serviceId}/queue-pause` with
`{"queuePaused":true}` or `{"queuePaused":false}`. Pausing also prevents new
direct virtual tickets for that service; booked appointment check-ins and
queue-processing actions remain available. The public waiting-room endpoint
continues to expose ticket numbers and counts only, never student identity.

### Slot response

```json
{
  "success": true,
  "message": "Request completed successfully.",
  "data": [{
    "id": "slot-uuid",
    "serviceId": "service-uuid",
    "serviceCode": "CTC",
    "serviceName": "CTC Document Claiming",
    "appointmentDate": "2026-10-20",
    "startTime": "09:00",
    "endTime": "09:30",
    "capacity": 10,
    "currentBookingCount": 4,
    "available": 6,
    "status": "Open",
    "dateClosed": false
  }]
}
```

### Booking example

```http
POST /api/appointments
Content-Type: application/json
Accept: application/json

{"appointment_slot_id":"slot-uuid","purpose":"Claim a certified true copy"}
```

The response is an appointment resource inside the standard envelope with
HTTP `201`, for example:

```json
{
  "success": true,
  "message": "Appointment created successfully.",
  "data": {
    "id": "appointment-uuid",
    "appointmentNumber": "PNC-20261020-0042",
    "serviceId": "service-uuid",
    "slotId": "slot-uuid",
    "appointmentDate": "2026-10-20",
    "startTime": "09:00",
    "endTime": "09:30",
    "status": "CONFIRMED"
  }
}
```

## Staff schedule and appointment endpoints

Staff endpoints below require an assignment to the referenced service for
Registrar and Department Staff; Administrator has service-wide access.

| Method and path | Purpose and parameters |
|---|---|
| `GET /staff/appointments` | Scoped appointment list. Filters: `appointment_date`, `service_id`, `status`, `reference_number`; supports `page` and `per_page` (1–100, default 50). |
| `GET /staff/appointment-slots` | Scoped schedule list. Optional `service_id`, `date_from`, `date_to`, `status`; supports `page` and `per_page` (1–100, default 50). |
| `POST /staff/appointment-slots` | Administrator only. Create slot with `service_id`, `appointment_date`, `start_time`, `end_time`, and `capacity` (1–500). |
| `PATCH /staff/appointment-slots/{id}` | Administrator only. Update an eligible slot. A slot with bookings cannot be moved; capacity cannot be reduced below active bookings. |
| `GET /staff/queue` | Queue entries for assigned services today or `queue_date`; optionally filter `service_id` and `status` (`WAITING`, `CALLED`, `SERVING`). Queue position is server-derived. |
| `GET /staff/reports/appointments` | Counts by status and service, limited to assigned services for operational staff. Optional `date_from`, `date_to`, `service_id`. |

### Create schedule example

```http
POST /api/staff/appointment-slots
Content-Type: application/json

{
  "service_id": "service-uuid",
  "appointment_date": "2026-10-20",
  "start_time": "09:00",
  "end_time": "09:30",
  "capacity": 10
}
```

## Staff queue operations

All operations require an explicit service assignment for Registrar and
Department Staff; Administrator may access any service. `{id}` here is the
queue-entry ID, except for check-in, where it is the appointment ID.
Queue entries are scoped to a service and Asia/Manila operating date.

| Method and path | Purpose and body |
|---|---|
| `POST /staff/appointments/{id}/check-in` | Check in an appointment. Optional `overrideReason`, mandatory when overriding the arrival window. |
| `POST /staff/queue/{id}/call` | Call a waiting queue entry. Only one entry per service can be called or serving at once. |
| `POST /staff/queue/{id}/start` | Start the called entry's service. |
| `POST /staff/queue/{id}/complete` | Complete an entry that is currently serving. |
| `POST /staff/queue/{id}/no-show` | Mark an eligible unserved appointment as no-show after its grace window. Optional `reason`. |
| `POST /staff/queue/{id}/override` | Call a waiting entry out of order. Body requires `reason`; action is audited. |

Queue estimates are estimates, not guaranteed service times. For a waiting entry,
`queuePosition`, `eligiblePeopleAhead`, `averageServiceDurationMinutes`, and
`estimatedWaitMinutes` are supplied by the backend. The initial estimator is:
`eligible people ahead × average service duration`. It excludes cancelled,
completed, and no-show entries by considering only `WAITING`, `CALLED`, and
`SERVING` queue entries. Completed service durations use the latest 20 valid
completed entries for that service; when no actual sample exists, the configured
service duration is used.

The model assumes one counter and broadly similar service durations. It does not
account for multiple counters, priority rules, interruptions, or materially
different service types. `estimatorVersion` makes this backend contract
replaceable when a more advanced estimator is introduced. Queue events and
status transitions are recorded in the appointment status history.

## Compatibility routes

Existing frontend consumers may continue using `/appointment-services`,
`/appointment-services/{serviceId}/slots`, `/appointment-services/{serviceId}`,
`/appointment-slots/{id}`, `/appointments`, and `/appointment-queue/{serviceId}`
plus the existing appointment lifecycle routes. The routes remain available
with their existing request fields and resource field naming, but now use the
standard success envelope. The frontend service unwraps this envelope for
existing screens. Unrelated API routes and response conventions are unchanged.

## Error and conflict examples

Validation error (`422`):

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "slotId": ["The slot id field is required."]
  }
}
```

Business conflict (`409`):

```json
{"message":"This appointment slot is fully booked."}
```

Authorization failure (`403`):

```json
{"message":"You do not have permission to perform this action."}
```

Actual validation and authorization messages are supplied by Laravel and the
existing middleware; clients should use the HTTP status and error object rather
than depend on exact message text.

Unauthenticated requests return HTTP `401`; missing resources return HTTP
`404`. Production deployments must keep `APP_DEBUG=false` so unexpected server
errors never include internal exception details.
