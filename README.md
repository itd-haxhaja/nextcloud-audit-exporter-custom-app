# ITdesign Audit Exporter App (`audit_export`)

A Nextcloud app that acts as a unified, admin-authenticated **REST API
gateway** for exporting permission-relevant data from a Nextcloud instance
into the ITdesign AudIT Tool.

The app is the *server-side* half of the ITdesign AudIT Nextcloud integration. The
*client-side* half is the ITdesign AudIT Nextcloud Exporter (repo:
`audit.nextcloud-exporter`, `python/nextcloud.py`) which calls this API and
writes the data to CSVs.

---

## How It Works

### 1. Self-introspection

When the Python exporter starts a Nextcloud export it first calls:

```
GET /apps/audit_export/api/v1/export/status
```

The app inspects the running Nextcloud instance and returns its own version,
the Nextcloud version, and a `modules` map indicating which optional data
domains are available on this instance:

```json
{
  "status": "ok",
  "data": [{
    "app_version": "1.0.0",
    "nextcloud_version": "31.0.14.1",
    "modules": {
      "users":            true,
      "groups":           true,
      "shares":           true,
      "groupfolders":     false,
      "external_storage": false,
      "ldap":             false,
      "activity":         true
    }
  }]
}
```

`users`, `groups` and `shares` are always `true` (built into Nextcloud
core). The other four are `true` only if the corresponding Nextcloud app is
installed and enabled:

| Module             | Required Nextcloud app |
|--------------------|------------------------|
| `groupfolders`     | `groupfolders`         |
| `external_storage` | `files_external`       |
| `ldap`             | `user_ldap`            |
| `activity`         | `activity`             |

### 2. Conditional export

The Python exporter reads the `modules` map and **only calls the endpoints
that are reported as available**. Anything `false` is skipped with a clean
"module not available" log line — no failed requests, no errors.

This means you can install the app on **any** Nextcloud instance and the
exporter will automatically adapt to whatever apps are installed there. No
configuration changes needed when the target instance gains or loses an
optional app.

### 3. Internal data routing

For each domain, the app picks the optimal source internally:

| Data domain        | Internal source                                  |
|--------------------|--------------------------------------------------|
| Users              | OCP `IUserManager` (Provisioning API equivalent) |
| Groups + subadmins | OCP `IGroupManager` + `ISubAdmin`                |
| Shares             | DB query on `oc_share` (+ `oc_filecache` for the owner path) |
| Group folders      | `groupfolders` API + DB join for ACLs            |
| External storage   | DB query (with secrets redacted server-side)     |
| LDAP mappings      | DB query (no API exists)                         |
| Activity log       | DB query against `oc_activity`                   |

All database access goes through Nextcloud's `QueryBuilder` — no raw SQL,
no credentials ever leave the Nextcloud server.

---

## Installation

### Manual

```bash
# 1. Copy the app folder into Nextcloud's custom_apps directory
cp -r audit_export /var/www/html/custom_apps/

# 2. Fix ownership so the web server can read it
chown -R www-data:www-data /var/www/html/custom_apps/audit_export

# 3. Enable the app
sudo -u www-data php /var/www/html/occ app:enable audit_export
```

### Docker / Podman

```bash
# Copy into a running container
podman cp audit_export <nextcloud_container>:/var/www/html/custom_apps/
podman exec -u root <nextcloud_container> \
    chown -R www-data:www-data /var/www/html/custom_apps/audit_export
podman exec -u www-data <nextcloud_container> \
    php occ app:enable audit_export
```

### Updating

Replace the `audit_export` folder with the new version, then run:

```bash
sudo -u www-data php /var/www/html/occ upgrade
```

**Every change that is shipped must bump `<version>` in `appinfo/info.xml`**: Nextcloud compares
it with the installed version and refuses to serve the instance ("update needed") until
`occ upgrade` has run.

### Verify

```bash
curl -u admin:<password> https://your-nextcloud/apps/audit_export/api/v1/export/status
```

