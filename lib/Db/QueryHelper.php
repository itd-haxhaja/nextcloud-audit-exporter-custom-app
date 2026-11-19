<?php

declare(strict_types=1);

namespace OCA\AuditExport\Db;

use OCP\IDBConnection;

/**
 * Shared QueryBuilder helpers reused by all service classes.
 */
class QueryHelper {

    public function __construct(private readonly IDBConnection $db) {}

    /**
     * Parse the `since` query parameter (ISO 8601 string) into a Unix timestamp.
     * Returns null if the value is empty or unparseable.
     */
    public function parseSince(?string $since): ?int {
        if ($since === null || $since === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($since, new \DateTimeZone('UTC'));
            return $dt->getTimestamp();
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Clamp and validate pagination parameters.
     *
     * @return array{limit: int, offset: int}
     */
    public function parsePagination(?string $limit, ?string $offset): array {
        $l = max(1, min(500, (int)($limit ?? 100)));
        $o = max(0, (int)($offset ?? 0));
        return ['limit' => $l, 'offset' => $o];
    }

    /**
     * Return the IDBConnection so services can call getQueryBuilder() directly.
     */
    public function db(): IDBConnection {
        return $this->db;
    }
}
