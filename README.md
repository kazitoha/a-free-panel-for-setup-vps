# VPS Panel

[GitHub](https://github.com/kazitoha/a-free-panel-for-setup-vps) · A self-hosted web panel for Ubuntu VPS — manage Caddy, phpMyAdmin, PowerDNS and an email server (Stalwart) entirely from a GUI.

A self-hosted control panel for Ubuntu VPS servers. Manage Caddy sites, phpMyAdmin, PowerDNS zones, a Stalwart mail server, databases, project deploys, backups and cron jobs from one web GUI.

## Features

| Module | What it does |
|---|---|
| **Dashboard** | Service status (LED strip), CPU load, RAM, disk, public IP, listening ports, service restart, job health checks |
| **Sites (Caddy)** | Add/edit/remove sites, PHP version, wildcard SSL, on-demand TLS, validate + auto-rollback |
| **phpMyAdmin** | One-click install + `pma.<panel-domain>` subdomain |
| **PHP versions** | Shows installed versions, and installs/removes any 8.x in one click (ondrej PPA) — pick them in Sites and Projects |
| **DNS (PowerDNS)** | Zone + record CRUD via the PowerDNS HTTP API, protected NS/panel zones, batch customer-domain import, service restart |
| **Email (Stalwart)** | Install wizard, mail domain + mailbox create/reset/delete, MX/SPF/DMARC/DKIM auto-records |
| **Databases** | MySQL database/user create, drop, grant, password reset |
| **Projects** | GitHub repo → clone, Caddy, Composer, Laravel deploy (just give a domain + PHP version) |
| **Logs** | journalctl viewer, Caddyfile editor (validate + rollback) |
| **Backups** | Per-database `.sql.gz` dumps + per-project `.tar.gz` archives, verified, retention-pruned, schedules (1–24 runs/day) |
| **Cron jobs** | Panel-managed jobs in `/etc/cron.d/panel` with strict command allowlist + the every-minute backup scheduler tick |
| **Health** | Dashboard checks: scheduler idle?, last backup/cron run failed?, cron file installed?, disk space |

## How to run — on a fresh Ubuntu VPS (22.04 / 24.04)

First, point an A record at the VPS: `panel.example.com → VPS_IP`. It also works without one — the installer writes two more addresses: `https://panel.<VPS_IP>.nip.io` (a real certificate, no DNS setup required) and `http://<VPS_IP>` (for the initial setup only). As soon as you add the A record, the main domain serves over HTTPS; that is the canonical URL. Then SSH into the VPS and run a single command — the panel download, full setup and admin login credentials are all handled at once:

```bash
curl -fsSL https://raw.githubusercontent.com/kazitoha/a-free-panel-for-setup-vps/main/install-panel.sh \
  | sudo bash -s -- \
      --repo=https://github.com/kazitoha/a-free-panel-for-setup-vps.git \
      --domain=panel.example.com \
      --email=you@example.com \
      --password='strong-password' \
      --ns-domain=example.com \
      --non-interactive
```

- Whatever you pass to `--password` becomes the admin password (at least 8 characters — if omitted, a random one is generated; if too short, the installer stops before installing any package). After the install finishes, the login URL and email/password are printed at the very end of the output
- Prompts are disabled in piped mode anyway (`curl | bash` gives the script stdin, so the installer assumes non-interactive)
- If a panel already exists in `/var/www/panel`, the installer asks what to do — **update** (default: keep it and fast-forward), **overwrite** (delete the whole project and reinstall; the `.env` and database are backed up to `/root/panel-backups/overwrite-<time>` first), **skip** (leave the code as it is). For the last two, pass `--code=overwrite` / `--code=skip` in piped mode
- `--repo` must be a full URL (`https://github.com/…`, `git@host:path`, or a local path). If you pass the `owner/repo` shorthand, the installer stops early and shows the exact form to use (e.g. `--repo=https://github.com/owner/repo`)
- Admin credentials can be retrieved later: `/root/.panel-secrets` contains `PANEL_ADMIN_EMAIL` / `PANEL_ADMIN_PASSWORD` (chmod 600)
- MySQL root password: on a fresh server a random one is generated and set for you. If MySQL is already secured (for example by an earlier setup script), the installer unlocks it via `/root/.panel-secrets`, Debian's `/etc/mysql/debian.cnf`, an interactive prompt, or `--mysql-password='<current mysql root password>'` (which also becomes the stored root password). If none of them work, the install still finishes — see the setup page below
- When the installer cannot set something itself (an unknown MySQL root password, or no `--email`), it records a marker in `storage/app/setup-pending.json` and the panel opens `/first-run` instead of the login page on the first visit. That page asks only for what is missing — the MySQL root password and/or the administrator email + password — applies it through the privileged runner and then hands over to `/login`. Everything else is already installed at that point, and no second setup run can be started from the web side

Alternative: download the script and run it (interactive wizard):

```bash
ssh root@VPS_IP
curl -o /tmp/install-panel.sh https://raw.githubusercontent.com/kazitoha/a-free-panel-for-setup-vps/main/install-panel.sh
sudo bash /tmp/install-panel.sh
```

The wizard asks three things: panel domain, admin email, admin password (blank = auto generate). If a panel already exists, a fourth question appears: update / overwrite / skip.

What the installer sets up: PHP-FPM + extensions · MySQL · Composer · Caddy · PowerDNS (HTTP API + zone seed) · Node/assets · panel code (clone) · migrations · admin account · HTTPS site · sudo runner · UFW. Re-running it is safe (idempotent) — it asks what already exists and updates the code by default.

```bash
# You can also run it from a local file with all options
sudo bash install-panel.sh \
  --domain=panel.example.com \
  --email=you@example.com \
  --password='strong-password' \
  --repo=https://github.com/kazitoha/a-free-panel-for-setup-vps.git \
  --ns-domain=example.com \
  --non-interactive
```

After installing:

1. Sign in at `https://panel.example.com`
2. Install the remaining modules from the Dashboard
3. The panel's `.env` and passwords (MySQL root, PowerDNS API key, panel admin login) live in `/root/.panel-secrets` (chmod 600)
4. To run the DNS module, point both ns1/ns2 at `${PDNS_SERVER_IP}` at your registrar

## Backups and cron

- On the **Backups** page, pick targets and create a schedule — 1–24 times per day (evenly spread), retention 1–90 days. Archives go to `/var/backups/panel/{databases,projects}/` (root-only, 0700); every dump is verified with `gzip -t`, project archives with `tar tzf`, and old files are auto-pruned according to retention.
- The scheduler tick runs every minute from `/etc/cron.d/panel` (`panel:scheduler:tick`) — whenever a schedule is due it runs the backup and advances `next_run_at`. The installer writes the tick line in advance.
- On the **Cron jobs** page, enter a command — allowlist only: `run panel:*`, `systemctl <start|stop|restart|reload> <unit>`, `reboot`. Every save/toggle/delete rewrites `/etc/cron.d/panel`; each job's last run status and output are shown on the page.
- The Dashboard **jobs / health** card shows: whether the cron file is installed, whether any schedule is overdue (scheduler idle), whether the last backup/cron run failed, and how much disk space remains.

## Google sign-in (optional, admin)

1. [console.cloud.google.com](https://console.cloud.google.com) → Credentials → **Create OAuth client ID** (Web application)
2. Authorized redirect URI: `https://panel.example.com/auth/google/callback`
3. Put these in the panel's `.env` and run `php artisan config:clear`:

```dotenv
GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxx
```

With both values in `.env`, the login page shows a **Sign in with Google** button. Only Google accounts whose email already exists as a panel user can sign in (the first successful sign-in links the `google_id`); other Google accounts are rejected. Password login keeps working as before alongside Google login.

## Security model

- Panel code is **root-owned** — the web user (www-data) cannot modify it
- www-data can only run `sudo -n /usr/local/panel/run panel:*` — the root-owned wrapper enforces a strict allowlist and offers no arbitrary shell
- Every privileged command validates its input (service unit allowlist, domain regex, PHP version enum)
- The Caddyfile is backed up first, with auto-rollback if `caddy validate` fails
- Cron jobs: only `run panel:*`, `systemctl <start|stop|restart|reload> <unit>`, `reboot` — shell metacharacters are forbidden, and the panel itself rewrites `/etc/cron.d/panel` every time
- Backups: `/var/backups/panel` is root-only (0700), every archive is verified with `gzip -t`/`tar tzf`, and retention auto-prunes
- UFW: everything blocked except SSH + 80 + 443 + 53 (DNS)
- Admin login: session auth + login throttle

## How to run (local development)

```bash
git clone https://github.com/kazitoha/a-free-panel-for-setup-vps.git
cd a-free-panel-for-setup-vps

composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan panel:admin:create you@example.com --password=secret123
npm install && npm run build     # or npm run dev
php artisan serve                # http://127.0.0.1:8000
```

Login: `you@example.com` / `secret123` → `http://127.0.0.1:8000/login`

Verify:

```bash
vendor/bin/pint --format agent   # code style
php artisan test --compact       # PHPUnit (231 tests)
```

## Structure

```
install-panel.sh          # bootstrap installer (must run as root)
scripts/run.sh            # sudo wrapper → /usr/local/panel/run
scripts/panel.sudoers     # sudoers rule template
scripts/legacy/           # previous bash wizards (reference)
app/Http/Controllers/     # Blade controllers
app/Console/Commands/     # panel:* privileged commands
app/Services/             # SystemService, SudoRunner, ...
app/Support/              # HostCommand, ServiceUnits, ...
resources/views/          # Blade templates (dark console UI)
```

## Roadmap

Phase 0 (this release): auth + dashboard + installer + sudo runner ✅
Phase 1 (this release): Caddy sites + phpMyAdmin ✅
Phase 2 (this release): PowerDNS zones + records ✅
Phase 3 (this release): Stalwart email ✅
Phase 4 (this release): Databases + Projects + Logs ✅
Phase 5 (this release): Backups + cron schedules + health checks ✅
Phase 6: Google login (admin)
