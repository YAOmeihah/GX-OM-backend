<?php

namespace App\Http\Controllers;

use App\Services\SystemUpdate\SystemUpdateService;

class SystemUpdateController extends ApiController
{
    public function check(SystemUpdateService $systemUpdateService): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse($systemUpdateService->checkForUpdate());
    }
}
