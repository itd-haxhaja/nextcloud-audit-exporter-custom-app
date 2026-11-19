<?php

declare(strict_types=1);

namespace OCA\AuditExport\Exception;

/** Thrown by AdminOnlyMiddleware when no user is logged in (normally caught by core before). */
class NotAuthenticatedException extends \Exception {
}
