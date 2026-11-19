<?php

declare(strict_types=1);

namespace OCA\AuditExport\Service;

use OCA\AuditExport\Db\QueryHelper;
use OCP\App\IAppManager;

/**
 * Exports LDAP user and group mappings directly from the database.
 *
 * No OCS/HTTP is involved; data lives in:
 *   oc_ldap_user_mapping  and  oc_ldap_group_mapping
 *
 * Returns available=false when the user_ldap app is not enabled.
 */
class LdapExportService {

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly QueryHelper $queryHelper,
    ) {}

    /**
     * Return ALL LDAP mappings (users + groups combined) plus count.
     * Each row has a `type` field: "user" or "group".
     *
     * @return array{0: list<array>, 1: int}
     */
    public function getLdapMappings(): array {
        if (!StatusService::isAppEnabled($this->appManager, 'user_ldap')) {
            return [[['available' => false, 'reason' => 'user_ldap app not enabled']], 1];
        }

        $users  = $this->queryTable('ldap_user_mapping', 'user');
        $groups = $this->queryTable('ldap_group_mapping', 'group');

        $items = array_merge($users, $groups);
        return [$items, count($items)];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function queryTable(string $table, string $type): array {
        $qb = $this->queryHelper->db()->getQueryBuilder();
        $qb->select('ldap_dn', 'owncloud_name', 'directory_uuid')
           ->from($table)
           ->orderBy('owncloud_name', 'ASC');

        $rows  = $qb->executeQuery()->fetchAll();
        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'type'           => $type,
                'ldap_dn'        => $row['ldap_dn'],
                'owncloud_name'  => $row['owncloud_name'],
                'directory_uuid' => $row['directory_uuid'],
            ];
        }
        return $items;
    }
}
