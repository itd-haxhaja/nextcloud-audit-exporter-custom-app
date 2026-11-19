<?php

declare(strict_types=1);

namespace OCA\AuditExport\AppInfo;

use OCA\AuditExport\Middleware\AdminOnlyMiddleware;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {

    public const APP_ID = 'audit_export';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerMiddleware(AdminOnlyMiddleware::class);
    }

    public function boot(IBootContext $context): void {
        // Nothing required at boot time
    }
}
