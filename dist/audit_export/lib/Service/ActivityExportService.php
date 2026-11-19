<?php

declare(strict_types=1);

namespace OCA\AuditExport\Service;

use OCA\AuditExport\AppInfo\Application;
use OCA\AuditExport\Db\QueryHelper;
use OCP\App\IAppManager;
use OCP\IConfig;

/**
 * Exports audit-relevant entries from the Nextcloud activity log (oc_activity).
 *
 * Only the event types relevant for permission auditing are included:
 *   file_created, file_changed, file_deleted, shared, remote_share
 * At most 10 000 rows are returned per export window (use `since` to narrow it).
 *
 * Returns available=false when the activity app is not enabled.
 *
 * Configurable parameters (Nextcloud Admin Settings):
 *   audit_export.activity_max_age_days   default: 90
 */
class ActivityExportService {

    // Nextcloud activity *type* identifiers (not subjects): unshare events are
    // subjects (unshared_*) of type "shared"; "remote_share" covers federated
    // shares. "public_links" (= downloads through links) is deliberately omitted.
    private const RELEVANT_TYPES = [
        'file_created',
        'file_changed',
        'file_deleted',
        'shared',
        'remote_share',
    ];

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly QueryHelper $queryHelper,
        private readonly IConfig     $config,
    ) {}

    /**
     * @param int      $limit
     * @param int      $offset
     * @param int|null $since   If null, defaults to now − activity_max_age_days
     *
     * @return array{0: list<array>, 1: int}
     */
    public function getActivity(int $limit = 100, int $offset = 0, ?int $since = null): array {
        if (!StatusService::isAppEnabled($this->appManager, 'activity')) {
            return [[['available' => false, 'reason' => 'activity app not enabled']], 1];
        }

        $maxAgeDays = (int)$this->config->getAppValue(
            Application::APP_ID,
            'activity_max_age_days',
            '90'
        );

        if ($since === null) {
            $since = time() - ($maxAgeDays * 86400);
        }

        $qb = $this->queryHelper->db()->getQueryBuilder();

        $qb->select(
            'activity_id', 'timestamp', 'type', 'user', 'affecteduser',
            'subject', 'subjectparams', 'object_type', 'object_id', 'file'
        )
            ->from('activity')
            ->where($qb->expr()->in(
                'type',
                $qb->createNamedParameter(self::RELEVANT_TYPES, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->andWhere($qb->expr()->gte('timestamp', $qb->createNamedParameter($since)))
            ->orderBy('timestamp', 'DESC')
            ->addOrderBy('activity_id', 'DESC'); // unique tiebreaker → stable pagination

        // Count total — must be a fresh builder: cloning an IQueryBuilder shares
        // the inner Doctrine builder, so select() on the clone would also
        // overwrite the data query's SELECT.
        $countQb = $this->queryHelper->db()->getQueryBuilder();
        $countQb->select($countQb->func()->count('activity_id', 'cnt'))
            ->from('activity')
            ->where($countQb->expr()->in(
                'type',
                $countQb->createNamedParameter(self::RELEVANT_TYPES, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_STR_ARRAY)
            ))
            ->andWhere($countQb->expr()->gte('timestamp', $countQb->createNamedParameter($since)));
        $total = (int)$countQb->executeQuery()->fetchOne();

        // Paged result (hard cap at 10 000 rows regardless of offset)
        $effectiveLimit = min($limit, 10000 - $offset);
        if ($effectiveLimit <= 0) {
            return [[], $total];
        }

        $qb->setMaxResults($effectiveLimit)
           ->setFirstResult($offset);

        $rows  = $qb->executeQuery()->fetchAll();
        $items = array_map([$this, 'normalize'], $rows);

        return [$items, $total];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function normalize(array $row): array {
        return [
            'activity_id'   => (int)$row['activity_id'],
            'timestamp'     => (int)$row['timestamp'],
            'type'          => $row['type'],
            'user'          => $row['user'],
            'affecteduser'  => $row['affecteduser'],
            'subject'       => $row['subject'],
            'subjectparams' => $row['subjectparams'],
            'object_type'   => $row['object_type'],
            'object_id'     => (int)$row['object_id'],
            'file'          => $row['file'],
        ];
    }
}
