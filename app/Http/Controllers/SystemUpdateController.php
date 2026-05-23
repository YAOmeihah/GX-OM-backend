<?php

namespace App\Http\Controllers;

use App\Services\SystemUpdate\SystemUpdateService;
use Illuminate\Http\Request;

class SystemUpdateController extends ApiController
{
    public function check(SystemUpdateService $systemUpdateService): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse($systemUpdateService->checkForUpdate());
    }

    public function install(Request $request, SystemUpdateService $systemUpdateService): \Illuminate\Http\JsonResponse
    {
        $payload = $request->validate([
            'tag' => ['required', 'string', 'regex:/^v\d+\.\d+\.\d+$/'],
            'sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'download_url' => ['nullable', 'url'],
            'confirmed' => ['accepted'],
        ]);

        return $this->successResponse($systemUpdateService->install($payload), 'System update installed.', 202);
    }
}
