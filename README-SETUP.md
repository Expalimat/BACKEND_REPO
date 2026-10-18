# PnC-OESS backend: setup

Files in this folder go ON TOP of a fresh Laravel install.

## 1. Create the Laravel project (local)
    composer create-project laravel/laravel pnc-oess-backend
    cd pnc-oess-backend
    php artisan install:api        # installs Sanctum + routes/api.php (say yes to any prompts)

## 2. Copy this overlay in
Copy everything from this folder into the project root, and let it overwrite:
`routes/api.php`, `database/seeders/DatabaseSeeder.php`, `app/Models/User.php`.

Then delete nothing, the new migration replaces the default users table on its own.

## 3. Local database
In `.env` set your DB (Postgres, or MySQL if you're on XAMPP), then:
    php artisan migrate:fresh --seed
    php artisan serve              # http://127.0.0.1:8000/api/health

Demo logins (all password `password123`): student, registrar, cashier, department, admin
(type them on the login page; the frontend no longer has quick-fill buttons)

Enrollment flow: Pending -> Registrar sets Approved / Pre-Assessed / Rejected -> student can upload
payment proof (API rejects it otherwise) -> Cashier verifies -> student is enrolled.

List endpoints accept `page` and `per_page` query parameters and return Laravel
pagination metadata. Page sizes are capped at 100. Paginated requests may include
`search`, `filter_field`, and `filter_value`; requests without pagination
parameters continue to return the full array.

## 4. Render
1. Push the backend to GitHub.
2. Render > New > Postgres (free), copy the Internal Database URL.
3. Render > New > Web Service > your repo > Runtime: Docker.
4. Add the env vars from `.env.render.example`.
5. Health check path: `/api/health`.

## 5. Security (v2)
- Set `CACHE_STORE=file` and `FRONTEND_URL` on Render (see `.env.render.example`).
- Copy `config/cors.php` and the two new middleware files in too; `routes/api.php` already uses them.
- Login lockout: 5 wrong passwords per username = 15 min lock (HTTP 429). Tokens expire after 8 hours.
- Enrollment/payment/grade/settings inputs are validated server-side; fees must add up to the total.
