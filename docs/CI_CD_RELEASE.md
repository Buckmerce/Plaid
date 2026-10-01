# CI/CD and Release Engineering

## 1. Required CI jobs

Recommended separate jobs:

```text
composer-validate
php-syntax
phpcs
phpstan
phpunit
frontend-build
frontend-lint
integration
browser-e2e
plugin-check
package
package-smoke
```

## 2. Runtime matrix

Test supported PHP versions and representative/current WordPress/WooCommerce versions.

Do not claim compatibility that CI/manual QA does not exercise.

## 3. Secrets in CI

Real Plaid Sandbox jobs use protected CI secrets and should be isolated from untrusted pull requests/forks.

Never echo secrets.

Mock/contract test jobs remain runnable without external credentials.

## 4. Build reproducibility

Release build should start from clean source:

```text
composer install --no-dev --optimize-autoloader
npm ci
npm run build
package
```

Exact commands may differ, but must be deterministic and documented. PayBridge builds are
reproducible: the same commit always produces a byte-identical ZIP (§9, checked by the
`source-package-parity` job).

## 5. Package requirements

Expected artifact:

```text
dist/paybridge-for-plaid-{version}.zip
```

ZIP root:

```text
paybridge-for-plaid/
```

Exclude:

- `.git`;
- local `.env`;
- IDE files;
- tests/coverage;
- node_modules;
- development caches;
- temporary artifacts;
- unnecessary internal docs if release policy excludes them.

Include all runtime dependencies and compiled assets.

## 6. Package smoke test

CI must extract/install the generated ZIP into a fresh WordPress environment and verify:

- plugin activation;
- WooCommerce dependency behavior;
- no fatal error;
- gateway registration;
- expected runtime files/assets.

Source-tree tests alone are insufficient.

## 7. Source/package parity

Critical source classes/assets expected at runtime must be checked against package contents to catch stale `.distignore` or build mistakes.

## 8. Release gate

No tag/release when:

- required CI job fails;
- package smoke fails;
- high/critical security issue exists;
- real-Sandbox gate required for the release has not been executed;
- docs/changelog/version metadata disagree.

## 9. Implementation (1.0)

### `.github/workflows/quality.yml` (push/PR to `master`, manual dispatch, and `workflow_call` from release.yml)

