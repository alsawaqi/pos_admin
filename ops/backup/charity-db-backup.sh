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
#
# Crontab line (runs at 03:00 UTC daily):
#   0 3 * * * /opt/mithqal/pos_admin/ops/backup/charity-db-backup.sh \
#       >> /var/log/mithqal/backup.log 2>&1
#
# Restore drill: see docs/runbooks/charity-db-restore.md.

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

if [ ! -r "$BACKUP_KEY_FILE" ]; then
    echo "FATAL: backup key file not readable: $BACKUP_KEY_FILE" >&2
    exit 1
fi

mkdir -p "$BACKUP_LOCAL_DIR"

TIMESTAMP="$(date -u +'%Y%m%dT%H%M%SZ')"
DUMP_NAME="charity_db_${TIMESTAMP}.dump.gz.enc"
DUMP_PATH="${BACKUP_LOCAL_DIR%/}/${DUMP_NAME}"

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

# ---- 5. Offsite mirror via rclone (optional) ----------------------
if [ -n "$RCLONE_REMOTE" ]; then
    if ! command -v rclone >/dev/null 2>&1; then
        echo "WARN: RCLONE_REMOTE set but rclone not installed — skipping offsite" >&2
    else
        echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] mirroring to ${RCLONE_REMOTE}"
        rclone copy "$DUMP_PATH" "$RCLONE_REMOTE" --transfers=1 --retries=3 --quiet
        echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] offsite mirror done"
    fi
fi

# ---- 6. Local retention prune -------------------------------------
echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] pruning local backups older than ${BACKUP_RETENTION_DAYS} days"
find "$BACKUP_LOCAL_DIR" -maxdepth 1 -type f -name 'charity_db_*.dump.gz.enc' \
    -mtime +"$BACKUP_RETENTION_DAYS" -print -delete || true

echo "[$(date -u +'%Y-%m-%d %H:%M:%S')] backup OK"
