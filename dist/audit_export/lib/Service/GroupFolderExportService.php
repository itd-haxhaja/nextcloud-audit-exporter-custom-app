<?php

declare(strict_types=1);

namespace OCA\AuditExport\Service;

use OCA\AuditExport\Db\QueryHelper;
use OCP\App\IAppManager;

/**
 * Exports Group Folder configurations and their fine-grained ACL entries.
 *
 * Requires the "groupfolders" app to be installed and enabled.
 * If unavailable, returns available=false in the result.
 *
 * Data sources:
 *  - OCA\GroupFolders\Folder\FolderManager::getAllFolders()  (basic folder + group assignments)
 *  - oc_group_folders_acl  JOIN  oc_filecache               (extended ACLs via QueryBuilder;
 *    the ACL table has no folder_id — the folder is derived from the file's
 *    "__groupfolders/<folder_id>/…" path in the filecache)
 */
class GroupFolderExportService {

    public function __construct(
        private readonly IAppManager $appManager,
        private readonly QueryHelper $queryHelper,
    ) {}

    /**
     * @return array{0: list<array>, 1: int}
     */
    public function getGroupFolders(int $limit = 100, int $offset = 0): array {
        if (!StatusService::isAppEnabled($this->appManager, 'groupfolders')) {
            return [[['available' => false, 'reason' => 'groupfolders app not enabled']], 1];
        }

        // Retrieve folders via the GroupFolders app's FolderManager without HTTP roundtrip
        $folders = $this->getFoldersFromManager();

        // Merge in ACL rows from the database
        $acls = $this->getAclsFromDb();
        $aclsByFolder = [];
        foreach ($acls as $acl) {
            $aclsByFolder[$acl['folder_id']][] = $acl;
        }

        $items = [];
        foreach ($folders as $folder) {
            $fid = (int)$folder['id'];
            $folder['acls'] = $aclsByFolder[$fid] ?? [];
            $items[] = $folder;
        }

        $total = count($items);
        $paged = array_slice($items, $offset, $limit);
        return [$paged, $total];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function getFoldersFromManager(): array {
        // Use FolderManager directly — avoids an internal HTTP call
        /** @var \OCA\GroupFolders\Folder\FolderManager $fm */
        $fm = \OC::$server->get(\OCA\GroupFolders\Folder\FolderManager::class);
        $raw = $fm->getAllFolders();

        $items = [];
        foreach ($raw as $id => $f) {
            // Newer groupfolders versions (NC ~32+) return FolderDefinition
            // objects; older ones return plain arrays. Support both.
            if (is_object($f)) {
                $groupsRaw = $f->groups ?? [];
                $mountPoint = $f->mountPoint;
                $quota      = $f->quota;
                $acl        = (bool)$f->acl;
                $folderId   = (int)$f->id;
            } else {
                $groupsRaw  = $f['groups'] ?? [];
                $mountPoint = $f['mount_point'];
                $quota      = $f['quota'];
                $acl        = (bool)($f['acl'] ?? false);
                $folderId   = (int)$id;
            }

            $groups = [];
            foreach ($groupsRaw as $gid => $perm) {
                // perm is an int in old versions, an applicable-array in new ones
                if (is_array($perm)) {
                    $perm = $perm['permissions'] ?? 0;
                }
                $groups[] = ['group_id' => (string)$gid, 'permissions' => (int)$perm];
            }

            $items[] = [
                'id'          => $folderId,
                'mount_point' => $mountPoint,
                'quota'       => $quota,
                'acl_enabled' => $acl,
                'groups'      => $groups,
            ];
        }
        return $items;
    }

    private function getAclsFromDb(): array {
        $qb = $this->queryHelper->db()->getQueryBuilder();

        $qb->select(
            'gfa.acl_id',
            'gfa.fileid',
            'gfa.mapping_type',
            'gfa.mapping_id',
            'gfa.mask',
            'gfa.permissions',
            'fc.path'
        )
            ->selectAlias('st.id', 'storage_id')
            ->from('group_folders_acl', 'gfa')
            ->leftJoin('gfa', 'filecache', 'fc', $qb->expr()->eq('gfa.fileid', 'fc.fileid'))
            ->leftJoin('fc', 'storages', 'st', $qb->expr()->eq('fc.storage', 'st.numeric_id'))
            ->orderBy('gfa.mapping_type', 'ASC')
            ->addOrderBy('gfa.mapping_id', 'ASC');

        $rows  = $qb->executeQuery()->fetchAll();
        $items = [];
        foreach ($rows as $row) {
            // Two layouts, depending on the groupfolders version:
            //  - old: shared storage, filecache path "__groupfolders/<folder_id>/…"
            //  - new (NC ~32+): one storage per folder, storage id ends
            //    with ".../__groupfolders/<folder_id>/"
            $folderId = 0;
            if (preg_match('#^__groupfolders/(\d+)#', (string)($row['path'] ?? ''), $m)) {
                $folderId = (int)$m[1];
            } elseif (preg_match('#__groupfolders/(\d+)/?$#', (string)($row['storage_id'] ?? ''), $m)) {
                $folderId = (int)$m[1];
            }
            $items[] = [
                'acl_id'       => (int)$row['acl_id'],
                'folder_id'    => $folderId,
                'fileid'       => (int)$row['fileid'],
                'mapping_type' => $row['mapping_type'],
                'mapping_id'   => $row['mapping_id'],
                'mask'         => (int)$row['mask'],
                'permissions'  => (int)$row['permissions'],
            ];
        }
        return $items;
    }
}
