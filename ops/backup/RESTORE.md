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

## How a run behaves

1. The **database dump always runs first** and is finished (written,
   mirrored offsite, old copies pruned) before the documents step starts.
   Nothing in the documents step can stop or delay it.
2. **Documents directory not there yet** (no document uploaded yet — the
   disk creates it on the first upload — or the volume cannot be found):
   the run logs a `WARN` line and still ends with exit 0 ("backup OK
   (database only)").
3. **Directory exists but archiving fails**: the partial archive is deleted
   and the run ends with exit 1 and a `FATAL` line saying the database
   backup is complete but the documents are not backed up. Look at it the
   same day.

## Cron settings for the documents part

| Variable | Meaning |
|---|---|
| `DOCUMENTS_DIR` | Host path of the documents directory. Optional. |
| `DOCUMENTS_VOLUME` | Docker volume to look it up in when `DOCUMENTS_DIR` is empty (default `pos_admin_storage-data`). |

## The live server runs a different script — add the documents step at deploy

Production cron does **not** run this file today: it runs
`/var/backups/charity_db/pg_backup.sh`. That script only dumps the database,
so merchant documents are **not backed up on live** until it gets the same
step. When P1 is deployed, add to the live script, **after** its database
dump has finished (and without making the dump depend on it):

```sh
DOCS="$(docker volume inspect pos_admin_storage-data --format '{{ .Mountpoint }}')/app/private/documents"
if [ -d "$DOCS" ]; then
    tar -C "$DOCS" -cf - . | gzip -9 \
        | openssl enc -aes-256-cbc -pbkdf2 -salt -pass file:<same key file> \
            -out "<backup dir>/documents_$(date -u +%Y%m%dT%H%M%SZ).tar.gz.enc" \
        || { echo "FATAL: documents archive failed (database backup is complete)" >&2; exit 1; }
else
    echo "WARN: no documents directory yet — database only" >&2
fi
```

…or switch the live cron to this script. Check the real volume name with
`docker volume ls` first.
