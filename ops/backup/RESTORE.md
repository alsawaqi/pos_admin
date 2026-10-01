# Restoring a MITHQAL backup

`charity-db-backup.sh` writes two encrypted files per run, with the same
timestamp:

| File | Contents |
|---|---|
| `charity_db_<TS>.dump.gz.enc` | `pg_dump` (custom format) of the whole shared `charity_db` |
| `documents_<TS>.tar.gz.enc` | pos_admin's private merchant documents (CR certificates, owner ID cards, ...) |

The database rows in `pos_company_documents` only point at files
(`disk = documents`, `path = companies/<uuid>/<file>`). Restore **both files
of the same run**, or document downloads will 404.

Both are encrypted with the key in `BACKUP_KEY_FILE` (AES-256-CBC, PBKDF2).
Without that key the backups cannot be read. Keep a copy of it offline.

## 1. Find the documents directory

The documents disk is `storage/app/private/documents` inside the
`storage-data` volume of the pos_admin production stack:

```sh
docker volume ls | grep storage-data          # e.g. pos_admin_storage-data
DOCS="$(docker volume inspect pos_admin_storage-data --format '{{ .Mountpoint }}')/app/private/documents"
```

## 2. Restore the database

Never restore over the live database without a fresh backup of it first.

```sh
openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/etc/mithqal/backup.key \
    -in charity_db_<TS>.dump.gz.enc | gunzip > /tmp/charity_db.dump
pg_restore --no-owner --no-privileges --dbname=charity_db /tmp/charity_db.dump
shred -u /tmp/charity_db.dump
```

## 3. Restore the documents

```sh
mkdir -p "$DOCS"
openssl enc -d -aes-256-cbc -pbkdf2 -pass file:/etc/mithqal/backup.key \
    -in documents_<TS>.tar.gz.enc | tar -xz -C "$DOCS"
chown -R www-data:www-data "$DOCS"       # the PHP container's user (uid 33)
```

To only look inside an archive: replace `tar -xz -C "$DOCS"` with `tar -tz`.

## 4. Check

- In the admin portal, open a merchant with documents and download one.
- `docker compose -f docker-compose.prod.yml exec pos_admin php artisan tinker`:
  `App\Models\CompanyDocument::all()->every(fn ($d) => Storage::disk('documents')->exists($d->path))`
  must return `true`.

## Cron settings for the documents part

| Variable | Meaning |
|---|---|
| `DOCUMENTS_DIR` | Host path of the documents directory. Optional. |
| `DOCUMENTS_VOLUME` | Docker volume to look it up in when `DOCUMENTS_DIR` is empty (default `pos_admin_storage-data`). |
| `DOCUMENTS_REQUIRED` | `1` (default) fails the run when the directory is missing, so a broken setup is noticed; `0` backs up the database only. |
