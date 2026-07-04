<?php

namespace App\Http\Controllers;

use App\Models\SystemUpdateRun;
use App\Services\SystemUpdate\GitHubRateLimitException;
use App\Services\SystemUpdate\InPlaceReleaseInstaller;
use App\Services\SystemUpdate\SystemUpdateEnvironmentNotReadyException;
use App\Services\SystemUpdate\SystemUpdateEnvironmentPreflight;
use App\Services\SystemUpdate\SystemUpdateService;
use Illuminate\Http\Request;
use UnexpectedValueException;

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

    public function preflight(SystemUpdateEnvironmentPreflight $environmentPreflight): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse($environmentPreflight->check());
    }

    public function install(Request $request, SystemUpdateService $systemUpdateService): \Illuminate\Http\JsonResponse
    {
        $payload = $request->validate([
            'tag' => ['required', 'string', 'regex:/^v\d+\.\d+\.\d+$/'],
            'sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'confirmed' => ['accepted'],
        ]);

        try {
            return $this->successResponse($systemUpdateService->install($payload), 'System update installed.', 202);
        } catch (SystemUpdateEnvironmentNotReadyException $exception) {
            return $this->errorResponse('System update environment is not ready.', 412, [
                'preflight' => $exception->report(),
            ]);
        }
    }

    public function installUpload(Request $request, SystemUpdateService $systemUpdateService): \Illuminate\Http\JsonResponse
    {
        $payload = $request->validate([
            'tag' => ['required', 'string', 'regex:/^v\d+\.\d+\.\d+$/'],
            'sha256' => ['required', 'string', 'regex:/^[A-Fa-f0-9]{64}$/'],
            'confirmed' => ['accepted'],
            'package' => ['required', 'file'],
        ]);
        $package = $request->file('package');

        if (is_array($package)) {
            return $this->errorResponse('System update package file is invalid.', 422);
        }

        try {
            return $this->successResponse(
                $systemUpdateService->queueUploadedPackage($payload, $package),
                'System update package uploaded.',
                202
            );
        } catch (SystemUpdateEnvironmentNotReadyException $exception) {
            return $this->errorResponse('System update environment is not ready.', 412, [
                'preflight' => $exception->report(),
            ]);
        } catch (UnexpectedValueException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        }
    }

    public function index(): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse(
            SystemUpdateRun::query()
                ->latest('id')
                ->limit(20)
                ->get()
                ->toArray()
        );
    }

    public function show(SystemUpdateRun $run): \Illuminate\Http\JsonResponse
    {
        return $this->successResponse($run->toArray());
    }

    public function installRun(
        Request $request,
        SystemUpdateRun $run,
        SystemUpdateService $systemUpdateService
    ): \Illuminate\Http\JsonResponse {
        $request->validate([
            'confirmed' => ['accepted'],
        ]);

        try {
            return $this->successResponse(
                $systemUpdateService->installUploadedPackage($run),
                'System update installed.',
                202
            );
        } catch (SystemUpdateEnvironmentNotReadyException $exception) {
            return $this->errorResponse('System update environment is not ready.', 412, [
                'preflight' => $exception->report(),
            ]);
        } catch (UnexpectedValueException $exception) {
            return $this->errorResponse($exception->getMessage(), 422);
        }
    }

    public function rollback(Request $request, InPlaceReleaseInstaller $installer): \Illuminate\Http\JsonResponse
    {
        $payload = $request->validate([
            'run_id' => ['nullable', 'integer', 'exists:system_update_runs,id'],
        ]);

        $run = isset($payload['run_id'])
            ? SystemUpdateRun::query()->findOrFail($payload['run_id'])
            : SystemUpdateRun::query()->latest('id')->firstOrFail();

        if (! $run->backup_path) {
            return $this->errorResponse('Rollback backup is missing.', 409);
        }

        $run->update([
            'status' => 'rolling_back',
            'step' => 'rolling_back',
            'log_lines' => array_merge($run->log_lines ?? [], ['Rollback requested.']),
        ]);

        $installer->rollback($run->backup_path);

        $run->update([
            'status' => 'rolled_back',
            'step' => 'rolled_back',
            'log_lines' => array_merge($run->log_lines ?? [], ['Rollback completed.']),
            'finished_at' => now(),
        ]);

        return $this->successResponse([
            'run_id' => $run->id,
            'status' => 'rolled_back',
        ], 'Rollback completed.');
    }
}
