# SPHPD — Simple PHP Deployer

A minimalist, single-file PHP deployment tool with a live dashboard, webhook support, Telegram notifications, and JSON/YAML instruction files.

> **Requires PHP 8.5+**

---

## Features

- **Single file** — the entire application lives in `index.php`
- **JSON or YAML** instruction files
- **Live status dashboard** — real-time task progress with expandable output per task
- **Webhook endpoint** — trigger deployments via HTTP (with optional security token)
- **Telegram notifications** — receive deployment results in a chat or thread
- **Multiple log formats** — plain text, raw, and HTML logs
- **HTTP Basic Auth** — optionally restrict access to the UI
- **Stop mid-run** — abort a running deployment from the dashboard
- **No-wait mode** — fire-and-forget webhook that returns `202 Accepted` immediately
- **Dark mode** UI built with Tailwind CSS

---

## Quick Start

### 1. Copy files to your server

Place `index.php` alongside your project or in a dedicated deployer directory.

### 2. Configure environment

```sh
cp .env.example .env
```

Edit `.env` with your settings (see [Configuration](#configuration)).

### 3. Create an instruction file

**`deploy.json`** (default):

```json
[
  { "name": "Git Pull", "run": "git pull origin main" },
  { "name": "Install Dependencies", "run": "composer install --no-dev" },
  { "name": "Optimize Cache", "run": "php artisan config:cache" }
]
```

Or use **`deploy.yml`** (requires `yq` — see [YAML Support](#yaml-support)):

```yaml
- name: "Git Pull"
  run: "git pull origin main"

- name: "Install Dependencies"
  run:
    - "composer install --no-dev"
    - "php artisan config:cache"
```

### 4. Start the built-in server (development)

```sh
composer start
# Runs php -S localhost:5173
```

Open `http://localhost:5173` to access the dashboard.

---

## Configuration

All configuration is done via `.env`:

| Variable                  | Default       | Description                                                     |
|---------------------------|---------------|-----------------------------------------------------------------|
| `PROJECT_PATH`            | *(required)*  | Absolute path where deployment commands run                     |
| `INSTRUCTIONS_FILE`       | `deploy.json` | Path to your instruction file (JSON or YAML)                    |
| `LOGS_PATH`               | `./logs`      | Directory where deployment logs are stored                      |
| `SECURITY_TOKEN`          |               | Secret token required for webhook requests (header or query)    |
| `WEBHOOK_METHOD`          | `POST`        | HTTP method accepted by the webhook (`GET` or `POST`)           |
| `LOAD_USER`               |               | HTTP Basic Auth username (optional)                             |
| `LOAD_PASS`               |               | HTTP Basic Auth password (optional)                             |
| `TELEGRAM_NOTIFICATIONS`  | `true`        | Enable/disable Telegram notifications                           |
| `TELEGRAM_BOT_TOKEN`      |               | Telegram bot token                                              |
| `TELEGRAM_CHAT_ID`        |               | Telegram chat or group ID                                       |
| `TELEGRAM_THREAD_ID`      |               | Telegram thread/topic ID (optional, for supergroups)            |
| `YQ_PATH`                 |               | Path to the `yq` binary (required for YAML instruction files)   |
| `MODE`                    | `production`  | Set to any non-`production` value to enable the `/debugdeploy` route |

---

## Instruction File Format

Each instruction file is an **array of tasks**. A task has:

| Field  | Type              | Description                           |
|--------|-------------------|---------------------------------------|
| `name` | string            | Human-readable label shown in the UI  |
| `run`  | string or array   | Shell command(s) to execute in order  |

Commands within a task share the same shell session, so environment variables exported in one command are available in subsequent ones.

### Examples

**Single command:**
```json
{ "name": "Pull latest code", "run": "git pull origin main" }
```

**Multiple commands (array):**
```json
{
  "name": "Build",
  "run": [
    "export NODE_ENV=production",
    "npm ci",
    "npm run build"
  ]
}
```

**Multi-line script (YAML block scalar):**
```yaml
- name: "Deploy"
  run: |
    git pull origin main
    composer install --no-dev
    php artisan migrate --force
```

---

## Endpoints

| Method      | Path                      | Description                                              |
|-------------|---------------------------|----------------------------------------------------------|
| `GET`       | `/`                       | Redirects to `/health`                                   |
| `GET`       | `/health`                 | Main dashboard — history, config status, example         |
| `GET/POST`  | `/webhook/deploy`         | Trigger deployment (waits for completion)                |
| `GET/POST`  | `/webhook/deploy?manual=1`| Trigger deployment from the UI (background process)      |
| `POST`      | `/webhook/deploy/nowait`  | Trigger deployment — returns `202` immediately           |
| `GET`       | `/status/live`            | Real-time live status page                               |
| `GET`       | `/status/check`           | JSON: `{ "finished": true/false }`                       |
| `GET`       | `/status/data`            | JSON: full current deployment status                     |
| `GET`       | `/log/view?file=<name>`   | View a specific log file                                 |
| `GET`       | `/log/rview/<id>`         | Raw `.log` view by ID                                    |
| `GET`       | `/log/bview/<id>`         | Base raw `.log.rlog` view by ID                          |
| `GET`       | `/log/htmlview/<id>`      | HTML log view by ID                                      |
| `GET`       | `/log/last`               | View the most recent log                                 |
| `GET`       | `/log/lasthtml`           | HTML view of the most recent log                         |
| `POST`      | `/deploy/stop`            | Stop the currently running deployment                    |
| `GET`       | `/test-notify`            | Send a test Telegram notification                        |
| `GET`       | `/clear-history`          | Clear all deployment log history                         |

### Webhook Security

When `SECURITY_TOKEN` is set, all webhook requests must include it:

- **Header:** `X-Deploy-Token: <token>`
- **Query string:** `?token=<token>`

Requests from `localhost` / `127.0.0.1` are always allowed (for UI-triggered deployments).

---

## YAML Support

To use a `.yml` / `.yaml` instruction file, you need the [`yq`](https://github.com/mikefarah/yq) binary:

1. Download the appropriate binary for your platform (a `yq_linux_amd64` binary is included in this repository for convenience).
2. Set `YQ_PATH` in your `.env`:

```env
INSTRUCTIONS_FILE=deploy.yml
YQ_PATH=./yq_linux_amd64
```

The deployer will automatically make the binary executable if needed.

---

## Telegram Notifications

Configure a bot to receive deployment reports:

```env
TELEGRAM_NOTIFICATIONS=true
TELEGRAM_BOT_TOKEN=123456:ABC-your-bot-token
TELEGRAM_CHAT_ID=-1001234567890
TELEGRAM_THREAD_ID=42          # optional, for forum/topic groups
```

Notifications are sent on deployment start, success, and failure. Use `/test-notify` to verify your setup.

---

## Development

### Linting / Formatting

This project uses [Mago](https://github.com/carthage-software/mago) for PHP code style:

```sh
composer mago
# or
vendor/bin/mago
```

Configuration is in `mago.toml`.

---

## Project Structure

```
index.php          # Entire application (router, logic, UI)
deploy.json        # Default instruction file (JSON example)
deploy.yml         # Alternative instruction file (YAML example)
.env.example       # Environment variable template
.env               # Your local configuration (not committed)
logs/              # Deployment logs (auto-created)
mago.toml          # Mago code style configuration
composer.json      # Dev dependencies (mago)
yq_linux_amd64     # yq binary for YAML support (Linux x86_64)
```
