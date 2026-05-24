<?php

namespace App\Services\SystemUpdate;

use RuntimeException;

class GitHubRateLimitException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('GitHub API rate limit exceeded. Configure SYSTEM_UPDATE_GITHUB_TOKEN or try again later.');
    }
}
