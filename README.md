<p align="center">
  <img src="public/images/logo.png" alt="BW Superbakeshop" width="160">
</p>

<h1 align="center">BW Superbakeshop · Ordering</h1>

<p align="center">
  Quantity-only ordering for store staff, with a live link to <strong>ECPOS</strong>.<br>
  Stores place one order (TR) a day; admins see everything in one place.
</p>

<p align="center">
  <img alt="Laravel 12" src="https://img.shields.io/badge/Laravel-12-ff2d20?logo=laravel&logoColor=white">
  <img alt="PHP 8.2+" src="https://img.shields.io/badge/PHP-8.2%2B-777bb4?logo=php&logoColor=white">
  <img alt="React 19" src="https://img.shields.io/badge/React-19-61dafb?logo=react&logoColor=black">
  <img alt="TypeScript" src="https://img.shields.io/badge/TypeScript-3178c6?logo=typescript&logoColor=white">
  <img alt="Tailwind CSS 4" src="https://img.shields.io/badge/Tailwind-4-06b6d4?logo=tailwindcss&logoColor=white">
  <img alt="Inertia" src="https://img.shields.io/badge/Inertia.js-3-9553e9">
  <img alt="MySQL" src="https://img.shields.io/badge/MySQL-4479a1?logo=mysql&logoColor=white">
</p>

---

## Contents

