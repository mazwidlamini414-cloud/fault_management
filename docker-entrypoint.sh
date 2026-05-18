#!/bin/bash

DB_HOST="${MYSQLHOST:-${DB_HOST:-localhost}}"
DB_PORT="${MYSQLPORT:-${DB_PORT:-3306}}"
DB_USER="${MYSQLUSER:-${DB_USER:-root}}"
DB_PASS="${MYSQLPASSWORD:-${DB_PASSWORD:-}}"
DB_NAME="${MYSQLDATABASE:-${DB_NAME:-busiquip_final}}"
SQL_FILE="/var/www/html/database.sql"
FLAG_FILE="/var/www/html/.db_imported"

(
    RETRIES=30
    until mysqladmin ping -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" --silent 2>/dev/null; do
        RETRIES=$((RETRIES - 1))
        [ $RETRIES -eq 0 ] && break
        sleep 3
    done

    if [ ! -f "${FLAG_FILE}" ] && [ -f "${SQL_FILE}" ]; then
        mysql -h"${DB_HOST}" -P"${DB_PORT}" -u"${DB_USER}" -p"${DB_PASS}" "${DB_NAME}" < "${SQL_FILE}" && touch "${FLAG_FILE}"
    fi
) &

echo "Starting Apache ..."
exec "$@"
