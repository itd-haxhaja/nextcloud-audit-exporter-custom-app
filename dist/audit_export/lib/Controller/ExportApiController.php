<?php

declare(strict_types=1);

namespace OCA\AuditExport\Controller;

use OCA\AuditExport\Db\QueryHelper;
use OCA\AuditExport\Response\JsonEnvelope;
use OCA\AuditExport\Service\ActivityExportService;
use OCA\AuditExport\Service\ExternalStorageExportService;
use OCA\AuditExport\Service\GroupExportService;
use OCA\AuditExport\Service\GroupFolderExportService;
use OCA\AuditExport\Service\LdapExportService;
use OCA\AuditExport\Service\ShareExportService;
use OCA\AuditExport\Service\StatusService;
use OCA\AuditExport\Service\UserExportService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * REST API controller for all /apps/audit_export/api/v1/export/* endpoints.
 *
 * Authentication and admin-only enforcement is handled by AdminOnlyMiddleware
 * before any method here is invoked.
 */
class ExportApiController extends Controller {

    public function __construct(
        string                              $appName,
        IRequest                            $request,
        private readonly UserExportService          $userService,
        private readonly GroupExportService         $groupService,
        private readonly ShareExportService         $shareService,
        private readonly GroupFolderExportService   $groupFolderService,
        private readonly ExternalStorageExportService $externalStorageService,
        private readonly LdapExportService          $ldapService,
        private readonly ActivityExportService      $activityService,
        private readonly StatusService              $statusService,
        private readonly QueryHelper                $queryHelper,
    ) {
        parent::__construct($appName, $request);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/status
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function status(): JSONResponse {
        $data = $this->statusService->getStatus();
        return JsonEnvelope::success('status', [$data]);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/users
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function users(): JSONResponse {
        ['limit' => $limit, 'offset' => $offset] = $this->queryHelper->parsePagination(
            $this->request->getParam('limit'),
            $this->request->getParam('offset')
        );
        $since = $this->queryHelper->parseSince($this->request->getParam('since'));

        [$items, $total] = $this->userService->getUsers($limit, $offset, $since);
        return JsonEnvelope::success('users', $items, $limit, $offset, $total);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/users/{userId}
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function userById(string $userId): JSONResponse {
        $user = $this->userService->getUserById($userId);
        if ($user === null) {
            return JsonEnvelope::error(404, "User '$userId' not found");
        }
        return JsonEnvelope::success('users', [$user], 1, 0, 1);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/groups
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function groups(): JSONResponse {
        ['limit' => $limit, 'offset' => $offset] = $this->queryHelper->parsePagination(
            $this->request->getParam('limit'),
            $this->request->getParam('offset')
        );

        [$items, $total] = $this->groupService->getGroups($limit, $offset);
        return JsonEnvelope::success('groups', $items, $limit, $offset, $total);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/groups/{groupId}
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function groupById(string $groupId): JSONResponse {
        $group = $this->groupService->getGroupById($groupId);
        if ($group === null) {
            return JsonEnvelope::error(404, "Group '$groupId' not found");
        }
        return JsonEnvelope::success('groups', [$group], 1, 0, 1);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/shares
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function shares(): JSONResponse {
        ['limit' => $limit, 'offset' => $offset] = $this->queryHelper->parsePagination(
            $this->request->getParam('limit'),
            $this->request->getParam('offset')
        );
        $since  = $this->queryHelper->parseSince($this->request->getParam('since'));
        $userId = $this->request->getParam('user');

        [$items, $total] = $this->shareService->getShares($limit, $offset, $since, $userId ?: null);
        return JsonEnvelope::success('shares', $items, $limit, $offset, $total);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/groupfolders
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function groupFolders(): JSONResponse {
        ['limit' => $limit, 'offset' => $offset] = $this->queryHelper->parsePagination(
            $this->request->getParam('limit'),
            $this->request->getParam('offset')
        );

        [$items, $total] = $this->groupFolderService->getGroupFolders($limit, $offset);
        return JsonEnvelope::success('groupfolders', $items, $limit, $offset, $total);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/external-storage
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function externalStorage(): JSONResponse {
        ['limit' => $limit, 'offset' => $offset] = $this->queryHelper->parsePagination(
            $this->request->getParam('limit'),
            $this->request->getParam('offset')
        );

        [$items, $total] = $this->externalStorageService->getExternalStorage($limit, $offset);
        return JsonEnvelope::success('external_storage', $items, $limit, $offset, $total);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/ldap-mappings
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function ldapMappings(): JSONResponse {
        [$items, $total] = $this->ldapService->getLdapMappings();
        return JsonEnvelope::success('ldap_mappings', $items, $total, 0, $total);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/activity
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function activity(): JSONResponse {
        ['limit' => $limit, 'offset' => $offset] = $this->queryHelper->parsePagination(
            $this->request->getParam('limit'),
            $this->request->getParam('offset')
        );
        $since = $this->queryHelper->parseSince($this->request->getParam('since'));

        [$items, $total] = $this->activityService->getActivity($limit, $offset, $since);
        return JsonEnvelope::success('activity', $items, $limit, $offset, $total);
    }

    // -------------------------------------------------------------------------
    // GET /api/v1/export/full
    // -------------------------------------------------------------------------

    #[NoCSRFRequired]
    #[NoAdminRequired] // group gating happens in AdminOnlyMiddleware (allowed_group support)
    public function full(): JSONResponse {
        $modulesParam = $this->request->getParam('modules', '');
        $requested    = $modulesParam !== ''
            ? array_map('trim', explode(',', $modulesParam))
            : ['users', 'groups', 'shares', 'groupfolders', 'external_storage', 'ldap_mappings', 'activity'];

        ['limit' => $limit, 'offset' => $offset] = $this->queryHelper->parsePagination(
            $this->request->getParam('limit'),
            $this->request->getParam('offset')
        );
        $since = $this->queryHelper->parseSince($this->request->getParam('since'));

        $result = [];

        if (in_array('users', $requested, true)) {
            [$items, $total] = $this->userService->getUsers($limit, $offset, $since);
            $result['users'] = ['total' => $total, 'data' => $items];
        }
        if (in_array('groups', $requested, true)) {
            [$items, $total] = $this->groupService->getGroups($limit, $offset);
            $result['groups'] = ['total' => $total, 'data' => $items];
        }
        if (in_array('shares', $requested, true)) {
            [$items, $total] = $this->shareService->getShares($limit, $offset, $since);
            $result['shares'] = ['total' => $total, 'data' => $items];
        }
        if (in_array('groupfolders', $requested, true)) {
            [$items, $total] = $this->groupFolderService->getGroupFolders($limit, $offset);
            $result['groupfolders'] = ['total' => $total, 'data' => $items];
        }
        if (in_array('external_storage', $requested, true)) {
            [$items, $total] = $this->externalStorageService->getExternalStorage($limit, $offset);
            $result['external_storage'] = ['total' => $total, 'data' => $items];
        }
        if (in_array('ldap_mappings', $requested, true)) {
            [$items, $total] = $this->ldapService->getLdapMappings();
            $result['ldap_mappings'] = ['total' => $total, 'data' => $items];
        }
        if (in_array('activity', $requested, true)) {
            [$items, $total] = $this->activityService->getActivity($limit, $offset, $since);
            $result['activity'] = ['total' => $total, 'data' => $items];
        }

        return JsonEnvelope::success('full', [$result]);
    }
}
