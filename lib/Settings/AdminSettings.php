<?php

declare(strict_types=1);

namespace OCA\AuditExport\Settings;

use OCA\AuditExport\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\Settings\ISettings;

/**
 * Admin settings page for audit_export — shows the current configuration and
 * how to change it (read-only; values are set with occ config:app:set).
 */
class AdminSettings implements ISettings {

    public function __construct(private readonly IConfig $config) {}

    public function getForm(): TemplateResponse {
        $params = [
            'allowed_group'         => $this->config->getAppValue(Application::APP_ID, 'allowed_group', 'admin'),
            'activity_max_age_days' => $this->config->getAppValue(Application::APP_ID, 'activity_max_age_days', '90'),
        ];
        return new TemplateResponse(Application::APP_ID, 'admin', $params, '');
    }

    public function getSection(): string {
        return 'additional';
    }

    public function getPriority(): int {
        return 50;
    }
}
