#!/bin/bash

DB_HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
DB_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
DB_USER="${MYSQLUSER:-${DB_USER:-root}}"
DB_PASS="${MYSQLPASSWORD:-${DB_PASSWORD:-}}"
DB_NAME="${MYSQLDATABASE:-${DB_NAME:-busiquip_final}}"
SQL_FILE="/var/www/html/database.sql"
FLAG_FILE="/var/www/html/.db_imported"

# Import DB in background so Apache starts immediately
(
    echo "Waiting for MySQL at ${DB_HOST}:${DB_PORT} ..."
    RETRIES=30
    until mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" --silent 2>/dev/null; do
        RETRIES=$((RETRIES - 1))
        if [ $RETRIES -eq 0 ]; then
            echo "MySQL did not become ready in time."
            break
        fi
        sleep 3
    done
    echo "MySQL is up."

    if [ ! -f "${FLAG_FILE}" ] && [ -f "${SQL_FILE}" ]; then
        echo "Importing database schema ..."
        mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${SQL_FILE}" && \
        touch "${FLAG_FILE}" && \
        echo "Database imported successfully."
    else
        echo "Database already imported - skipping."
    fi
) &

echo "Starting Apache ..."
exec "$@"
