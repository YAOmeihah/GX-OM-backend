<?php

return [
    'github' => [
        'owner' => env('SYSTEM_UPDATE_GITHUB_OWNER', 'YAOmeihah'),
        'repo' => env('SYSTEM_UPDATE_GITHUB_REPO', 'GX-OM-backend'),
        'token' => env('SYSTEM_UPDATE_GITHUB_TOKEN'),
    ],
    'git_binary' => env('SYSTEM_UPDATE_GIT_BINARY', 'git'),
];
