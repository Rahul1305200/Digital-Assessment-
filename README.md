# YouMind digital assessment (Laravel)

The pain-trial assessment system, moved onto Laravel 13 and MySQL. The screens (HTML, CSS, JavaScript) are the same
as the first version; Laravel now provides the database layer, sessions, configuration, migrations and commands.

- First version (plain PHP, untouched): `..\digital-assessment`
- Backup of that version and its database: `..\digital-assessment-backup-2026-11-06`

## Run locally

1. Start MySQL in Laragon.
2. `php artisan migrate` (creates the tables in `youmind_assessment_laravel`).
3. `php artisan assessment:setup` (creates the link key and a first admin if none exists).
4. Optional, to bring over the data from the first version: `php artisan assessment:import-v1 --fresh`.
   Participant links and staff passwords keep working because the key and password hashes are copied.
5. `.\start-local.ps1` and open http://localhost:8089/

## Database

- **Structure:** `php artisan migrate` (or import `database/dumps/schema.sql` into an empty MySQL 8 database).
- **Demo data:** `php artisan assessment:demo` creates 11 demo patients, one for every step of the journey, plus a sample
  recording. They are test records and stay out of the reports. Use `--fresh` to recreate them.
- **Not included on purpose:** staff passwords, real patient records, patient links and the audit history. `php artisan assessment:setup`
  creates a fresh link key and a first admin (credentials are written to `storage/app/private/LOCAL-LOGIN.txt`, which is not committed).

## How patients get in

Two shared links, printed as QR codes on the flyers: `/music` and `/no-music`. Everyone uses the same address.
The first scan shows a welcome screen; one tap on **Start** creates the patient in the database (group = the link
scanned, a code like `YM-AB12CD`) and opens the first question. The phone remembers the patient, so scanning the same
QR again later resumes their own screen with no typing. Every action is logged against that patient.

- Another phone or cleared browser: **Already started? Enter your study code** on the welcome screen.
- Shared phone: **Not you? Start over** under Assessment status on the waiting screens.
- Staff see the code and both links on the participant page under **Patient access** and can still add patients by hand.
- New patients start with consent **not confirmed** until staff record it. Sign-ups are limited to 40 per hour per
  network and 300 per hour overall.

Check it with `node tests/compare/enroll.cjs` and `node tests/compare/entry.cjs`. These features exist only in the Laravel version.

## How it is organised

| Piece | Where |
|---|---|
| Screens | `resources/app.html`, `public/assets/` |
| Study API (`/api.php?action=...`, same as before) | `app/Support/AssessmentApi.php`, `app/Support/helpers.php` |
| Route | `routes/web.php` |
| Tables | `database/migrations/` (baseline = identical to the first version) |
| Link key, audio, first-admin login | `storage/app/private/` (not committed) |
| Questions | `questions.json` |
| Setup and import commands | `app/Console/Commands/` |

The API logic was moved over unchanged so behaviour stays identical. The next step is to split it into
controllers and services with automated tests.

## Check it still behaves like the first version

With both versions running (first version on 8088, this one on 8089):

```
node tests/compare/compare.cjs
```

It runs about 75 identical steps against both (sign-in, forms, group changes, step release, audio ranges, roles,
exports) and prints any difference. Last run: 74 identical, 1 harmless header wording difference
(`Cache-Control` on the CSV download; both say `no-store`).

## Before going live

HTTPS and a real web server with PHP-FPM, a restricted MySQL user (not root), `APP_DEBUG=false`,
`SESSION_SECURE_COOKIE=true`, daily database and `storage/app/private` backups, a host that fits the clinic's
privacy rules, and the approved SMS and consent arrangements. SMS follow-ups can use the queue and scheduler.
