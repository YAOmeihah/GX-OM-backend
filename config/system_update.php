<?php

return [
    'deployment_root' => env('SYSTEM_UPDATE_DEPLOYMENT_ROOT', base_path()),
    'php_binary' => env('SYSTEM_UPDATE_PHP_BINARY'),
    'github' => [
        'owner' => env('SYSTEM_UPDATE_GITHUB_OWNER', 'YAOmeihah'),
        'repo' => env('SYSTEM_UPDATE_GITHUB_REPO', 'GX-OM-backend'),
        'token' => env('SYSTEM_UPDATE_GITHUB_TOKEN'),
    ],
    'git_binary' => env('SYSTEM_UPDATE_GIT_BINARY', 'git'),
    'post_update_commands' => [],
    'backup_limit' => 3,
];
