# VPS Manager Panel — Project Context

> Last updated: 2026-06-16 22:02 GMT+8

## VPS Server
- **IP:** 159.65.232.23
- **OS:** Ubuntu 22.04
- **CPU:** 1 core, RAM: 957MB
- **User:** root / #123Alalpp (SSH via SSH_ASKPASS)

## Panel
- **File:** `/var/www/manager/index.php` — 3805 lines PHP (single-file application)
- **Runtime:** PHP 8.1, PM2 `vps-manager` on port **8080**
- **Auth:** POST `user`/`pass`, cookie-based
- **Credentials:** `admin` / `adminku`
- **Config files:**
  - `/var/www/manager/.users` — user list
  - `/var/www/manager/.passwd` — password hashes
  - `/var/www/manager/.site_php.json` — per-site PHP versions
  - `/var/www/manager/.cf_token` — Cloudflare token
  - `/var/www/manager/uptime.log` — uptime history
- **Backup storage:** `/var/www/manager/backups/migration/`

## Web Terminal
- **Binary:** `/usr/local/bin/ttyd` v1.7.7
- **PM2:** `web-terminal` on port **8081**
- **Access:** iframe embed in panel sidebar menu "💻 Terminal"

## Nginx
- **Config dirs:** `/etc/nginx/sites-available/`, `/etc/nginx/sites-enabled/`
- **Active sites (sites-enabled):** `pma`, `pma.kotakide.web.id`, `school_blocked` (444 block for web.kotakide.web.id)
- **Available configs:** `default`, `pma`, `pma.kotakide.web.id`, `school_blocked`
- **Warning:** conflicting server_name `pma.kotakide.web.id` (two configs — pma & pma.kotakide.web.id)

## Websites on VPS
| Directory | Domain | Status |
|-----------|--------|--------|
| `/var/www/html/` | (default) | Active |
| `/var/www/pma.kotakide.web.id/` | pma.kotakide.web.id | Active |
| `/var/www/school/` | (orphaned — config deleted) | ⚠️ Dir exists, no nginx |
| `/var/www/stj.kotakide.web.id/` | stj.kotakide.web.id | Active |
| `/var/www/test.kotakide.web.id/` | test.kotakide.web.id | Active |
| `web.kotakide.web.id` | ❌ Deleted — 444 blocked | |

## SSL (Let's Encrypt)
- **Active certs:** `test.kotakide.web.id`
- **Deleted:** `web.kotakide.web.id` (removed via certbot)

## Database
- **MariaDB:** user `mahimmah` / pass `Mahimmah#2024`
- **Database:** `mahimmah_db`

## Management Panel Features (24 menus)
1. Dashboard
2. Websites (add/edit/delete/SSL)
3. Databases (create/list/delete)
4. File Manager
5. PHP Settings (version per-site)
6. Nginx Config
7. Cron Jobs
8. SSH Keys
9. SSL Certificates
10. Firewall (UFW)
11. System Info
12. Services
13. PM2 Processes
14. Backups
15. Logs
16. Users
17. PHP Info
18. phpMyAdmin (link)
19. Cloudflare
20. Migration (backup/restore)
21. DNS
22. Security
23. Settings
24. 💻 Terminal

## Delete Website — Full Cleanup Behavior
1. **Nginx config** — deleted from sites-available + sites-enabled (uses cfg_name for correct file lookup)
2. **Website files** — `rm -rf /var/www/$domain`
3. **SSL certificate** — `certbot delete --cert-name $domain`
4. **Block config** — creates 444 return config to prevent fallback to default server
5. **Database** — NOT deleted (manual from Databases menu)
6. **Nginx reload** — always runs, even if test fails
7. **Re-create** — old block config auto-removed when same domain is re-created

## Install Script
- **Build process:** `build.py` encodes `index.php` → `install-core.sh` → `install.sh`
- **Install gate:** `alalpp123`
- **Install scope:** only touches `/var/www/manager/` (does NOT affect other websites)
- **Pipeline:** `index.php` → gzip+base64 → inject into install-core.sh → gzip+base64 → install.sh (2-line wrapper)

## GitHub
- **Repo:** [panelboss/vps-manager](https://github.com/panelboss/vps-manager)
- **Branch:** `main` (force push from local `master`)
- **Branch protection:** enabled (PR required, block force push — admin bypass)
- **GitHub Pages:** https://panelboss.github.io/vps-manager/
- **Collaborators:** `panelboss` (admin)
- **Workspace git:** `/root/.openclaw-autoclaw/workspace/.git`
- **Build script:** `/root/.openclaw-autoclaw/workspace/build.py`

## SSH Passthrough
- **Script:** `/tmp/ssh_pass.sh` (echoes `#123Alalpp`)
- **Usage:** `export SSH_ASKPASS=/tmp/ssh_pass.sh DISPLAY=dummy:0; setsid ssh ... root@159.65.232.23`

## Key Notes
- Theme: Cream + Dark Navy (#1e3a5f), background #f5f0e8
- PHP 7.2+ compatibility (no arrow functions, no typed properties)
- PM2 runs both `vps-manager` (8080) and `web-terminal` (8081)
- `school` directory still exists at `/var/www/school/` but has no active nginx config
- `pma` and `pma.kotakide.web.id` have conflicting server_name — needs cleanup
