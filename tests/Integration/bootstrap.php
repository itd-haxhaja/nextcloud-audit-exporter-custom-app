<?php

declare(strict_types=1);

/**
 * Bootstrap for the HTTP-level integration test suite.
 *
 * The tests talk to a running Nextcloud instance (see tests/docker/) over
 * plain HTTP — no Nextcloud server classes are loaded. Configuration comes
 * from the same env vars the Makefile uses:
 *
 *   NC_URL   (default http://localhost:8080)
 *   NC_USER  (default admin)
 *   NC_PASS  (default adminpassword)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

define('NC_URL', rtrim(getenv('NC_URL') ?: 'http://localhost:8080', '/'));
define('NC_ADMIN_USER', getenv('NC_USER') ?: 'admin');
define('NC_ADMIN_PASS', getenv('NC_PASS') ?: 'adminpassword');

// Matches the password scheme in tests/docker/seed.sh
define('SEED_USER_PASS_PREFIX', 'SeedPass!2026-');
