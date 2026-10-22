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

## Virtual queue

Run `php artisan migrate` to install the queue-only schema. The forward migration keeps direct student and guest queue tickets, service queue settings, service assignments, and queue sequence values. It intentionally deletes appointment-linked tickets and all appointment/slot/history data; do not run it against a shared or production database until a separately approved backup and migration plan is in place.

Services are managed by Administrators through `GET /api/services`, `POST /api/services`, and `PUT /api/services/{serviceId}`. Public guest intake is off by default and requires all three service flags: `isActive`, `publicBookable`, and `queueEnabled`. Students may join every active, queue-enabled service, regardless of its guest-access setting. Registrar and Department Staff access is limited to explicitly assigned services; Administrators can access all services. Assigned staff may pause or resume intake without changing existing tickets.

Authenticated student queue routes are `GET /api/student/virtual-queue/services`, `GET /api/student/virtual-queue/tickets`, `POST /api/student/virtual-queue/tickets`, and `PATCH /api/student/virtual-queue/tickets/{queueEntryId}/cancel`. Public guest routes are `GET /api/public/virtual-queue/services`, `POST /api/public/virtual-queue/tickets`, `POST /api/public/virtual-queue/tickets/retrieve`, and `PATCH /api/public/virtual-queue/tickets/cancel`. Guest tickets require an access token for retrieval and cancellation; only its SHA-256 hash is stored, and guest contact details are not returned to clients.

Staff use `GET /api/staff/queue?service_id={serviceId}`, `GET /api/queue/{serviceId}`, and the `/api/staff/queue/{queueEntryId}/{call,start,recall,complete,no-show,cancel,override}` actions. Queue display data is available at `GET /api/queue/display`. Ticket lifecycle statuses are `WAITING`, `CALLED`, `SERVING`, `COMPLETED`, `NO_SHOW`, and `CANCELLED`. Daily capacity counts every issued queue ticket, including cancelled tickets, and ticket numbers are sequenced per service and Manila date. Queue positions and wait durations are estimates, not guarantees.

Student ticket pages poll every ten seconds and guest ticket pages every eight seconds while active. This provides in-page updates only, not background push or SMS notifications. Appointment booking, slots, check-in, reports, notifications, and reminder scheduling are no longer part of the active application or API.

See [docs/virtual-queue-api.md](docs/virtual-queue-api.md) for route details and response fields.
## Tests

Run the backend tests with:

```powershell
php artisan test
```
