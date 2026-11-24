# DCP Pragati — web app and API

Laravel 12 · PostgreSQL 16 + PostGIS · Spatie Permission / Activitylog · Sanctum · Pest.
Design: [../../docs](../../docs/). Rules for working in this code: [../../CLAUDE.md](../../CLAUDE.md).

## Run with Docker (recommended)

From the repo root:

```bash
cp .env.docker.example .env
# set APP_KEY in .env, e.g. from: docker compose run --rm --no-deps --entrypoint php app artisan key:generate --show
docker compose up -d --build
```

Open http://localhost:8090. On first start, migrations run automatically and an empty database gets the demo seed (`SEED_DEMO=true`).

| Service | What | Notes |
|---|---|---|
| `web` | nginx 1.27 | port `WEB_PORT` (8090) |
| `app` | PHP 8.3-FPM, Laravel | runs migrations on start; health check on php-fpm ping |
| `worker` | `queue:work redis` | queued jobs (alerts, reports, sync) |
| `scheduler` | `schedule:work` | alert evaluation, digests (as they land) |
| `db` | PostGIS 16-3.4 | host port `DB_PORT_HOST` (5433); also creates `dcp_pragati_test` |
| `redis` | Redis 7 | cache, sessions, queues |
| `pdf` | Gotenberg 8 | HTML → PDF for reports (internal only) |

Volumes: `dcp_pgdata` (database), `app_storage` (photos, logs), `redis_data`.

Everyday commands:

```bash
docker compose logs -f app                          # OTP codes in dev
docker compose exec app php artisan test            # full test suite against the test DB
docker compose exec app php artisan migrate:fresh --seed --force
docker compose up -d --build                        # rebuild after code changes
docker compose down                                 # stop (data kept); add -v to wipe volumes
```

Production builds: set `APP_ENV=production`, `APP_DEBUG=false`, `SEED_DEMO=false` and `INSTALL_DEV=false`, use a real OTP driver, and remove the `db` port mapping (docs/16).

## Run on the host (optional)

Use the Docker `db` (port 5433) with PHP 8.3 and Node on the host:

```bash
cd apps/web
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate   # DB_* as below
php artisan migrate:fresh --seed
./vendor/bin/pest
php artisan serve
```

```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5433
DB_DATABASE=dcp_pragati
DB_USERNAME=dcp
DB_PASSWORD=dcp_local_only
```

## Demo logins (seeded outside production)

Demo data is **not** real Assam data. Codes are prefixed `DEMO`, and the Department's pilot data replaces them.

| Mobile | Role | Sees |
|---|---|---|
| 9000000001 | state_admin | everything |
| 9000000002 | dpc | Demo District A |
| 9000000003 | dpc | Demo District B |
| 9000000004 | co_district_officer | Demo Co-district A1 |
| 9000000005 | field_user | 3 works in District A |
| 9000000006 | field_user | 2 works in District B |
| 90000011NN | dpc | one statewide demo district (NN = 11 Dhubri … 24 Cachar, in the order of `DemoStatewideSeeder::DISTRICTS`) |
| 90000021NN | field_user | that district's works |

The statewide demo (`DemoStatewideSeeder`) adds 14 districts named after real places with "(demo)", about 95 works and their progress history, so the dashboard map, reports and alerts have something to show:

- **Schemes:** Assam's real Decentralised Planning schemes are the fund sources (MLALADS, MPLADS, Untied Fund, Assam Darshan, Aspirational District Programme, Special Development Package), with work types and amounts in their published ranges.
- **Made up:** work names, villages, sanction orders and progress figures.
- **Outlines:** district outlines are Voronoi cells clipped to the Assam outline, not real borders.

To add it to an existing demo database: `docker compose exec app php artisan db:seed --class=DemoStatewideSeeder --force` (does nothing if already present).

`POST /api/v1/auth/otp/request {"mobile": "9000000005"}`, then read the OTP from `docker compose logs app` (or `storage/logs/laravel.log` on the host).

## Code map

| Path | What |
|---|---|
| `app/Domain/Access` | Roles, permission matrix, `JurisdictionScope` |
| `app/Domain/Rules` | Rule engine and R01–R11 (pure functions of `Context`) |
| `app/Domain/Progress` | Photo storage, submission, approval workflow |
| `app/Domain/Auth` | OTP login |
| `app/Domain/Import` | Workbook template, reader, importer |
| `app/Domain/Gis` | Map GeoJSON, area figures, boundary import |
| `app/Domain/Reports` | MIS reports, AQ desk, Excel / PDF export, weekly email |
| `app/Domain/Alerts` | Alert evaluation, escalation, notifications, digest |
| `app/Http/Controllers/Api/V1` | REST API (docs/12) |
| `database/migrations` | Full schema from docs/03 |
| `tests/fixtures/rules/rules.json` | Shared rule fixtures (PHP now, Dart later) |

