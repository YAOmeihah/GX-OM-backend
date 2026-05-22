<?php

namespace App\Http\Controllers;

use App\Services\InvoiceBusinessQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PrintTaskController extends ApiController
{
    public function __construct(private readonly InvoiceBusinessQueryService $queryService) {}

    public function todayUnpaid(Request $request)
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            return $this->successResponse($this->queryService->todayUnpaidPrintTasks(
                Auth::user(),
                (int) $validated['store_id'],
                $validated['date'] ?? now()->toDateString(),
                (int) ($validated['per_page'] ?? 50),
            ));
        } catch (HttpException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->getStatusCode());
        }
    }
}
