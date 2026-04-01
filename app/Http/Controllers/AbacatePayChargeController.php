<?php

namespace App\Http\Controllers;

use App\Models\AbacatePayCharge;
use App\Services\AbacatePayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AbacatePayChargeController extends Controller
{
    public function __construct(
        private readonly AbacatePayService $abacatePayService,
    ) {}

    public function createTransparentPix(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'external_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        $externalId = $validated['external_id'] ?? 'pix_'.Str::uuid();

        $payload = [
            'amount' => $validated['amount'],
            'method' => 'PIX_QRCODE',
            'externalId' => $externalId,
            'metadata' => array_merge($validated['metadata'] ?? [], [
                'app_user_id' => $request->user()->id,
            ]),
        ];

        $response = $this->abacatePayService->createTransparentCharge($payload);

        if (! ($response['success'] ?? false)) {
            return response()->json([
                'success' => false,
                'error' => $response['error'] ?? 'abacatepay_request_failed',
            ], 422);
        }

        $data = $response['data'];

        $charge = AbacatePayCharge::query()->create([
            'user_id' => $request->user()->id,
            'external_id' => $data['externalId'] ?? $externalId,
            'gateway_charge_id' => $data['id'] ?? null,
            'charge_type' => 'transparent',
            'method' => $data['method'] ?? 'PIX_QRCODE',
            'status' => $data['status'] ?? 'PENDING',
            'amount' => $data['amount'] ?? $validated['amount'],
            'payment_url' => $data['url'] ?? null,
            'br_code' => $data['brCode'] ?? null,
            'br_code_base64' => $data['brCodeBase64'] ?? null,
            'receipt_url' => $data['receiptUrl'] ?? null,
            'expires_at' => $data['expiresAt'] ?? null,
            'dev_mode' => (bool) ($data['devMode'] ?? false),
            'payload' => $data,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $charge->id,
                'external_id' => $charge->external_id,
                'gateway_charge_id' => $charge->gateway_charge_id,
                'status' => $charge->status,
                'amount' => $charge->amount,
                'br_code' => $charge->br_code,
                'br_code_base64' => $charge->br_code_base64,
                'expires_at' => optional($charge->expires_at)->toIso8601String(),
                'dev_mode' => $charge->dev_mode,
            ],
        ], 201);
    }
}
