#!/usr/bin/env bash
#
# Daily encrypted backup of charity_db (Sprint 3 hardening,
# blueprint §9.14).
#
# What it does:
#   1. pg_dump  → custom-format dump of charity_db
#   2. gzip     → compress
#   3. openssl  → AES-256-CBC encrypt with a key from disk
#   4. local    → drop the encrypted blob in $BACKUP_LOCAL_DIR
#   5. rclone   → mirror to a remote (OneDrive/GDrive/S3) so a
#                 Hostinger-side disaster doesn't take the backups
#                 with it
#   6. retain   → prune local copies older than $BACKUP_RETENTION_DAYS
#
# A single dump covers the WHOLE shared Postgres instance — charity
# tables, pos_admin tables, and (future) pos_merchant + pos_api
# tables — because they all live in the same database. Restoring is
# a single pg_restore against an empty instance.
#
# LAUNCH-P1 P1-16 — merchant documents. The database only holds each
# document's metadata; the files themselves (CR certificates, owner ID
# cards, ...) live on pos_admin's PRIVATE documents disk:
#   storage/app/private/documents  inside the `storage-data` volume.
# Every run therefore ALSO archives that directory (tar | gzip | the
# same openssl key) as documents_<TIMESTAMP>.tar.gz.enc next to the
# database dump, mirrors it offsite and prunes it on the same schedule.
# The two files of one run share the timestamp; restore them together.
#
# Environment variables (read from /etc/mithqal/backup.env or the
# inherited shell env):
#   PGHOST                — Postgres host (e.g. chariyt-db)
#   PGPORT                — Postgres port (default 5432)
#   PGUSER                — DB user with read on every schema
#   PGPASSWORD            — DB password (or use a ~/.pgpass file)
#   PGDATABASE            — should always be charity_db
#   BACKUP_LOCAL_DIR      — where to write the encrypted file
#   BACKUP_KEY_FILE       — file containing the symmetric key (one line)
#   BACKUP_RETENTION_DAYS — local prune cutoff (default 14)
#   RCLONE_REMOTE         — `<remote>:<path>` for `rclone copy`
#                           (e.g. onedrive:Mithqal/Backups). Leave
#                           unset to skip the offsite step.
#   DOCUMENTS_DIR         — host path of the private documents
#                           directory. Optional: when unset it is found
#                           from the docker volume DOCUMENTS_VOLUME
#                           (default pos_admin_storage-data) as
#                           <mountpoint>/app/private/documents.
#   DOCUMENTS_REQUIRED    — 1 (default) = fail the run if the documents
#                           directory cannot be found; 0 = warn and
#                           back up the database only.
#
# Restore (see ops/backup/RESTORE.md for the full drill):
#   openssl enc -d -aes-256-cbc -pbkdf2 -pass file:KEY \
#       -in charity_db_<TS>.dump.gz.enc | gunzip > charity_db.dump
#   pg_restore --no-owner --no-privileges -d charity_db charity_db.dump
#   openssl enc -d -aes-256-cbc -pbkdf2 -pass file:KEY \
#       -in documents_<TS>.tar.gz.enc | tar -xz -C <documents dir>
#
# Crontab line (runs at 03:00 UTC daily):
#   0 3 * * * /opt/mithqal/pos_admin/ops/backup/charity-db-backup.sh \
#       >> /var/log/mithqal/backup.log 2>&1
#
# Restore drill: see ops/backup/RESTORE.md.

set -Eeuo pipefail

# Load env if a config file is present. Keeps secrets out of crontabs.
if [ -f /etc/mithqal/backup.env ]; then
    # shellcheck disable=SC1091
    source /etc/mithqal/backup.env
fi

# ---- Required env --------------------------------------------------
: "${PGHOST:?missing PGHOST}"
: "${PGUSER:?missing PGUSER}"
: "${PGDATABASE:?missing PGDATABASE}"
: "${BACKUP_LOCAL_DIR:?missing BACKUP_LOCAL_DIR}"
: "${BACKUP_KEY_FILE:?missing BACKUP_KEY_FILE}"

# ---- Optional env with defaults -----------------------------------
PGPORT="${PGPORT:-5432}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-14}"
RCLONE_REMOTE="${RCLONE_REMOTE:-}"
DOCUMENTS_DIR="${DOCUMENTS_DIR:-}"
DOCUMENTS_VOLUME="${DOCUMENTS_VOLUME:-pos_admin_storage-data}"
DOCUMENTS_REQUIRED="${DOCUMENTS_REQUIRED:-1}"

if [ ! -r "$BACKUP_KEY_FILE" ]; then
    echo "FATAL: backup key file not readable: $BACKUP_KEY_FILE" >&2
    exit 1
fi

