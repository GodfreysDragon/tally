# Project Summary: Tally

_Last updated: 2026-10-05_

## What it is

A small Laravel invoicing API whose real purpose is to show a production-grade testing and CI/CD pipeline. The app is deliberately modest: customers, invoices, partial payments, signed webhooks and a daily overdue-reminder command. What the repo demonstrates is everything around it:
- **Testing:** Pest with architecture, unit and feature suites, coverage, mutation testing and type coverage.
- **Static analysis:** PHPStan at max level.
- **CI/CD:** a GitHub Actions matrix across PHP versions and databases, plus a release workflow.
- **Containers:** a multi-stage Docker image that is smoke-tested in CI.

**Stack:**
- **Runtime:** PHP 8.4+, Laravel 13, Sanctum.
- **Quality tooling:** Pest 5 (arch, mutate, type-coverage), Larastan 3 at level max, Pint.
- **Delivery:** GitHub Actions, Docker (FrankenPHP, Alpine), MySQL 8.4, Postgres 17, Dependabot.

## What was built

### Domain (`app/`)
- **`Support/Money`**: an immutable money value object.
  - Amounts are integer minor units (pence, cents); floats are never used.
  - Rounding is half away from zero.
  - `allocate()` splits an amount without losing a penny.
- **`Support/InvoiceCalculator`**: applies per-line discounts, then rounds tax once on the subtotal.
- **`Support/WebhookSigner`**: signs payloads as `t=<timestamp>,v1=<HMAC-SHA256>` and rejects replays outside a tolerance window.
- **`Enums/InvoiceStatus`**: the allowed status changes are draft → sent → paid, with void allowed only before payment.
- **Actions** (one use case per class, each with a single `handle()` method):
  - **`CreateInvoice`**: creates a draft invoice from its lines.
  - **`SendInvoice`**, **`RecordPayment`**, **`VoidInvoice`**: each runs in a transaction and re-reads the invoice with a row lock, so concurrent requests can't double-send or overpay.
  - **`NextInvoiceNumber`**: gap-free, per-user, per-year numbers (`INV-2026-0001`). The counter is incremented under a lock, and a rolled-back send gives its number back.
  - **`QueueInvoiceWebhook`**: queues delivery only after the transaction commits.
- **`Jobs/DeliverWebhook`**: signed POST, 5 attempts, backoff from 10s up to 15m, and a log entry when it gives up.
- **Notifications**: `InvoiceSent` and `InvoiceOverdue`, both queued mail.
- **Command**: `invoices:remind-overdue`, scheduled daily at 09:00.
  - Sends at most one reminder per invoice per interval.
  - Supports `--dry-run`.
- **API**: `/api/tokens`, `/api/customers`, `/api/invoices`, and `/send`, `/void` and `/payments` on an invoice.
  - Policies scope every record to its owner.
  - Customers' webhook secrets are encrypted at rest.
- **Strict mode outside production**: `Model::shouldBeStrict()` makes N+1 queries and silently dropped attributes throw.

### Tests (`tests/`)
- **217 tests, 567 assertions**, about 9 seconds locally.
- **Arch (14 rules)**:
  - Pest's php, security and laravel presets.
  - Strict types and final classes everywhere.
  - No debugging calls, and `env()` only inside config files.
  - The domain code doesn't depend on the HTTP layer.
  - All jobs and notifications are queued.
- **Unit**: plain PHPUnit with no framework. Covers Money, the calculator, the signer, the full status-change matrix and the line-input DTO, with datasets for edge cases.
- **Feature**: every endpoint, plus:
  - authorization from a second user's point of view
  - validation datasets
  - the full invoice lifecycle
  - webhook signatures verified from the raw request body
  - time travel for overdue logic
  - schedule registration
  - notification content
- **Every test file declares `mutates(...)`**, which scopes mutation testing and documents what the file is for.
- **Safety nets in the test harness**:
  - `Http::preventStrayRequests()` fails any HTTP call a test didn't fake.
  - Tests run in random order and fail on warnings and deprecations.
  - `TestCase` refuses to run against a database whose name doesn't contain "test".

### CI/CD (`.github/`)
- **`ci.yml`** runs these jobs in parallel, all feeding a single required check, `CI passed`:
  - code style (Pint)
  - static analysis (PHPStan level max and 100% type coverage)
  - dependency health (`composer validate --strict`, `composer audit`)
  - tests on PHP 8.4 and 8.5 with SQLite, plus MySQL 8.4 and Postgres 17, uploading JUnit reports
  - coverage with PCOV, at least 95%, with a summary table and an HTML report
  - mutation testing, at least 90%
  - Docker: build the production image, smoke-test it, run a Trivy scan, and build the dev image
- **`release.yml`** publishes to GHCR:
  - Images are built for amd64 and arm64.
  - Each image has an SBOM, max-mode provenance and a GitHub build attestation.
  - Tags are `:edge` and `:sha-xxxxxxx` on `main`, and semver tags on `v*` releases.
- **Dependabot** covers Composer, Actions, Docker and Compose, with routine bumps grouped into one PR.
- **PR template.**

### Docker
- **`Dockerfile`** has three main targets:
  - `base`: PHP and extensions, shared by the others.
  - `dev`: adds dev dependencies and PCOV.
  - `production`: no dev dependencies, classmap-authoritative autoloader, OPcache with JIT, runs as non-root on port 8080, has a `HEALTHCHECK`, and is served by FrankenPHP.
- **`docker/entrypoint.sh`** builds the config, route and event caches at startup.
  - It refuses to start without `APP_KEY`.
  - It runs migrations when `RUN_MIGRATIONS=true`.
