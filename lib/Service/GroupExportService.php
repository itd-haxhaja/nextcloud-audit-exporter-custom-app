<?php

declare(strict_types=1);

namespace OCA\AuditExport\Service;

use OCP\IGroupManager;
use OCP\Group\ISubAdmin;

/**
 * Exports Nextcloud groups (including members and subadmins) via IGroupManager.
 */
class GroupExportService {

    public function __construct(
        private readonly IGroupManager $groupManager,
        private readonly ISubAdmin     $subAdmin,
    ) {}

    /**
     * Return a page of groups with members and subadmins, plus total count.
     *
     * @return array{0: list<array>, 1: int}  [items, total]
     */
    public function getGroups(int $limit = 100, int $offset = 0): array {
        $groups = $this->groupManager->search('', $limit, $offset);
        $total  = count($this->groupManager->search(''));

        $items = [];
        foreach ($groups as $group) {
            $members = array_map(
                fn($u) => $u->getUID(),
                $group->getUsers()
            );

            $subAdmins = array_map(
                fn($u) => $u->getUID(),
                $this->subAdmin->getGroupsSubAdmins($group)
            );

            $items[] = [
                'id'          => $group->getGID(),
                'displayName' => $group->getDisplayName(),
                'members'     => array_values($members),
                'subadmins'   => array_values($subAdmins),
            ];
        }

        return [$items, $total];
    }

    /**
     * Return a single group by ID, or null if not found.
     *
     * @return array|null
     */
    public function getGroupById(string $groupId): ?array {
        $group = $this->groupManager->get($groupId);
        if ($group === null) {
            return null;
        }

        $members = array_map(fn($u) => $u->getUID(), $group->getUsers());
        $subAdmins = array_map(
            fn($u) => $u->getUID(),
            $this->subAdmin->getGroupsSubAdmins($group)
        );

        return [
            'id'          => $group->getGID(),
            'displayName' => $group->getDisplayName(),
            'members'     => array_values($members),
            'subadmins'   => array_values($subAdmins),
        ];
    }
}
