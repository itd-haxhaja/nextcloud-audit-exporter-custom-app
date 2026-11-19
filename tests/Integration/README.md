# Integration Tests

HTTP-level tests for the audit_export REST API. They run against a real
Nextcloud instance — no Nextcloud server classes are mocked or loaded; every
test is a plain HTTP request via curl.

## Running

```bash
make test-integration
```

This target:

1. starts a Nextcloud + MariaDB stack via `tests/docker/docker-compose.yml`
   (default image `nextcloud:31`, override with `NC_IMAGE=nextcloud:34`;
   works with `docker compose` and `podman compose`; the app repo is mounted
   read-only into the container at `/opt/audit_export_src`),
2. waits until Nextcloud is installed,
3. runs `tests/docker/seed.sh`, which copies the app into `custom_apps/`,
   enables `audit_export`, `files_external`, `activity`, installs
   `groupfolders`, and seeds:
   - 10 users (`user0`–`user9`; 0–4 intern `@itdesign.at`, 5–9 extern; `user9` disabled),
   - 5 groups + an `auditors` group (`user8`) wired to `audit_export.allowed_group`,
   - 50 shares (2 user + 2 group + 1 link share per user),
   - 2 group folders (`Finance` with ACL enabled + one ACL rule, `Projects` with 1 GB quota),
   - 1 external storage mount (`/ExtData`, local backend),
4. runs this PHPUnit suite (`phpunit.integration.xml`),
5. tears the stack down (`make stop-nc`). On failure the stack is left
   running for debugging.

Seeding is idempotent via a marker file inside the container
(`/var/www/html/.audit_export_seeded`): re-running `make seed` only redeploys
the app code (plus an Apache reload to bust the opcache).

## Configuration

The suite reads `NC_URL` (default `http://localhost:8080`), `NC_USER`
(`admin`) and `NC_PASS` (`adminpassword`) — override them to point the tests
at another instance, e.g.:

```bash
NC_URL=http://localhost:9000 ./vendor/bin/phpunit -c phpunit.integration.xml
```

Tests for optional modules (group folders, external storage, activity) skip
themselves when the corresponding app is unavailable on the target instance;
the LDAP test asserts the `available=false` marker since `user_ldap` is never
enabled in the test stack.

## Exporter end-to-end tests

`tests/exporter/exporter_e2e.sh` runs the Python exporter
(`audit.nextcloud-exporter` repo, path override via `EXPORTER_DIR`) against
the local instance and asserts the produced CSVs:

```bash
make test-exporter                             # instance WITH the app → all CSVs
make test-exporter-noapp NC_IMAGE=nextcloud:34 # vanilla instance (SEED_WITH_APP=0)
                                               # → users/groups via OCS only,
                                               # app-based CSVs skipped gracefully
```
