# VPS Panel

[GitHub](https://github.com/kazitoha/a-free-panel-for-setup-vps) · একটা self-hosted web panel for Ubuntu VPS — Caddy, phpMyAdmin, PowerDNS আর email server (Stalwart) সবকিছু GUI থেকে manage করার জন্য।

A self-hosted control panel for Ubuntu VPS servers. Manage Caddy sites, phpMyAdmin, PowerDNS zones, a Stalwart mail server, databases, project deploys, backups and cron jobs from one web GUI.

## কী কী আছে / Features

| Module | কী করে |
|---|---|
| **Dashboard** | Service status (LED strip), CPU load, RAM, disk, public IP, listening ports, service restart, job health checks |
| **Sites (Caddy)** | Add/edit/remove sites, PHP version, wildcard SSL, on-demand TLS, validate + auto-rollback |
| **phpMyAdmin** | One-click install + `pma.<panel-domain>` subdomain |
| **PHP versions** | Installed versions দেখায়, আর 8.x এক ক্লিকে install/remove (ondrej PPA) — Sites আর Projects-এ সেগুলোই বেছে নেওয়া যায় |
| **DNS (PowerDNS)** | Zone + record CRUD via the PowerDNS HTTP API, protected NS/panel zones, batch customer-domain import, service restart |
| **Email (Stalwart)** | Install wizard, mail domain + mailbox create/reset/delete, MX/SPF/DMARC/DKIM auto-records |
| **Databases** | MySQL database/user create, drop, grant, password reset |
| **Projects** | GitHub repo → clone, Caddy, Composer, Laravel deploy (domain + PHP version দিলেই) |
| **Logs** | journalctl viewer, Caddyfile editor (validate + rollback) |
| **Backups** | Per-database `.sql.gz` dumps + per-project `.tar.gz` archives, verified, retention-pruned, schedules (1–24 runs/day) |
| **Cron jobs** | Panel-managed jobs in `/etc/cron.d/panel` with strict command allowlist + the every-minute backup scheduler tick |
| **Health** | Dashboard checks: scheduler idle?, last backup/cron run failed?, cron file installed?, disk space |

## কিভাবে চালাবেন / How to run — নতুন Ubuntu VPS এ (22.04 / 24.04)

আগে DNS এ A record বসান: `panel.example.com → VPS_IP`। না বসালেও চলবে — installer আরও দুটো address লেখে: `https://panel.<VPS_IP>.nip.io` (সত্যিকারের certificate, কোনো DNS setup ছাড়াই চলে) আর `http://<VPS_IP>` (শুধু শুরুর setup-এর জন্য)। A record বসানোর সাথে সাথেই main domain টা HTTPS দিয়ে চলবে, সেটাই চূড়ান্ত URL। তারপর VPS এ ssh করে একটাই command — panel download, full setup আর admin login credential একসাথে set হয়ে যাবে:

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

- `--password` দিলে সেটাই admin password হবে (কমপক্ষে ৮ অক্ষর — না দিলে random generate হবে, অল্প হলে কোনো প্যাকেজ ইনস্টল করার আগেই installer থেমে যাবে)। ইনস্টল শেষ হওয়ার পর output এর সবার শেষে login URL আর email/password দেখানো হয়
- piped mode এ prompt চালু থাকলেও নেই (`curl | bash` এ stdin = script, তাই installer নিজেই non-interactive ধরে নেয়)
- আগে থেকেই `/var/www/panel` এ panel থাকলে installer জিজ্ঞেস করে কী করবে — **update** (ডিফল্ট: থকেই রেখে fast-forward), **overwrite** (পুরো project মুছে নতুন করে ইনস্টল; আগে `.env` আর database `/root/panel-backups/overwrite-<time>` এ backup নেয়), **skip** (code যেমন আছে তেমনই রাখবে)। শেষ দুটোর জন্য piped mode এ `--code=overwrite` / `--code=skip` দিতে হবে
- `--repo` এ full URL দিতে হবে (`https://github.com/…`, `git@host:path` বা local path)। `owner/repo` shorthand দিলে installer আগেই থেমে গিয়ে ঠিক কী লিখবে দেখিয়ে দেয় (যেমন `--repo=https://github.com/owner/repo`)
- Admin credential পরেও পাওয়া যায়: `/root/.panel-secrets` এ `PANEL_ADMIN_EMAIL` / `PANEL_ADMIN_PASSWORD` লেখা থাকে (chmod 600)

বিকল্প: script download করে চালাতে চাইলে (interactive wizard):

```bash
ssh root@VPS_IP
curl -o /tmp/install-panel.sh https://raw.githubusercontent.com/kazitoha/a-free-panel-for-setup-vps/main/install-panel.sh
sudo bash /tmp/install-panel.sh
```

Wizard ৩টা জিনিস চাবে: panel domain, admin email, admin password (blank = auto generate)। panel আগে থেকেই থাকলে ৪র্থ প্রশ্ন আসে: update / overwrite / skip।

Installer যা সেটআপ করে: PHP-FPM + extensions · MySQL · Composer · Caddy · PowerDNS (HTTP API + zone seed) · Node/assets · panel code (clone) · migrations · admin account · HTTPS site · sudo runner · UFW। আবার চালালে safe (idempotent) — আগে থেকেই আছে কিনা জিজ্ঞেস করে, ডিফল্টে code update হয়।

```bash
# সব option দিয়ে local file থেকেও চালানো যায়
sudo bash install-panel.sh \
  --domain=panel.example.com \
  --email=you@example.com \
  --password='strong-password' \
  --repo=https://github.com/kazitoha/a-free-panel-for-setup-vps.git \
  --ns-domain=example.com \
  --non-interactive
```

Install এর পর:

1. `https://panel.example.com` এ sign in করুন
2. Dashboard থেকে বাকি modules install করুন
3. Panel er `.env` আর passwords (MySQL root, PowerDNS API key, panel admin login) থাকে `/root/.panel-secrets` এ (chmod 600)
4. DNS module চালাতে চাইলে registrar এ ns1/ns2 দুটোকই `${PDNS_SERVER_IP}` এ point করে দিন

## Backups আর cron

- **Backups** page এ target বেছে schedule বানান — দিনে 1–24 বার (evenly spread), retention 1–90 দিন। Archive যায় `/var/backups/panel/{databases,projects}/` (root-only, 0700); প্রতিটা dump `gzip -t`, project archive `tar tzf` দিয়ে verify হয়, পুরোনো ফাইল retention অনুযায়ী auto-prune হয়।
- Scheduler tick প্রতি মিনিটে `/etc/cron.d/panel` থেকে চলে (`panel:scheduler:tick`) — কোনো schedule due হলেই backup চালায় আর `next_run_at` এগিয়ে দেয়। Installer এ tick line আগে থেকেই লেখা থাকে।
- **Cron jobs** page এ command দিন — শুধু allowlist: `run panel:*`, `systemctl <start|stop|restart|reload> <unit>`, `reboot`। প্রতিবার save/toggle/delete করলেই `/etc/cron.d/panel` নতুন করে লেখা হয়; প্রতিটা job এর last run status + output page এ থাকে।
- Dashboard এর **jobs / health** card দেখায়: cron file ইনস্টলড কিনা, কোনো schedule overdue (scheduler idle) কিনা, শেষ backup/cron run fail কিনা, disk space কত বাকি।

## Google sign-in (ঐচ্ছিক, admin)

1. [console.cloud.google.com](https://console.cloud.google.com) → Credentials → **Create OAuth client ID** (Web application)
2. Authorized redirect URI: `https://panel.example.com/auth/google/callback`
3. panel এর `.env` এ বসান আর `php artisan config:clear`:

```dotenv
GOOGLE_CLIENT_ID=xxxxxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxxxxx
```

`.env` এ দুটো value থাকলেই login page এ **Sign in with Google** button দেখাবে। শুধু যার email আগে থেকেই panel user হিসেবে আছে সেই Google account দিয়ে sign in করা যাবে (প্রথম successful sign-in এ `google_id` link হয়ে যায়); অন্য Google account এর জন্য পাওয়া যাবে না। Google login ছাড়াও password login আগের মতোই থাকবে।

## নিরাপত্তা / Security model

- Panel code **root-owned** থাকে — web user (www-data) code modify করতে পারে না
- www-data শুধু `sudo -n /usr/local/panel/run panel:*` চালাতে পারে — root-owned wrapper strict allowlist যাচাই করে, arbitrary shell নেই
- প্রতিটা privileged command input validate করে (service unit allowlist, domain regex, PHP version enum)
- Caddyfile এর আগে backup, `caddy validate` fail করলে auto-rollback
- Cron jobs: শুধু `run panel:*`, `systemctl <start|stop|restart|reload> <unit>`, `reboot` — shell metacharacter নিষিদ্ধ, `/etc/cron.d/panel` প্রতিবার panel নিজেই লেখে
- Backups: `/var/backups/panel` root-only (0700), প্রতিটা archive `gzip -t`/`tar tzf` দিয়ে verify, retention দিয়ে auto-prune
- UFW: SSH + 80 + 443 + 53 (DNS) ছাড়া সব blocked
- Admin login: session auth + login throttle

## কিভাবে চালাবেন (local development)

```bash
git clone https://github.com/kazitoha/a-free-panel-for-setup-vps.git
cd a-free-panel-for-setup-vps

composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan panel:admin:create you@example.com --password=secret123
npm install && npm run build     # অথবা npm run dev
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
install-panel.sh          # bootstrap installer (root এ চালাতে হয়)
scripts/run.sh            # sudo wrapper → /usr/local/panel/run
scripts/panel.sudoers     # sudoers rule template
scripts/legacy/           # আগের bash wizard গুলো (reference)
app/Http/Controllers/     # Blade controllers
app/Console/Commands/     # panel:* privileged commands
app/Services/             # SystemService, SudoRunner, ...
app/Support/              # HostCommand, ServiceUnits, ...
resources/views/          # Blade templates (dark console UI)
```

## Roadmap

Phase 0 (এই ভাল্সন): auth + dashboard + installer + sudo runner ✅
Phase 1 (এই ভাল্সন): Caddy sites + phpMyAdmin ✅
Phase 2 (এই ভাল্সন): PowerDNS zones + records ✅
Phase 3 (এই ভাল্সন): Stalwart email ✅
Phase 4 (এই ভাল্সন): Databases + Projects + Logs ✅
Phase 5 (এই ভাল্সন): Backups + cron schedules + health checks ✅
Phase 6: Google login (admin)
