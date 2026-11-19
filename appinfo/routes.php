<?php

declare(strict_types=1);

return [
    'routes' => [
        // Status / health-check
        ['name' => 'ExportApi#status',          'url' => '/api/v1/export/status',              'verb' => 'GET'],

        // Users
        ['name' => 'ExportApi#users',            'url' => '/api/v1/export/users',               'verb' => 'GET'],
        ['name' => 'ExportApi#userById',         'url' => '/api/v1/export/users/{userId}',      'verb' => 'GET'],

        // Groups
        ['name' => 'ExportApi#groups',           'url' => '/api/v1/export/groups',              'verb' => 'GET'],
        ['name' => 'ExportApi#groupById',        'url' => '/api/v1/export/groups/{groupId}',    'verb' => 'GET'],

        // Shares
        ['name' => 'ExportApi#shares',           'url' => '/api/v1/export/shares',              'verb' => 'GET'],

        // Group Folders (requires groupfolders app)
        ['name' => 'ExportApi#groupFolders',     'url' => '/api/v1/export/groupfolders',        'verb' => 'GET'],

        // External Storage (requires files_external app)
        ['name' => 'ExportApi#externalStorage',  'url' => '/api/v1/export/external-storage',   'verb' => 'GET'],

        // LDAP Mappings (requires user_ldap app)
        ['name' => 'ExportApi#ldapMappings',     'url' => '/api/v1/export/ldap-mappings',      'verb' => 'GET'],

        // Activity Log (requires activity app)
        ['name' => 'ExportApi#activity',         'url' => '/api/v1/export/activity',            'verb' => 'GET'],

        // Full export (all modules in one response)
        ['name' => 'ExportApi#full',             'url' => '/api/v1/export/full',                'verb' => 'GET'],
    ],
];