# ---- Locate the private documents directory (P1-16) ----------------
# Found BEFORE the dump so a misconfigured host fails fast instead of
# silently producing database-only backups.
if [ -z "$DOCUMENTS_DIR" ] && command -v docker >/dev/null 2>&1; then
    VOLUME_ROOT="$(docker volume inspect "$DOCUMENTS_VOLUME" --format '{{ .Mountpoint }}' 2>/dev/null || true)"
    if [ -n "$VOLUME_ROOT" ]; then
        DOCUMENTS_DIR="${VOLUME_ROOT%/}/app/private/documents"
    fi
fi

if [ -z "$DOCUMENTS_DIR" ] || [ ! -d "$DOCUMENTS_DIR" ]; then
    if [ "$DOCUMENTS_REQUIRED" = "1" ]; then
        echo "FATAL: merchant documents directory not found (DOCUMENTS_DIR='${DOCUMENTS_DIR}', volume '${DOCUMENTS_VOLUME}'). Set DOCUMENTS_DIR, or DOCUMENTS_REQUIRED=0 to back up the database only." >&2
        exit 1
    fi
    echo "WARN: merchant documents directory not found — this run backs up the database only" >&2
    DOCUMENTS_DIR=""
fi

mkdir -p "$BACKUP_LOCAL_DIR"

TIMESTAMP="$(date -u +'%Y%m%dT%H%M%SZ')"
DUMP_NAME="charity_db_${TIMESTAMP}.dump.gz.enc"
DUMP_PATH="${BACKUP_LOCAL_DIR%/}/${DUMP_NAME}"
DOCS_NAME="documents_${TIMESTAMP}.tar.gz.enc"
DOCS_PATH="${BACKUP_LOCAL_DIR%/}/${DOCS_NAME}"

echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] backup start → ${DUMP_PATH}"

# ---- 1-3. Dump | gzip | encrypt → local file ----------------------
# pg_dump  -F c    : custom format → restorable per-table via pg_restore
# gzip     -9      : max compression
# openssl  enc     : AES-256-CBC; -pbkdf2 strengthens the key
#                   derivation; -salt randomises per-file IV
PGPASSWORD="${PGPASSWORD:-}" \
    pg_dump \
        --host="$PGHOST" \
        --port="$PGPORT" \
        --username="$PGUSER" \
        --dbname="$PGDATABASE" \
        --format=custom \
        --no-owner \
        --no-privileges \
    | gzip -9 \
    | openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:${BACKUP_KEY_FILE}" \
        -out "$DUMP_PATH"

SIZE_BYTES="$(stat -c%s "$DUMP_PATH" 2>/dev/null || stat -f%z "$DUMP_PATH")"
echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] dump complete (${SIZE_BYTES} bytes)"

# ---- 4. Merchant documents: tar | gzip | encrypt (P1-16) ------------
# Paths inside the archive are relative to the documents directory
# (companies/<uuid>/<file>), matching pos_company_documents.path.
if [ -n "$DOCUMENTS_DIR" ]; then
    DOC_COUNT="$(find "$DOCUMENTS_DIR" -type f | wc -l | tr -d ' ')"
    echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] archiving ${DOC_COUNT} document file(s) from ${DOCUMENTS_DIR}"
    tar -C "$DOCUMENTS_DIR" -cf - . \
        | gzip -9 \
        | openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:${BACKUP_KEY_FILE}" \
            -out "$DOCS_PATH"
    DOCS_BYTES="$(stat -c%s "$DOCS_PATH" 2>/dev/null || stat -f%z "$DOCS_PATH")"
    echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] documents archive complete (${DOCS_BYTES} bytes)"
fi

# ---- 5. Offsite mirror via rclone (optional) ----------------------
if [ -n "$RCLONE_REMOTE" ]; then
    if ! command -v rclone >/dev/null 2>&1; then
        echo "WARN: RCLONE_REMOTE set but rclone not installed — skipping offsite" >&2
    else
        echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] mirroring to ${RCLONE_REMOTE}"
        rclone copy "$DUMP_PATH" "$RCLONE_REMOTE" --transfers=1 --retries=3 --quiet
        if [ -n "$DOCUMENTS_DIR" ]; then
            rclone copy "$DOCS_PATH" "$RCLONE_REMOTE" --transfers=1 --retries=3 --quiet
        fi
        echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] offsite mirror done"
    fi
fi

# ---- 6. Local retention prune -------------------------------------
echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] pruning local backups older than ${BACKUP_RETENTION_DAYS} days"
find "$BACKUP_LOCAL_DIR" -maxdepth 1 -type f \
    \( -name 'charity_db_*.dump.gz.enc' -o -name 'documents_*.tar.gz.enc' \) \
    -mtime +"$BACKUP_RETENTION_DAYS" -print -delete || true

echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] backup OK"
