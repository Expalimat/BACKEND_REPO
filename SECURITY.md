# PnC-OESS security checklist (v2.1)

Where each item lives and what to say when asked about it.

| # | Feature | Where it lives | Defense answer |
|---|---------|----------------|----------------|
| 1 | User Authentication | `AuthController`, Laravel Sanctum tokens (8 hour expiry) | Token auth works across two domains (Vercel frontend, Render API) |
| 2 | Password Hashing | `User` model `hashed` cast (bcrypt, `BCRYPT_ROUNDS=12`) | Plain passwords are never stored, and `password` is in `$hidden` so it is never returned |
| 3 | RBAC | `RoleMiddleware` on routes, `Oess::scopeToStudent` | Roles are checked on the server, students only get their own records. Hiding buttons in React is not security |
| 4 | Input Validation | `$request->validate()` in every controller, `Route::pattern` for URL params | Whitelist rules (types, max lengths, regex, `Rule::in`), anything else is rejected. List endpoints only search and filter on whitelisted columns, `per_page` is capped at 100 |
| 5 | SQL Injection | Eloquent bindings, `Oess::escapeLike` | No user input is ever concatenated into SQL |
| 6 | XSS | `SecurityMiddleware` strips tags, API CSP `default-src 'none'`, React escapes output, CSP in `vercel.json` | Layers: clean on input, escape on output, CSP as the safety net |
| 7 | CSRF | Not needed by design | Auth is a Bearer token in the `Authorization` header, not a cookie, so browsers never attach it on their own. CORS allows only `FRONTEND_URL` |
| 8 | Secure Session Management | `AuthController`, `UserController`, `EnsureActiveUser` | Tokens expire, die on logout, and are revoked on password, role, or deactivation changes |
| 9 | HTTPS / TLS | Render and Vercel (managed certs, HTTP redirects to HTTPS), HSTS in `SecurityMiddleware` and `vercel.json`, `DB_SSLMODE=require` | Browser to Vercel, browser to Render, and API to database are all encrypted |
| 10 | Login Rate Limiting | `AuthController`: 5 wrong passwords per username, 20 per IP, 15 minute lock | Needs `CACHE_STORE=file`. Render's edge does not cover targeted credential stuffing, so this is app-level |
| 11 | Data Encryption | In transit: TLS (see 9). At rest: Render Postgres AES-256 (platform). Passwords are bcrypt, Sanctum stores only token hashes | Honest gap: no field-level encryption of student contact/address (possible with Laravel `encrypted` casts) |
| 12 | API Authentication | `auth:sanctum` on every route except `/health` and `/login` | |
| 13 | API Rate Limiting | `throttle:120,1` per user, `throttle:10,1` on write endpoints | Needs `CACHE_STORE=file` |
| 14 | Security Headers | `SecurityMiddleware` (API), `vercel.json` (site) | nosniff, frame denial, referrer policy, permissions policy, CSP, no-store, HSTS |
| 15 | Secure Error Handling | `APP_DEBUG=false`, `display_errors=Off`, generic login message, JSON-only errors | No stack traces or SQL errors reach the client |
| 16 | Firewall / Network Protection | Render: all inbound traffic goes through Cloudflare (DDoS), database reached over the internal URL, external database access closed. Vercel: DDoS mitigation always on, Firewall rules. Docker: non-root user, build tools removed | Honest gap: no app-level WAF |
| 17 | Basic Security Monitoring | `audit_logs` (admin page), failed logins logged to stderr (Render Logs), account locks audited | |

## One-time setup

Render web service, Environment:

    CACHE_STORE=file
    FRONTEND_URL=https://frontend-repo-eta-sandy.vercel.app
    DB_SSLMODE=require
    LOG_LEVEL=warning
    BCRYPT_ROUNDS=12

Render Postgres, Access Control: delete the `0.0.0.0/0` entry. The API uses the internal URL, so it keeps working. Re-add your own IP only if you need to connect from your PC.

Vercel: put `vercel.json` in the frontend repo root (merge if one exists). After deploying, open the site with DevTools Console open. A "Refused to ..." line means the CSP blocked something.

## Quick tests

Lockout (uses a fake username, so no demo account gets locked). Expect five 401s, then 429:

    for i in 1 2 3 4 5 6; do curl -s -o /dev/null -w "%{http_code}\n" -X POST https://backend-repo-2nwf.onrender.com/api/login -H "Content-Type: application/json" -d '{"username":"locktest","password":"wrong"}'; done

Headers:

    curl -sI https://backend-repo-2nwf.onrender.com/api/health

## Known weak spots (know these before the defense)

- The container runs `php artisan serve` (PHP's built-in server). Fine for this project, a real deployment would use php-fpm with nginx.
- The lockout counters live in a file cache, so they reset when Render restarts the service.
- A per-username lockout can be abused to lock someone out on purpose. It is a trade-off against brute force.
- `UserController::store` gives new users `password123` when the admin leaves the password blank.
- The API token sits in browser storage, so XSS is the main risk. The CSP and the 8 hour expiry limit the damage.
- Payment proof stores only a filename, not the file.
