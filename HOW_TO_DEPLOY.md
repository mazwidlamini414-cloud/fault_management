# ✅ FIX: Railway Deployment Failure

## ROOT CAUSE
Railway injects a dynamic `$PORT` environment variable at runtime (usually `8080`).  
Your Apache was hardcoded to listen on port `80` — Railway's health check hit `$PORT`, got no response, and marked the deploy as **Failed**.

The deploy logs showed the app was actually running fine (DB connected, schema skipped), but Apache was listening on the wrong port.

---

## WHAT WAS FIXED

| File | What Changed |
|------|-------------|
| `docker-entrypoint.sh` | Added `APP_PORT="${PORT:-80}"` and `sed` commands to rewrite `ports.conf` and the VirtualHost to use `$PORT` at startup |
| `railway.json` | Increased `healthcheckTimeout` from `60` → `120` seconds (extra safety) |
| `Dockerfile` | Added comment clarifying the `EXPOSE 80` is a hint only — runtime uses `$PORT` |

---

## STEP-BY-STEP: Apply the Fix (Windows CMD)

```
cd C:\Users\LENOVO\Downloads\fault_management_push
```

### Step 1 — Copy the 3 fixed files into your project folder
From the zip `FIXED_FILES.zip`, extract and overwrite:
- `Dockerfile`
- `docker-entrypoint.sh`
- `railway.json`

### Step 2 — Stage, commit, and push
```
git add Dockerfile docker-entrypoint.sh railway.json
git commit -m "fix: configure Apache to listen on Railway PORT env variable"
git push origin main
```

### Step 3 — Watch Railway deploy
Go to Railway → your project → fault_management service.

You should now see in Deploy Logs:
```
Starting Container
Starting Apache...
Apache will listen on port 8080    ← NEW LINE (confirms fix)
Waiting for database...
mysqld is alive
Database already initialised. Skipping schema import.
Starting Apache on port 8080...
```

And the deploy status will turn **green ✅**.

---

## RAILWAY ENVIRONMENT VARIABLES CHECKLIST
Confirm these are set in Railway → your service → Variables:

| Variable | Value |
|----------|-------|
| `APP_URL` | `https://faultmanagement-production.up.railway.app` |
| `MYSQLHOST` | *(auto-filled by Railway MySQL plugin)* |
| `MYSQLPORT` | `3306` |
| `MYSQLUSER` | *(auto-filled)* |
| `MYSQLPASSWORD` | *(auto-filled)* |
| `MYSQLDATABASE` | `busiquip_final` |

**Only `APP_URL` needs to be set manually.** All `MYSQL*` variables are auto-injected when the MySQL plugin is connected.
