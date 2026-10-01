#!/usr/bin/env bash
#
# Daily encrypted backup of charity_db (Sprint 3 hardening,
# blueprint §9.14) plus pos_admin's private merchant documents
# (LAUNCH-P1 P1-16).
#
# What it does, in this order:
#   1. pg_dump  → custom-format dump of charity_db
#   2. gzip     → compress
#   3. openssl  → AES-256-CBC encrypt with a key from disk
#   4. local    → drop the encrypted blob in $BACKUP_LOCAL_DIR
#   5. rclone   → mirror to a remote (OneDrive/GDrive/S3) so a
#                 Hostinger-side disaster doesn't take the backups
#                 with it
#   6. retain   → prune local copies older than $BACKUP_RETENTION_DAYS
#   7. documents → archive the private documents directory the same way
#                 (documents_<TIMESTAMP>.tar.gz.enc), mirror and prune it
#
# The DATABASE DUMP ALWAYS RUNS FIRST and completes on its own: nothing
# about the documents step can stop or delay it. Then:
#   - documents directory not there yet (no upload ever made, or the
#     volume cannot be found) → a WARNING, and the run still ends OK;
#   - the directory exists but archiving it fails → the partial archive
#     is removed and the run FAILS (exit 1) so the problem is noticed —
#     the database dump of this run is already safe on disk / offsite.
#
# A single dump covers the WHOLE shared Postgres instance — charity
# tables, pos_admin tables, and (future) pos_merchant + pos_api
# tables — because they all live in the same database. Restoring is
# a single pg_restore against an empty instance.
#
# The documents themselves (CR certificates, owner ID cards, ...) are
# NOT in the database — only their metadata is. They live on pos_admin's
# PRIVATE documents disk: storage/app/private/documents inside the
# `storage-data` volume. The two files of one run share the timestamp;
# restore them together (ops/backup/RESTORE.md).
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

if [ ! -r "$BACKUP_KEY_FILE" ]; then
    echo "FATAL: backup key file not readable: $BACKUP_KEY_FILE" >&2
    exit 1
fi

mkdir -p "$BACKUP_LOCAL_DIR"

TIMESTAMP="$(date -u +'%Y%m%dT%H%M%SZ')"
DUMP_NAME="charity_db_${TIMESTAMP}.dump.gz.enc"
DUMP_PATH="${BACKUP_LOCAL_DIR%/}/${DUMP_NAME}"
DOCS_NAME="documents_${TIMESTAMP}.tar.gz.enc"
DOCS_PATH="${BACKUP_LOCAL_DIR%/}/${DOCS_NAME}"

mirror_offsite() {
    local file="$1"
    if [ -z "$RCLONE_REMOTE" ]; then
        return 0
    fi
    if ! command -v rclone >/dev/null 2>&1; then
        echo "WARN: RCLONE_REMOTE set but rclone not installed — skipping offsite for $(basename "$file")" >&2
        return 0
    fi
    echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] mirroring $(basename "$file") to ${RCLONE_REMOTE}"
    rclone copy "$file" "$RCLONE_REMOTE" --transfers=1 --retries=3 --quiet
}

echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] backup start → ${DUMP_PATH}"

# ---- 1-4. Dump | gzip | encrypt → local file (ALWAYS FIRST) ---------
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

# ---- 5. Offsite mirror of the dump (optional) -----------------------
mirror_offsite "$DUMP_PATH"

# ---- 6. Local retention prune of the dumps --------------------------
echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] pruning local backups older than ${BACKUP_RETENTION_DAYS} days"
find "$BACKUP_LOCAL_DIR" -maxdepth 1 -type f \
    \( -name 'charity_db_*.dump.gz.enc' -o -name 'documents_*.tar.gz.enc' \) \
    -mtime +"$BACKUP_RETENTION_DAYS" -print -delete || true

echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] database backup OK"

# ---- 7. Merchant documents (P1-16) — AFTER the database ------------
if [ -z "$DOCUMENTS_DIR" ] && command -v docker >/dev/null 2>&1; then
    VOLUME_ROOT="$(docker volume inspect "$DOCUMENTS_VOLUME" --format '{{ .Mountpoint }}' 2>/dev/null || true)"
    if [ -n "$VOLUME_ROOT" ]; then
        DOCUMENTS_DIR="${VOLUME_ROOT%/}/app/private/documents"
    fi
fi

if [ -z "$DOCUMENTS_DIR" ] || [ ! -d "$DOCUMENTS_DIR" ]; then
    # Normal before the first document upload: the disk creates the
    # directory on the first write. Never a reason to fail the backup.
    echo "WARN: merchant documents directory not found (DOCUMENTS_DIR='${DOCUMENTS_DIR}', volume '${DOCUMENTS_VOLUME}') — no documents to archive yet; the database backup above is complete" >&2
    echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] backup OK (database only)"
    exit 0
fi

# Paths inside the archive are relative to the documents directory
# (companies/<uuid>/<file>), matching pos_company_documents.path.
DOC_COUNT="$(find "$DOCUMENTS_DIR" -type f | wc -l | tr -d ' ')"
echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] archiving ${DOC_COUNT} document file(s) from ${DOCUMENTS_DIR}"
if ! tar -C "$DOCUMENTS_DIR" -cf - . \
        | gzip -9 \
        | openssl enc -aes-256-cbc -pbkdf2 -salt -pass "file:${BACKUP_KEY_FILE}" \
            -out "$DOCS_PATH"; then
    rm -f "$DOCS_PATH"
    echo "FATAL: archiving the merchant documents in ${DOCUMENTS_DIR} failed — the database backup ${DUMP_NAME} is complete, the documents are NOT backed up" >&2
    exit 1
fi

DOCS_BYTES="$(stat -c%s "$DOCS_PATH" 2>/dev/null || stat -f%z "$DOCS_PATH")"
echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] documents archive complete (${DOCS_BYTES} bytes)"

mirror_offsite "$DOCS_PATH"

echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] backup OK (database + documents)"