## Web screens

Sign in at `/login`: mobile number and password (`password` for the demo users), then the OTP from `docker compose logs app`.

The public landing page is at `/`, with grievances at `/grievances/new` and `/grievances/track`. Officers handle grievances at `/grievances`, and the dashboard is now at `/dashboard`. Both public features are flagged (`DCP_PUBLIC_PORTAL`, `DCP_GRIEVANCES`): on outside production, off in production until the Department decides (docs/20). Citizen OTPs and SMS appear in `docker compose logs app`, like staff OTPs. Demo grievances use mobiles `90000099NN`.
Imported users have no password; they set one at `/password/setup` ("First time here, or forgot your password?").
`npm install && npm run build` once, or `npm run dev` while editing views.

| Route | Screen | Who |
|---|---|---|
| `/` | Dashboard: filters, KPI tiles, state → district → co-district → works drill-down, "needs attention" lists | all except field users |
| `/works`, `/works/{id}` | Works list; unified progress record (chart, timeline, photos, milestones) | same |
| `/works/{id}/progress` | Web progress form (same rules; uploaded photos always flagged by R12) | DPC, co-district, agency |
| `/bulk-edit` | Bulk edit grid: stage / % / spend for many works; each changed row is one `bulk` update through the rules engine, with per-row results | DPC, state admin |
| `/approvals` | DPC queue: flagged / clean, review panel with photo distances, bulk approve | DPC, state admin |
| `/map` | Works map: base-map switcher (light, streets, satellite, satellite + labels, terrain, dark, Bhuvan), clustered status markers, search, legend filters, area shading, work panel with photos (`?base=`, `?work=`) | everyone with the dashboard |
| `/reports` | MIS-01…06 on screen, Excel and PDF (Gotenberg) | everyone with reports |
| `/aq` | Assembly Question desk: reply by constituency / scheme / year, PDF + Excel, history | DPC, state |
| `/alerts` | Alert inbox: open / acknowledged / closed, acknowledge or resolve with a note | everyone with alerts (DPC, DC, co-district, agency, state) |
| `/import` | Excel / CSV import of districts, co-districts, constituencies, users, works, releases, with preview; template download | state admin, DPC (own district: works, releases, and co-district / agency / field users) |
| `/photos/{id}` | Photo, served only inside the viewer's jurisdiction | logged-in users |

## Build status (against docs/14)

Done:
- Schema for all doc 03 tables.
- Roles, permissions and jurisdiction scoping.
- Rules engine R01–R11.
- OTP login, sync, photo upload, progress submission and the approval API.
- Audit log.
- Web: password + OTP sign-in, dashboard, works list and record, approval queue, light/dark theme.
- Demo progress history that goes through the real rules and approval services.
- Works map (OpenLayers): status markers by colour and shape, area shading by share delayed/stalled with table view, work popup with site radius and photo points, `geo:import-boundaries` command.
- Reports MIS-01…06 and the AQ desk: on screen, Excel, PDF with embedded Assamese/Bengali fonts, run timings for metric M4, weekly Excel email.
- Alerts A01–A05 with escalation (DPC → DC → DCP Division), quiet hours, rate limit, WhatsApp opt-in, daily digest, inbox and API; SMS/WhatsApp via log driver until providers are chosen.
- Workbook import (preview first, bad rows skipped and listed, re-import matches on e-Rupantor ID then code), fund release entry, web progress entry, rule R12.
- User import (Users sheet, docs/18 D-6): matched on mobile, role + jurisdiction replaced and audited, no passwords imported.
- Set or reset a web password with a mobile OTP (`/password/setup`, linked from sign-in); same response for unknown numbers; audited.
- Bulk edit grid (`channel = bulk`), one update per changed row, rejected rows keep their figures for correction.

Gaps:
- **UI labels are English only.** Data (stage names, district names) and rule messages switch to as / bn, but the ~140 interface strings still need `lang/as.json` and `lang/bn.json` from a translator.
- Status thresholds (stale 7 days, stalled 60 days) are in `config/dcp.php` and still need confirming at the week-2 workshop.

Next:
- e-Rupantor gateway.
