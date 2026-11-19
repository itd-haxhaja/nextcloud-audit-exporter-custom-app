#!/usr/bin/env bash
#
# Seeds the local Docker/Podman Nextcloud instance with deterministic test data:
#
#   - 10 users   (user0..user9; user0-4 intern @itdesign.at, user5-9 extern; user9 disabled)
#   - 5 groups   (group0..group4, users spread across them, user0 subadmin of group0)
#     + an "auditors" group (user8) set as audit_export.allowed_group
#   - 50 shares  (5 per user: user shares, group shares, public links)
#   - 2 group folders (Finance + Projects, Finance with ACL enabled)
#   - 1 external storage mount (/ExtData, local backend)
#
# Also enables the apps needed by the audit_export optional modules:
# groupfolders (installed from app store), files_external, activity.
#
# Requirements: the compose stack from docker-compose.yml must be up and
# Nextcloud must answer on $NC_URL. Idempotent: a marker file inside the
# container makes a second run skip the data seeding (the app re-install and
# app enabling always run, so seed also serves as "redeploy the app").
#
# Env overrides: NC_URL, NC_USER, NC_PASS, COMPOSE (e.g. "podman compose"),
# SEED_WITH_APP=0 seeds a vanilla instance: no audit_export deploy, no extra
# apps, no group folders / external storage — only users, groups and shares.
# (Used to test the exporter's partial mode against instances without the app.)

set -uo pipefail

NC_URL="${NC_URL:-http://localhost:8080}"
NC_USER="${NC_USER:-admin}"
NC_PASS="${NC_PASS:-adminpassword}"
COMPOSE="${COMPOSE:-docker compose}"
COMPOSE_FILE="$(cd "$(dirname "$0")" && pwd)/docker-compose.yml"

USER_PASS_PREFIX="SeedPass!2026-"   # user{i} password = SeedPass!2026-{i}
SEED_WITH_APP="${SEED_WITH_APP:-1}"

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

# Run occ inside the nextcloud container as www-data.
occ() {
    $COMPOSE -f "$COMPOSE_FILE" exec -T -u www-data nextcloud php occ "$@"
}

# Run a shell command inside the container as root.
in_container() {
    $COMPOSE -f "$COMPOSE_FILE" exec -T nextcloud "$@"
}

# OCS API call as admin. Usage: ocs METHOD PATH [extra curl args...]
ocs() {
    local method="$1" path="$2"; shift 2
    curl -s -u "$NC_USER:$NC_PASS" -H "OCS-APIRequest: true" \
         -X "$method" "$NC_URL$path" "$@" > /dev/null
}

# OCS API call as a seeded user. Usage: ocs_as USER PASS METHOD PATH [args...]
ocs_as() {
    local user="$1" pass="$2" method="$3" path="$4"; shift 4
    curl -s -u "$user:$pass" -H "OCS-APIRequest: true" \
         -X "$method" "$NC_URL$path" "$@" > /dev/null
}

step() { echo; echo "==> $*"; }

# ---------------------------------------------------------------------------
# 0. Apps
# ---------------------------------------------------------------------------

if [ "$SEED_WITH_APP" = "1" ]; then

step "Checking that the nextcloud container is running"
if ! in_container true 2>/dev/null; then
    echo "FATAL: the nextcloud container is not running (see '$COMPOSE -f $COMPOSE_FILE logs nextcloud')" >&2
    exit 1
fi

step "Installing audit_export app into custom_apps"
# Same content as the release tarball: everything minus .dockerignore (rsync is part of the official image)
in_container mkdir -p /var/www/html/custom_apps/audit_export
in_container rsync -a --delete --exclude-from=/opt/audit_export_src/.dockerignore \
    /opt/audit_export_src/ /var/www/html/custom_apps/audit_export/
in_container chown -R www-data:www-data /var/www/html/custom_apps/audit_export
# Reload Apache so a redeploy is not served from a stale opcache
in_container apachectl -k graceful || true

step "Enabling apps (audit_export, files_external, activity, groupfolders)"
# A bumped app version puts Nextcloud into "upgrade needed" state in which app:enable is refused
occ upgrade
occ app:enable audit_export || { echo "FATAL: could not enable audit_export" >&2; exit 1; }
occ app:enable files_external
occ app:enable activity || true
occ app:install groupfolders || occ app:enable groupfolders || \
    echo "WARNING: groupfolders could not be installed — group folder seeding will be skipped"

else
    step "SEED_WITH_APP=0 — vanilla instance, skipping app deploy and extra apps"
fi

# ---------------------------------------------------------------------------
# Idempotency guard — data seeding runs only once per instance
# ---------------------------------------------------------------------------

if in_container test -f /var/www/html/.audit_export_seeded; then
    step "Data already seeded (marker /var/www/html/.audit_export_seeded exists) — done"
    exit 0
fi

# ---------------------------------------------------------------------------
# 1. Users (10)
# ---------------------------------------------------------------------------

