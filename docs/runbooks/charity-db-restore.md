# Restore drill — charity_db from encrypted nightly backup

Companion to `ops/backup/charity-db-backup.sh`. Tested quarterly per blueprint §13 exit criteria.

A single dump covers the whole shared Postgres instance (charity tables + pos_admin tables + future pos_merchant / pos_api tables), because they all live in `charity_db`.

## Pre-flight

You need:
- The encrypted dump file `charity_db_YYYYMMDDTHHMMSSZ.dump.gz.enc`.
- The same key file that was used to encrypt it (`/etc/mithqal/backup.key` or wherever `BACKUP_KEY_FILE` pointed).
- A target Postgres instance with an empty database (for a drill, use a scratch container; for a real incident, the production instance after it's been wiped or replaced).
- `openssl`, `gunzip`, `pg_restore` on the box running the restore.

## Steps

1. **Decrypt + decompress to a plain dump.**
   ```bash
   openssl enc -d -aes-256-cbc -pbkdf2 \
       -pass file:/etc/mithqal/backup.key \
       -in   charity_db_20260526T030000Z.dump.gz.enc \
   | gunzip > /tmp/charity_db.dump
   ```
   If `openssl` errors with `bad decrypt`, the key file doesn't match the one used at backup time. Cross-check with the APP_KEY rotation runbook — the backup key is independent of `APP_KEY`, so check `/etc/mithqal/backup.env` history.

2. **Verify the dump is well-formed.**
   ```bash
   pg_restore --list /tmp/charity_db.dump | head
   ```
   You should see a TOC listing every table prefixed `pos_` and the charity-owned tables alongside. If `pg_restore` says "expected a Magic header" you're looking at corrupted/partial output from step 1 — re-do the decrypt step.

3. **Provision the target.**
   ```bash
   createdb -h <host> -U postgres charity_db
   ```
   For a drill: spin up `docker run --rm -p 5433:5432 -e POSTGRES_PASSWORD=x postgres:16` and target that.

4. **Restore.** `--no-owner` + `--no-privileges` matches the dump flags so role mismatches don't block.
   ```bash
   pg_restore \
       --host=<host> --port=<port> --username=<user> --dbname=charity_db \
       --no-owner --no-privileges \
       --jobs=4 \
       /tmp/charity_db.dump
   ```

5. **Smoke-check the restored data.**
   ```bash
   psql -h <host> -U <user> -d charity_db -c "SELECT COUNT(*) FROM pos_companies;"
   psql -h <host> -U <user> -d charity_db -c "SELECT COUNT(*) FROM pos_audit_logs;"
   psql -h <host> -U <user> -d charity_db -c "SELECT MAX(created_at) FROM pos_audit_logs;"
   ```
   Compare against pre-incident expectations (or against a recent monitoring snapshot).

6. **Point the app at the restored DB.** Update each `.env` (`pos_admin/src/.env`, charity app's, future pos_merchant), restart the PHP containers. The `pos_admin_migrations` history table travels in the dump so no migrations need to re-run.

7. **Tear down the drill target** (drills only — not production!).
   ```bash
   docker rm -f <scratch-postgres-container>
   shred -u /tmp/charity_db.dump
   ```

## Schedule

- **Nightly backup** runs at 03:00 UTC via cron (see backup script header for the line).
- **Quarterly restore drill** rotates between team members. Drill is "green" iff steps 1–5 complete with row counts within a known margin of the production sample.
- **Post-incident restore** invokes the same procedure — log the incident id in `docs/incidents/`.

## Encryption notes

- The dump is encrypted with a SEPARATE symmetric key (`BACKUP_KEY_FILE`), NOT the Laravel `APP_KEY`. Rotating `APP_KEY` (per `app-key-rotation.md`) has no effect on backup readability.
- The application-layer `encrypted` casts on `pos_company_owners.{civil_id,phone,email}` and `pos_users.phone` mean those columns are double-encrypted: the column value is already ciphertext (encrypted with `APP_KEY`) at dump time, then the whole dump is encrypted again with the backup key. Restoring decrypts the outer layer; reading the columns through the app's models decrypts the inner layer. **You need both keys** (the backup key for restore, the current or any rotated-in `APP_KEY` for app access) to read PII.
