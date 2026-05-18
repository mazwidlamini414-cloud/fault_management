#!/bin/bash
set -e

# ─── Railway PORT fix ──────────────────────────────────────────────────────────
# Railway injects $PORT (e.g. 8080). Apache must listen on that port, not 80.
# If $PORT is not set (local/docker-compose), default to 80.
APP_PORT="${PORT:-80}"

# Rewrite Apache's ports.conf to listen on $APP_PORT
sed -i "s/^Listen .*/Listen ${APP_PORT}/" /etc/apache2/ports.conf

# Update the VirtualHost to bind to $APP_PORT
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${APP_PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

echo "Apache will listen on port ${APP_PORT}"

# ─── Database config ──────────────────────────────────────────────────────────
DB_HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
DB_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
DB_USER="${MYSQLUSER:-${DB_USER:-root}}"
DB_PASS="${MYSQLPASSWORD:-${DB_PASSWORD:-}}"
DB_NAME="${MYSQLDATABASE:-${DB_NAME:-busiquip_final}}"
SQL_FILE="/var/www/html/database.sql"

# ─── Ensure Apache runtime dirs exist ────────────────────────────────────────
mkdir -p /var/run/apache2 /var/lock/apache2 /var/log/apache2
chown www-data:www-data /var/run/apache2 /var/lock/apache2 /var/log/apache2

# ─── Background: wait for DB then import schema if needed ────────────────────
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
        # Only import if the 'admin' table does not exist yet (fresh database).
        TABLE_EXISTS=$(mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" \
            -sN -e "SELECT COUNT(*) FROM information_schema.tables \
                    WHERE table_schema='${DB_NAME}' AND table_name='admin';" 2>/dev/null || echo "0")

        if [ "${TABLE_EXISTS}" = "0" ]; then
            echo "Fresh database detected. Importing schema..."
            mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" \
                < "${SQL_FILE}" 2>&1 | grep -v "Warning: Using a password"
            echo "Database import complete."
        else
            echo "Database already initialised. Skipping schema import."
        fi
    fi
) &

echo "Starting Apache on port ${APP_PORT}..."
exec "$@"
