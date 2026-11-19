<?php

declare(strict_types=1);

namespace OCA\AuditExport\Exception;

/** Thrown by AdminOnlyMiddleware when the user is neither admin nor in the allowed group. */
class ForbiddenException extends \Exception {
}
