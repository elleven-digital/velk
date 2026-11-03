# Installing Velk

Velk supports two install paths. Both end with the same state: a working
database, an admin user, and a site you can log into.

## Requirements

- PHP 8.2+ (with `pdo_mysql`, `gd`, `fileinfo`, `mbstring`)
- MySQL 5.7+ / MariaDB 10.3+
- Apache with `mod_rewrite` (or Nginx — see [nginx.conf.example](nginx.conf.example))

## Path 1 — One-shot CLI install

Use this when you have shell access (local dev, VPS, anything where you can
run `./bin/velk`).

### 1. Get the code

```bash
git clone https://github.com/your-org/your-theme.git my-site
cd my-site
```

### 2. Configure `.env`

```bash
cp .env.example .env
```

Edit `.env`. At minimum:

```env
DB_HOST=localhost
DB_DATABASE=mysite
DB_USERNAME=root
DB_PASSWORD="your-mysql-password"

INITIAL_USER_EMAIL="you@example.com"
INITIAL_USER_PASSWORD="a-secure-password"
INITIAL_USER_NAME="Your Name"
```

### 3. Run the installer

```bash
./bin/velk install
```

Output:

```
Velk — Install
──────────────────

1. Database `mysite` … OK
2. Migrations: 1 applied
   + 2026_04_26_000001_initial_schema.php
3. Pages: 2 added
4. Initial user: you@example.com

✓ Installed.
```

You can also override `.env` with flags:

```bash
./bin/velk install \
  --email=you@example.com \
  --password=secret123 \
  --name="Your Name"
```

### 4. Done

Open `http://your-domain/admin` and log in.

## Path 2 — Auto-install on first web request

Use this when you don't have shell access (most cheap shared hosts).

### 1. Upload the code

Via FTP/SFTP/git, get the files into your hosting account.

### 2. Configure `.env`

Create `.env` at the project root with the same values as Path 1 (DB
credentials + `INITIAL_USER_*`).

If your shared host won't let you create databases, ask the panel to create
the database for you and only fill in `DB_DATABASE`. The installer will
create the tables inside it.

### 3. Open the site

The first request to any URL detects the empty database, runs migrations,
syncs pages from `site.json`, and creates the admin user from `INITIAL_USER_*`.

If the install completes, you're redirected to the front-end. Visit `/admin`
to log in.

If something is missing (no DB credentials, no INITIAL_USER), Velk shows a
**Setup required** page with a checklist of what's missing and how to fix it.

### 4. Cleanup (recommended)

After first login, the `INITIAL_USER_*` lines in `.env` are ignored on
subsequent boots. You can safely delete them.

## What happens during install

The installer is idempotent — running it twice is safe.

1. **Storage** — creates `storage/uploads`, `storage/cache`, `storage/logs`
   with permissions the web server can write to (0775).
2. **Database** — `CREATE DATABASE IF NOT EXISTS` against the configured name.
   Skipped if the user lacks `CREATE` privilege but the DB already exists.
3. **Migrations** — runs every file in `migrations/` that hasn't been applied
   yet. Recorded in the `migrations` table.
4. **Pages** — for every entry in `theme/site.json → pages`, creates a row in
   the `pages` table if it doesn't exist. Idempotent.
5. **Initial user** — only if `users` table is empty AND `INITIAL_USER_EMAIL` /
   `INITIAL_USER_PASSWORD` are set. Skipped if any user already exists.

## Theme: a separate package, like WordPress

Velk core ships with **no theme**. A theme is its own package — typically a
git repo — that lives in `theme/` and contains:

```
theme/
├── site.json          ← schema (pages, item types, taxonomies, fields)
├── style.css          ← stylesheet
├── partials/          ← header.php, footer.php, ...
└── templates/         ← page-*.php, single-*.php, archive-*.php, taxonomy-*.php
```

A site = Velk core + a theme. The theme defines the structure (via
`site.json`) AND the rendering (via templates).

### Installing a theme

Drop the theme into `theme/`:

```bash
git clone https://github.com/your-org/your-theme.git theme
```

Or copy any folder structure that matches the layout above. As long as
`theme/site.json` exists and is valid, Velk boots normally.

If `theme/` is empty or missing `site.json`, Velk shows a **Setup required**
page asking you to install a theme.

### Authoring a theme

The theme has no boilerplate to subclass. Templates are pure PHP using the
helpers from [AGENTS.md](AGENTS.md). The `site.json` schema declares pages,
item types, taxonomies, fields — and the admin panel is generated from it.

## Common errors

| Symptom | Cause | Fix |
|---------|-------|-----|
| `SQLSTATE[HY000] [1045] Access denied` | Wrong DB credentials | Check `DB_USERNAME` / `DB_PASSWORD` in `.env` |
| `SQLSTATE[HY000] [2002] Connection refused` | MySQL not running or wrong host/port | Start MySQL, check `DB_HOST` / `DB_PORT` |
| `Database does not exist and could not be created` | DB user lacks `CREATE` privilege | Create the DB manually via your hosting panel |
| Setup required page after install | `.env` not loaded or missing | Verify `.env` exists at project root and is readable |
| `INITIAL_USER_EMAIL is not a valid email` | Typo in `.env` | Fix the email format |
| Upload returns "Não foi possível salvar o arquivo" | Web server can't write to `storage/uploads/` | `chmod -R 775 storage` (or `chown -R www-data storage` if your web server runs as `www-data`). The installer attempts this automatically but won't override permissions on existing files created with stricter umask. |

## What the installer does NOT touch

- Your existing `site.json` content
- The `theme/` directory
- Files in `storage/uploads/`
- Any data in tables that already exist (only adds, never overwrites)

## Uninstalling

There is no built-in uninstall. To wipe and reinstall:

```bash
mysql -e "DROP DATABASE mysite"
rm -rf storage/uploads/*
./bin/velk install
```
