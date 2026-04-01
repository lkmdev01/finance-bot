<?php

use App\Models\AbacatePayCharge;
use App\Models\User;
use Illuminate\Support\Facades\Http;

test('usuario autenticado pode criar cobranca pix transparente', function () {
    $user = User::factory()->create();

    config([
        'abacatepay.base_url' => 'https://api.abacatepay.com/v2',
        'abacatepay.api_key' => 'abacate_dev_123',
    ]);

    Http::fake([
        'https://api.abacatepay.com/v2/transparents/create' => Http::response([
            'success' => true,
            'error' => null,
            'data' => [
                'id' => 'pix_char_123',
                'externalId' => 'pedido-123',
                'amount' => 1000,
                'status' => 'PENDING',
                'devMode' => true,
                'method' => 'PIX_QRCODE',
                'brCode' => '000201010212',
                'brCodeBase64' => 'data:image/png;base64,abc123',
                'expiresAt' => '2026-04-02T12:00:00.000Z',
            ],
        ], 200),
    ]);

    $response = $this
        ->actingAs($user)
        ->postJson(route('billing.abacatepay.transparents.pix'), [
            'amount' => 1000,
            'external_id' => 'pedido-123',
            'metadata' => [
                'origin' => 'pricing-page',
            ],
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.gateway_charge_id', 'pix_char_123')
        ->assertJsonPath('data.external_id', 'pedido-123');

    $this->assertDatabaseHas('abacate_pay_charges', [
        'user_id' => $user->id,
        'gateway_charge_id' => 'pix_char_123',
        'external_id' => 'pedido-123',
        'status' => 'PENDING',
    ]);
});
