# 🖥️ VPS Manager Panel

Single-file PHP server management panel. Manage VPS, websites, databases, SSL, DNS, cloud backup, and monitoring from one panel.

![Version](https://img.shields.io/badge/version-2.3-blue)
![License](https://img.shields.io/badge/license-MIT-green)

---

## 🚀 Install

```bash
bash <(curl -sSL https://raw.githubusercontent.com/panelboss/vps-manager/main/install.sh)
```

Panel runs at `http://YOUR_IP:8080`

---

## ✨ Features (24 Menus)

### 📊 Main
Dashboard · Websites · Databases

### ⚙️ Management
Backup & Restore · Services · PHP Multi-version · SSL · Monitor · Firewall · File Manager · Logs · Cron Jobs · Optimize

### 🔧 Tools
Redirects (301/302) · Cache (FastCGI Purge) · Uptime Monitor · Security (Fail2Ban) · Web Terminal

### ☁️ Advanced
Cloud Backup (Mega.nz / Google Drive via Rclone) · PHP Settings per Site · DNS Manager (Cloudflare API) · System Migration

### 👥 System
Multi-User Management · Settings

### 🔄 System Migration (NEW)
Full system backup and restore for seamless VPS-to-VPS migration:
- **Backup**: websites, all MySQL databases, Nginx configs, panel settings, cron jobs, UFW rules, PHP versions, SSL certs (optional)
- **Restore**: upload backup archive, auto-extract, import databases, restore websites, apply Nginx configs, reload services
- Live progress bar with polling
- Migration file format: `migration-VPS-HOSTNAME-YYYYMMDD-HHMMSS.tar.gz`
- Compatible between VPS instances running the same panel

### 💻 Web Terminal (NEW)
Browser-based terminal access — no more PuTTY needed:
- Powered by [ttyd](https://github.com/tsl0922/ttyd) (port 8081)
- Full bash shell, embedded via iframe
- Runs via PM2 alongside the panel
- Single binary, minimal footprint

---

## 📋 Requirements
- Ubuntu 20.04 / 22.04
- Root access
- Port 8080 open

---

## 📁 Tech Stack
- Single-file PHP (~3750 lines)
- Nginx + MariaDB + PHP 8.1-FPM + ttyd

---

## 📝 License
MIT