- [What it does](#what-it-does)
- [Tech stack](#tech-stack)
- [Getting started](#getting-started)
- [Configuration](#configuration)
- [How ordering works](#how-ordering-works)
- [ECPOS integration](#ecpos-integration)
- [Everyday commands](#everyday-commands)
- [Project layout](#project-layout)
- [Testing](#testing)
- [Going live](#going-live)
- [Troubleshooting](#troubleshooting)

---

## What it does

### For store staff
- **Place an order** from a phone, tablet or desktop: pick quantities across four catalogs — **BW Products**, **Warehouse**, **Merchandise** and **Rejects**. Search, filter by category, or tick "Selected only".
- **Copy last order** fills the sheet with the store's previous order in one tap.
- **Autosave**: leave the page and come back; the draft (quantities, notes) is kept under the same TR number.
- **Post** the order before the daily cutoff. A pending order can be **reset** to a draft to add a missed item.
- **Reminders** 30 and 10 minutes before the cutoff if today's order isn't placed yet.
- **My Orders**, order details with history, and the live **ECPOS status** of each sent order.

### For admins
- **Today's Orders**: which active stores have ordered and which haven't, grouped by cutoff, with a sidebar badge and a pre-cutoff reminder.
- **All Store Orders**: consolidated items across stores with date, store, status, product and TR filters, plus CSV export.
- **Stores**: add/edit/deactivate, logins, password resets, and the **ECPOS store ID** link.
- **Product Catalog** (synced from ECPOS), archived items (items are archived, never deleted), categories per catalog, Excel export.
- **Order Settings**: the default cutoff, per-store and per-day deadlines (with history), one-order-per-day rule.
- **Cancel** a posted TR (reason required) so the store can order again.

### Rules the system enforces
- One active TR per store per day (configurable).
- Submissions close at the store's cutoff (date-specific → store's own → default), in `APP_TIMEZONE`.
- Order date is fixed when the order is started; staff can't edit it on the order sheet.
- Every store has its **own TR series**: `TR-<ECPOS store ID>-<6 digits>`, e.g. `TR-BW0018-000001`.
- A store only ever sees its own orders (404 for anything else).

---

## Tech stack

| Layer | Technology |
|---|---|
| Backend | Laravel 12, PHP 8.2+, MySQL |
| Frontend | React 19, TypeScript, Inertia.js 3, Tailwind CSS 4, Radix UI, lucide icons, SweetAlert2 |
| Build | Vite 7 |
| Excel export | OpenSpout |
| Tests | PHPUnit (SQLite in memory) |

---

## Getting started

### Requirements
PHP 8.2+ (with `pdo_mysql`, `mbstring`, `zip`), Composer, Node.js 20+, MySQL 8 / MariaDB 10.6+ (XAMPP works).

### Install

```bash
git clone https://github.com/SurfingPanda/ecordering-new.git
cd ecordering-new

composer install
npm install

cp .env.example .env          # Windows: copy .env.example .env
php artisan key:generate
```

Create an empty MySQL database (default name `laravel_ordering`), set the `DB_*` values in `.env`, then:

```bash
php artisan migrate --seed    # tables + the admin account and 3 sample stores
npm run build                 # or `npm run dev` while developing
```

### Load the catalog and stores from ECPOS

Add `ECPOS_API_KEY` to `.env` (see [Configuration](#configuration)), then:

```bash
php artisan ecpos:sync-items        # BW Products, Merchandise, Rejects
php artisan ecpos:sync-stores       # links existing stores, adds the rest
php artisan stores:create-logins    # a login for every store that has none
```

Add `--dry-run` to the two sync commands to see what *would* change without saving anything.

### Run it

```bash
php artisan serve                   # http://localhost:8000
```

…or point XAMPP/Apache at the `public/` folder. Keep `npm run dev` running if you're editing the frontend.

### Sign in

| Account | Email | Password |
|---|---|---|
| Admin (seed) | `staff@bwbakeshop.test` | `password` |
| Store logins | `bw.<storename>@ecticketph.com` | `welcome` (from `stores:create-logins`) |

> These are **development defaults**. Change them before real use: staff can change their own password from **My account** (click your name in the sidebar), and admins can reset any store's password from the store's page.

---

## Configuration

All settings live in `.env` (never committed). The important ones:

| Variable | Purpose | Default |
|---|---|---|
| `APP_TIMEZONE` | Business time zone: decides "today" and the cutoff | `Asia/Manila` |
| `DB_*` | MySQL connection | `laravel_ordering` |
| `SESSION_LIFETIME` | Minutes of inactivity before sign-out | `120` |
| `SESSION_EXPIRE_ON_CLOSE` | Sign out when the browser closes (unless "Keep me signed in" was ticked) | `true` |
| `ECPOS_BASE_URL` | ECPOS external ordering API | `https://xv2.eljin.org/api/external/orders` |
| `ECPOS_API_KEY` | **Secret.** Sent as `X-API-Key`; used only on the server | *(empty)* |
| `ECPOS_SEND_ORDERS` | `true` = posting a TR also sends it to ECPOS | `false` |
| `ECPOS_SKIP_GROUPS` | ECPOS item groups to leave out of the sync, comma-separated | *(none)* |
| `ECPOS_TIMEOUT` | Seconds to wait for ECPOS | `30` |

Business rules that admins change in the app (no `.env` edit needed): default cutoff time, per-store / per-day deadlines, and the one-order-per-day switch — under **Order Settings**.

---

## How ordering works

```
Place an Order ─► pick the date ─► order sheet (autosaves a draft) ─► Place order ─► PENDING
                                                                          │
                              Reset ◄── (store, to edit) ◄────────────────┤
                                                                          ▼
                                                              Post order (before cutoff)
                                                                          │  ──► sent to ECPOS (if enabled)
                                                                          ▼
                                                                       POSTED ──► Cancel (admin only, reason required)
```

- **TR numbers** are reserved when a store opens the *New order* prompt and never change afterwards. Each store counts up in its own series.
- **Statuses:** `pending`, `posted`, `cancelled` (plus an internal `draft` that holds the TR number while the order is being filled in).
- **Cancelling** keeps the TR and its items on record and frees the store to order again that day.
- **Catalog items** come from ECPOS and are routed by ECPOS item group: `MERCHANDISE` → Merchandise, `BW REJECTS` → Rejects, everything else → BW Products. Warehouse items are local only. Items that disappear from ECPOS are archived, not deleted.

---

## ECPOS integration

ECPOS exposes a small, quantity-only API (stores, items, staff, create order, order status). This app uses it **server-side only**:

| What | Where | Direction |
|---|---|---|
| Item catalog | `ecpos:sync-items` (also **Product Catalog → Sync from ECPOS**, and a nightly run at 02:00) | read |
| Store list | `ecpos:sync-stores` (also **Stores → Sync from ECPOS**) | read |
| Send a posted order | automatic on **Post order** when `ECPOS_SEND_ORDERS=true` | `POST /` |
| Order status | order page, "ECPOS: …" chip with refresh | `GET /{journalid}` |

Details worth knowing:

- Each store needs its **ECPOS store ID** (set automatically by the store sync, editable on the store's page). A store without one can't post while sending is on.
- The order sent contains the store, the staff login name, the TR number (plus any note) as the description, and each ECPOS item with its quantity. Items that aren't in ECPOS are left out and noted on the order.
- If ECPOS refuses the order or can't be reached, the TR **stays pending** and the store sees why. Sends are **never retried automatically**, so an order can't be created twice.
- A posted order that never reached ECPOS shows a **Send to ECPOS** button (once).
- ECPOS has no cancel endpoint: cancelling a posted TR here does **not** cancel it in ECPOS.
- The API key never reaches the browser and is never committed. Automated tests run with no key and sending off, and use a fake ECPOS.

---

## Everyday commands

```bash
php artisan test                         # run the test suite
npm run dev                              # Vite dev server
npm run build                            # production assets
php artisan ecpos:sync-items [--dry-run]
php artisan ecpos:sync-stores [--dry-run]
php artisan stores:create-logins [--password=welcome] [--domain=ecticketph.com] [--dry-run]
php artisan schedule:work                # runs the nightly catalog sync locally
```

**Scheduler:** the 02:00 catalog sync only runs if Laravel's scheduler runs. On a server add one cron line:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## Project layout

```
app/
  Console/Commands/      ecpos:sync-items, ecpos:sync-stores, stores:create-logins
  Http/Controllers/      Orders, Items, Dashboard, Account, Auth/, Admin/ (stores, settings, reports, today, categories, ECPOS sync)
  Models/                Store, User, Item, Category, Order, OrderItem, OrderEvent, settings & deadline tables
  Services/              SubmissionDeadline, BarcodeGenerator, Ecpos{Client,Catalog,Stores,Orders}
config/ecpos.php         ECPOS settings (reads .env)
database/                migrations and seeders (admin + 3 sample stores, no sample items)
resources/js/
  pages/                 Inertia pages: auth/, dashboard, orders/, retails/, admin/, account
  components/            dialogs, charts, order cards, shared UI
  layouts/app-layout.tsx sidebar, reminders, pop-ups
routes/web.php           all routes, grouped by role
tests/Feature/           feature tests for every rule above
```

---

## Testing

```bash
php artisan test
```

Tests use an in-memory SQLite database, so your MySQL data is never touched. They cover access control (stores can't see each other's orders), deadlines and the one-order-per-day rule, TR numbering, autosave, reset/cancel, the catalog and store syncs, and sending to ECPOS — all against a **fake** ECPOS.

---

## Going live

- [ ] `APP_ENV=production`, `APP_DEBUG=false`, a fresh `APP_KEY`, HTTPS enabled.
- [ ] Change the seeded admin password and the `welcome` store passwords (or let each store change theirs from **My account**).
- [ ] Set `ECPOS_API_KEY` in the server's `.env`; flip `ECPOS_SEND_ORDERS=true` only when ECPOS is ready to receive real orders.
- [ ] `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan config:cache route:cache`.
- [ ] Add the scheduler cron line above.
- [ ] Schedule regular database backups.

---

## Troubleshooting

| Symptom | Likely cause / fix |
|---|---|
| Blank page or missing styles | Run `npm run build` (or keep `npm run dev` running). |
| "ECPOS_API_KEY is not set" | Add the key to `.env`, then `php artisan config:clear`. |
| Posting says the store "isn't linked to ECPOS" | Run `ecpos:sync-stores`, or set the store's ECPOS store ID on its page. |
| A store can't sign in | Check it has a login (store page → Logins) and that the store is active. After too many wrong passwords an email is locked for one minute. |
| Changes to `.env` ignored | `php artisan config:clear` |
| Nightly sync never runs | The scheduler isn't running — see [Everyday commands](#everyday-commands). |

---

<p align="center"><sub>Built for BW Superbakeshop · Laravel · Inertia · React</sub></p>
