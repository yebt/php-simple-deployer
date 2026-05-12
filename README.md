![banner](./assets/banner.jpg)

# SPHPD — Simple PHP Deployer

[![CI](https://github.com/yebt/php-simple-deployer/actions/workflows/ci.yml/badge.svg)](https://github.com/yebt/php-simple-deployer/actions/workflows/ci.yml)

A minimalist PHP deployment tool with a live dashboard, webhook support, Telegram notifications, and JSON/YAML instruction files.

The application compiles to a **single `index.php` file** — copy it to your server, configure `.env`, done.

> Requires **PHP 7.4+** on the server. No Composer needed after deploying.

---

## Quick Start

### 1. Download the latest release

```sh
curl -L https://github.com/yebt/php-simple-deployer/releases/latest/download/index.php -o index.php
```

### 2. Configure environment

```sh
cp .env.example .env
```

Minimum required variables:

```env
PROJECT_PATH=/path/to/your/project
SECURITY_TOKEN=your-secret-token
INSTRUCTIONS_FILE=deploy.json
```

### 3. Create an instruction file

**`deploy.json`:**

```json
[
  { "name": "Git Pull",              "run": "git pull origin main" },
  { "name": "Install Dependencies",  "run": "composer install --no-dev" },
  { "name": "Clear Cache",           "run": "php artisan config:cache" }
]
```

**`deploy.yml`** (YAML also supported natively — no extra tools needed):

```yaml
- name: "Git Pull"
  run: "git pull origin main"

- name: "Build"
  run:
    - "npm ci"
    - "npm run build"
```

### 4. Serve with Apache

Add a `.htaccess` in the same directory as `index.php`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

Open your browser at `/health` to verify the setup.

---

## Configuration

All configuration is via `.env`:

| Variable                     | Default                                                                      | Description                                                         |
|------------------------------|------------------------------------------------------------------------------|---------------------------------------------------------------------|
| `PROJECT_PATH`               | _(required)_                                                                 | Absolute path where deployment commands run                         |
| `INSTRUCTIONS_FILE`          | `deploy.json`                                                                | Path to your instruction file (JSON or YAML)                        |
| `LOGS_PATH`                  | `logs/`                                                                      | Directory where deployment logs are stored (auto-created)           |
| `SECURITY_TOKEN`             |                                                                              | Secret token required for webhook requests                          |
| `WEBHOOK_METHOD`             | `POST`                                                                       | HTTP method for the webhook (`GET` or `POST`)                       |
| `LOAD_USER`                  |                                                                              | HTTP Basic Auth username (optional)                                 |
| `LOAD_PASSWORD`              |                                                                              | HTTP Basic Auth password (optional)                                 |
| `TELEGRAM_NOTIFICATIONS`     | `true`                                                                       | Enable/disable Telegram notifications                               |
| `TELEGRAM_BOT_TOKEN`         |                                                                              | Telegram bot token                                                  |
| `TELEGRAM_CHAT_ID`           |                                                                              | Telegram chat or group ID                                           |
| `TELEGRAM_THREAD_ID`         |                                                                              | Thread/topic ID (optional, for supergroups)                         |
| `GITLAB_TOKEN`               |                                                                              | GitLab private token to download CI artifacts                       |
| `GITLAB_BASE_URL`            | `https://gitlab.com`                                                         | GitLab instance base URL                                            |
| `ARTIFACT_DEPLOY_DIR`        | `artifact-deploy/`                                                           | Directory for downloaded and extracted artifacts                    |
| `ARTIFACT_INSTRUCTIONS_FILE` | `artifact-deploy.json`                                                       | Instruction file executed after artifact extraction                 |
| `DASHBOARD_ROUTE`            | `health`                                                                     | Default dashboard (`health`, `health1`, `health2`)                  |
| `SELF_UPDATE_URL`            | `https://github.com/yebt/php-simple-deployer/releases/latest/download/index.php` | URL used by the self-update feature                            |

---

## Endpoints

### Dashboard & Status

| Method | Path            | Description                                             |
|--------|-----------------|---------------------------------------------------------|
| `GET`  | `/`             | Redirects to the default dashboard (`DASHBOARD_ROUTE`)  |
| `GET`  | `/health`       | Classic dashboard — full config details, sidebar layout |
| `GET`  | `/health1`      | Alternative classic layout                              |
| `GET`  | `/health2`      | Modern dashboard — minimal, light/dark theme            |
| `GET`  | `/status/live`  | Real-time live status page                              |
| `GET`  | `/status/check` | JSON: `{ "finished": true/false }`                      |
| `GET`  | `/status/data`  | JSON: full current deployment status                    |

### Deploy

| Method       | Path                            | Description                                    |
|--------------|---------------------------------|------------------------------------------------|
| `GET`/`POST` | `/webhook/deploy`               | Trigger deployment (waits for completion)      |
| `POST`       | `/webhook/deploy/nowait`        | Trigger deployment — returns `202` immediately |
| `POST`       | `/deploy/stop`                  | Stop the currently running deployment          |

### Artifact Deploy

| Method | Path                              | Description                                              |
|--------|-----------------------------------|----------------------------------------------------------|
| `POST` | `/webhook/artifact-deploy`        | Download artifact, extract, and run instructions (sync)  |
| `POST` | `/webhook/artifact-deploy/nowait` | Same, returns `202 Accepted` immediately                 |

### Logs & Utilities

| Method | Path                     | Description                                                   |
|--------|--------------------------|---------------------------------------------------------------|
| `GET`  | `/alllogs`               | Browse all logs (`?type=log\|rlog\|html\|fraw`)               |
| `GET`  | `/log/view?file=<name>`  | View a specific log file                                      |
| `GET`  | `/log/last`              | Most recent formatted log                                     |
| `GET`  | `/log/lasthtml`          | Most recent HTML log                                          |
| `GET`  | `/log/lastfraw`          | Most recent full raw stream log                               |
| `GET`  | `/test-notify`           | Send a test Telegram notification                             |
| `GET`  | `/clear-history`         | Clear all deployment log history                              |
| `GET`  | `/script/update`         | Download the latest release and back up the current file      |

### Webhook Security

When `SECURITY_TOKEN` is set, all webhook requests must include it via:

- **Header:** `X-Deploy-Token: <token>` (case-insensitive)
- **Query string:** `?token=<token>`

Requests from `localhost` / `127.0.0.1` are always allowed (for UI-triggered deployments).

---

## Instruction File Format

Each task has:

| Field  | Type            | Description                          |
|--------|-----------------|--------------------------------------|
| `name` | string          | Label shown in the dashboard         |
| `run`  | string or array | Shell command(s) to execute in order |

Commands within a task share the same shell session — environment variables set in one command carry over to the next.

---

## Artifact Deploy

Lets a GitLab CI pipeline trigger a deployment without requiring Git access on the server.

### Flow

```
GitLab CI pipeline
       │
       │  POST /webhook/artifact-deploy
       │  { "project_id": "123", "job_id": "9876" }
       ▼
SPHPD
   1. Validates X-Deploy-Token
   2. Downloads artifact ZIP from GitLab API
   3. Extracts to artifact-deploy/extracted/
   4. Runs tasks from artifact-deploy.json
   5. Sends Telegram notification
```

### GitLab CI example

```yaml
notify-deploy:
  stage: deploy
  script:
    - |
      curl -X POST https://your-server/webhook/artifact-deploy \
        -H "X-Deploy-Token: $SECURITY_TOKEN" \
        -H "Content-Type: application/json" \
        -d '{
          "project_id": "'$CI_PROJECT_ID'",
          "job_id":     "'$CI_JOB_ID'",
          "branch":     "'$CI_COMMIT_REF_NAME'",
          "job":        "build"
        }'
  only:
    - main
```

### Request body

| Field        | Type   | Required | Description                              |
|--------------|--------|----------|------------------------------------------|
| `project_id` | string | yes      | GitLab project ID (`$CI_PROJECT_ID`)     |
| `job_id`     | string | yes      | GitLab job ID (`$CI_JOB_ID`)             |
| `branch`     | string | no       | Branch name (informational)              |
| `job`        | string | no       | CI job name (informational)              |

---

## Log Formats

Each deployment writes four files to `LOGS_PATH`:

| Extension    | Written      | Description                                              |
|--------------|--------------|----------------------------------------------------------|
| `.log`       | After finish | Human-readable summary with task separators              |
| `.log.rlog`  | After finish | Timestamped entries (`[info  ]` / `[error ]`)            |
| `.log.html`  | After finish | Styled HTML log, viewable in a browser                   |
| `.log.fraw`  | **Per line** | Full raw stream — useful for `tail -f` live monitoring   |

---

## Telegram Notifications

```env
TELEGRAM_NOTIFICATIONS=true
TELEGRAM_BOT_TOKEN=123456:ABC-your-bot-token
TELEGRAM_CHAT_ID=-1001234567890
TELEGRAM_THREAD_ID=42   # optional, for forum/topic groups
```

Use `/test-notify` to verify your setup before the first real deployment.

---

## Self-Update

From the dashboard, click **Check for updates**. If a newer release is available, SPHPD will:

1. Download the latest `index.php` from GitHub Releases
2. Back up the current file as `backups/index.php.bak.<timestamp>`
3. Replace the running file

---

## Development

### Requirements

- PHP 8.x (host)
- Composer
- Podman (or Docker)

### Run locally

```sh
composer install
composer start
# Open http://localhost:19090
```

The `.env` file is loaded automatically via `vlucas/phpdotenv`.

### Run tests

```sh
# Inside the container (PHP 7.4)
podman exec -it gmn_app_1 bash -c "cd /var/www/html && php vendor/bin/pest"

# On host (PHP 8.x)
php vendor/bin/pest
```

### Build the PHAR

```sh
podman exec -it gmn_app_1 bash -c "cd /var/www/html && php vendor/bin/box compile"
# Output: dist/index.php
```

### Project structure

```
bin/app.php                  Entry point (DI wiring + routing)
src/
├── Actions/                 HTTP action handlers (one class per route group)
├── Core/                    Config, Router, Security, ViewRenderer
├── Domain/
│   ├── Deployment/          ProcessRunner, Deployment, ArtifactDeployment
│   ├── Instructions/        JSON/YAML instruction loader + validator
│   ├── Logger/              Log writing and live status
│   └── Notifier/            Telegram notifications
└── Support/                 SelfUpdater
templates/                   Latte templates
tests/Unit/                  Pest test suite
dist/index.php               Compiled PHAR output (gitignored)
.github/workflows/
├── ci.yml                   Tests on every PR
└── release.yml              Build + publish on version tags
```
