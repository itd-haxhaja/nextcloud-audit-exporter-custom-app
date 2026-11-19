<?php

declare(strict_types=1);

namespace OCA\AuditExport\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * HTTP-level integration tests for the audit_export API.
 *
 * Requires a running, seeded Nextcloud (make start-nc + make seed, or simply
 * make test-integration). Assertions are aligned with tests/docker/seed.sh:
 * 10 users, 5 groups, 50 shares, 2 group folders, 1 external mount.
 */
final class ExportApiTest extends TestCase {

    private const API = '/apps/audit_export/api/v1';

    /** Cached /export/status modules map, fetched once. */
    private static ?array $modules = null;

    // -------------------------------------------------------------------------
    // HTTP helper
    // -------------------------------------------------------------------------

    /**
     * @return array{code: int, body: array|null}
     */
    private static function request(
        string $path,
        array $query = [],
        ?string $user = NC_ADMIN_USER,
        ?string $pass = NC_ADMIN_PASS,
    ): array {
        $url = NC_URL . self::API . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($user !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, "$user:$pass");
        }

        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        self::assertNotFalse($raw, "curl request to $url failed entirely");
        $body = json_decode((string)$raw, true);

        return ['code' => $code, 'body' => is_array($body) ? $body : null];
    }

    /** Fetch all pages of a paginated endpoint and return [items, total]. */
    private static function fetchAll(string $path): array {
        $items  = [];
        $offset = 0;
        $total  = 0;
        do {
            $res = self::request($path, ['limit' => 100, 'offset' => $offset]);
            self::assertSame(200, $res['code'], "GET $path returned {$res['code']}");
            self::assertSame('ok', $res['body']['status'] ?? null);
            $page   = $res['body']['data'];
            $total  = $res['body']['pagination']['total'];
            $items  = array_merge($items, $page);
            $offset += 100;
        } while ($offset < $total && $page !== []);

        return [$items, $total];
    }

    private static function modules(): array {
        if (self::$modules === null) {
            $res = self::request('/export/status');
            self::assertSame(200, $res['code'], 'status endpoint not reachable — is the stack up and seeded?');
            self::$modules = $res['body']['data'][0]['modules'];
        }
        return self::$modules;
    }

    // -------------------------------------------------------------------------
    // Status + auth
    // -------------------------------------------------------------------------

    public function testStatusReportsOkAndModules(): void {
        $res = self::request('/export/status');

        $this->assertSame(200, $res['code']);
        $this->assertSame('ok', $res['body']['status']);

        $data = $res['body']['data'][0];
        $this->assertNotEmpty($data['app_version']);
        $this->assertNotEmpty($data['nextcloud_version']);

        $modules = $data['modules'];
        foreach (['users', 'groups', 'shares'] as $core) {
            $this->assertTrue($modules[$core], "core module '$core' must always be available");
        }
        // Seeded/enabled by seed.sh
        $this->assertTrue($modules['external_storage'], 'files_external was enabled by seeding');
        $this->assertTrue($modules['activity'], 'activity was enabled by seeding');
        // user_ldap is never enabled in the test stack
        $this->assertFalse($modules['ldap']);
    }

    public function testUnauthenticatedRequestIsRejected(): void {
        $res = self::request('/export/users', [], null, null);
        $this->assertNotSame(200, $res['code'], 'endpoint must not be readable without credentials');
        $this->assertContains($res['code'], [401, 403], "expected 401/403, got {$res['code']}");
    }

    public function testNonAdminUserIsForbidden(): void {
        // user1 is neither admin nor in the allowed_group ("auditors")
        $res = self::request('/export/users', [], 'user1', SEED_USER_PASS_PREFIX . '1');
        $this->assertSame(403, $res['code']);
        $this->assertSame('error', $res['body']['status']);
    }

    public function testAllowedGroupMemberCanExport(): void {
        // user8 is a plain user but member of "auditors" = audit_export.allowed_group
        $res = self::request('/export/users', [], 'user8', SEED_USER_PASS_PREFIX . '8');
        $this->assertSame(200, $res['code'], 'allowed_group member must be able to export');
        $this->assertSame('ok', $res['body']['status']);
    }

    // -------------------------------------------------------------------------
    // Users
    // -------------------------------------------------------------------------

    public function testUsersExport(): void {
        [$users, $total] = self::fetchAll('/export/users');

        // 10 seeded + at least the admin account
        $this->assertGreaterThanOrEqual(11, $total);
        $this->assertCount($total, $users, 'pagination must yield exactly `total` items');

        $byId = array_column($users, null, 'id');
        foreach (range(0, 9) as $i) {
            $this->assertArrayHasKey("user$i", $byId, "seeded user$i missing from export");
        }

        $u0 = $byId['user0'];
        $this->assertSame('Test User 0', $u0['displayname']);
        $this->assertSame('user0@itdesign.at', $u0['email']);
        $this->assertTrue((bool)$u0['enabled']);
        $this->assertArrayHasKey('quota', $u0);
        $this->assertArrayHasKey('lastLogin', $u0);
        $this->assertArrayHasKey('backend', $u0);

        $this->assertFalse((bool)$byId['user9']['enabled'], 'user9 was disabled by seeding');
    }

    public function testUsersPagination(): void {
        $res = self::request('/export/users', ['limit' => 5, 'offset' => 0]);
        $this->assertSame(200, $res['code']);
        $this->assertCount(5, $res['body']['data']);
        $this->assertSame(5, $res['body']['pagination']['limit']);
        $this->assertGreaterThanOrEqual(11, $res['body']['pagination']['total']);
    }

    public function testUserById(): void {
        $res = self::request('/export/users/user3');
        $this->assertSame(200, $res['code']);
        $this->assertSame('user3', $res['body']['data'][0]['id']);

        $res = self::request('/export/users/no-such-user');
        $this->assertSame(404, $res['code']);
    }

    // -------------------------------------------------------------------------
    // Groups
    // -------------------------------------------------------------------------

    public function testGroupsExport(): void {
        [$groups, $total] = self::fetchAll('/export/groups');

        // 5 seeded + admin group
        $this->assertGreaterThanOrEqual(6, $total);

        $byId = array_column($groups, null, 'id');
        foreach (range(0, 4) as $g) {
            $this->assertArrayHasKey("group$g", $byId, "seeded group$g missing from export");
        }

        // Every user is in 2 of the 5 groups → each group has 4 members
        $g0 = $byId['group0'];
        $this->assertCount(4, $g0['members']);
        $this->assertContains('user0', $g0['members']);
        $this->assertContains('user0', $g0['subadmins'], 'user0 is subadmin of group0');
    }

    public function testGroupById(): void {
        $res = self::request('/export/groups/group2');
        $this->assertSame(200, $res['code']);
        $this->assertSame('group2', $res['body']['data'][0]['id']);

        $res = self::request('/export/groups/no-such-group');
        $this->assertSame(404, $res['code']);
    }

    // -------------------------------------------------------------------------
    // Shares
    // -------------------------------------------------------------------------

    public function testSharesExport(): void {
        [$shares, $total] = self::fetchAll('/export/shares');

        $this->assertGreaterThanOrEqual(50, $total, 'seed creates 50 shares');

        $first = $shares[0];
        foreach (['id', 'shareType', 'ownerId', 'initiatorId', 'shareWith', 'path', 'permissions', 'stime'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        $ids = array_map(fn ($s) => (string)$s['id'], $shares);
        $this->assertSame(count($ids), count(array_unique($ids)), 'share ids must be unique');

        $types = array_count_values(array_map(fn ($s) => (int)$s['shareType'], $shares));
        $this->assertGreaterThanOrEqual(20, $types[0] ?? 0, 'user shares (type 0)');
        $this->assertGreaterThanOrEqual(20, $types[1] ?? 0, 'group shares (type 1)');
        $this->assertGreaterThanOrEqual(10, $types[3] ?? 0, 'public links (type 3)');
        $this->assertArrayNotHasKey(2, $types, 'internal usergroup share copies must not be exported');

        // Link shares must carry a token
        $link = current(array_filter($shares, fn ($s) => (int)$s['shareType'] === 3));
        $this->assertNotEmpty($link['token']);
    }

    // -------------------------------------------------------------------------
    // Group folders
    // -------------------------------------------------------------------------

    public function testGroupFoldersExport(): void {
        if (!self::modules()['groupfolders']) {
            $this->markTestSkipped('groupfolders app not installed in the test instance');
        }

        [$folders, $total] = self::fetchAll('/export/groupfolders');
        $this->assertGreaterThanOrEqual(2, $total);

        $byMount = array_column($folders, null, 'mount_point');
        $this->assertArrayHasKey('Finance', $byMount);
        $this->assertArrayHasKey('Projects', $byMount);

        $this->assertTrue((bool)$byMount['Finance']['acl_enabled'], 'Finance has ACL enabled');
        $financeGroups = array_column($byMount['Finance']['groups'], 'group_id');
        $this->assertContains('group0', $financeGroups);
    }

    // -------------------------------------------------------------------------
    // External storage
    // -------------------------------------------------------------------------

    public function testExternalStorageExport(): void {
        if (!self::modules()['external_storage']) {
            $this->markTestSkipped('files_external app not enabled in the test instance');
        }

        [$mounts, $total] = self::fetchAll('/export/external-storage');
        $this->assertGreaterThanOrEqual(1, $total);

        $mount = current(array_filter($mounts, fn ($m) => str_contains($m['mount_point'] ?? '', 'ExtData')));
        $this->assertNotFalse($mount, 'seeded /ExtData mount missing from export');
        $this->assertSame('local', strtolower((string)$mount['storage_backend']));

        // Secrets must be redacted server-side
        $configJson = json_encode($mount['config']);
        $this->assertStringNotContainsStringIgnoringCase('"password":"', $configJson);
    }

    // -------------------------------------------------------------------------
    // LDAP
    // -------------------------------------------------------------------------

    public function testLdapMappingsUnavailableWithoutLdapApp(): void {
        if (self::modules()['ldap']) {
            $this->markTestSkipped('user_ldap unexpectedly enabled — marker test not applicable');
        }

        $res = self::request('/export/ldap-mappings');
        $this->assertSame(200, $res['code']);
        $this->assertFalse($res['body']['data'][0]['available'], 'must return the available=false marker');
    }

    // -------------------------------------------------------------------------
    // Activity
    // -------------------------------------------------------------------------

    public function testActivityExport(): void {
        if (!self::modules()['activity']) {
            $this->markTestSkipped('activity app not enabled in the test instance');
        }

        [$rows, $total] = self::fetchAll('/export/activity');
        $this->assertGreaterThan(0, $total, 'file uploads + shares must have produced activity entries');
        $this->assertCount($total, $rows, 'pagination must yield exactly `total` items');

        $first = $rows[0];
        foreach (['activity_id', 'timestamp', 'type', 'user', 'affecteduser', 'subject'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }

        // Rows must contain real data (guards against a broken data query)
        $this->assertGreaterThan(0, (int)$first['activity_id']);
        $this->assertGreaterThan(0, (int)$first['timestamp']);
        $types = array_unique(array_map(fn ($r) => $r['type'], $rows));
        $this->assertContains('file_created', $types, 'seeded uploads must appear');
        $this->assertContains('shared', $types, 'seeded shares must appear');
    }
}
