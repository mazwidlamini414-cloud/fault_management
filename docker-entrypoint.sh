#!/bin/bash
set -e

DB_HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
DB_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
DB_USER="${MYSQLUSER:-${DB_USER:-root}}"
DB_PASS="${MYSQLPASSWORD:-${DB_PASSWORD:-}}"
DB_NAME="${MYSQLDATABASE:-${DB_NAME:-busiquip_final}}"
SQL_FILE="/var/www/html/database.sql"

# Import DB in background - don't block Apache from starting
(
    echo "[DB] Waiting for database at ${DB_HOST}:${DB_PORT}..."
    RETRIES=30
    until mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" --silent 2>/dev/null; do
        RETRIES=$((RETRIES - 1))
        if [ $RETRIES -eq 0 ]; then
            echo "[DB] WARNING: Could not connect after 30 retries. Skipping import."
            exit 0
        fi
        sleep 2
    done
    echo "[DB] Connected successfully"

    if [ -f "${SQL_FILE}" ]; then
        echo "[DB] Importing schema..."
        mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" \
            < "${SQL_FILE}" 2>&1 | grep -v "Warning: Using a password" || true
        echo "[DB] Import complete"
    fi
) &

echo "[Web] Starting Apache..."
exec "$@"
