<?php

declare(strict_types=1);

namespace OCA\AuditExport\Service;

use OCA\AuditExport\Db\QueryHelper;
use OCP\App\IAppManager;

/**
 * Exports external storage mount configurations.
 *
 * Requires the "files_external" app.
 *
 * Data sources:
 *  - OCA\Files_External\Service\GlobalStoragesService::getAllStorages()   (mount list)
 *  - oc_external_mounts JOIN oc_external_config  via QueryBuilder          (detailed config)
 *
 * Security: any config key matching /password|secret|key|token/i is
 *           replaced with "***REDACTED***" before return.
 */
class ExternalStorageExportService {

    /** Pattern of config keys whose values must never leave the server. */
    private const SENSITIVE_PATTERN = '/password|secret|key|token/i';

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly QueryHelper $queryHelper,
    ) {}

    /**
     * @return array{0: list<array>, 1: int}
     */
    public function getExternalStorage(int $limit = 100, int $offset = 0): array {
        if (!StatusService::isAppEnabled($this->appManager, 'files_external')) {
            return [[['available' => false, 'reason' => 'files_external app not enabled']], 1];
        }

        $mounts = $this->getMountsWithConfig();

        $total = count($mounts);
        $paged = array_slice($mounts, $offset, $limit);
        return [$paged, $total];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function getMountsWithConfig(): array {
        // Load mount metadata from DB (oc_external_mounts + oc_external_config)
        $qb = $this->queryHelper->db()->getQueryBuilder();

        $qb->select(
            'em.mount_id', 'em.mount_point', 'em.storage_backend',
            'em.auth_backend', 'em.type',
            'ec.key', 'ec.value'
        )
            ->from('external_mounts', 'em')
            ->leftJoin('em', 'external_config', 'ec', $qb->expr()->eq('em.mount_id', 'ec.mount_id'))
            ->orderBy('em.mount_id', 'ASC');

        $rows = $qb->executeQuery()->fetchAll();

        // Group config key/value pairs under their mount
        $byMount = [];
        foreach ($rows as $row) {
            $mid = (int)$row['mount_id'];
            if (!isset($byMount[$mid])) {
                $byMount[$mid] = [
                    'mount_id'        => $mid,
                    'mount_point'     => $row['mount_point'],
                    'storage_backend' => $row['storage_backend'],
                    'auth_backend'    => $row['auth_backend'],
                    'type'            => $row['type'],
                    'applicable_id'   => '',
                    'config'          => [],
                ];
            }
            if ($row['key'] !== null) {
                $value = $this->isSensitive($row['key']) ? '***REDACTED***' : $row['value'];
                $byMount[$mid]['config'][$row['key']] = $value;
            }
        }

        // Applicability (users/groups the mount applies to) lives in its own table;
        // an entry without a value means the mount is global.
        foreach ($this->getApplicables() as $mid => $values) {
            if (isset($byMount[$mid])) {
                $byMount[$mid]['applicable_id'] = implode(', ', $values);
            }
        }

        return array_values($byMount);
    }

    /**
     * @return array<int, list<string>> mount_id → applicable principals
     */
    private function getApplicables(): array {
        $qb = $this->queryHelper->db()->getQueryBuilder();
        $qb->select('mount_id', 'type', 'value')
            ->from('external_applicable')
            ->orderBy('mount_id', 'ASC');

        $result = [];
        foreach ($qb->executeQuery()->fetchAll() as $row) {
            // type 1 = global, 2 = group, 3 = user (OCA\Files_External DBConfigService)
            $type  = ['1' => 'global', '2' => 'group', '3' => 'user'][(string)$row['type']] ?? 'type' . $row['type'];
            $value = $row['value'] !== null && $row['value'] !== '' ? $type . ':' . $row['value'] : 'global';
            $result[(int)$row['mount_id']][] = $value;
        }
        return $result;
    }

    private function isSensitive(string $key): bool {
        return (bool)preg_match(self::SENSITIVE_PATTERN, $key);
    }
}
