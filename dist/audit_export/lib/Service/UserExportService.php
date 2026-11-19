<?php

declare(strict_types=1);

namespace OCA\AuditExport\Service;

use OCP\IGroupManager;
use OCP\Group\ISubAdmin;
use OCP\IUserManager;

/**
 * Exports Nextcloud users via the internal IUserManager/IGroupManager interfaces.
 * No HTTP roundtrips; runs entirely within the PHP process.
 */
class UserExportService {

    public function __construct(
        private readonly IUserManager  $userManager,
        private readonly IGroupManager $groupManager,
        private readonly ISubAdmin     $subAdmin,
    ) {}

    /**
     * Return a page of users plus the total user count.
     *
     * @param int      $limit  Page size (1–500)
     * @param int      $offset Page offset
     * @param int|null $since  Filter: only users whose lastLogin >= $since (Unix ts)
     *
     * @return array{0: list<array>, 1: int}  [items, total]
     */
    public function getUsers(int $limit = 100, int $offset = 0, ?int $since = null): array {
        // IUserManager::search returns IUser objects, offset-paginated
        $users = $this->userManager->search('', $limit, $offset);
        $total = $this->userManager->countUsers();
        // countUsers() returns an array of [backend => count]; sum all backends
        if (is_array($total)) {
            $total = array_sum($total);
        }

        $items = [];
        foreach ($users as $user) {
            $lastLogin = $user->getLastLogin();
            if ($since !== null && $lastLogin > 0 && $lastLogin < $since) {
                continue;
            }

            $uid    = $user->getUID();
            $groups = array_values($this->groupManager->getUserGroupIds($user));

            $subAdminGroups = array_map(
                fn($g) => $g->getGID(),
                $this->subAdmin->getSubAdminsGroups($user)
            );

            $quota = $user->getQuota();

            $items[] = [
                'id'          => $uid,
                'displayname' => $user->getDisplayName(),
                'email'       => $user->getEMailAddress() ?? '',
                'enabled'     => $user->isEnabled(),
                'lastLogin'   => $lastLogin,
                'quota'       => $quota,
                'groups'      => $groups,
                'subadmin'    => $subAdminGroups,
                'backend'     => $user->getBackendClassName(),
            ];
        }

        return [$items, (int)$total];
    }

    /**
     * Return a single user by UID, or null if not found.
     *
     * @return array|null
     */
    public function getUserById(string $userId): ?array {
        $user = $this->userManager->get($userId);
        if ($user === null) {
            return null;
        }

        $groups = array_values($this->groupManager->getUserGroupIds($user));
        $subAdminGroups = array_map(
            fn($g) => $g->getGID(),
            $this->subAdmin->getSubAdminsGroups($user)
        );

        return [
            'id'          => $user->getUID(),
            'displayname' => $user->getDisplayName(),
            'email'       => $user->getEMailAddress() ?? '',
            'enabled'     => $user->isEnabled(),
            'lastLogin'   => $user->getLastLogin(),
            'quota'       => $user->getQuota(),
            'groups'      => $groups,
            'subadmin'    => $subAdminGroups,
            'backend'     => $user->getBackendClassName(),
        ];
    }
}
