# ZULU Backend (Laravel 11)

The API + admin/seller business logic + commerce engine for the ZULU
platform. Production deploys to Hetzner (`api.zulu.am`) on every push to
`main` via GitHub Actions.

Repos in the ZULU monorepo layout:
- **zulu-backend** ← you are here
- [zulu-admin-next](https://github.com/arshakbabayan-sudo/zulu-admin-next) — `admin.zulu.am`
- [zulu-frontend-next](https://github.com/arshakbabayan-sudo/zulu-frontend-next) — `zulu.am`

## Stack

- PHP 8.3 + Laravel 11
- PostgreSQL 15 (Hetzner-hosted in prod, local in dev)
- Sanctum tokens for API auth (cookie-based for web SPA)
- Pest/PHPUnit for tests
- Larastan static analysis
- DomPDF for invoice / payslip / contract PDFs
- Socialite (Google + Facebook OAuth)
- Self-hosted error capture → `audit_logs.category=error`
- Cron-backed scheduler (health checks, daily backups, restore drills, escalation sweep, etc.)

## Local setup

```bash
# 1. Clone + install
git clone git@github.com:arshakbabayan-sudo/zulu-backend.git
cd zulu-backend
composer install

# 2. Env
cp .env.example .env
php artisan key:generate
# Edit .env: set DB_*, MAIL_*, TELEGRAM_BOT_TOKEN (optional for dev)

# 3. Database
createdb zulu                # or use pg_admin / docker compose
php artisan migrate --seed   # seeds default UI translations + locations + roles

# 4. Storage
php artisan storage:link

# 5. Serve
php artisan serve            # http://127.0.0.1:8008 by convention
```

## Daily commands

```bash
# Run tests
php artisan test                      # full suite
php artisan test --filter=CasesControllerTest   # one class
php artisan test tests/Unit            # one folder

# Lint / static analysis
./vendor/bin/pint                     # auto-format PHP
./vendor/bin/phpstan analyse          # static analysis (level set in phpstan.neon)

# Scheduled jobs locally
php artisan schedule:work             # runs the scheduler in foreground

# UI translation cache invalidation (after raw SQL inserts)
php artisan cache:forget ui_translations_hy
php artisan cache:forget ui_translations_ru
php artisan cache:forget ui_translations_en

# Generate OpenAPI spec
php artisan api:generate-openapi --output=storage/app/openapi.json

# Database backup (manually)
php artisan db:backup --disk=local --keep=14

# Restore drill (replays latest dump into scratch DB)
php artisan db:restore-drill --execute
```

## Project structure (high-level)

```
app/
  Http/Controllers/
    Api/            ← REST API endpoints (Sanctum auth)
    Admin/          ← legacy Blade admin (mostly deprecated)
  Models/           ← Eloquent models (100+ tables, see docs/db-schema)
  Services/         ← business logic
    Admin/          ← AdminAccessService (RBAC, super-admin, scoping)
    Commissions/    ← CommissionService + CommissionRuleResolver (audit pipeline)
    Finance/        ← FinanceService + SupplierEntitlement (payout pipeline)
    Subscriptions/  ← PlanFeature + PlanGateService (plan-gated features)
    UserAccount/    ← DataExportService, account-deletion flow (GDPR)
    Pdf/            ← InvoicePdfService, ContractPdfService, payslip Blade
    ErrorReporting/ ← ErrorReportService (self-hosted Sentry replacement)
  Console/Commands/ ← Artisan commands (registered in bootstrap/app.php)
  Jobs/             ← queued jobs (ReleaseExpiredHolds, etc)
database/
  migrations/       ← timestamped, run in order
  seeders/          ← UI translations, default roles, locations, etc.
routes/
  api.php           ← 200+ endpoints (most under /api/auth-protected)
  console.php       ← scheduler registration
  web.php           ← legacy /admin Blade pages (deprecated)
resources/views/    ← Blade templates: PDFs, transactional emails
storage/app/        ← user uploads, backups, OpenAPI spec
tests/Feature/      ← 125+ feature tests (HTTP-level)
tests/Unit/         ← unit tests (services, model methods)
```

## Conventions

- Controllers return `{ success: bool, data: ..., message?: string, errors?: {} }` envelopes.
- 401 = unauthenticated, 403 = forbidden, 404 = not found, 422 = validation, 402 = plan-limit-reached (rare).
- Migrations are immutable once shipped — no editing past migrations, always add a new one.
- Status fields use lower_snake_case strings (`'in_progress'`, `'past_due'`, never enums).
- `is_super_admin` is the only bypass — every other access check goes through `AdminAccessService`.
- Cache keys for translations follow `ui_translations_<lang>`; always invalidate after raw SQL inserts.

## Deploy

`main` auto-deploys to Hetzner (`api.zulu.am`) via GitHub Actions on every push:
1. SSH to Hetzner box
2. `git pull` in `/var/www/zulu-backend`
3. `composer install --no-dev --optimize-autoloader`
4. `php artisan migrate --force`
5. `php artisan config:cache` + `route:cache`
6. Reload PHP-FPM + nginx

If a deploy fails, GitHub Actions Telegram notifier pings `@Zulu_Deploy_arshak_Bot`.

## Documentation

- `docs/decisions/ADR-*.md` — architecture decisions (see docs/decisions/)
- `docs/roadmaps/zulu-roadmap-2026-05-20.md` — what's still on the burn-down
- `docs/audits/` — point-in-time audits (translations, locations, etc.)
- `docs/design/specs/` — Figma design specs cached for offline reference

## Common operations

| Task | Command / location |
|---|---|
| Add a new bucket-3 module | New migration + Model + Controller + routes/api.php entry + admin page; copy a sibling (e.g. `cases`) as a template |
| Scope queries to company | `AdminAccessService::companyIdsFor(...)` or `isSuperAdmin($user)` bypass |
| Add a scheduled job | Console command in `app/Console/Commands/`, register in `bootstrap/app.php` `withCommands`, schedule in `routes/console.php` |
| Add a PDF export | Blade template in `resources/views/pdf/*.blade.php`, service in `app/Services/Pdf/*`, controller endpoint returns `$pdf->download(...)` |
| Send a notification | `App\Models\Notification::create([...])` row → frontend polls / WebSocket later |

## Production gotchas

- Hetzner runs PHP 8.3.x — match locally to avoid surprises.
- `php artisan migrate` on deploy will fail loudly if a migration errors; fix → re-push.
- `php artisan optimize:clear` if cached routes/config look stale after deploy.
- Telegram bridge service runs on the same Hetzner box (`systemctl status zulu-bridge`).
- Backups land in `storage/app/backups/db/` (14 days retention by default) — DR drill runs monthly.