| Job | Content |
|---|---|
| `composer-validation` | Composer **2.10.3** (pinned: `vendor-prefixed/composer/InstalledVersions.php` is copied from Composer's own runtime, so the committed tree is reproducible only with one Composer version), `composer validate --strict`, install (Strauss regenerates `vendor-prefixed/`), no diff and no untracked file in `vendor-prefixed/` except the root commit hash in `installed.php` |
| `docs-integrity` | `scripts/check-docs.sh` (mandatory docs and every ADR tracked, relative links resolve) and `scripts/audit-source.sh` (no `.env`, traces, IDE state, caches or credentials in the source archive; `.env.example` placeholders only) |
| `php-syntax` | Lint every project PHP file on PHP 8.1 and 8.4 |
| `scripts-lint` | `shellcheck -x -P SCRIPTDIR -S warning` on `scripts/` |
| `phpcs`, `phpstan` | `composer lint`, `composer stan` (level 6) |
| `phpunit` | PHP 8.1, 8.2, 8.3, 8.4 (blocking); PHP 8.5 early-warning (non-blocking, not claimed) |
| `dependency-audit` | `composer audit --locked --no-dev`, `composer audit --locked`, `npm audit --audit-level=high` |
| `dependency-review` | GitHub dependency review on pull requests (fails on high) |
| `frontend-build`, `typecheck`, `javascript-lint` | `npm run build`, `npm run typecheck`, `npm test` (build + typecheck + bundle syntax + JS tests) |
| `package` | Builds the ZIP **once** (`npm run plugin-zip`), runs `scripts/verify-package.sh` (required files, every `src/**/*.php`, fresh-asset byte parity, no dev files/source maps/local paths/secrets, PHP lint), records `<zip>.sha256`, publishes `zip_name`/`zip_sha256` outputs, uploads ZIP + hash |
| `package-smoke` | `scripts/test-package-smoke.sh` on pristine WordPress + WooCommerce with only the ZIP — a fresh HPOS store and a fresh legacy-storage store per row: minimum (WP 6.6, WC 8.7.0, PHP 8.1) and latest (WP 7.1.2, WC 11.1.2, PHP 8.4), MySQL 8.4 |
| `source-package-parity` | The downloaded artifact still matches its hash, passes `verify-package.sh`, and a fresh rebuild of the commit is **byte-identical** (`cmp`) |
| `integration-mysql` | `scripts/test-integration.sh` on the ZIP: WP 6.6/WC 8.7.0/PHP 8.1/MySQL 8.0 (minimum), WP 6.8/WC 9.9.5/PHP 8.3/MySQL 8.0 (intermediate), WP 7.1.2/WC 11.1.2/PHP 8.2 and 8.4/MySQL 8.4 (latest) — every suite with HPOS off and on |
| `integration-mariadb` | Same suites on MariaDB 10.11 (WP 6.6/WC 8.7.0/PHP 8.1) and MariaDB 11.4 (WP 7.1.2/WC 11.1.2/PHP 8.3): schema and migration, reservations, advisory locks, event leases, account scopes, reconciliation, refunds, uninstall |
| `plugin-check` | WordPress Plugin Check on the ZIP |
| `browser-e2e` | `scripts/test-browser-e2e.sh`: Classic Checkout gate, Checkout Blocks gate, admin refunds, return UX incl. blocked re-debit, accessibility (axe-core WCAG 2.2 AA, keyboard, focus). Core dumps enabled with `gdb` installed: a crashed PHP server is reported with its backtrace, the server log and `debug.log` tails and the failing phase. The web server runs with OPcache's JIT off (`tests/fixtures/php-server/`): setup-php's default tracing JIT crashes PHP 8.2's built-in server workers (reproduced; the cause of the former `ERR_EMPTY_RESPONSE`) |
| `sandbox` | Real Plaid Sandbox gate with repository secrets; never for forks; exit 78 (a missing input) is a notice on push/PR and a **failure** when called by a release (`require_sandbox: true`). The job publishes `executed` (`true` only when the gate really ran against Plaid and passed) and writes "Real Plaid Sandbox: EXECUTED / NOT EXECUTED" to the run summary, so a green job that skipped the gate is never mistaken for a pass. Its web server uses the same PHP settings as the browser suite (JIT off); a failure prints the server state, server log and `debug.log` |
| `quality-gate` | Fails unless every job succeeded (`dependency-review` and `sandbox` may be skipped outside releases); for a release it additionally requires `sandbox.executed == true` |

### `.github/workflows/release.yml` (tags `vX.Y.Z` or `vX.Y.Z-rc.N`)

1. `verify-tag`: the tag commit is in `master`; tag = plugin header = `PAYBRIDGE_PLAID_VERSION`
   = readme Stable tag = `package.json`; the `readme.txt` changelog has a `= X.Y.Z =` section and
   `CHANGELOG.md` has a `## [X.Y.Z]` section (both tracked).
2. `quality`: calls `quality.yml` with `require_sandbox: true` and inherited secrets — the full
   pipeline above, including the real Plaid Sandbox gate.
3. `release`: downloads the artifact of THIS run, checks its name and that its SHA-256 equals the
   hash recorded by the `package` job, then publishes exactly that ZIP and its `.sha256`
   (`--prerelease` for `-rc.N`). Nothing is rebuilt after testing.

A tag therefore cannot publish a ZIP that did not pass the complete quality pipeline.

### Build and package

```bash
composer install          # dev tools; Strauss regenerates vendor-prefixed/
npm ci
npm run plugin-zip        # npm run build + scripts/package.sh
bash scripts/verify-package.sh
bash scripts/test-package-smoke.sh
```

`scripts/package.sh` refuses to build when the plugin header, `PAYBRIDGE_PLAID_VERSION`, the
readme Stable tag and `package.json` disagree, or when compiled assets or `vendor-prefixed/`
are missing. It stages only `paybridge-for-plaid.php`, `readme.txt`, `uninstall.php`,
`LICENSE`, `src/`, `assets/`, `languages/` and `vendor-prefixed/`. `vendor/` (dev tools) is
never shipped.

The ZIP is reproducible: permissions are normalized (0755/0644), every entry gets the
`SOURCE_DATE_EPOCH` timestamp (default: the HEAD commit time), entries are sorted
(`LC_ALL=C`), extra attributes are dropped (`zip -X`) and `zip` runs in UTC. The same commit
therefore always produces the same SHA-256; `PBFP_DIST_DIR=<dir>` writes a rebuild elsewhere
for comparison.

### Plugin Check notes

Plugin Check 2.1 reports no errors. One warning is accepted: `trademarked_term`, because the
product name fixed by the specification, "PayBridge for Plaid — WooCommerce Pay by Bank",
contains "WooCommerce" outside the allowed "for WooCommerce" pattern (only relevant for a
WordPress.org listing). Justified `phpcs:ignore` annotations exist only for direct queries on
PayBridge-owned tables, the opt-in uninstall `DROP TABLE`, nonces verified by WooCommerce
itself, the unversioned Plaid Link CDN script, HTML assembled from escaping renderers,
chained exceptions and `load_plugin_textdomain()`.

### Runtime support claims

Declared: PHP 8.1–8.4, WordPress 6.6–7.1, WooCommerce 8.7–11.1 (8.5/8.6 lose the gateway flag of
HPOS refunds, ADR-0022), MySQL 8.0/8.4, MariaDB 10.11/11.4, HPOS on and off. PHP 8.5 is exercised
by non-blocking unit tests only and is not claimed.

### Secrets and variables of the real Sandbox gate

| Name | Kind | Purpose |
|---|---|---|
| `PAYBRIDGE_PLAID_SANDBOX_CLIENT_ID` | secret | Plaid Sandbox Client ID (a dedicated test team is recommended) |
| `PAYBRIDGE_PLAID_SANDBOX_SECRET` | secret | Plaid Sandbox secret |
| `PAYBRIDGE_PLAID_SANDBOX_USERNAME`, `PAYBRIDGE_PLAID_SANDBOX_PASSWORD` | secret | Plaid Sandbox Link test user (`user_good` / `pass_good`) |
| `PAYBRIDGE_PLAID_SANDBOX_LINK_CUSTOMIZATION` | variable (or secret) | Name of the Sandbox Link customization with Account Select "Enabled for one account" (ADR-0020) |
| `NGROK_AUTHTOKEN` | secret | Optional: public HTTPS tunnel for genuine Plaid-signed webhooks |
| `PAYBRIDGE_PLAID_NGROK_DOMAIN` | variable | Optional: the reserved ngrok domain for that tunnel |

A `v*` tag runs `quality.yml` with `require_sandbox: true`: without the first five the release
fails. The ngrok pair enables the genuine signed-webhook gate; without it the Sandbox gate pulls
events with `/transfer/event/sync` and the webhook attack matrix is covered by the deterministic
integration suite.

### Regenerating `vendor-prefixed/`

Run `composer install` with Composer 2.10.3 (for example
`php composer-2.10.3.phar install`) and commit every change under `vendor-prefixed/`.
A dependency change without the regenerated prefixed runtime fails `composer-validation`.

The CI database password `paybridge-ci-only` belongs to throwaway service containers.
