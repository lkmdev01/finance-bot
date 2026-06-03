<?php

use App\Jobs\ProcessPluggyWebhook;
use App\Models\OpenFinanceConnection;
use App\Models\PluggyWebhookEvent;
use App\Models\User;
use App\Services\OpenFinance\OpenFinanceSyncService;
use Illuminate\Support\Facades\Queue;

it('recebe o webhook da pluggy e despacha processamento assincrono', function () {
    Queue::fake();

    $response = $this->postJson(route('webhook.pluggy'), [
        'eventId' => 'evt-123',
        'event' => 'item/updated',
        'itemId' => 'item-abc',
        'clientUserId' => '7',
    ]);

    $response->assertOk()->assertJson(['received' => true]);

    $this->assertDatabaseHas('pluggy_webhook_events', [
        'event_id' => 'evt-123',
        'event_name' => 'item/updated',
        'item_id' => 'item-abc',
    ]);

    Queue::assertPushed(ProcessPluggyWebhook::class, function (ProcessPluggyWebhook $job) {
        return $job->webhookEventId > 0;
    });
});

it('marca webhook duplicado sem redespachar', function () {
    Queue::fake();

    PluggyWebhookEvent::create([
        'event_id' => 'evt-dup',
        'event_name' => 'item/updated',
        'item_id' => 'item-dup',
        'status' => 'processed',
        'payload' => ['event' => 'item/updated'],
        'received_at' => now(),
        'processed_at' => now(),
    ]);

    $response = $this->postJson(route('webhook.pluggy'), [
        'eventId' => 'evt-dup',
        'event' => 'item/updated',
        'itemId' => 'item-dup',
    ]);

    $response->assertOk()->assertJson([
        'received' => true,
        'status' => 'duplicate',
    ]);

    Queue::assertNothingPushed();
});

it('processa item updated sincronizando a conexao', function () {
    $user = User::factory()->create();

    $connection = OpenFinanceConnection::create([
        'user_id' => $user->id,
        'provider' => 'pluggy',
        'item_id' => 'item-sync',
        'connected_at' => now(),
    ]);

    $event = PluggyWebhookEvent::create([
        'event_id' => 'evt-sync',
        'event_name' => 'item/updated',
        'item_id' => 'item-sync',
        'client_user_id' => (string) $user->id,
        'status' => 'received',
        'payload' => [
            'eventId' => 'evt-sync',
            'event' => 'item/updated',
            'itemId' => 'item-sync',
            'clientUserId' => (string) $user->id,
        ],
        'received_at' => now(),
    ]);

    $sync = Mockery::mock(OpenFinanceSyncService::class);
    $sync->shouldReceive('syncConnection')
        ->once()
        ->with(Mockery::on(fn (OpenFinanceConnection $model) => $model->is($connection)))
        ->andReturn([
            'accounts' => 1,
            'cards' => 0,
            'transactions' => 2,
        ]);

    app()->instance(OpenFinanceSyncService::class, $sync);

    app(\App\Services\OpenFinance\PluggyWebhookProcessor::class)->process($event->fresh());

    expect($event->fresh()->status)->toBe('processed');
});
