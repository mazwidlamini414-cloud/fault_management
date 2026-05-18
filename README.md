# BUSIQUIP — Fault Management System

A multi-role fault management web application for BusiQuip (Eswatini).  
Built with **PHP 8.2 + MySQL**, deployable locally via Docker or live on **Railway**.

---

## 👥 User Roles

| Role | Login Entry Point |
|------|------------------|
| Client | `/modules/clients/client_login.php` |
| Technician | `/modules/staff/technician_login.php` |
| Accountant | `/modules/staff/accountant_login.php` |
| Admin | `/modules/admin/admin_login.php` |

---

## 🚀 Deploy to Railway (Recommended — Free Tier Available)

### Step 1 — Push to GitHub

```bash
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin https://github.com/YOUR_USERNAME/busiquip.git
git push -u origin main
```

### Step 2 — Create Railway Project

1. Go to [railway.app](https://railway.app) → **New Project**
2. Choose **Deploy from GitHub repo** → select your repo
3. Railway auto-detects the `Dockerfile` ✅

### Step 3 — Add MySQL Database

1. In your Railway project → **+ New** → **Database** → **MySQL**
2. Railway injects these env vars automatically:
   - `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLDATABASE`

### Step 4 — Set Environment Variables

In Railway → your app service → **Variables**, add:

```
APP_URL = https://YOUR-APP.up.railway.app
```

That's it! Railway will build and deploy. The **database schema imports automatically** on first boot.

### Step 5 — Generate a Domain

Railway → your app service → **Settings** → **Networking** → **Generate Domain**

---

## 🐳 Local Development with Docker

### Prerequisites
- [Docker Desktop](https://www.docker.com/products/docker-desktop/)

### Start the stack

```bash
# Clone / unzip project, then:
docker compose up --build
```

| Service | URL |
|---------|-----|
| App | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |

The database schema is imported automatically on first start.

### Stop

```bash
docker compose down        # keep data
docker compose down -v     # wipe everything (fresh start)
```

---

## 📁 Project Structure

```
busiquip/
├── Dockerfile               ← Production Docker image
├── docker-compose.yml       ← Local dev stack (app + MySQL + phpMyAdmin)
├── docker-entrypoint.sh     ← Auto DB-import on first boot
├── railway.json             ← Railway deployment config
├── .htaccess                ← Apache rewrite + security headers
├── .env.example             ← Copy to .env for local dev
├── database.sql             ← Full schema + seed data
├── index.php                ← Landing page
├── login.php                ← Role selector
├── config/
│   └── database.php         ← DB + BASE_URL config (env-aware)
├── modules/
│   ├── admin/               ← Admin dashboard, reports
│   ├── clients/             ← Client portal, fault reporting, invoices
│   └── staff/               ← Technician + Accountant dashboards
├── includes/                ← Shared headers/footers
├── images/                  ← Static images & logo
└── uploads/                 ← User-uploaded files (writable)
```

---

## ⚙️ Environment Variables Reference

| Variable | Description | Default (local) |
|----------|-------------|-----------------|
| `APP_URL` | Full public URL of the app | auto-detected |
| `MYSQLHOST` | Database host | `localhost` |
| `MYSQLPORT` | Database port | `3306` |
| `MYSQLUSER` | Database user | `root` |
| `MYSQLPASSWORD` | Database password | *(empty)* |
| `MYSQLDATABASE` | Database name | `busiquip_final` |

Railway's MySQL plugin sets all `MYSQL*` variables automatically.

---

## 🔒 Security Notes

- `.env`, `.sql`, `.log`, `.sh` files are blocked from web access via `.htaccess`
- HTTPS redirect is enforced automatically on Railway (via `X-Forwarded-Proto` header)
- Directory listing is disabled
- Change default admin credentials after first login

---

## 🛠 Tech Stack

- **Backend**: PHP 8.2 (MySQLi + PDO)
- **Database**: MySQL 8.0
- **Frontend**: HTML5, CSS3, Bootstrap 5, Font Awesome 6
- **Server**: Apache 2.4
- **Container**: Docker (php:8.2-apache base image)
- **Hosting**: Railway (or any Docker-capable host)
