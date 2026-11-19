#!/usr/bin/env bash
#
# End-to-end test of the Python Nextcloud exporter (audit.nextcloud-exporter)
# against the local test instance.
#
#   MODE=app     — instance has the audit_export app: expect all CSVs
#   MODE=noapp   — vanilla instance: expect only users/groups CSVs, the rest
#                  must be skipped gracefully (exit code still 0)
#
# Env: MODE (required), NC_URL, NC_USER, NC_PASS, EXPORTER_DIR
# Expected seed data (tests/docker/seed.sh): 10 users + admin, 5 groups +
# admin + auditors, 50 shares, 2 group folders, 1 ACL, 1 external mount.

set -uo pipefail

MODE="${MODE:?set MODE=app or MODE=noapp}"
NC_URL="${NC_URL:-http://localhost:8080}"
NC_USER="${NC_USER:-admin}"
NC_PASS="${NC_PASS:-adminpassword}"

REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
EXPORTER_DIR="${EXPORTER_DIR:-$REPO_ROOT/../../nextcloud-exporter/audit.nextcloud-exporter}"
EXPORTER_DIR="$(cd "$EXPORTER_DIR" && pwd)" || { echo "FATAL: exporter repo not found"; exit 1; }

CSV_DIR="$EXPORTER_DIR/csv"
LOG="$(mktemp)"

FAILURES=0

fail() { echo "  FAIL: $*"; FAILURES=$((FAILURES + 1)); }
ok()   { echo "  ok:   $*"; }

# assert_rows <file> <op> <expected>  — data rows (excluding header)
assert_rows() {
    local file="$1" op="$2" expected="$3"
    if [ ! -f "$CSV_DIR/$file" ]; then
        fail "$file missing"
        return
    fi
    local rows=$(( $(wc -l < "$CSV_DIR/$file") - 1 ))
    if [ "$rows" "$op" "$expected" ]; then
        ok "$file has $rows data rows ($op $expected)"
    else
        fail "$file has $rows data rows, expected $op $expected"
    fi
}

assert_absent() {
    if [ -f "$CSV_DIR/$1" ]; then
        fail "$1 exists but must not be produced in $MODE mode"
    else
        ok "$1 not produced (as expected)"
    fi
}

assert_log() {
    if grep -q "$1" "$LOG"; then
        ok "output contains: $1"
    else
        fail "output does not contain: $1"
    fi
}

# ---------------------------------------------------------------------------
# Run the exporter
# ---------------------------------------------------------------------------

echo "==> Running exporter (MODE=$MODE) against $NC_URL"
rm -f "$CSV_DIR"/*.csv

(
    cd "$EXPORTER_DIR" &&
    NEXTCLOUD_BASE_URL="$NC_URL" \
    NEXTCLOUD_USER="$NC_USER" \
    NEXTCLOUD_APP_PASSWORD="$NC_PASS" \
    UPLOAD_TARGETS="" \
    python3 python/main.py
) 2>&1 | tee "$LOG"
EXPORTER_EXIT=${PIPESTATUS[0]}

echo
echo "==> Assertions (MODE=$MODE)"

if [ "$EXPORTER_EXIT" -eq 0 ]; then
    ok "exporter exit code 0"
else
    fail "exporter exited with $EXPORTER_EXIT"
fi

# Users + groups come from the native OCS API — expected in BOTH modes
assert_rows nextcloud_users.csv             -ge 11   # 10 seeded + admin
assert_rows nextcloud_groups.csv            -ge 7    # 5 seeded + admin + auditors
assert_rows nextcloud_group_memberships.csv -ge 22

if [ "$MODE" = "app" ]; then
    assert_rows nextcloud_shares.csv            -eq 50
    assert_rows nextcloud_group_folders.csv     -eq 2
    assert_rows nextcloud_group_folder_acls.csv -ge 1
    assert_rows nextcloud_external_storage.csv  -eq 1
    assert_rows nextcloud_activity.csv          -ge 50
    assert_absent nextcloud_ldap_mappings.csv            # user_ldap never enabled
    assert_log "Available modules"
else
    assert_absent nextcloud_shares.csv
    assert_absent nextcloud_group_folders.csv
    assert_absent nextcloud_group_folder_acls.csv
    assert_absent nextcloud_external_storage.csv
    assert_absent nextcloud_activity.csv
    assert_absent nextcloud_ldap_mappings.csv
    assert_log "audit_export app not installed"
    assert_log "partial — users and groups only"
fi

rm -f "$LOG"

echo
if [ "$FAILURES" -eq 0 ]; then
    echo "==> exporter e2e ($MODE): PASS"
else
    echo "==> exporter e2e ($MODE): $FAILURES FAILURE(S)"
    exit 1
fi