- **`docker/smoke-test.sh`** checks the running container:
  - it runs as non-root
  - it won't start without a key
  - migrations run inside the image
  - the health check turns healthy
  - the API returns a JSON 401, not a 500
  - no stack traces leak
  - there is no `X-Powered-By` header
- **`compose.yaml`** runs the app, queue worker, scheduler, MySQL and Mailpit.
  - A `test` service runs the suite against a separate `tally_testing` database.

### Things the checks caught during the build
- **A 500 instead of a 401** on unauthenticated requests without `Accept: application/json`. Laravel tried to redirect to a `login` route the API doesn't have. Fixed in `bootstrap/app.php`.
- **11 PHPStan errors at max level**:
  - untyped request data flowing into typed code
  - `Money::allocate()` returning a value PHPStan couldn't prove was a list
  - `env()` calls in skeleton config files that can return a bool
- **Two arch violations**: a command class missing the `Command` suffix, and a file missing `declare(strict_types=1)`.

## Verification status

| Area | Status |
| --- | --- |
| Test suite | ✅ 217 tests, 567 assertions, all passing (PHP 8.4.25, SQLite) |
| Line coverage | ✅ 100% (PCOV loaded from the scratchpad, not installed into Herd) |
| Type coverage | ✅ 100% |
| PHPStan (Larastan, level max) | ✅ 0 errors, no baseline, no ignores |
| Mutation testing | ✅ All 480 reachable mutants killed. Score 92.66% across `app/`; the 38 "uncovered" mutants are in attribute arrays and property defaults |
| Code style | ✅ Pint passes |
| `composer validate --strict`, `composer audit` | ✅ Valid, no advisories |
| Workflow / compose YAML | ✅ All files parse; compose anchors and merges resolve correctly |
| Shell scripts | ✅ `bash -n` / `sh -n` syntax checks pass |
| PHP 8.5, MySQL, Postgres | ⚠️ Not run locally; first run will be in CI |
| Docker image, compose stack, smoke test | ⚠️ Written but never built or run: Docker isn't installed on this machine |
| GitHub Actions (CI and release) | ⚠️ Written but never run; the repo hasn't been pushed |
| Git | ⚠️ Repo initialised on `main` with all files staged; nothing committed |

## To-Dos

- [ ] **Make the first commit** and create the GitHub repo `calmarusin/tally`, then push to `main`. Change the handle in the README badges, `composer.json` and the image name if `calmarusin` isn't right.
- [ ] **Watch the first CI run** and fix whatever only shows up on real runners:
  - [ ] **Action versions:** check every pinned version resolves (`actions/checkout@v5`, `actions/cache@v4`, `actions/upload-artifact@v4`, `docker/*`, `actions/attest-build-provenance@v3`, `aquasecurity/trivy-action@0.33.1`).
  - [ ] **MySQL and Postgres:** confirm both test jobs pass (sequence `insertOrIgnore`, date comparisons, integer casts).
  - [ ] **PHP 8.5:** confirm the job passes.
  - [ ] **Docker:** confirm the FrankenPHP base tag `dunglas/frankenphp:1-php8.4-alpine` exists and the production image builds.
  - [ ] **Smoke test:** confirm it passes on the runner.
  - [ ] **Trivy:** if it fails on an unfixable base-image CVE, decide whether to gate on it or report only.
  - [ ] **Mutation testing:** confirm the score with `mutates()` scoping in parallel on Linux. Raise `--min` above 90 if there's headroom.
- [ ] **Branch protection on `main`:** require the `CI passed` check and up-to-date branches.
- [ ] **Container registry:** after the first release run, make the GHCR package public if the image should be pullable without logging in.
- [ ] **Tag `v0.1.0`** to exercise the semver release path.
- [ ] **Docker locally:** install Docker Desktop and run `docker compose up -d`, then `docker compose run --rm test`, to verify the dev stack and the MySQL test database.

## Next steps (optional improvements)

- **Coverage history:** add Codecov or a coverage badge, so coverage is visible over time and not just per run.
- **Mutation testing on PRs:** run only against changed files, and keep the full run on `main`.
- **OpenAPI:** add a spec (e.g. Scribe) and a contract test that responses match it.
- **Parallel test balancing:** commit Pest's `shards.json` and shard the matrix once the suite grows.
- **Security scanning:**
  - [ ] Upload Trivy results as SARIF to GitHub code scanning.
  - [ ] Add a secrets scan (gitleaks) to CI.
- **Pre-commit hook** (CaptainHook or a simple git hook) running `pint --test` and PHPStan on staged files.
- **Seeder and demo data**, so `docker compose up` shows something useful immediately.
- **Upstream bug report:** report the Windows path bug in `pestphp/pest-plugin-mutate`. `FileFinder` treats absolute `C:\…` source paths as relative; the local workaround is `--path=app`.

## Notes

- **PHP floor is 8.4** because the locked Symfony 8 and Pest 5 packages require it.
- **No coverage driver locally.** Herd's PHP has none, so `composer test:coverage` and `composer test:mutate` need PCOV or Xdebug. For verification, a PECL PCOV build was loaded from the session scratchpad with `-d extension=`, and nothing was installed.
- **Pruned skeleton files:**
  - The frontend scaffold (Vite, npm) was removed; the project is API-only.
  - The skeleton's `CLAUDE.md`/`AGENTS.md` (Laravel Boost bootstrap prompts) were removed, and Boost wasn't installed.
- **Hard-coded dev keys.** `phpunit.xml` and `compose.yaml` contain a fixed, non-secret `APP_KEY` for tests and local dev only. Production gets its key from the environment.