You should get back a JSON envelope with the `modules` map.

---

## Usage

### From the AudIT Python exporter

Add these variables to the exporter's `.env` file:

```env
NEXTCLOUD_BASE_URL=https://nextcloud.example.com
NEXTCLOUD_USER=audit-service
NEXTCLOUD_APP_PASSWORD=xxxx-xxxx-xxxx-xxxx
```

Then run `python python/main.py`. The exporter will:

1. Call `/export/status` to discover what's available
2. Iterate the available endpoints with pagination (`limit=100`)
3. Write one CSV per domain into the `csv/` folder:
   `nextcloud_users.csv`, `nextcloud_groups.csv`,
   `nextcloud_group_memberships.csv`, `nextcloud_shares.csv`, and
   conditionally `nextcloud_group_folders.csv`,
   `nextcloud_group_folder_acls.csv`, `nextcloud_external_storage.csv`,
   `nextcloud_ldap_mappings.csv`, `nextcloud_activity.csv`

### Direct API usage

All endpoints live under `/apps/audit_export/api/v1/` and require HTTP
Basic Auth.

| Method | Endpoint                          | Description                            |
|--------|-----------------------------------|----------------------------------------|
| GET    | `/export/status`                  | Health check + module availability     |
| GET    | `/export/users`                   | All users                              |
| GET    | `/export/users/{userId}`          | Single user                            |
| GET    | `/export/groups`                  | All groups with members + subadmins    |
| GET    | `/export/groups/{groupId}`        | Single group                           |
| GET    | `/export/shares`                  | All shares                             |
| GET    | `/export/shares?user={userId}`    | Shares *created* by one user (initiator) |
| GET    | `/export/groupfolders`            | Group folders + ACLs                   |
| GET    | `/export/external-storage`        | External storage mounts (redacted)     |
| GET    | `/export/ldap-mappings`           | LDAP user + group mappings             |
| GET    | `/export/activity`                | Audit-relevant activity log entries    |
| GET    | `/export/full`                    | All available modules in one response  |

Common query parameters:

| Parameter | Description                                               | Default        |
|-----------|-----------------------------------------------------------|----------------|
| `limit`   | Page size                                                 | 100 (max 500)  |
| `offset`  | Page offset                                               | 0              |
| `since`   | ISO 8601 timestamp — only entries changed since           | —              |
| `modules` | Comma-separated module list for `/export/full`            | all available  |

### Response envelope

```json
{
  "status": "ok",
  "timestamp": "2026-04-30T15:41:11+00:00",
  "module": "users",
  "pagination": { "limit": 100, "offset": 0, "total": 342 },
  "data": [ ... ]
}
```

Errors:

```json
{ "status": "error", "code": 403, "message": "Admin privileges required" }
```

---

## Authentication & Authorization

The app uses Nextcloud's standard authentication. Requests must use HTTP
Basic Auth with either:

- An **admin account** (member of the `admin` group), or
- A user in the configurable `audit_export.allowed_group` (default: `admin`)

**Recommended setup for production:**

1. Create a dedicated `audit-service` Nextcloud user
2. Create a group `audit_export_readers`
3. Add the user to that group
4. `occ config:app:set audit_export allowed_group --value audit_export_readers`
   (shown under Admin → Additional settings → ITdesign Audit Exporter App)
5. Generate an **app password** for the service account
   (Settings → Security → App passwords) and put it in the AudIT vault

This keeps the audit account out of the `admin` group while still giving it
access to the export API.

---

## Admin Settings

Available under **Admin → Additional settings → ITdesign Audit Exporter App**:

| Setting                              | Default | Description                                                       |
|--------------------------------------|---------|-------------------------------------------------------------------|
| `audit_export.allowed_group`         | `admin` | Group whose members may call the export API                       |
| `audit_export.activity_max_age_days` | `90`    | Maximum age of activity log entries to include (when no `since`)  |

