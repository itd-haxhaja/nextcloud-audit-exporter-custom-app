<?php

declare(strict_types=1);

namespace OCA\AuditExport\Service;

use OCA\AuditExport\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IConfig;

/**
 * Returns the health/status of the audit_export app including
 * which optional modules are available on this Nextcloud instance.
 */
class StatusService {

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly IConfig     $config,
    ) {}

    public function getStatus(): array {
        return [
            'app_version'       => $this->appManager->getAppVersion(Application::APP_ID),
            // system value instead of \OCP\Util::getVersion() — the latter is
            // deprecated since NC 31 and not safe across the supported 27–34 range
            'nextcloud_version' => (string)$this->config->getSystemValue('version', ''),
            'modules'           => [
                'users'            => true,
                'groups'           => true,
                'shares'           => true,
                'groupfolders'     => self::isAppEnabled($this->appManager, 'groupfolders'),
                'external_storage' => self::isAppEnabled($this->appManager, 'files_external'),
                'ldap'             => self::isAppEnabled($this->appManager, 'user_ldap'),
                'activity'         => self::isAppEnabled($this->appManager, 'activity'),
            ],
            'config' => [
                'allowed_group'          => $this->config->getAppValue(Application::APP_ID, 'allowed_group', 'admin'),
                'activity_max_age_days'  => (int)$this->config->getAppValue(Application::APP_ID, 'activity_max_age_days', '90'),
            ],
        ];
    }

    /**
     * Whether an app is installed and enabled for *anyone* — not only for the
     * calling user: isEnabledForUser() without a user argument evaluates the
     * current session, so an app restricted to certain groups would vanish
     * from the export whenever the service account is outside those groups.
     * isEnabledForAnyone() exists since NC 32; isInstalled() covers 27–31.
     */
    public static function isAppEnabled(IAppManager $appManager, string $appId): bool {
        if (method_exists($appManager, 'isEnabledForAnyone')) {
            return $appManager->isEnabledForAnyone($appId);
        }
        return $appManager->isInstalled($appId);
    }
}
