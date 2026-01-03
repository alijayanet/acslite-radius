# 📡 ACSLite-Radius
**One-click FreeRADIUS + Go-ACS (TR-069) solution for ISPs**

[![GitHub release](https://img.shields.io/github/v/release/alijayanet/acslite-radius?style=flat-square)](https://github.com/alijayanet/acslite-radius/releases)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue?style=flat-square)](https://opensource.org/licenses/MIT)
[![Docker Ready](https://img.shields.io/badge/Docker-Ready-green?style=flat-square)](https://hub.docker.com/r/alijayanet/acslite-radius)

> **Alijaya-Net** – 0819-4721-5703 – *Your trusted ISP automation partner*

---

## 🎯 Overview

**ACSLite-Radius** is a complete, production-ready ISP management system that combines:
- **Go-ACS**: Lightweight TR-069 Auto-Configuration Server for ONU/CPE management
- **FreeRADIUS**: Full-featured RADIUS server for PPPoE, Hotspot & Accounting
- **Modern Web UI**: Glass-morphism dashboard with real-time monitoring
- **Billing System**: Automated invoice generation, payment tracking & isolir management
- **Hotspot Voucher**: Mikhmon-style voucher generation with batch management

All components are integrated and ready to deploy with **two simple commands**.

---

## 🗂️ What's Inside?

| Component | Description | Main Entry Point |
|-----------|-------------|------------------|
| **Go-ACS** | Lightweight TR-069 Auto-Configuration Server (written in Go) | `install.sh` → creates `/opt/acs` and systemd service `acslite` |
| **FreeRADIUS** | Full-featured RADIUS server for PPPoE, hotspot & accounting | `install_radius.sh` (stand-alone script) |
| **Web UI** | Modern, glass-morphism dashboard (Bootstrap 5 + Inter font) | `web/templates/*.html` |
| **PHP API** | JSON-REST API used by the UI (user, NAS, voucher, billing) | `web/api/*.php` |
| **Database** | MySQL/MariaDB schema for ACS, billing, RADIUS & telemetry | Created by the installers |
| **Cron Jobs** | • Clean orphaned RADIUS sessions<br>• Auto-isolir & invoice generation | Installed automatically |
| **Utilities** | `fix_freeradius_ipv4.sh`, `debug_radius.sh`, migration scripts | Helper scripts in repo root |

---

## 🚀 Quick Start (One-Click Installation)

> **All commands must be run as `root` (or with `sudo`).**  
> The scripts are **non-interactive** – they will create the database, tables, dummy data and services automatically.

### Step 1: Clone the Repository

```bash
git clone https://github.com/alijayanet/acslite-radius.git
cd acslite-radius
```

### Step 2: Install Go-ACS (Main Application)

```bash
bash install.sh
```

This will:
- ✅ Install MariaDB & PHP (if not present)
- ✅ Create `/opt/acs` directory with all application files
- ✅ Create `acs` database with billing tables (customers, invoices, payments, etc.)
- ✅ Create systemd services (`acslite` on port 7547, `acs-php-api` on port 8888)
- ✅ Set up cron jobs for auto-isolir and invoice generation
- ✅ Configure real-time ONU monitoring (5-minute intervals)

**Access the dashboard:**
```
http://<SERVER_IP>:7547/web/index.html
```

**Default credentials:**
- Username: `admin`
- Password: `admin123`

### Step 3: Install FreeRADIUS (Optional but Recommended)

```bash
bash install_radius.sh
```

This will:
- ✅ Install FreeRADIUS 3.0+ (if not present)
- ✅ Create `radius` database with all standard tables (`nas`, `radcheck`, `radreply`, `radacct`, etc.)
- ✅ Insert dummy data (default NAS: `192.168.1.1`, user: `demo/demo123`)
- ✅ Configure SQL authorization & accounting
- ✅ Fix IPv4/IPv6 listen configuration
- ✅ Install hourly cron job to clean orphaned sessions
- ✅ Start and enable FreeRADIUS service

**Access RADIUS dashboard:**
```
http://<SERVER_IP>:7547/web/radius.html
```

**Default RADIUS credentials:**
- Database: `radius` / User: `radius` / Password: `radius123`
- Test user: `demo` / Password: `demo123`
- Default NAS: `192.168.1.1` / Secret: `radius`

---

## 📦 Prerequisites

| Item | Minimum Version | Why |
|------|----------------|-----|
| **Linux** | Ubuntu 20.04 / Debian 11 / CentOS 8+ | Tested on these distros |
| **MariaDB / MySQL** | 10.3+ | Stores ACS, billing & RADIUS data |
| **PHP** | 7.4+ (cli & curl) | API backend for the UI |
| **FreeRADIUS** | 3.0+ | RADIUS server (installed by `install_radius.sh`) |
| **Git** | any | To clone the repo |
| **cURL** | any | Used by the installers |
| **systemd** | any | Service management |

> **The installers will automatically install missing packages** (MariaDB, PHP-curl, etc.) using `apt` or `yum`.

---

## 🛠️ Installation Details

### 1️⃣ Go-ACS Installation (`install.sh`)

**What it does:**

1. **Creates** `/opt/acs` directory structure and copies:
   - Go binary (`acs-linux-amd64` or `acs-linux-arm64`)
   - Web assets (`web/templates/*.html`, `web/api/*.php`)
   - Configuration files (`web/data/settings.json`, `admin.json`)

2. **Generates** `.env` file with database DSN:
   ```bash
   ACS_PORT=7547
   DB_DSN=root:secret123@tcp(127.0.0.1:3306)/acs?parseTime=true
   API_KEY=secret
   WEB_DIR=/opt/acs/web
   ```

3. **Creates** systemd services:
   - `acslite.service` → Go-ACS TR-069 server (port 7547)
   - `acs-php-api.service` → PHP API server (port 8888)

4. **Creates** database tables:
   - `onu_locations` – ONU device registry with GPS coordinates
   - `customers` – Customer management with PPPoE credentials
   - `packages` – Service packages with pricing
   - `invoices` – Billing invoices
   - `payments` – Payment records
   - `hotspot_vouchers` – Hotspot voucher management
   - `hotspot_profiles` – Hotspot plans/profiles
   - `telegram_config` – Telegram bot configuration

5. **Sets up** cron jobs:
   - Auto-isolir overdue customers (daily at 00:01)
   - Auto-generate monthly invoices (1st of month at 00:01)
   - Auto-refresh ONU data (every 5 minutes)

**Result:** Go-ACS runs on port 7547, PHP API on 8888, both start on boot.

---

### 2️⃣ FreeRADIUS Installation (`install_radius.sh`)

**What it does:**

1. **Installs** MariaDB (if not present) and creates `radius` database.

2. **Creates** all standard FreeRADIUS tables:
   - `radcheck` → User authentication (username/password)
   - `radreply` → User-specific reply attributes (e.g., Framed-IP-Address)
   - `radacct` → Accounting sessions (start/stop/interim-update)
   - `nas` → Network Access Server (MikroTik router) registry
   - `radgroupcheck` → Group-based check attributes
   - `radgroupreply` → Group-based reply attributes
   - `radusergroup` → User-to-group mappings
   - `radpostauth` → Post-authentication log

3. **Inserts** dummy data for testing:
   - NAS: `192.168.1.1` (shortname: `mikrotik1`, secret: `radius`)
   - User: `demo` / Password: `demo123`
   - Group: `demo-group` with sample rate limit

4. **Configures** FreeRADIUS:
   - Enables SQL module for authorization & accounting
   - Fixes DateTime queries using `FROM_UNIXTIME()`
   - Configures IPv4/IPv6 listen addresses (ports 1812/1813)
   - Updates `settings.json` with RADIUS database credentials

5. **Installs** cron job:
   - `cleanup_radius_sessions.sh` runs hourly to purge orphaned sessions

6. **Starts** FreeRADIUS service:
   ```bash
   systemctl enable freeradius
   systemctl start freeradius
   ```

**Result:** A ready-to-use RADIUS server reachable at `127.0.0.1:1812` (auth) & `1813` (acct).

---

### 3️⃣ Running Both Together

The two installers are **independent**. You may:

* Run **only** `install.sh` – Perfect for a pure ACS deployment (TR-069 only).
* Run **both** – Full-stack solution (ACS + RADIUS + billing + hotspot).

Both services listen on different ports, so they never clash.

**Environment Variables** (optional customization):

For `install_radius.sh`, you can override defaults:

```bash
export Mikrotik_IP=192.168.10.1
export Mikrotik_SECRET=myradius
export Mikrotik_NAME=router1
export DEFAULT_RADIUS_USER=testuser
export DEFAULT_RADIUS_PASS=testpass

bash install_radius.sh
```

---

## 📂 Repository Layout

```
acslite-radius/
│
├─ install.sh                      # Go-ACS installer (creates /opt/acs)
├─ install_radius.sh               # FreeRADIUS one-click installer
├─ fix_freeradius_ipv4.sh         # Helper script for IPv4/IPv6 listen fix
├─ configure_freeradius_sql.sh    # FreeRADIUS SQL configuration helper
├─ cleanup_radius_sessions.sh     # Hourly cron job to clean orphaned sessions
├─ debug_radius.sh                # Debugging script for RADIUS issues
│
├─ build/                          # Go binaries (amd64 & arm64)
│   ├─ acs-linux-amd64
│   └─ acs-linux-arm64
│
├─ web/                            # UI & PHP API
│   ├─ templates/                  # HTML pages (dashboard, radius.html, …)
│   ├─ api/                        # PHP endpoints (radius_api.php, notify.php, …)
│   ├─ data/                       # JSON config (settings.json, admin.json)
│   ├─ js/                         # Shared JavaScript modules
│   └─ .htaccess                   # Apache rewrite rules
│
├─ README.md                       # **THIS FILE**
├─ README_FREERADIUS.md           # Detailed FreeRADIUS guide
└─ LICENSE                         # MIT License
```

---

## 🖥️ How to Use the Dashboard

| Page | Purpose | Key Actions |
|------|---------|-------------|
| **Dashboard** (`dashboard.html`) | Overview of service health, revenue, active users | Refresh, view service status |
| **RADIUS Manager** (`radius.html`) | Manage RADIUS users, NAS, sessions, accounting | Add NAS, add PPPoE user, clean orphaned sessions |
| **Hotspot/Voucher** (`hotspot.html`) | Create & manage hotspot vouchers (Mikhmon-style) | Add plan, generate batch, view sales |
| **MikroTik Manager** (`mikrotik.html`) | Manage MikroTik PPPoE users, profiles, active sessions | Add user, isolir, disconnect session |
| **Map** (`map.html`) | Visualize ONU locations on Google Maps | Add/edit coordinates, view device status |
| **Customers** (`customers.html`) | Customer management (billing, packages, isolir) | Add customer, assign package, isolir/un-isolir |
| **Invoices** (`invoices.html`) | Generate & manage invoices | Auto-invoice cron runs monthly |
| **Payments** (`payments.html`) | Record payments, view payment history | Manual payment entry, export reports |
| **Packages** (`packages.html`) | Service packages & pricing | Add/edit packages, set MikroTik profiles |
| **Settings** (`settings.html`) | Edit DB connection, enable/disable modules | Save → updates `.env` & `settings.json` |
| **Database Admin** (`db_admin.html`) | SQL terminal for direct database queries | Execute queries, view table structure |

All actions are performed via the **PHP API** (`/api/...`) – no page reloads needed.

---

## 🔧 Configuration Files

### 1. `/opt/acs/.env`
Main Go-ACS configuration:
```bash
ACS_PORT=7547
DB_DSN=root:secret123@tcp(127.0.0.1:3306)/acs?parseTime=true
API_KEY=secret
WEB_DIR=/opt/acs/web
```

### 2. `/opt/acs/web/data/settings.json`
Application settings (DB, Telegram, RADIUS, billing):
```json
{
  "general": {
    "app_name": "ACS-Lite",
    "company_name": "Alijaya-Net"
  },
  "hotspot": {
    "backend": "radius",
    "radius": {
      "enabled": true,
      "db_host": "127.0.0.1",
      "db_port": 3306,
      "db_name": "radius",
      "db_user": "radius",
      "db_pass": "radius123"
    }
  }
}
```

### 3. `/opt/acs/web/data/admin.json`
Admin login credentials:
```json
{
  "admin": {
    "username": "admin",
    "password": "admin123"
  }
}
```

**🔒 Security Note:** Change these default passwords in production!

---

## 🔌 MikroTik Configuration

### For PPPoE Authentication

1. **Add RADIUS server** on MikroTik:
   ```bash
   /radius add address=<RADIUS_SERVER_IP> secret=radius service=pppoe
   ```

2. **Enable RADIUS** in your PPP profile:
   ```bash
   /ppp profile set default use-radius=yes
   ```

3. **Test authentication:**
   - Create a PPPoE client on MikroTik
   - Use credentials from RADIUS (e.g., `demo` / `demo123`)
   - Check `/ppp active` on MikroTik
   - Check `radius.html` → "Active Sessions" on the dashboard

### For Hotspot Authentication

1. **Add RADIUS server** on MikroTik:
   ```bash
   /radius add address=<RADIUS_SERVER_IP> secret=radius service=hotspot
   ```

2. **Enable RADIUS** in hotspot server profile:
   ```bash
   /ip hotspot profile set default use-radius=yes
   ```

3. **Test voucher login:**
   - Generate vouchers in `hotspot.html`
   - Connect to hotspot WiFi
   - Use voucher credentials on login page

---

## 📊 Database Schema

### ACS Database (`acs`)

| Table | Purpose |
|-------|---------|
| `onu_locations` | ONU device registry with GPS coordinates & customer login |
| `customers` | Customer management (name, phone, PPPoE, package, status) |
| `packages` | Service packages (name, speed, price, MikroTik profile) |
| `invoices` | Billing invoices (period, due date, amount, status) |
| `payments` | Payment records (invoice, amount, method, date) |
| `hotspot_vouchers` | Hotspot voucher management (batch, username, password, status) |
| `hotspot_profiles` | Hotspot plans (price, duration, rate limit, on-login script) |
| `voucher_batches` | Voucher batch tracking (quantity, revenue, stats) |
| `hotspot_sales` | Voucher sales records (customer, payment method) |
| `telegram_config` | Telegram bot configuration (token, webhook) |
| `telegram_admins` | Authorized Telegram users (chat ID, role) |

### RADIUS Database (`radius`)

| Table | Purpose |
|-------|---------|
| `nas` | Network Access Servers (MikroTik routers) |
| `radcheck` | User authentication (username, password) |
| `radreply` | User-specific reply attributes (IP, rate limit) |
| `radacct` | Accounting sessions (start, stop, duration, bytes) |
| `radgroupcheck` | Group-based check attributes |
| `radgroupreply` | Group-based reply attributes |
| `radusergroup` | User-to-group mappings |
| `radpostauth` | Post-authentication log |

---

## 🛠️ Troubleshooting

### Go-ACS Not Starting

```bash
# Check service status
systemctl status acslite

# View logs
journalctl -u acslite -f

# Check database connection
mysql -u root -psecret123 -e "USE acs; SHOW TABLES;"
```

### FreeRADIUS Not Starting

```bash
# Check service status
systemctl status freeradius

# Test configuration
freeradius -X

# Check database connection
mysql -u radius -pradius123 -e "USE radius; SHOW TABLES;"

# Check listen addresses
netstat -tulpn | grep 1812
```

### PPPoE Authentication Failing

```bash
# Check RADIUS logs
tail -f /var/log/freeradius/radius.log

# Check radpostauth table
mysql -u radius -pradius123 -D radius -e "SELECT * FROM radpostauth ORDER BY authdate DESC LIMIT 10;"

# Verify NAS client
mysql -u radius -pradius123 -D radius -e "SELECT * FROM nas;"

# Test authentication manually
radtest demo demo123 127.0.0.1 0 radius
```

### Orphaned Sessions

```bash
# Manual cleanup
/opt/acs/radius/cleanup_radius_sessions.sh

# Check cron job
crontab -l | grep cleanup

# View orphaned sessions
mysql -u radius -pradius123 -D radius -e \
  "SELECT username, acctstarttime FROM radacct WHERE acctstoptime IS NULL;"
```

---

## 🧑‍💻 Contributing

We welcome contributions from the community!

1. **Fork** the repository
2. **Create** a feature branch (`git checkout -b feat/awesome-feature`)
3. **Follow** existing code style:
   - Bash: `set -euo pipefail`, clear comments
   - PHP: PSR-12 standard
   - HTML/CSS: Bootstrap 5 conventions
4. **Test** your changes on a clean VM
5. **Submit** a Pull Request with clear description and screenshots (if UI changes)

> **Please keep the one-click installers functional** – they must remain non-interactive.

---

## 📜 License

This project is licensed under the **MIT License** – see the [LICENSE](LICENSE) file for details.

---

## 📞 Contact & Support

**Alijaya-Net** – *Your ISP Automation Partner*

- 📱 Phone / WhatsApp: **0819-4721-5703**
- 📧 Email: `support@alijaya-net.id`
- 🌐 Website: [alijaya-net.id](https://alijaya-net.id)
- 🐛 GitHub Issues: [github.com/alijayanet/acslite-radius/issues](https://github.com/alijayanet/acslite-radius/issues)

### Need Help?

- **Bug reports:** Open a GitHub issue with logs and steps to reproduce
- **Feature requests:** Describe your use case and expected behavior
- **Deployment support:** Contact us via WhatsApp for commercial support
- **Custom development:** We offer custom ISP solutions tailored to your needs

---

## 🎉 Happy Deploying!

With a single `bash install.sh && bash install_radius.sh` you now have a **complete, production-ready** ISP management stack:

✅ **ACS** for TR-069 device provisioning  
✅ **FreeRADIUS** for PPPoE & hotspot authentication  
✅ **Billing system** with auto-isolir & invoice generation  
✅ **Hotspot vouchers** (Mikhmon-style)  
✅ **Modern web UI** for daily operations  
✅ **Real-time monitoring** every 5 minutes  
✅ **Automated maintenance** via cron jobs  

**All configured and ready to use in under 5 minutes!**

---

*Made with ❤️ by **Alijaya-Net** (0819-4721-5703) – Your network, automated.*
