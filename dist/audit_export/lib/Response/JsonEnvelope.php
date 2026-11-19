<?php

declare(strict_types=1);

namespace OCA\AuditExport\Response;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Builds the unified JSON envelope used by all audit_export endpoints.
 *
 * Success:
 * {
 *   "status": "ok",
 *   "timestamp": "2026-03-30T14:22:00+00:00",
 *   "module": "users",
 *   "pagination": {"limit": 100, "offset": 0, "total": 342},
 *   "data": [ ... ]
 * }
 *
 * Error:
 * {
 *   "status": "error",
 *   "code": 403,
 *   "message": "Admin privileges required"
 * }
 */
class JsonEnvelope {

    /**
     * Build a successful paginated response.
     *
     * @param string $module   Logical module name (users, groups, shares, …)
     * @param array  $data     The payload items
     * @param int    $limit    Page size that was applied
     * @param int    $offset   Offset that was applied
     * @param int    $total    Total available items (before pagination)
     * @param int    $status   HTTP status code (default 200)
     */
    public static function success(
        string $module,
        array  $data,
        int    $limit  = 100,
        int    $offset = 0,
        int    $total  = 0,
        int    $status = Http::STATUS_OK
    ): JSONResponse {
        return new JSONResponse(
            [
                'status'     => 'ok',
                'timestamp'  => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
                'module'     => $module,
                'pagination' => [
                    'limit'  => $limit,
                    'offset' => $offset,
                    'total'  => $total,
                ],
                'data' => $data,
            ],
            $status
        );
    }

    /**
     * Build an error response.
     *
     * @param int    $code    HTTP status code
     * @param string $message Human-readable error message
     */
    public static function error(int $code, string $message): JSONResponse {
        return new JSONResponse(
            ['status' => 'error', 'code' => $code, 'message' => $message],
            $code
        );
    }
}
