# SPHPD — Refactor Plan

Migrar de un único `index.php` monolítico a un proyecto multi-archivo con build a single file,
testing con Pest, templates con Latte, y CI/CD con GitHub Actions.

---

## Structure (target)

```
src/
├── Actions/
│   ├── DeployAction.php
│   ├── ArtifactDeployAction.php
│   ├── LogAction.php
│   ├── SelfUpdateAction.php
│   └── ...
├── Core/
│   ├── Config.php
│   ├── Router.php
│   └── Security.php
├── Domain/
│   ├── Deployment/
│   │   ├── Deployment.php
│   │   ├── ArtifactDeployment.php
│   │   └── ProcessRunner.php
│   ├── Instructions/
│   │   └── Instructions.php
│   ├── Notifier/
│   │   └── TelegramNotifier.php
│   └── Logger/
│       └── Logger.php
├── Support/
│   └── SelfUpdater.php
templates/
├── health.latte
├── health1.latte
├── health2.latte
├── logs.latte
├── live-status.latte
└── partials/
    ├── flash.latte
    ├── head-imports.latte
    └── update-banner.latte
tests/
├── Unit/
│   └── Domain/
│       └── Deployment/
│           └── ProcessRunnerTest.php
└── Feature/
bin/
└── app.php
dist/
└── index.php        ← build output (gitignored, published as release asset)
```

---

## Phase 0 — Project foundation

- [ ] Definir `composer.json` con PSR-4 autoload para `src/` y `tests/`
- [ ] Agregar dependencias de producción:
  - `latte/latte` — templating
  - `symfony/yaml` — reemplaza el CLI `yq`
  - `symfony/process` — reemplaza el loop manual de `proc_open`
- [ ] Agregar dependencias de dev:
  - `pestphp/pest`
  - `box-project/box`
- [ ] Crear `box.json` con entry point `bin/app.php` y output `dist/index.php`
- [ ] Crear `bin/app.php` como entry point mínimo
- [ ] Agregar `dist/` a `.gitignore`

---

## Phase 1 — ProcessRunner (crítico, primero)

Reemplazar el loop manual de `fread` + buffer de `runTasks()` con `symfony/process`.
Es la parte más frágil del sistema — el bug de marker partido lo demostró.

- [ ] Crear `src/Domain/Deployment/ProcessRunner.php` sobre `symfony/process`
- [ ] Soportar: stdout/stderr separados, exit codes, timeout configurable, CWD
- [ ] Eliminar `runTasksWithShell()` (dead code confirmado)

---

## Phase 2 — Tests (antes de migrar el resto)

Pest. Prioridad por riesgo:

- [ ] `tests/Unit/Domain/Deployment/ProcessRunnerTest.php`
  - [ ] **Caso crítico**: marker `__STDOUT_EOF__` llega partido en dos chunks — replica el bug de producción
  - [ ] Comando exitoso retorna exit code 0
  - [ ] Comando fallido retorna exit code correcto
  - [ ] Timeout se detecta y retorna exit code 124
  - [ ] stdout y stderr se capturan por separado
- [ ] `tests/Unit/Domain/Instructions/InstructionsTest.php`
  - [ ] YAML parsing nativo (sin `yq`)
  - [ ] JSON parsing
  - [ ] Array de comandos en un task
  - [ ] `job_id` requerido en artifact deploy
- [ ] `tests/Unit/Core/SecurityTest.php`
  - [ ] Token en header case-insensitive (`x-deploy-token` == `X-Deploy-Token`)
  - [ ] Request desde localhost siempre permitida
  - [ ] Token en query string
  - [ ] Token inválido rechazado
- [ ] `tests/Unit/Support/SelfUpdaterTest.php`
  - [ ] Hash local == hash remoto → no update
  - [ ] Hash local != hash remoto → update disponible
  - [ ] Cache se invalida post-update (`has_update: false`)

---

## Phase 3 — Extraer clases de negocio

Mover funciones de `index.php` a clases. Orden sugerido (menor a mayor acoplamiento):

- [ ] `src/Core/Config.php` — `env()`, `$config`
- [ ] `src/Core/Security.php` — `validateSecurity()`, `getRequestHeadersSafe()`
- [ ] `src/Domain/Instructions/Instructions.php` — `validateInstructions()`, `getInstructionsContent()`, reemplazar `convertYmlToJson()` con `symfony/yaml`
- [ ] `src/Domain/Logger/Logger.php` — `createLogHtml()`, `logRequestToFile()`, `updateLiveStatus()`
- [ ] `src/Domain/Notifier/TelegramNotifier.php` — `sendTelegram()`, `buildReport()`
- [ ] `src/Support/SelfUpdater.php` — `updateCurrentScript()`, `resolveSelfUpdateStatus()`
- [ ] `src/Domain/Deployment/Deployment.php` — `executeDeploymentWithSingleShellProccess()`
- [ ] `src/Domain/Deployment/ArtifactDeployment.php` — `executeArtifactDeployment()`, `extractArtifact()`, `checkArtifact()`
- [ ] `src/Core/Router.php` — el mini-router actual
- [ ] `src/Actions/*.php` — una clase por cada `action*()` actual

---

## Phase 4 — Migrar templates a Latte

- [ ] Instalar y configurar Latte en el entry point
- [ ] Migrar `renderHealth2View()` → `templates/health2.latte`
- [ ] Migrar `renderHealth1View()` → `templates/health1.latte`
- [ ] Migrar `renderHealthView()` → `templates/health.latte`
- [ ] Migrar `renderLogsView()` → `templates/logs.latte`
- [ ] Migrar `renderLiveStatus()` → `templates/live-status.latte`
- [ ] Extraer partials:
  - [ ] `renderDashboardFlash()` → `templates/partials/flash.latte`
  - [ ] `renderHeadImports()` → `templates/partials/head-imports.latte`
  - [ ] `renderSelfUpdateBanner()` → `templates/partials/update-banner.latte`
- [ ] Eliminar todas las funciones `render*()` del código PHP

---

## Phase 5 — Build pipeline

- [ ] Validar que `box.json` produce un `dist/index.php` funcional
- [ ] Precompilar templates Latte en el build (cache embebido)
- [ ] Actualizar `SELF_UPDATE_URL` para apuntar al asset del último GitHub Release en lugar del raw de `main`
- [ ] Crear `.github/workflows/release.yml`:
  - Trigger: push a `main` con tag `v*`
  - Steps: `composer install`, `vendor/bin/pest`, `vendor/bin/box compile`, publicar `dist/index.php` como release asset

---

## Phase 6 — CI en PRs

- [ ] Crear `.github/workflows/ci.yml`:
  - Trigger: pull request a `main`
  - Steps: `composer install`, `vendor/bin/pest`
- [ ] Agregar badge de CI al README

---

## Notes

- `runTasksWithShell()` (línea 1983) es dead code — eliminar sin reemplazar.
- `renderValidationError()` no usa lógica — migrar a Latte es trivial.
- El build con Box incluye `vendor/` completo — las extensiones PHP nativas (curl, ZipArchive) siguen siendo requisito del servidor, eso no cambia.
- `SELF_UPDATE_URL` actual apunta a `main` sin versión — cambiar en Phase 5 para evitar que usuarios en versiones viejas descarguen código incompatible.
