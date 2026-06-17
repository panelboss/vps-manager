# 🖥️ VPS Manager

**Open-Source VPS Management Panel** — single-file PHP, lightweight, powerful.

[![Version](https://img.shields.io/badge/version-2.3-blue)](https://github.com/panelboss/vps-manager)
[![License](https://img.shields.io/badge/license-MIT-green)](./LICENSE)

## ✨ Features

- **Websites** — Add domains, Nginx vhost auto-config, SSL (Let's Encrypt)
- **Databases** — Create/drop MySQL & PostgreSQL, phpMyAdmin built-in
- **File Manager** — Browse, upload, edit, rename, delete, extract archives
- **Service Manager** — One-click install Nginx, MySQL, PHP, Certbot
- **PHP Manager** — Install & switch between PHP 5.6 - 8.3
- **Cron Jobs** — Schedule tasks from UI
- **Firewall** — Block/unblock IPs, view open ports (Fail2ban + iptables)
- **Monitoring** — CPU, Memory, Disk, Network live charts + Nginx logs
- **Backups** — Backup websites & databases, S3/FTP/local storage
- **DNS Zones** — Manage A, AAAA, CNAME, MX, TXT, NS, SRV records
- **FTP Accounts** — Create & manage FTP users
- **Multi-user** — Admin + User roles with separate access
- **Terminal** — Web-based terminal (commands with timeout protection)

## 🚀 Quick Install

```bash
bash <(curl -sSL https://raw.githubusercontent.com/panelboss/vps-manager/main/install.sh)
```

During install you'll be prompted to create an admin password.

## 🔧 Architecture

| Component | Port | Purpose |
|:----------|:-----|:--------|
| Panel UI | **8080** | Management panel (separate from websites) |
| Nginx | 80/443 | Future user websites |
| MySQL | 3306 | Internal |
| PHP-FPM 8.1 | socket | Panel backend |

**Why port 8080?** The panel runs on its own port so that user websites can use ports 80/443 without conflict.

## 📦 Requirements

- Ubuntu 20.04 / 22.04 / 24.04 (clean install recommended)
- Debian 11 / 12
- 1 GB RAM minimum
- Root access

## 🔐 Security

- bcrypt password hashing
- Session-based auth with auto-logout (2h)
- Command whitelist for terminal (prevents shell injection)
- 30-second timeout on all shell commands
- Fail2ban integration (sshd, nginx, phpmyadmin jails)
- iptables firewall management

## 📝 License

MIT — free to use, modify, and distribute.
