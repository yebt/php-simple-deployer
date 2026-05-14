![banner](./assets/banner.jpg)

# SPHPD — Simple PHP Deployer

[![PHP](https://img.shields.io/badge/PHP-8.5%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![Single File](https://img.shields.io/badge/single--file-index.php-blue)](#)
[![License](https://img.shields.io/github/license/yebt/php-simple-deployer)](./LICENSE)
[![Release](https://img.shields.io/github/v/release/yebt/php-simple-deployer?include_prereleases)](https://github.com/yebt/php-simple-deployer/releases/latest)
[![Self-update](https://img.shields.io/badge/self--update-supported-brightgreen)](#self-update)

A minimalist, single-file PHP deployment tool with a live dashboard, webhook support, Telegram notifications, conditional task execution, and JSON/YAML instruction files.

> **Requires PHP 8.5+** — no Composer, no dependencies.

---

## Features

- **Single file** — the entire application lives in `index.php`
- **JSON or YAML** instruction files
- **Conditional tasks** — `when:` field evaluates a bash expression; skipped tasks appear in the live view
- **Live status dashboard** — two views: classic (`/status/live`) and GitHub Actions-style (`/status/live2`) with sidebar, output panel, and skip toggle
- **Webhook endpoint** — trigger deployments via HTTP (with optional security token)
- **Artifact deploy** — download a GitLab CI artifact, extract it, and run conditional instructions on it
- **Webhook context** — query params and JSON body are exposed as `DEPLOY_*` env vars to `when` conditions and task commands
- **Telegram notifications** — receive deployment results in a chat or thread
- **Self-update** — download the latest `index.php` from GitHub releases and keep a timestamped backup
- **Update notice banner** — all dashboard variants show a notice only when a newer upstream script is available
- **Multiple log formats** — plain text (`.log`), timestamped raw (`.rlog`), HTML (`.html`), and full raw stream (`.fraw`)
- **Logs browser** — paginated, tab-filtered view of all logs at `/alllogs`
- **Real-time `.fraw` log** — written line-by-line during execution; `tail -f` it for live stream debugging
- **HTTP Basic Auth** — optionally restrict access to the UI
- **Stop mid-run** — abort a running deployment from the dashboard
- **No-wait mode** — fire-and-forget webhook that returns `202 Accepted` immediately
- **Auto-download `yq`** — if `yq` is missing, SPHPD downloads the correct binary for your OS/arch automatically
- **Dark mode** UI built with Tailwind CSS

---

## Quick Start

### 1. Download

```sh
curl -Lo index.php https://github.com/yebt/php-simple-deployer/releases/latest/download/index.php
```

### 2. Configure environment

```sh
cp .env.example .env
```

Edit `.env` (see [Configuration](#configuration)).

### 3. Create an instruction file

**`deploy.json`:**

```json
[
  { "name": "Git Pull", "run": "git pull origin main" },
  { "name": "Install Dependencies", "run": "composer install --no-dev" },
  { "name": "Optimize Cache", "run": "php artisan config:cache" }
]
```

Or **`deploy.yml`** (requires `yq` — auto-downloaded if missing):

```yaml
- name: Git Pull
  run: git pull origin main

- name: Install Dependencies
  run:
    - composer install --no-dev
    - php artisan config:cache
```

### 4. Start the built-in server (development)

```sh
php -S localhost:5173 index.php
```

Open `http://localhost:5173` to access the dashboard.

---

## Configuration

All configuration is done via `.env`:

| Variable                     | Default                | Description                                                          |
| ---------------------------- | ---------------------- | -------------------------------------------------------------------- |
| `PROJECT_PATH`               | _(required)_           | Absolute path where deployment commands run                          |
| `INSTRUCTIONS_FILE`          | `deploy.json`          | Path to your instruction file (JSON or YAML)                         |
| `LOGS_PATH`                  | `./logs`               | Directory where deployment logs are stored                           |
| `SECURITY_TOKEN`             |                        | Secret token required for webhook requests (header or query)         |
| `WEBHOOK_METHOD`             | `POST`                 | HTTP method accepted by the webhook (`GET` or `POST`)                |
| `LOAD_USER`                  |                        | HTTP Basic Auth username (optional)                                  |
| `LOAD_PASS`                  |                        | HTTP Basic Auth password (optional)                                  |
| `TELEGRAM_NOTIFICATIONS`     | `true`                 | Enable/disable Telegram notifications                                |
| `TELEGRAM_BOT_TOKEN`         |                        | Telegram bot token                                                   |
| `TELEGRAM_CHAT_ID`           |                        | Telegram chat or group ID                                            |
| `TELEGRAM_THREAD_ID`         |                        | Telegram thread/topic ID (optional, for supergroups)                 |
| `YQ_PATH`                    |                        | Path to the `yq` binary (auto-downloaded if missing)                 |
| `MODE`                       | `production`           | Set to any non-`production` value to enable the `/debugdeploy` route |
| `GITLAB_TOKEN`               |                        | GitLab private token to download CI artifacts                        |
| `GITLAB_BASE_URL`            | `https://gitlab.com`   | GitLab instance base URL (change for self-hosted)                    |
| `ARTIFACT_DEPLOY_DIR`        | `./artifact-deploy`    | Directory where artifacts are downloaded and extracted               |
| `ARTIFACT_INSTRUCTIONS_FILE` | `artifact-deploy.json` | Instruction file executed after artifact extraction                  |
| `DASHBOARD_ROUTE`            | `health`               | Default dashboard route (`health`, `health1`, `health2`)             |

---

## Instruction File Format

Each instruction file is an **array of tasks**. A task has:

| Field  | Type            | Required | Description                                              |
| ------ | --------------- | -------- | -------------------------------------------------------- |
| `name` | string          | yes      | Human-readable label shown in the UI and live view       |
| `run`  | string or array | yes      | Shell command(s) to execute in order                     |
| `when` | string          | no       | Bash expression — task is skipped if it exits non-zero   |

Commands within a task share the same shell session, so `export`ed variables carry over between commands in the same task.

### Basic examples

**Single command:**

```json
{ "name": "Pull latest code", "run": "git pull origin main" }
```

**Multiple commands (array):**

```json
{
  "name": "Build",
  "run": ["export NODE_ENV=production", "npm ci", "npm run build"]
}
```

**YAML block scalar:**

```yaml
- name: Deploy
  run: |
    git pull origin main
    composer install --no-dev
    php artisan migrate --force
```

---

## Conditional Tasks (`when`)

The `when` field is evaluated as a **bash expression**. If it exits with a non-zero code, the task is skipped — no error, just a `SKIP` badge in the live view.

Webhook context (query params + JSON body) is available as `DEPLOY_*` environment variables inside `when` and `run` commands.

### deploy.yml — branch-aware deploy

```yaml
- name: Git Pull
  run: git pull origin main

- name: Run migrations
  when: '[ "$DEPLOY_ENV" = "production" ]'
  run: php artisan migrate --force

- name: Seed test data
  when: '[ "$DEPLOY_ENV" = "staging" ]'
  run: php artisan db:seed --class=TestSeeder

- name: Clear cache
  run: php artisan config:cache
```

Trigger with context:

```sh
curl -X POST https://your-server/webhook/deploy \
  -H "X-Deploy-Token: $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"env": "production"}'
```

The body key `env` becomes `$DEPLOY_ENV` in every task.

### deploy.json — multi-environment

```json
[
  {
    "name": "Git Pull",
    "run": "git pull origin main"
  },
  {
    "name": "Install (production)",
    "when": "[ \"$DEPLOY_ENV\" = 'production' ]",
    "run": "composer install --no-dev --optimize-autoloader"
  },
  {
    "name": "Install (dev)",
    "when": "[ \"$DEPLOY_ENV\" != 'production' ]",
    "run": "composer install"
  },
  {
    "name": "Migrate",
    "when": "[ \"$DEPLOY_MIGRATE\" = '1' ]",
    "run": "php artisan migrate --force"
  },
  {
    "name": "Reload service",
    "run": "systemctl reload php-fpm"
  }
]
```

### artifact-deploy.json — frontend vs backend

When the same webhook is shared between a frontend and a backend pipeline, use `when` to run only the relevant tasks:

```json
[
  {
    "name": "Show extracted files",
    "run": "ls -la"
  },
  {
    "name": "Frontend: Install dependencies",
    "when": "[ \"$DEPLOY_TYPE\" = 'frontend' ]",
    "run": "npm ci --prefer-offline"
  },
  {
    "name": "Frontend: Build",
    "when": "[ \"$DEPLOY_TYPE\" = 'frontend' ]",
    "run": "npm run build"
  },
  {
    "name": "Frontend: Copy to web root",
    "when": "[ \"$DEPLOY_TYPE\" = 'frontend' ]",
    "run": "rsync -av --delete dist/ /var/www/html/"
  },
  {
    "name": "Backend: Install dependencies",
    "when": "[ \"$DEPLOY_TYPE\" = 'backend' ]",
    "run": "composer install --no-dev"
  },
  {
    "name": "Backend: Run migrations",
    "when": "[ \"$DEPLOY_TYPE\" = 'backend' ]",
    "run": "php artisan migrate --force"
  },
  {
    "name": "Backend: Restart workers",
    "when": "[ \"$DEPLOY_TYPE\" = 'backend' ]",
    "run": "php artisan queue:restart"
  },
  {
    "name": "Verify deploy",
    "run": "echo 'Deploy finished for type: $DEPLOY_TYPE'"
  }
]
```

GitLab CI sends the type in the body:

```yaml
# .gitlab-ci.yml (frontend pipeline)
notify-deploy:
  stage: deploy
  script:
    - |
      curl -X POST https://your-server/webhook/artifact-deploy/nowait \
        -H "X-Deploy-Token: $SECURITY_TOKEN" \
        -H "Content-Type: application/json" \
        -d '{
          "project_id": "'$CI_PROJECT_ID'",
          "branch":     "'$CI_COMMIT_REF_NAME'",
          "job":        "build",
          "job_id":     "'$CI_JOB_ID'",
          "type":       "frontend"
        }'
```

---

## Dashboard Routes

| Route          | Description                                                                 |
| -------------- | --------------------------------------------------------------------------- |
| `/health`      | Classic dashboard — full config details, sidebar layout                     |
| `/health1`     | Alias for `/health`                                                         |
| `/health2`     | Modern dashboard — minimal design, compact config checks, light/dark theme  |
| `/status/live` | Classic live view — real-time task progress with expandable output          |
| `/status/live2`| GitHub Actions-style live view — sidebar task list, output panel, skip toggle |

Set the default dashboard via `.env`:

```env
DASHBOARD_ROUTE=health2
```

---

## Endpoints

### Standard Deploy

| Method     | Path                        | Description                                                      |
| ---------- | --------------------------- | ---------------------------------------------------------------- |
| `GET`      | `/`                         | Redirects to dashboard (see `DASHBOARD_ROUTE`)                   |
| `GET`      | `/health`                   | Classic dashboard                                                |
| `GET`      | `/health2`                  | Modern dashboard                                                 |
| `GET/POST` | `/webhook/deploy`           | Trigger deployment (waits for completion)                        |
| `POST`     | `/webhook/deploy/nowait`    | Trigger deployment — returns `202` immediately                   |
| `GET`      | `/status/live`              | Classic real-time live status page                               |
| `GET`      | `/status/live2`             | GitHub Actions-style live status page                            |
| `GET`      | `/status/check`             | JSON: `{ "finished": true/false }`                               |
| `GET`      | `/status/data`              | JSON: full current deployment status                             |
| `GET`      | `/alllogs`                  | Browse all logs                                                  |
| `GET`      | `/log/rview/<id>`           | Formatted log by ID                                              |
| `POST`     | `/deploy/stop`              | Stop the currently running deployment                            |
| `GET`      | `/test-notify`              | Send a test Telegram notification                                |
| `GET`      | `/clear-history`            | Clear all deployment log history                                 |
| `GET`      | `/script/update?manual=1`   | Self-update `index.php` from latest GitHub release               |

### Artifact Deploy

| Method | Path                               | Description                                              |
| ------ | ---------------------------------- | -------------------------------------------------------- |
| `POST` | `/webhook/artifact-deploy`         | Download artifact, extract, and run instructions (sync)  |
| `POST` | `/webhook/artifact-deploy/nowait`  | Same as above — returns `202 Accepted` immediately       |

### Webhook Security

When `SECURITY_TOKEN` is set, requests must include it via:

- **Header:** `X-Deploy-Token: <token>`
- **Query string:** `?token=<token>`

Requests from `localhost` / `127.0.0.1` bypass the token check (for UI-triggered deployments).

---

## Artifact Deploy

This feature lets a GitLab CI pipeline notify the deployer, which then downloads the pipeline artifact, extracts it, and runs a set of instructions — all without requiring the server to have Git access.

### How it works

```
GitLab CI pipeline
       │
       │  POST /webhook/artifact-deploy
       │  { "project_id": "123", "job_id": "456", "type": "frontend" }
       ▼
SPHPD (index.php)
   1. Validates X-Deploy-Token
   2. Locks (rejects concurrent requests)
   3. Downloads artifact ZIP from GitLab API using GITLAB_TOKEN
   4. Extracts to artifact-deploy/extracted/
   5. Resolves body + query params as DEPLOY_* env vars
   6. Runs tasks from artifact-deploy.json (CWD = extracted dir)
      — tasks with 'when' are skipped if condition is not met
   7. Sends Telegram notification with result
```

### Setup

```env
GITLAB_TOKEN=your_gitlab_private_token
GITLAB_BASE_URL=https://gitlab.com
ARTIFACT_DEPLOY_DIR=./artifact-deploy
ARTIFACT_INSTRUCTIONS_FILE=artifact-deploy.json
```

See the [frontend vs backend example](#artifact-deployjson--frontend-vs-backend) above for a full `artifact-deploy.json` with conditional tasks.

### Request body

| Field        | Type   | Required | Description                                      |
| ------------ | ------ | -------- | ------------------------------------------------ |
| `project_id` | string | yes      | GitLab project ID (`$CI_PROJECT_ID`)             |
| `job_id`     | string | yes      | GitLab job ID that produced the artifact         |
| `branch`     | string | no       | Branch name (informational)                      |
| `job`        | string | no       | CI job name (informational)                      |
| _(any key)_  | scalar | no       | Exposed as `DEPLOY_<KEY>` to `when` and commands |

### Artifact format support

| Format | Method                                       |
| ------ | -------------------------------------------- |
| `.zip` | PHP `ZipArchive` (built-in, no dependencies) |
| `.rar` | `unrar`, falls back to `7z`                  |
| other  | `7z` (supports tar, gz, xz, etc.)            |

---

## YAML Support

To use a `.yml` / `.yaml` instruction file, the [`yq`](https://github.com/mikefarah/yq) binary is required. SPHPD **auto-downloads** the correct binary for your OS and architecture if `YQ_PATH` points to a non-existent file.

```env
INSTRUCTIONS_FILE=deploy.yml
YQ_PATH=./yq
```

To download manually:

```sh
# Linux amd64
curl -Lo yq https://github.com/mikefarah/yq/releases/latest/download/yq_linux_amd64
chmod +x yq
```

---

## Self-Update

```sh
# Via CLI
php index.php self-update

# Via dashboard UI
GET /script/update?manual=1
```

Downloads from the latest GitHub release and stores the previous file as `./backups/index.php.bak.<timestamp>`.

---

## Log Formats

| Extension    | Written      | Description                                                         |
| ------------ | ------------ | ------------------------------------------------------------------- |
| `.log`       | After finish | Human-readable summary with task separators and exit codes          |
| `.log.rlog`  | After finish | Timestamped entries prefixed with `[info]` / `[error]`              |
| `.log.html`  | After finish | Styled HTML log, viewable in a browser                              |
| `.log.fraw`  | **Per line** | Full raw stream — every line as received, suitable for `tail -f`    |

```sh
tail -f logs/deploy_20240101_120000.log.fraw
```

---

## Telegram Notifications

```env
TELEGRAM_NOTIFICATIONS=true
TELEGRAM_BOT_TOKEN=123456:ABC-your-bot-token
TELEGRAM_CHAT_ID=-1001234567890
TELEGRAM_THREAD_ID=42
```

Use `/test-notify` to verify your setup.

---

## Project Structure

```
index.php               # Entire application (router, logic, UI)
deploy.json             # Default instruction file for standard deploys
deploy.yml              # YAML instruction file example
artifact-deploy.json    # Instruction file for artifact deploys
fixtures/               # Local ZIP fixtures for artifact testing
.env.example            # Environment variable template
.env                    # Your local configuration (not committed)
logs/                   # Deployment logs (auto-created)
artifact-deploy/        # Artifact download & extraction directory (auto-created)
mago.toml               # Mago code style configuration
composer.json           # Dev dependencies (mago, local server)
```
