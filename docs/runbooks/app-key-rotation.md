# APP_KEY rotation — pos_admin

Companion to `charity-db-restore.md`. Rotate `APP_KEY` proactively every 12 months, or immediately on key compromise (laptop stolen, secret leaked in CI logs, ex-employee with access, etc.).

## What APP_KEY protects

`APP_KEY` is the symmetric key for `Illuminate\Encryption\Encrypter`. Anywhere in this app that depends on Laravel's encryption uses it. As of Sprint 3 that means:

| Surface | Mechanism | Notes |
|---|---|---|
| Session payload | Laravel session encryption | Rotates without re-encrypting — sessions just bounce to /login next request. |
| Cookies (where flagged) | `EncryptCookies` middleware | Same — re-login is the recovery path. |
| `pos_company_owners.civil_id` | `encrypted` cast | **Must be re-encrypted** during rotation (see procedure). |
| `pos_company_owners.phone` | `encrypted` cast | Same. |
| `pos_company_owners.email` | `encrypted` cast | Same. |
| `pos_users.phone` | `encrypted` cast | Same. |
| Signed URLs (`URL::signedRoute`) | HMAC of APP_KEY | Old links break; usually fine because TTLs are short. |
| CSRF tokens | Hashed APP_KEY in token | Rotating invalidates in-flight forms — same recovery as sessions. |

Backup-file encryption (`ops/backup/charity-db-backup.sh`) uses a SEPARATE key (`BACKUP_KEY_FILE`) and is NOT affected by `APP_KEY` rotation. Rotate that key on its own schedule.

## Procedure

### 1. Pre-flight

- Schedule a maintenance window (15–30 min). The re-encryption pass runs against live data; production must accept a brief read-only period.
- Take a fresh nightly backup (do not rely on yesterday's — you want a snapshot from immediately before the rotation).
- Verify you can read it back (run steps 1–2 of `charity-db-restore.md` against the new dump).

### 2. Generate the new key

```bash
docker compose run --rm --no-deps artisan php artisan key:generate --show
# Outputs: base64:abc123...
```

Do **not** apply it to `.env` yet. Hold the value in your password manager.

### 3. Add it as a fallback BEFORE making it primary

Laravel supports a `APP_PREVIOUS_KEYS` env var (comma-separated). During the transition window, set:

```
APP_KEY=<OLD key — leave alone for now>
APP_PREVIOUS_KEYS=
```

Restart the PHP container so the env reloads:
```bash
docker compose restart php-fpm
```

At this point reads still use the old key. We're just sanity-checking the deployment can hold two keys.

### 4. Promote the new key to primary, old key to fallback

Edit `.env`:
```
APP_KEY=<NEW key generated in step 2>
APP_PREVIOUS_KEYS=<OLD key>
```

Restart the PHP container. Verify:
- A new user session works (login → /admin loads).
- A model that has encrypted casts reads back correctly:
  ```bash
  docker compose run --rm --no-deps artisan php artisan tinker --execute='dump(\App\Models\CompanyOwner::first()?->phone);'
  ```
  Expected: plaintext value, no `DecryptException`.

### 5. Re-encrypt the persisted ciphertext

Saves of any model with an `encrypted` cast now write with the new key. To force-rewrite the existing rows so the old key is no longer needed for reads, run the one-shot Artisan command below.

> **Note:** as of Sprint 3 there is no `app:rotate-encrypted-columns` command. When `APP_KEY` rotation becomes a recurring operation, create one that iterates the column inventory below. Until then, the equivalent tinker snippet is:

```bash
docker compose run --rm --no-deps artisan php artisan tinker
```
```php
// Inside tinker:
\App\Models\CompanyOwner::query()->chunkById(500, function ($rows) {
    foreach ($rows as $r) {
        // The cast decrypts on read with whichever key works (new or
        // previous) and re-encrypts on save with the new key.
        $r->saveQuietly();
    }
});

\App\Models\User::query()->whereNotNull('phone')->chunkById(500, function ($rows) {
    foreach ($rows as $r) {
        $r->saveQuietly();
    }
});
```

### 6. Verify + drop the fallback

After the re-encryption pass, every column should decrypt with the new key alone. Confirm by temporarily clearing `APP_PREVIOUS_KEYS`:

```
APP_KEY=<NEW key>
APP_PREVIOUS_KEYS=
```

Restart, then re-run the verification reads from step 4. If everything still decrypts cleanly, the rotation is done. Otherwise restore `APP_PREVIOUS_KEYS` and find the row(s) that didn't get re-encrypted.

### 7. Archive the old key

Move the old key into the secrets vault under `archived/app-keys/`. Do not delete it for at least 90 days — if a backup taken before rotation needs to be restored, the old `APP_KEY` is needed to read the encrypted columns inside that backup (the backup itself is decryptable with the backup key, but the column ciphertext was encrypted with the old `APP_KEY`).

### 8. Schedule the next rotation

- Calendar reminder 12 months out.
- Cross-reference: when an admin leaves the team with access to `.env`, do an unscheduled rotation within 24h.

## Encrypted-column inventory

Keep this list in sync with reality. When a new `encrypted` cast lands on a column, **add it here in the same PR**. Otherwise the next rotation will silently leave that column ciphertext stuck on the old key.

| Table | Column | Added in |
|---|---|---|
| pos_company_owners | civil_id | Sprint 3 |
| pos_company_owners | phone | Sprint 3 |
| pos_company_owners | email | Sprint 3 |
| pos_users | phone | Sprint 3 |

Deferred (table doesn't exist yet — add when it does):
- customers.phone, customers.email (Phase 6 — Merchant Portal)
- customer_vehicles.plate_number (Phase 6)
- pos_staff.pin_hash, pos_staff.phone (Phase 4 — Merchant Portal POS Staff)
- device bank-credentials JSON column (Phase 8 — POS Backend Services)
