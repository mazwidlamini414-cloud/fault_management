# HOW TO FIX AND REDEPLOY — STEP BY STEP (CMD Prompt on Windows)

## ERRORS FOUND AND FIXED

### Error 1 (CMD Prompt — Image 1)
**Problem:** You typed/pasted the contents of railway.json directly into CMD.
CMD tried to run `"deploy": {` as a command — which is not valid.
**Fix:** Replace the 3 files as shown below, then use `git` to push.

### Error 2 (Railway — Image 2)
**Problem:** `The executable 'apache2-foreground' could not be found`
Railway uses Ubuntu's Apache (`/usr/sbin/apache2ctl`), NOT Debian's wrapper script.
The entrypoint also needed runtime directories pre-created.
**Fix:** Dockerfile and docker-entrypoint.sh have been corrected.

---

## STEP-BY-STEP: Run these commands in CMD

```
cd C:\Users\LENOVO\Downloads\fault_management_push
```

### Step 1 — Replace Dockerfile
Copy the new Dockerfile from this zip into your project folder,
overwriting the old one. Then verify it:
```
type Dockerfile
```
You should see `CMD ["apache2ctl", "-D", "FOREGROUND"]` at the bottom.

### Step 2 — Replace docker-entrypoint.sh
Copy the new docker-entrypoint.sh into your project folder.
```
type docker-entrypoint.sh
```

### Step 3 — Stage and commit all fixed files
```
git add Dockerfile docker-entrypoint.sh railway.json
git commit -m "fix: correct apache2ctl command and entrypoint for Railway deploy"
```

### Step 4 — Push to GitHub (triggers Railway redeploy)
```
git push origin main
```

### Step 5 — Watch Railway deploy
Go to https://railway.app → your project → fault_management service.
You should now see:
  ✅ Initialization
  ✅ Build
  ✅ Deploy > Create container
  ✅ Network

---

## RAILWAY ENVIRONMENT VARIABLES (set these in Railway dashboard)
Go to your fault_management service → Variables tab and add:

| Variable       | Value                        |
|---------------|------------------------------|
| APP_URL       | https://your-railway-domain  |
| MYSQLHOST     | (Railway MySQL host)         |
| MYSQLPORT     | 3306                         |
| MYSQLUSER     | (Railway MySQL user)         |
| MYSQLPASSWORD | (Railway MySQL password)     |
| MYSQLDATABASE | busiquip_final               |

Railway auto-fills MYSQL* variables when you connect the MySQL plugin.
Only APP_URL needs to be set manually.

---

## WHAT WAS WRONG (Technical Summary)

| File                   | Problem                                      | Fix Applied                              |
|------------------------|----------------------------------------------|------------------------------------------|
| Dockerfile             | CMD used `/usr/sbin/apache2ctl` but Railway  | Changed to `apache2ctl -D FOREGROUND`;   |
|                        | couldn't find `apache2-foreground`           | added `ServerName localhost`; added      |
|                        |                                              | `sed -i 's/\r//'` to strip Windows CRLF |
| docker-entrypoint.sh   | Runtime dirs not pre-created; no `set -e`;   | Added mkdir for runtime dirs; added      |
|                        | silent failures on DB connect                | proper logging and error handling        |
| railway.json           | Correct content but was being pasted         | File is valid — just push it correctly   |
|                        | directly into CMD as commands                | via git, don't type it into CMD          |
