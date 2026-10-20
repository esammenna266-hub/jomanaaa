# Frontend

Arabic (RTL) single-page app: React 19, Vite, TypeScript. It talks to the backend API at `/api/v1` with a Sanctum bearer token.

```powershell
cd frontend
npm install
copy .env.example .env     # optional: where the dev server proxies /api
npm run dev                # http://localhost:5173  (proxies /api to http://127.0.0.1:8000)
npm test                   # Vitest + Testing Library
npm run build              # type-check and build to dist/
```

In production serve `dist/` from the same origin as the API, or set `VITE_API_URL` at build time.

## What exists
Login (and forced password change for temporary passwords), the app shell with a permission-aware menu, and the screens for branches, users, customers and shipments (list, new/edit form, and the shipment file with approval, collection, installments, receive, deliver, cancel and PDF printing), warehouse transfers (exit form, barcode scan and count differences), cargo trips (load with fingerprint upload, stage updates, printing, expenses split across shipments, delays), passenger trips and bookings (trip list and new trip, the seat map with booking, ticket printing and cancel/refund, depart/complete/cancel, passenger manifest, bookings list, express seats: the bus-trip choice on express shipments and the linked shipments with the free express seats on the trip file), hotel and car bookings, reports and the dashboard, external offices (list, office file with its account, payments and price list; and "my account" for the office's own users) and finance (treasuries with movements, cash remittances). Screens come from section 5 of [../docs/spec/handover.pdf](../docs/spec/handover.pdf).

## Structure

```
src/
├─ app/            shell: providers, routes, layout, nav, auth (AuthContext, route guards)
├─ modules/        one folder per backend module, each with its screens and api.ts
│  ├─ identity/    login, change password, users
│  ├─ core/        branches, external offices (agents)
│  ├─ shipping/    shipments (list, form, file) and warehouse transfers (list + scan, exit form, receive)
│  ├─ trips/       cargo trips (list, new, file with stages and printing)
│  ├─ booking/     passenger trips (list, new, file with seat map and express seats), bookings, hotel and car bookings
│  ├─ reports/     reports page (pick, period, print, CSV for Excel) and the dashboard cards on the home page
│  ├─ finance/     treasuries, movements, remittances
│  ├─ master-data/ customers
│  └─ home/
├─ shared/
│  ├─ api/         fetch client (token, errors, 401 handling) and API types
│  ├─ ui/          Button, Fields, Modal, DataTable, Pagination, ...
│  ├─ forms/       server validation error helpers
│  └─ hooks/
├─ i18n/ar.ts      all Arabic text
├─ styles/app.css  design tokens (light and dark) and component styles
└─ test/           test setup and the fetch-mocking helpers
```

## Conventions
- Permissions: the API returns `permissions` (code -> `all` | `branch` | `own`) for the signed-in user. The menu and routes use `can(code)`; the backend remains the real gate.
- Server validation errors (HTTP 422) appear next to the field they belong to; other errors appear at the top of the form.
- Phone numbers, usernames and codes use the `ltr` class so they read left-to-right inside Arabic text.
- No hard-coded Arabic in components: add strings to `i18n/ar.ts`.
