<?php

declare(strict_types=1);

namespace OCA\AuditExport\Middleware;

use OCA\AuditExport\AppInfo\Application;
use OCA\AuditExport\Exception\ForbiddenException;
use OCA\AuditExport\Exception\NotAuthenticatedException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Rejects any request whose authenticated user is not a member of the
 * admin group or the configurable audit_export.allowed_group group.
 *
 * Unauthenticated requests never reach this middleware: Nextcloud's core
 * SecurityMiddleware answers them with 401 first (the endpoints are not
 * public pages). The NotAuthenticatedException branch is a safety net only.
 */
class AdminOnlyMiddleware extends Middleware {

    public function __construct(
        private readonly IUserSession    $userSession,
        private readonly IGroupManager   $groupManager,
        private readonly IConfig         $config,
        private readonly LoggerInterface $logger,
    ) {}

    public function beforeController(Controller $controller, string $methodName): void {
        $user = $this->userSession->getUser();

        if ($user === null) {
            throw new NotAuthenticatedException('Authentication required');
        }

        $allowedGroup = trim($this->config->getAppValue(Application::APP_ID, 'allowed_group', 'admin'));
        if ($allowedGroup === '') {
            $allowedGroup = 'admin';
        }

        if (
            !$this->groupManager->isAdmin($user->getUID()) &&
            !$this->groupManager->isInGroup($user->getUID(), $allowedGroup)
        ) {
            throw new ForbiddenException('Admin privileges required');
        }
    }

    public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
        if ($exception instanceof ForbiddenException) {
            return new JSONResponse(
                ['status' => 'error', 'code' => 403, 'message' => 'Admin privileges required'],
                Http::STATUS_FORBIDDEN
            );
        }

        if ($exception instanceof NotAuthenticatedException) {
            return new JSONResponse(
                ['status' => 'error', 'code' => 401, 'message' => 'Authentication required'],
                Http::STATUS_UNAUTHORIZED
            );
        }

        // Anything else is a bug or an infrastructure problem: log it with the
        // full exception server-side, answer with a generic envelope (no class
        // names, messages or paths leave the server).
        $this->logger->error('audit_export: unhandled exception in {controller}::{method}', [
            'exception'  => $exception,
            'controller' => get_class($controller),
            'method'     => $methodName,
        ]);

        return new JSONResponse(
            ['status' => 'error', 'code' => 500, 'message' => 'Internal error, see the Nextcloud log'],
            Http::STATUS_INTERNAL_SERVER_ERROR
        );
    }
}
