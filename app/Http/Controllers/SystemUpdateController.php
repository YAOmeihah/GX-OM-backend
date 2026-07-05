<?php

namespace App\Http\Controllers;

use App\Services\SystemUpdate\GitHubRateLimitException;
use App\Services\SystemUpdate\SystemUpdateService;

class SystemUpdateController extends ApiController
{
    public function current(SystemUpdateService $systemUpdateService): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse($systemUpdateService->currentRelease());
    }

    public function check(SystemUpdateService $systemUpdateService): \Illuminate\Http\JsonResponse
    {
        try {
            return $this->successResponse($systemUpdateService->checkForUpdate());
        } catch (GitHubRateLimitException $exception) {
            return $this->errorResponse($exception->getMessage(), 429);
        }
    }
}
