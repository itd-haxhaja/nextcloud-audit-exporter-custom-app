<?php

declare(strict_types=1);

namespace OCA\AuditExport\Service;

use OCA\AuditExport\Db\QueryHelper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Share\IShare;

/**
 * Exports all shares across all users straight from oc_share.
 *
 * One code path only (1.1.0): the earlier IShareManager::getSharesBy() path was
 * dropped because it is not side-effect free (the share manager deletes expired
 * shares while iterating), returned reshares twice, omitted shares of disabled
 * users on newer Nextcloud versions and re-read the whole table for every page.
 *
 * Semantics:
 *  - every share type except TYPE_USERGROUP (2) is exported — those rows are
 *    per-member bookkeeping copies of group shares, not shares of their own;
 *  - `path` is the owner's node path ("/<owner>/files/…", resolved through
 *    oc_filecache), falling back to the share's file_target when the file is gone;
 *  - `?user=` restricts to shares *created* by that user (uid_initiator);
 *  - `since` restricts to shares created at or after the given time (stime);
 *  - public-link tokens are credentials and are never exported (`token` is
 *    "***REDACTED***" when a token exists, null otherwise); `shareWith` of link
 *    shares (the password hash) is likewise redacted.
 */
class ShareExportService {

    /** Permissions bitmask bit positions → label. */
    private const PERM_BITS = [
        'read'    => 1,
        'update'  => 2,
        'create'  => 4,
        'delete'  => 8,
        'reshare' => 16,
    ];

    private const COLUMNS = [
        's.id', 's.share_type', 's.share_with', 's.uid_owner', 's.uid_initiator',
        's.file_target', 's.item_type', 's.permissions', 's.stime', 's.expiration', 's.token',
    ];

    public function __construct(
        private readonly QueryHelper $queryHelper,
    ) {}

    /**
     * @param int         $limit
     * @param int         $offset
     * @param int|null    $since   Unix timestamp — include only shares with stime >= since
     * @param string|null $userId  Restrict to shares created by this user (uid_initiator)
     *
     * @return array{0: list<array>, 1: int}  [items, total]
     */
    public function getShares(
        int     $limit  = 100,
        int     $offset = 0,
        ?int    $since  = null,
        ?string $userId = null
    ): array {
        // Count — a fresh builder: cloning an IQueryBuilder shares the inner
        // Doctrine builder, so select() on the clone would also overwrite the
        // data query's SELECT.
        $countQb = $this->queryHelper->db()->getQueryBuilder();
        $countQb->select($countQb->func()->count('s.id', 'cnt'))->from('share', 's');
        $this->applyFilters($countQb, $since, $userId);
        $total = (int)$countQb->executeQuery()->fetchOne();

        $qb = $this->queryHelper->db()->getQueryBuilder();
        $qb->select(...self::COLUMNS)
            ->selectAlias('fc.path', 'fc_path')
            ->from('share', 's')
            ->leftJoin('s', 'filecache', 'fc', $qb->expr()->eq('s.file_source', 'fc.fileid'));
        $this->applyFilters($qb, $since, $userId);
        $qb->orderBy('s.id', 'ASC')
           ->setMaxResults($limit)
           ->setFirstResult($offset);

        $rows  = $qb->executeQuery()->fetchAll();
        $items = array_map([$this, 'normalizeDbRow'], $rows);

        return [$items, $total];
    }

    private function applyFilters(IQueryBuilder $qb, ?int $since, ?string $userId): void {
        // TYPE_USERGROUP (2) rows are per-member copies of group shares —
        // internal bookkeeping, not real shares.
        $qb->where($qb->expr()->neq('s.share_type', $qb->createNamedParameter(IShare::TYPE_USERGROUP, IQueryBuilder::PARAM_INT)));
        if ($since !== null) {
            $qb->andWhere($qb->expr()->gte('s.stime', $qb->createNamedParameter($since, IQueryBuilder::PARAM_INT)));
        }
        if ($userId !== null) {
            $qb->andWhere($qb->expr()->eq('s.uid_initiator', $qb->createNamedParameter($userId)));
        }
    }

    private function normalizeDbRow(array $row): array {
        $permissions = (int)$row['permissions'];
        $decoded     = [];
        foreach (self::PERM_BITS as $label => $bit) {
            $decoded[$label] = (bool)($permissions & $bit);
        }

        $shareType = (int)$row['share_type'];
        $fcPath    = (string)($row['fc_path'] ?? '');
        $path      = $fcPath !== '' ? '/' . $row['uid_owner'] . '/' . $fcPath : (string)$row['file_target'];

        // oc_share.expiration is a DATETIME column, not a unix timestamp
        $expiration = null;
        if (!empty($row['expiration'])) {
            try {
                $expiration = (new \DateTimeImmutable((string)$row['expiration'], new \DateTimeZone('UTC')))->getTimestamp();
            } catch (\Exception) {
                $expiration = null;
            }
        }

        // Link/mail shares: share_with holds the (hashed) link password, token is the access credential
        $isLink    = in_array($shareType, [IShare::TYPE_LINK, IShare::TYPE_EMAIL], true);
        $shareWith = $isLink && !empty($row['share_with']) ? '***REDACTED***' : $row['share_with'];
        $token     = !empty($row['token']) ? '***REDACTED***' : null;

        return [
            'id'          => (string)$row['id'],
            'shareType'   => $shareType,
            'shareWith'   => $shareWith,
            'ownerId'     => $row['uid_owner'],
            'initiatorId' => $row['uid_initiator'],
            'path'        => $path,
            'itemType'    => $row['item_type'],
            'permissions' => $permissions,
            'decoded'     => $decoded,
            'stime'       => (int)$row['stime'],
            'expiration'  => $expiration,
            'token'       => $token,
        ];
    }
}
