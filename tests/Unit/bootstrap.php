<?php

declare(strict_types=1);

// Unit tests run without a Nextcloud server: load composer's autoloader and the
// OCP interfaces shipped by the nextcloud/ocp dev dependency (no autoload entry).
require_once __DIR__ . '/../../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'OCP\\')) {
        $file = __DIR__ . '/../../vendor/nextcloud/ocp/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

// OCP\DB\QueryBuilder\IQueryBuilder reads \PDO::PARAM_* constants at load time;
// provide them when the CLI has no pdo extension (unit tests never touch a DB).
if (!class_exists('PDO', false)) {
    class PDO {
        public const PARAM_NULL = 0;
        public const PARAM_INT = 1;
        public const PARAM_STR = 2;
        public const PARAM_LOB = 3;
        public const PARAM_BOOL = 5;
    }
}