step "Creating 10 users"
for i in $(seq 0 9); do
    if [ "$i" -lt 5 ]; then
        email="user${i}@itdesign.at"      # Intern
    else
        email="user${i}@example.com"      # Extern
    fi
    ocs POST /ocs/v1.php/cloud/users \
        -d "userid=user${i}" \
        -d "password=${USER_PASS_PREFIX}${i}" \
        -d "displayName=Test User ${i}" \
        -d "email=${email}"
    echo "    user${i} (${email})"
done

# ---------------------------------------------------------------------------
# 2. Groups (5) + memberships + subadmin
# ---------------------------------------------------------------------------

step "Creating 5 groups + memberships"
for g in $(seq 0 4); do
    ocs POST /ocs/v1.php/cloud/groups -d "groupid=group${g}"
    echo "    group${g}"
done

for i in $(seq 0 9); do
    ocs POST "/ocs/v1.php/cloud/users/user${i}/groups" -d "groupid=group$((i % 5))"
    ocs POST "/ocs/v1.php/cloud/users/user${i}/groups" -d "groupid=group$(((i + 1) % 5))"
done

# user0 becomes subadmin of group0
ocs POST /ocs/v1.php/cloud/users/user0/subadmins -d "groupid=group0"

# auditors: non-admin group allowed to use the export API (audit_export.allowed_group)
ocs POST /ocs/v1.php/cloud/groups -d "groupid=auditors"
ocs POST /ocs/v1.php/cloud/users/user8/groups -d "groupid=auditors"
if [ "$SEED_WITH_APP" = "1" ]; then
    occ config:app:set audit_export allowed_group --value auditors
fi

# ---------------------------------------------------------------------------
# 3. Files + Shares (5 shares per user = 50)
# ---------------------------------------------------------------------------

step "Uploading files and creating 50 shares (5 per user)"
tmpfile=$(mktemp)
echo "audit_export seed file" > "$tmpfile"

for i in $(seq 0 9); do
    pass="${USER_PASS_PREFIX}${i}"

    # Upload 5 files per user via WebDAV (this also provisions the user dir)
    for j in $(seq 0 4); do
        curl -s -u "user${i}:${pass}" -T "$tmpfile" \
            "$NC_URL/remote.php/dav/files/user${i}/doc_${i}_${j}.txt" > /dev/null
    done

    share() { # share ARGS... — create one share as user{i}
        ocs_as "user${i}" "$pass" POST /ocs/v2.php/apps/files_sharing/api/v1/shares "$@"
    }

    # 2 user shares, 2 group shares, 1 public link
    share -d "path=/doc_${i}_0.txt" -d "shareType=0" -d "shareWith=user$(((i + 1) % 10))"
    share -d "path=/doc_${i}_1.txt" -d "shareType=0" -d "shareWith=user$(((i + 2) % 10))"
    share -d "path=/doc_${i}_2.txt" -d "shareType=1" -d "shareWith=group$((i % 5))"
    share -d "path=/doc_${i}_3.txt" -d "shareType=1" -d "shareWith=group$(((i + 1) % 5))"
    share -d "path=/doc_${i}_4.txt" -d "shareType=3"
    echo "    user${i}: 5 files, 5 shares"
done
rm -f "$tmpfile"

# ---------------------------------------------------------------------------
# 4. Disable user9 (AFTER its shares were created, so the count stays at 50)
# ---------------------------------------------------------------------------

step "Disabling user9"
ocs PUT /ocs/v1.php/cloud/users/user9/disable

# ---------------------------------------------------------------------------
# 5. Group folders (2, Finance with ACL)
# ---------------------------------------------------------------------------

if [ "$SEED_WITH_APP" = "1" ] && occ app:list | grep -q groupfolders; then
    step "Creating 2 group folders"
    fid1=$(occ groupfolders:create Finance | tr -dc '0-9')
    fid2=$(occ groupfolders:create Projects | tr -dc '0-9')
    occ groupfolders:group "$fid1" group0
    occ groupfolders:group "$fid2" group1
    occ groupfolders:quota "$fid2" 1073741824
    occ groupfolders:permissions "$fid1" --enable
    # Per-path ACL rule (creates a group_folders_acl row); tolerate syntax drift
    occ groupfolders:permissions "$fid1" --group group2 / -- +read -share \
        || echo "    WARNING: could not create ACL rule (occ syntax mismatch?)"
    echo "    Finance (id $fid1, group0, ACL on), Projects (id $fid2, group1, 1GB quota)"
else
    step "Skipping group folders (app not installed)"
fi

# ---------------------------------------------------------------------------
# 6. External storage (1 local mount)
# ---------------------------------------------------------------------------

if [ "$SEED_WITH_APP" = "1" ]; then
    step "Creating 1 external storage mount (/ExtData)"
    in_container mkdir -p /var/ext-data
    in_container chown www-data:www-data /var/ext-data
    occ files_external:create ExtData local null::null --config datadir=/var/ext-data \
        || echo "    (mount may already exist)"
else
    step "Skipping external storage mount (SEED_WITH_APP=0)"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------

in_container touch /var/www/html/.audit_export_seeded

step "Seed complete"
occ user:list | head -15
occ group:list | head -30
echo
echo "Nextcloud ready at $NC_URL (admin: $NC_USER)"
