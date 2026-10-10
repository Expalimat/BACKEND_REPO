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

## 4. Render
1. Push the backend to GitHub.
2. Render > New > Postgres (free), copy the Internal Database URL.
3. Render > New > Web Service > your repo > Runtime: Docker.
4. Add the env vars from `.env.render.example`.
5. Health check path: `/api/health`.
