# VPS Panel

একটা self-hosted web panel for Ubuntu VPS — Caddy, phpMyAdmin, PowerDNS আর email server (Stalwart) সবকিছু GUI থেকে manage করার জন্য।

A self-hosted control panel for Ubuntu VPS servers. Manage Caddy sites, phpMyAdmin, PowerDNS zones and a Stalwart mail server from one web GUI.

## কী কী আছে / Features

| Module | কী করে |
|---|---|
| **Dashboard** | Service status (LED strip), CPU load, RAM, disk, public IP, listening ports, service restart |
| **Sites (Caddy)** | Add/edit/remove sites, PHP version, wildcard SSL, on-demand TLS, validate + auto-rollback |
| **phpMyAdmin** | One-click install + `pma.<panel-domain>` subdomain |
| **DNS (PowerDNS)** | Zone + record CRUD via the PowerDNS HTTP API, protected NS/panel zones, batch customer-domain import, service restart |
| **Email (Stalwart)** | Install wizard, mail domain + mailbox create/reset/delete, MX/SPF/DMARC/DKIM auto-records |
| **Databases** | MySQL database/user create, drop, grant, password reset |
| **Projects** | GitHub repo → clone, Caddy, Composer, Laravel deploy (domain + PHP version দিলেই) |
| **Logs** | journalctl viewer, Caddyfile editor |

## Install — নতুন Ubuntu VPS এ (22.04 / 24.04)

আগে DNS এ A record বসান: `panel.example.com → VPS_IP`। তারপর একটাই command — download, full setup আর admin login credential একসাথে set হয়ে যাবে:

```bash
curl -fsSL https://raw.githubusercontent.com/<you>/<repo>/main/install-panel.sh \
  | sudo bash -s -- \
      --repo=https://github.com/<you>/<repo>.git \
      --domain=panel.example.com \
      --email=you@example.com \
      --password='strong-password' \
      --ns-domain=example.com \
      --non-interactive
```

- `--password` দিলে সেটাই admin password হবে; না দিলে random generate হবে আর summary এ দেখাবে
- piped mode এ prompt চালু থাকলেও নেই (`curl | bash` এ stdin = script, তাই installer নিজেই non-interactive ধরে নেয়)
- Admin credential পরেও পাওয়া যায়: `/root/.panel-secrets` এ `PANEL_ADMIN_EMAIL` / `PANEL_ADMIN_PASSWORD` লেখা থাকে (chmod 600)

বিকল্প: script download করে চালাতে চাইলে (interactive wizard):

```bash
ssh root@VPS_IP
curl -o /tmp/install-panel.sh https://raw.githubusercontent.com/<you>/<repo>/main/install-panel.sh
sudo bash /tmp/install-panel.sh
```

Wizard ৩টা জিনিস চাবে: panel domain, admin email, admin password (blank = auto generate)।

Installer যা সেটআপ করে: PHP-FPM + extensions · MySQL · Composer · Caddy · PowerDNS (HTTP API + zone seed) · Node/assets · panel code (clone) · migrations · admin account · HTTPS site · sudo runner · UFW। আবার চালালে safe (idempotent) — code update হয়।

```bash
# সব option দিয়ে local file থেকেও চালানো যায়
sudo bash install-panel.sh \
  --domain=panel.example.com \
  --email=you@example.com \
  --password='strong-password' \
  --repo=https://github.com/<you>/<repo>.git \
  --ns-domain=example.com \
  --non-interactive
```

Install এর পর:

1. `https://panel.example.com` এ sign in করুন
2. Dashboard থেকে বাকি modules install করুন
3. Panel er `.env` আর passwords (MySQL root, PowerDNS API key, panel admin login) থাকে `/root/.panel-secrets` এ (chmod 600)
4. DNS module চালাতে চাইলে registrar এ ns1/ns2 দুটোকই `${PDNS_SERVER_IP}` এ point করে দিন

## নিরাপত্তা / Security model

- Panel code **root-owned** থাকে — web user (www-data) code modify করতে পারে না
- www-data শুধু `sudo -n /usr/local/panel/run panel:*` চালাতে পারে — root-owned wrapper strict allowlist যাচাই করে, arbitrary shell নেই
- প্রতিটা privileged command input validate করে (service unit allowlist, domain regex, PHP version enum)
- Caddyfile এর আগে backup, `caddy validate` fail করলে auto-rollback
- UFW: SSH + 80 + 443 + 53 (DNS) ছাড়া সব blocked
- Admin login: session auth + login throttle

## Development

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite && php artisan migrate
php artisan panel:admin:create you@example.com --password=secret123
npm install && npm run build     # অথবা npm run dev
php artisan serve                # http://127.0.0.1:8000
```

Verify:

```bash
vendor/bin/pint --format agent   # code style
php artisan test --compact       # PHPUnit (125 tests)
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
Phase 4: Databases + Projects + Logs
# a-free-panel-for-setup-vps
