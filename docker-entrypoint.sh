#!/bin/bash
set -e

DB_HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
DB_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
DB_USER="${MYSQLUSER:-${DB_USER:-root}}"
DB_PASS="${MYSQLPASSWORD:-${DB_PASSWORD:-}}"
DB_NAME="${MYSQLDATABASE:-${DB_NAME:-busiquip_final}}"
SQL_FILE="/var/www/html/database.sql"

# Ensure Apache runtime dirs exist
mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2
chown www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

# Background job: wait for DB then import schema (safe to run multiple times)
(
    echo "Waiting for database at ${DB_HOST}:${DB_PORT}..."
    RETRIES=30
    until mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" --silent 2>/dev/null; do
        RETRIES=$((RETRIES - 1))
        if [ $RETRIES -eq 0 ]; then
            echo "WARNING: Could not connect to DB after 30 retries. Skipping import."
            exit 0
        fi
        sleep 3
    done
    echo "mysqld is alive"

    if [ -f "${SQL_FILE}" ]; then
        echo "Importing database schema..."
        # FIX: Use --force so existing tables don't crash the import
        mysql --force -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" < "${SQL_FILE}" 2>&1 | grep -v "Warning: Using a password"
        echo "Database import complete."
    fi
) &

echo "Starting Apache..."
exec "$@"
