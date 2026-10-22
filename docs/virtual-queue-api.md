# Virtual queue API

All authenticated routes are under `/api` and require a Sanctum-authenticated active account. Successful authenticated service and queue responses use the existing `success` / `message` / `data` envelope. Public guest routes return their payload directly.

## Service and access configuration

- `GET /services` — active service list for Students; assigned services for Registrar and Department Staff; all services for Administrators. Each service includes `id`, `code`, `name`, `description`, `instructions`, `isActive`, `publicBookable`, `queueEnabled`, `queuePaused`, and `queueCapacity`.
- `POST /services`, `PUT /services/{serviceId}` — Administrator service configuration.
- `GET /staff/services/{serviceId}/staff`, `PUT /staff/services/{serviceId}/staff` — Administrator service staffing. Assignments are limited to active Registrar and Department Staff accounts.
- `PATCH /staff/services/{serviceId}/queue-pause` — assigned staff or Administrator toggles queue intake with `queuePaused`.

Students may join any active service with `queueEnabled=true`, including a guest-enabled service. Public guests can only use a service where `isActive`, `publicBookable`, and `queueEnabled` are all true. Guest access must be explicitly enabled by an Administrator.

## Student tickets

- `GET /student/virtual-queue/services`
- `GET /student/virtual-queue/tickets`
- `POST /student/virtual-queue/tickets` with `{ "serviceId": "..." }`
- `PATCH /student/virtual-queue/tickets/{queueEntryId}/cancel`

Student tickets expose queue ticket identity, service details, `queueDate`, `queueStatus`, `serviceInstructions`, and wait-estimate fields while waiting. A Student may hold one active ticket per queue date and cancel only a waiting ticket for the current date.

## Public guest tickets

- `GET /public/virtual-queue/services`
- `POST /public/virtual-queue/tickets` with `service_id`, `applicant_name`, `applicant_email`, and `applicant_phone`
- `POST /public/virtual-queue/tickets/retrieve` with `ticket_number` and `access_token`
- `PATCH /public/virtual-queue/tickets/cancel` with `ticket_number` and `access_token`

A successful join returns `{ "ticket": { ... }, "accessToken": "..." }`. Retrieval and cancellation return `{ "ticket": { ... } }`. Save the ticket number and access token; only a SHA-256 token hash is stored. Guest email, phone, and token hash are never included in public ticket responses.

## Staff queue

- `GET /staff/queue?service_id={serviceId}` — assigned queue tickets. The `service_id` filter is optional; staff see only their assigned services.
- `GET /queue/{serviceId}` — returns `{ "service": { ... }, "tickets": [...] }`; each ticket exposes `queueEntryId`, `queueTicket`, `serviceName`, `studentName`, `status`, `isGuest`, and `queuePosition`.
- `GET /queue/display` — public display returns `{ "services": [{ "serviceCode", "serviceName", "nowServing", "waitingCount" }] }` and never exposes student identity.
- `POST /staff/queue/{queueEntryId}/call`
- `POST /staff/queue/{queueEntryId}/start`
- `POST /staff/queue/{queueEntryId}/recall`
- `POST /staff/queue/{queueEntryId}/complete`
- `POST /staff/queue/{queueEntryId}/no-show`
- `POST /staff/queue/{queueEntryId}/cancel`
- `POST /staff/queue/{queueEntryId}/override` with `status` and required `reason`

Queue statuses are `WAITING`, `CALLED`, `SERVING`, `COMPLETED`, `NO_SHOW`, and `CANCELLED`. Completing requires `SERVING`; calling allows only one active called/serving ticket per service at a time. Overrides require a reason. Daily capacity includes all issued tickets regardless of status. Guest tickets can be retrieved or cancelled only with their private access token.
