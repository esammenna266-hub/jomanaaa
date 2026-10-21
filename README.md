# Gomana System (نظام الجومانا)

Shipping and travel management for branches in Egypt and Sudan: shipments, treasuries, trips, documents.
Phases and status: [ROADMAP.md](ROADMAP.md). Source spec: [docs/spec/handover.pdf](docs/spec/handover.pdf).

## Repository layout

```
gomana-system/
├─ backend/     Laravel 13 API (PHP 8.3, MySQL 8). All business logic lives here.
├─ frontend/    Arabic RTL web app: React + Vite + TypeScript (see frontend/README.md)
├─ docs/
│  └─ spec/     The handover file the system is built from
├─ ROADMAP.md   Phases and what is done
└─ README.md
```

## Backend structure

Code is grouped by business module, not by technical layer. Each module owns its models, services, controllers and resources.

```
backend/
├─ app/
│  ├─ Modules/
│  │  ├─ Identity/     users, roles, permissions, login          (User, Role, Permission)
│  │  ├─ Core/         branches, external offices, settings, audit log
│  │  ├─ MasterData/   customers, vehicles, drivers, seat layouts, border points, destinations, clearing agents, currencies
│  │  ├─ Shipping/     shipments, approvals, warehouse transfers, public tracking, delivery
│  │  ├─ Booking/      bus/flight/ship trips, seat map, seat booking (row-locked), cancel and refund, hotel and car bookings, express seats (cargo shipments riding a bus trip, unused seats released to passengers after the cutoff)
│  │  ├─ Trips/        truck trips, driver undertaking + fingerprint, stages, expenses, delays
│  │  ├─ Reports/      management and finance reports (tables for the screen and CSV), the owner's dashboard
│  │  ├─ Finance/      treasuries, the payments ledger, installments, remittances
│  │  └─ Documents/    PDF documents, archive, locking, edit requests (+ PDF engine)
│  │      each module:  Models/  Services/  Http/Controllers/  Http/Resources/  Console/   (only what it needs)
│  ├─ Support/         shared building blocks: AccessScope, PhoneNumber, ArabicMoney, SerialNumberService, Auditing/
│  ├─ Http/            base Controller, CrudController, ModelResource
│  └─ Providers/
├─ routes/
│  ├─ api.php          loads the files below under /api/v1
│  └─ api/             one file per module (+ public.php for routes that need no token)
├─ database/
│  ├─ sql/             the schema: hand-written MySQL (tables, views, triggers, seed data), applied by one migration
│  ├─ migrations/      Laravel migrations (SQL loader, Sanctum, queue)
│  └─ factories/
├─ resources/views/documents/   Blade templates for the printed documents
├─ config/gomana.php   company details, tracking URL, PDF engine settings
└─ tests/
   ├─ Feature/<Module>/   API and database-rule tests, one folder per module
   └─ Unit/Support/
```

Rules that keep the split clean:
- A module may use `Support/` and the models of other modules; business rules stay in the owning module's services.
- Money only moves through `Finance\Services\LedgerService`. Nothing writes to `payments` directly.
- Access scoping ("branch" / "own") goes through `Support\AccessScope`, never in the UI only.

## Run it

```powershell
cd backend
copy .env.example .env             # set DB_PASSWORD (and DB_HOST/DB_PORT if needed)
php artisan key:generate
php artisan migrate                # tables, views, triggers and reference data
php artisan gomana:create-owner boss --name="المالك"    # first owner account
php artisan serve
```

Create the `gomana` and `gomana_test` MySQL databases (utf8mb4) first. Scheduled work (overdue installments) needs the usual `php artisan schedule:run` every minute.

### PDF documents
Documents are HTML templates rendered by headless Chromium (Browsershot). The server needs:
- Node and `npm install` in `backend/` (installs puppeteer; set `PUPPETEER_SKIP_DOWNLOAD=1` to use a system browser)
- a Chromium, Chrome or Edge binary: set `PDF_CHROME_PATH` (and `PDF_NODE_BINARY` / `PDF_NPM_BINARY` if they are not on PATH, `PDF_NO_SANDBOX=1` in containers)
- an Arabic font (for example Noto Naskh Arabic on Linux)

Company name, address, phones and the undertaking text are set in `.env` (see `config/gomana.php`).

### Demo data and the company logo
```powershell
cd backend
node database/demo/demo-data.mjs http://127.0.0.1:8000 owner "<owner password>"   # branches, treasuries, logins, trips, shipments, an office
```
It runs through the public API (so every rule is checked), refuses to run twice, and writes the new logins to `backend/DEMO_ACCOUNTS.local.txt` (ignored by git). Do not run it on a real database.

The logo shows in the web app (`frontend/public/logo.png`) and in the header of every printed paper (`backend/resources/branding/logo.png`, or set `COMPANY_LOGO`). Without a file the company name is shown alone. Reports open as A4 PDF (portrait, or landscape when the table is wide) and as an Excel workbook from the Reports screen.
### Live seat maps (WebSocket)
Seat maps update on every screen the moment someone books, cancels or moves a trip. It uses Laravel Reverb: set `BROADCAST_CONNECTION=reverb` and the `REVERB_*` values in `backend/.env` (see `.env.example`), put the same key in `frontend/.env` as `VITE_REVERB_APP_KEY`, and keep this running next to the API:

```powershell
cd backend; php artisan reverb:start      # port 8080
```
The event carries no passenger data; screens ask the API again, so permissions still apply. If Reverb is down or not configured, bookings still work and the seat map refreshes every 15 seconds. In production run `reverb:start` as a service and put it behind HTTPS (`wss`) on the same domain.
### Putting a demo online
The web app is static and fits **Vercel**. The API is PHP/Laravel with MySQL and cannot run there; host it on anything that runs PHP 8.3 and MySQL 8 (a VPS, Railway, Render, Fly.io, or hosting with PHP 8.3).

1. **API host:** set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, the `DB_*` values and `CORS_ALLOWED_ORIGINS=https://<your vercel domain>`; then `php artisan key:generate`, `php artisan migrate --force` and `php artisan gomana:create-owner <name>`. Use a new database with its own password, never a real one. PDFs need Node and Chrome on that host (`PDF_CHROME_PATH`); everything else, including Excel, works without them. Live seat maps need `php artisan reverb:start` behind HTTPS; without it the seat map refreshes every 15 seconds.
2. **Vercel:** import this repository, set *Root Directory* to `frontend`, and add `VITE_API_URL=https://<your api host>` (and the `VITE_REVERB_*` values for live updates). `frontend/vercel.json` already sends every path to the app.
3. For a client demo, fill the demo database with `node backend/database/demo/demo-data.mjs` against the API and share the logins privately.
### Frontend
```powershell
cd frontend; npm install; npm run dev    # http://localhost:5173, proxies /api to the backend on :8000
```

### Tests
They need a real MySQL (the schema uses MySQL-only features) and a `gomana_test` database, which they wipe on every run.

```powershell
cd backend
php artisan test --compact
$env:GOMANA_PDF_E2E = "1"; php artisan test --filter=RealPdfRendering   # optional: real Chromium
```

Set `DB_HOST` / `DB_PORT` / `DB_PASSWORD` in the environment to point the tests at another server.
