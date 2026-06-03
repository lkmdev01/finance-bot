<?php

use App\Models\OpenFinanceConnection;
use App\Models\User;

it('renderiza a pagina de integracao de open finance para usuario autenticado', function () {
    $user = User::factory()->create();

    OpenFinanceConnection::create([
        'user_id' => $user->id,
        'provider' => 'pluggy',
        'item_id' => 'item-123',
        'connector_name' => 'Banco Teste',
        'status' => 'UPDATED',
        'connected_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('integrations.open-finance'));

    $response->assertOk();
    $response->assertSee('Open Finance');
    $response->assertSee('Banco Teste');
});
