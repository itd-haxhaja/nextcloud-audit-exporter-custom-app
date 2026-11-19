# Changelog

All notable changes to this project are documented in this file (Keep a Changelog format).

## [1.1.1] - 2026-11-19

### Changed
- `info.xml`: bug tracker and repository URLs point to the public GitHub repository; ITdesign
  is listed as author; German description added.
- Group folder export resolves the `FolderManager` through the public `\OCP\Server::get()` API.

### Removed
- Init-container image: Dockerfile, `scripts/install-app.sh`, Jenkinsfile, `make image` and
  `make image-smoke`, and the Kubernetes section of the README. The app is installed by copying
  the folder into `custom_apps`.

## [1.1.0] - 2026-10-08

### Fixed
- Users export: `groups` contained array indexes (`[0, 1]`) instead of group ids.
- Shares: `expiration` was cast from a DATETIME string to a bogus integer; `?user=` meant
  owner in one code path and initiator in the other; `path` had different meanings per row.
- Activity export: pagination was unstable (no unique tiebreaker); `unshared` is not a
  Nextcloud activity type (removed), `remote_share` added.
- Module availability was evaluated for the calling user, hiding group-restricted apps.
- External storage applicability now keeps the principal type (`user:`/`group:`/`global`).
- Middleware threw a non-Throwable object and returned exception class/message to clients;
  errors are now logged server-side and answered with a generic envelope.
- `make build` could not run (expected a checkout named `audit_export`) and packaged
  `vendor/`, `tests/`, `.git/`; `phpunit.xml` pointed at a non-existent unit suite.

### Changed
- Shares are always read directly from `oc_share` (read-only; the share-manager path deleted
  expired shares while iterating and returned reshares twice). `share_db_threshold` removed.
- The never-enforced `rate_limit` setting was removed from the settings page, status and docs.
- Public-link tokens and link passwords are redacted in the shares export.
- Admin settings page is read-only (values are set with `occ config:app:set`).
- `info.xml`: SPDX licence id, Bitbucket bug/repository URLs.

### Added
- Dockerfile + `scripts/install-app.sh`: init-container image that installs the app into a
  Nextcloud `custom_apps` volume; Jenkinsfile pushing it to `harbor.itdesign.cloud/audit/nextcloud-export-app`.
- Unit test suite (`make test`), `make lint`, `make image`, `make image-smoke`; test stack
  supports `PROJECT`/`NC_PORT` for parallel runs; seed aborts when the container is down and
  deploys the app with the same exclusion list as the image.

## [1.0.0] - 2026-04-30

- Initial release: REST API gateway exporting users, groups, shares, group folders, external
  storage, LDAP mappings and activity log for AudIT.
