<?php

namespace App\Http\Controllers;

use App\Services\InvoiceBusinessQueryService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InvoiceBusinessController extends ApiController
{
    public function __construct(private readonly InvoiceBusinessQueryService $queryService) {}

    public function allocatable(Request $request)
    {
        $validated = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'customer_id' => ['required', 'integer', 'exists:customers,id'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            return $this->successResponse($this->queryService->allocatable(
                Auth::user(),
                (int) $validated['store_id'],
                (int) $validated['customer_id'],
                (int) ($validated['per_page'] ?? 20),
            ));
        } catch (HttpException $exception) {
            return $this->errorResponse($exception->getMessage(), $exception->getStatusCode());
        }
    }

    public function printDetails(Request $request)
    {
        try {
            $validated = $request->validate([
                'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
                'invoice_ids.*' => ['required', 'integer'],
            ]);

            $invoiceIds = array_map('intval', $validated['invoice_ids']);

            return $this->successResponse($this->queryService->printDetails(Auth::user(), $invoiceIds));
        } catch (ValidationException $exception) {
            return $this->errorResponse($exception->errors()['invoice_ids'][0] ?? $exception->getMessage(), 422);
        }
    }
}