The settings page is read-only; change values with
`occ config:app:set audit_export <key> --value <value>` (effective immediately). The former
`share_db_threshold` and `rate_limit` settings were removed in 1.1.0: shares are always read
directly from the database, and the rate limit had never been enforced.

---

## Security

- All traffic should run over **HTTPS**. Plain HTTP should be blocked at
  the reverse proxy.
- External storage config keys matching `password`, `secret`, `key` or
  `token` are automatically replaced with `***REDACTED***` server-side.
- Public-link tokens and link passwords (`token`, `shareWith` of link/mail shares) are
  never exported (`***REDACTED***`): they are access credentials, not permissions.
- Share `path` is the owner's node path (`/<owner>/files/…`); activity entries cover the
  types `file_created`, `file_changed`, `file_deleted`, `shared`, `remote_share` and are
  capped at 10 000 rows per export window (use `since` to narrow it).
- The app is strictly read-only — it never writes back to Nextcloud.
- No database credentials ever leave the Nextcloud server.
- All access is gated by the `AdminOnlyMiddleware` before any service is
  even instantiated.

---

## Development

```bash
# Install PHP dependencies
make install

# Run unit tests
make test

# Full integration cycle: start Nextcloud (default nextcloud:31) in
# Docker/Podman, deploy the app, seed test data, run the HTTP integration
# suite, tear everything down. Test another version via NC_IMAGE:
make test-integration
make test-integration NC_IMAGE=nextcloud:34

# End-to-end tests of the Python exporter (audit.nextcloud-exporter repo):
make test-exporter                          # fresh instance WITH the app → all CSVs
make test-exporter-noapp NC_IMAGE=nextcloud:34   # vanilla instance → users/groups only

# Or step by step (leaves the instance running at http://localhost:8080):
make start-nc     # start the containers (docker compose or podman compose)
make wait-nc      # block until Nextcloud is installed
make seed         # deploy the app + seed 10 users / 5 groups / 50 shares /
                  # 2 group folders / 1 external mount (idempotent; re-running
                  # only redeploys the app code)
make stop-nc      # tear down containers and volumes (required before
                  # switching NC_IMAGE — the DB volume can't be downgraded)

# Build distributable archive
make build
```

The local instance uses `admin` / `adminpassword`. Seeded users are
`user0`–`user9` with password `SeedPass!2026-<n>`; `user8` is member of the
`auditors` group, which seeding sets as `audit_export.allowed_group` — so
API access as a non-admin can be tested. To try the Python exporter against
this instance, use the `.env.example` in the `audit.nextcloud-exporter` repo
(`NEXTCLOUD_BASE_URL=http://localhost:8080`, `admin`/`adminpassword`).

If `make test-integration` fails, the containers are left running for
debugging — `make stop-nc` cleans up.

When iterating against the bundled Podman/Docker setup, redeploy with:

```bash
podman cp audit_export/lib <container>:/var/www/html/custom_apps/audit_export/
podman exec -u root <container> chown -R www-data:www-data /var/www/html/custom_apps/audit_export
podman restart <container>
```

(The restart is needed because PHP's opcache otherwise serves the previous
version of changed files.)

---

## Version Compatibility

| App version | NC 27 | NC 28 | NC 29 | NC 30 | NC 31 | NC 32 | NC 33 | NC 34 |
|-------------|:-----:|:-----:|:-----:|:-----:|:-----:|:-----:|:-----:|:-----:|
| 1.0.x       | ✓     | ✓     | ✓     | ✓     | ✓     | ✓     | ✓     | ✓     |

Integration-tested against `nextcloud:31` and `nextcloud:34` (see
`make test-integration` / `NC_IMAGE`).

---

## Revision History

| Version | Date       | Changes                                                                 |
|---------|------------|-------------------------------------------------------------------------|
| 1.0.0   | 2026-04-30 | Initial release. Self-introspecting `/status`, all 11 endpoints live.   |
