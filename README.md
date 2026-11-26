# FreshRSS Hub

Deployment configuration for self-hosted [FreshRSS](https://freshrss.org): Docker Compose stack for local use (Windows) plus our forks wired in as git submodules. FreshRSS itself runs as the prebuilt `freshrss/freshrss:latest` image — no FreshRSS sources are cloned; customization lives in extensions (submodules) and volume mounts. Update: `docker compose pull && docker compose up -d`.

Live instance: https://rss.adventure-awaits.ru (manual install, no Docker).

## Repository layout

```
freshrss/
├── README.md              # this file
├── docker-compose.yml     # mounts → ./runtime/...
├── .env                   # secrets (not committed)
├── .env.example           # template
├── .gitignore             # runtime/data, runtime/db, .env
└── runtime/               # runtime data
    ├── data/              # FreshRSS configs and users (gitignored)
    ├── db/                # MariaDB (gitignored)
    ├── themes/
    │   └── Reed           # git submodule: dobien/reed-theme fork (Reed theme)
    └── extensions/
        ├── xExtension-ThreePaneReader  # git submodule: dobien fork (see below)
        └── xExtension-DailyDigest      # leftover extension from an old install, unused
```

## Three Pane Reader (submodule)

The three-pane layout (feeds / article list / article) is provided by the
[Three Pane Reader](https://github.com/caglaryalcin/xExtension-ThreePaneReader) extension.
We use the **`dobien/xExtension-ThreePaneReader` fork** as a submodule with our branches:

- `fix-menuresize`: `menuForm.style.height` in `_resize()` crashed the extension JS on FreshRSS ≥ 1.30 (the `mark-read-aside` element no longer exists); commit `5d21abb` adds a null check.
- `reed-compat` (based on `fix-menuresize`): removes all hard-coded dark colors (`#16191C`, `#C1C4C7`, forced fonts/typography) from `inoreader.css`, so the FreshRSS theme (Reed) fully owns the palette; layout, splitters and transparent backgrounds stay.

Upstream PRs from these branches are welcome material. Update flow: `git -C runtime/extensions/xExtension-ThreePaneReader pull --rebase`.

**The extension must be enabled after (re)creating a user:**

1. Via UI: Settings → Extensions → «Three Pane Reader» → Enable.
2. Or in `runtime/data/users/<user>/config.php`:

```php
'extensions' => array(
  'ThreePaneReader' => true,
),
```

Then hard-refresh the page, switch to **Normal view** (key `1`) and click an article — it opens in the right pane. Pane widths are draggable and persisted in localStorage. Requires viewport ≥ 800px.

## Reed theme (submodule)

Modern theme [reed-theme](https://github.com/SiggeMcKvack/reed-theme): fork **`dobien/reed-theme`**, submodule at `runtime/themes/Reed`, mounted into the container as `/var/www/FreshRSS/p/themes/Reed`. Style customization goes into `reed.css` (CSS variables) in the fork.

- Enabled for the user via `'theme' => 'Reed'` in `runtime/data/users/<user>/config.php`;
- Light/dark palettes follow the system (Settings → Display → Automatic dark mode = Auto);
- Update from upstream: `git -C runtime/themes/Reed pull --rebase`;
- Compatible with FreshRSS 1.30.4 (the version this stack uses).

## Run (local, Windows)

Requires Docker Desktop (installed in `%LOCALAPPDATA%\Programs\DockerDesktop`).

```
git submodule update --init   # first time only
docker compose up -d
```

- UI: http://localhost:8090
- Admin login: `krechet` (password is set / reset via CLI, see below)
- DB: MariaDB, host `db`, credentials in `.env` (template: `.env.example`)

## Server deployment (manual, no Docker)

The production instance at https://rss.adventure-awaits.ru (sprintbox VPS, Debian 12) runs FreshRSS 1.30.4 manually: PHP 8.2-fpm + SQLite, nginx + certbot, cron every 15 min (`/etc/cron.d/freshrss`). FreshRSS core lives in `/opt/freshrss`; customizations from this repo's submodules are copied into `/opt/freshrss/extensions/xExtension-ThreePaneReader` and `/opt/freshrss/p/themes/Reed`. Details in the Obsidian note «FreshRSS на vps Sprintbox (185.72.146.91)».

On a fresh server: clone this repo with `--recurse-submodules` (public — no token needed), then install FreshRSS core and copy the submodule folders in.

## Handy commands

```powershell
# Reset a user password
docker compose exec freshrss php ./cli/update-user.php --user=krechet --password=<new>

# List users
docker compose exec freshrss php ./cli/list-users.php

# Export / import subscriptions (OPML)
docker compose exec freshrss php ./cli/export-opml-for-user.php --user krechet
docker compose exec freshrss php ./cli/import-for-user.php --user krechet --filename /path/to/file.opml

# Logs
docker compose logs freshrss --tail 50
```

## Configuration

- Feed auto-refresh: `CRON_MIN` in `.env` (every 15 min), TZ `Europe/Moscow`.
- Healthchecks: freshrss uses a PHP check of `/i/` (the image has no curl); db uses the stock MariaDB healthcheck.
