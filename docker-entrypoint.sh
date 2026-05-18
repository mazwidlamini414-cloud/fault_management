#!/bin/bash
set -e

DB_HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
DB_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
DB_USER="${MYSQLUSER:-${DB_USER:-root}}"
DB_PASS="${MYSQLPASSWORD:-${DB_PASSWORD:-}}"
DB_NAME="${MYSQLDATABASE:-${DB_NAME:-busiquip_final}}"
SQL_FILE="/var/www/html/database.sql"
FLAG_FILE="/var/www/html/.db_imported"

# FIX: Ensure Apache runtime dirs exist (needed on fresh container start)
mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2
chown www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

# Background job: wait for DB then import schema once
(
    echo "Waiting for database at ${DB_HOST}:${DB_PORT}..."
    RETRIES=30
    until mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" --silent 2>/dev/null; do
        RETRIES=$((RETRIES - 1))
        if [ $RETRIES -eq 0 ]; then
            echo "WARNING: Could not connect to DB after 30 retries. Skipping import."
            break
        fi
        sleep 3
    done

    if [ ! -f "${FLAG_FILE}" ] && [ -f "${SQL_FILE}" ]; then
        echo "Importing database schema..."
        mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${SQL_FILE}" \
            && touch "${FLAG_FILE}" \
            && echo "Database import complete."
    fi
) &

echo "Starting Apache..."
exec "$@"
