# PnC-OESS Backend

This folder contains the Laravel API for the PnC-OESS frontend. The frontend
must be run separately and configured to use this API.

## Requirements

- PHP 8.2 or newer with the required Laravel extensions, including `pdo_mysql`
- Composer
- MySQL 8 or another database supported by Laravel

## Set up locally

1. Open a terminal in this backend folder and install PHP dependencies:

   ```powershell
   composer install
   ```

2. Create your local environment file:

   ```powershell
   Copy-Item .env.example .env
   ```

3. Edit `.env` and configure your local database. For MySQL, set values similar
   to these, using your own database name, username, and password:

   ```dotenv
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=pnc_oess
   DB_USERNAME=your_database_user
   DB_PASSWORD=your_database_password
   ```

   Create the database before continuing. Do not share your `.env` file or put
   database passwords in source control.

4. Generate an application key and create the database tables with initial
   sample records:

   ```powershell
   php artisan key:generate
   php artisan migrate --seed
   ```

   The seeder only adds sample data when the database has no users. Use a fresh
   database if you want the complete initial sample dataset.

5. Start the API:

   ```powershell
   php artisan serve
   ```

   The API is normally available at `http://127.0.0.1:8000/api`. Check
   `http://127.0.0.1:8000/api/health` to verify the server is responding.

6. In the separately installed frontend, set `VITE_API_URL` to
   `http://127.0.0.1:8000/api` in its local `.env.local` file, then start the
   frontend.

## List pagination

List endpoints accept `page` and `per_page` query parameters and return Laravel's
paginated response, including page data and total-page metadata. Page sizes are
capped at 100 records. `search`, `filter_field`, and `filter_value` can be used
with paginated requests. Requests without pagination parameters continue to
return the full array for existing dashboard and integration consumers.

## Appointments and queue

Run `php artisan migrate` to add service configuration, schedule closures,
appointment snapshots and status history, separate queue entries, and
notification-delivery tracking. The migration is additive and preserves the
existing users, students, document requests, and appointment records. The
existing CTC claim service remains available; other service categories must be
created by authorized staff rather than being assumed to be offered.

Administrators configure services, instructions, prerequisites, estimated
duration, booking window, cancellation cutoff, late arrival grace period,
capacity, and time slots. They can close an individual slot or a service/date
to prevent new bookings and manage service staff assignments. Registrar and
Department Staff accounts can view and operate only services explicitly
assigned to them; no assignments are granted automatically. The existing
service schema has no office ownership field, so service is the authorization
scope. A slot with active appointments cannot be moved; close it and create a
new slot instead. Service names and slot times are snapshotted when booked, so
later service edits do not rewrite appointment history. Capacity, service
availability, date closures, and the configured booking window are rechecked
when booking is submitted.

Students can book, view their own appointment details and event history,
cancel eligible upcoming appointments, and reschedule eligible bookings.
Student self-check-in is disabled; an authorized assigned Registrar,
Department Staff member, or Administrator checks students in at the office.
The authenticated account supplies the
student identity. Booking references use `PNC-YYYYMMDD-XXXX` and are generated
from a locked per-date sequence with a unique database constraint. Rescheduling
creates a new reference, marks the original `RESCHEDULED`, and links the new
appointment to the original in both directions (`rescheduledFromId` and
`rescheduledToId`).

### Appointment status and queue policy

The API exposes only these canonical appointment statuses:
`PENDING`, `CONFIRMED`, `CHECKED_IN`, `WAITING`, `SERVING`, `COMPLETED`,
`CANCELLED`, `NO_SHOW`, and `RESCHEDULED`. Booking is auto-confirmed. Statuses
are changed only by the booking, cancellation/reschedule, check-in, and
staff queue actions; there is no general-purpose status update endpoint.
Check-in and queue assignment are separate recorded history events
(`CONFIRMED -> CHECKED_IN -> WAITING`). Calling updates the queue entry but
leaves the appointment `WAITING`; starting service changes it to `SERVING`.
Only a serving appointment can be completed. Only unserved appointments can
be marked `NO_SHOW`, and only after the scheduled slot plus that appointment's
snapshotted late-arrival grace period ends. Cancelled, completed, rescheduled,
and no-show appointments cannot re-enter the queue.

Each physical queue entry is scoped to one service and the appointment's
Asia/Manila operating date. Queue numbers are unique within that scope and are
assigned at check-in; the unique appointment relationship prevents duplicate
entries. Initial order is FIFO by successful check-in. This is an explicit
default policy, not a university priority policy. Staff can call an eligible
waiting student out of order only with a reason; overrides are recorded in
appointment history and the existing audit log. Each appointment snapshots the
service's arrival and cancellation rules when booked, so later service edits
do not change existing booking terms. Student check-in is allowed only within
the configured early-arrival window
(default 30 minutes before the slot) through the late-arrival grace period
(default 0 minutes after the slot); authorized staff may override either
boundary with a reason. These are configurable defaults, not an official
university policy. No-show is available after the scheduled slot and grace
period.
Queue positions are derived from waiting entries; estimated wait is based on
the configured service duration and active queue and is explicitly an estimate,
not a guaranteed service time. No operating hours are hardcoded; staff create
the actual schedule.

Appointment history is append-only through the API and records status changes,
queue events, actor ID, Manila timestamp, and override reason where applicable.
Students can read only their own appointment history; assigned Registrar and
Department Staff users can read history for their services, and Administrators
can read it across services.

### Notifications and operations

The current application has in-app notifications stored in its existing
`notifications` table. Appointment confirmation, cancellation, rescheduling,
call, and completion notifications are queued and tracked as `QUEUED`, `SENT`,
or `FAILED`; `SENT` means the in-app notification row was created, not that an
email was delivered. Existing document-release flow uses the same in-app
notification table. No appointment email provider is currently configured.

The queue connection defaults to `database`; run a worker in deployment:

```powershell
php artisan queue:work
```

For email delivery, configure Laravel's `MAIL_MAILER`, `MAIL_HOST`,
`MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and `MAIL_FROM_ADDRESS` in the
deployment environment. Never add credentials to source control. The module
does not report email delivery until an email transport is explicitly
integrated and confirms it. One-day in-app reminders are queued at 09:00
Asia/Manila by the Laravel scheduler. Run `php artisan schedule:work` locally,
or configure the host's Laravel scheduler/cron in deployment; reminders need
the queue worker as well.

The public `/api/appointment-queue/display` endpoint returns service labels,
the currently called ticket, and waiting counts only; it does not return
student identity.

See [docs/appointments-api.md](docs/appointments-api.md) for the REST endpoint
reference, request filters, status values, response examples, and error
semantics. Successful appointment and queue API responses use the
`success`/`message`/`data` envelope; existing appointment resource field names
are retained inside `data`, and the frontend adapter unwraps it for current
screens.

## Tests

Run the backend tests with:

```powershell
php artisan test
```
