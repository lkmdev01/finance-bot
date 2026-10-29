<?php

use App\Models\ResearchQuery;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\Research\WebResearchService;
use App\Services\WhatsApp\ResearchCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function researchContact(User $user): WhatsAppContact
{
    return WhatsAppContact::factory()->create([
        'user_id' => $user->id,
        'phone_number' => '5511999000000',
    ]);
}

it('answers capability questions clearly without falling through to the finance agent', function (): void {
    config(['services.searxng.enabled' => false, 'services.searxng.base_url' => null]);
    Queue::fake();
    $user = User::factory()->create();

    $reply = app(ResearchCommandService::class)->handle('você consegue fazer pesquisa?', $user, researchContact($user));

    expect($reply)->toContain('está desativada')
        ->and(ResearchQuery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('recognizes a direct request addressed to Italo without requiring a research verb', function (): void {
    config(['services.searxng.enabled' => false, 'services.searxng.base_url' => null]);
    $user = User::factory()->create();

    $reply = app(ResearchCommandService::class)->handle('Ítalo, quanto custa um notebook intermediário?', $user, researchContact($user));

    expect($reply)->toContain('não está habilitada');
});

it('intercepts research requests in the orchestrator before the financial agent', function (): void {
    config(['services.searxng.enabled' => false, 'services.searxng.base_url' => null]);
    $user = User::factory()->create();
    $contact = researchContact($user);

    $result = app(\App\Services\WhatsApp\ConversationOrchestrator::class)
        ->beforeAI('você consegue fazer pesquisa?', $user, $contact);

    expect($result)->toMatchArray([
        'handled' => true,
        'classification' => 'research_command',
        'domain' => 'research',
    ])->and($result['reply'])->toContain('está desativada');
});

it('stores an encrypted user-scoped request and queues public research', function (): void {
    config(['services.searxng.enabled' => true, 'services.searxng.base_url' => 'https://search.example.test']);
    Queue::fake();
    $user = User::factory()->create(['name' => 'Pessoa Exemplo']);
    $contact = researchContact($user);

    $reply = app(ResearchCommandService::class)->handle('Ítalo, pesquisa preço médio do notebook para Pessoa Exemplo, 5511999000000', $user, $contact);

    $research = ResearchQuery::query()->sole();
    expect($reply)->toContain('assim que estiver pronto')
        ->and($research->user_id)->toBe($user->id)
        ->and($research->contact->is($contact))->toBeTrue()
        ->and($research->query)->toBe('Ítalo, pesquisa preço médio do notebook para Pessoa Exemplo, 5511999000000')
        ->and($research->search_query)->toBe('preço médio do notebook para [nome removido], [contato removido]')
        ->and(DB::table('research_queries')->where('id', $research->id)->value('query'))->not->toBe($research->query)
        ->and(DB::table('research_queries')->where('id', $research->id)->value('search_query'))->not->toBe($research->search_query);

    Queue::assertPushed(\App\Jobs\RunWebResearch::class, fn ($job) => $job->researchQueryId === $research->id);
});

it('keeps private account questions outside the web search provider', function (): void {
    config(['services.searxng.enabled' => true, 'services.searxng.base_url' => 'https://search.example.test']);
    Queue::fake();
    $user = User::factory()->create();

    $reply = app(ResearchCommandService::class)->handle('pesquise meu saldo e meus gastos', $user, researchContact($user));

    expect($reply)->toContain('Não envio saldo')
        ->and(ResearchQuery::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('normalizes provider results into safe source links', function (): void {
    config(['services.searxng.enabled' => true, 'services.searxng.base_url' => 'https://search.example.test']);
    Http::fake(['search.example.test/search*' => Http::response([
        'results' => [
            ['title' => '<b>Fonte confiável</b>', 'url' => 'https://example.test/article', 'content' => '<p>Informação pública</p>'],
            ['title' => 'descartada', 'url' => 'javascript:alert(1)', 'content' => 'não segura'],
        ],
    ])]);

    $sources = app(WebResearchService::class)->search('preço médio de notebook');

    expect($sources)->toHaveCount(1)
        ->and($sources[0])->toMatchArray([
            'title' => 'Fonte confiável',
            'url' => 'https://example.test/article',
            'domain' => 'example.test',
            'snippet' => 'Informação pública',
        ]);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://search.example.test/search?')
        && $request['format'] === 'json'
        && $request['language'] === 'pt-BR');
});

it('summarizes grounded sources once and sends the report through WhatsApp delivery', function (): void {
    config(['services.searxng.enabled' => true, 'services.searxng.base_url' => 'https://search.example.test']);
    Http::fake(['search.example.test/search*' => Http::response([
        'results' => [[
            'title' => 'Cotação de referência',
            'url' => 'https://example.test/cotacao',
            'content' => 'A cotação indicada na página é R$ 5,00.',
        ]],
    ])]);
    $user = User::factory()->create();
    $contact = researchContact($user);
    $research = ResearchQuery::query()->create([
        'user_id' => $user->id,
        'whatsapp_contact_id' => $contact->id,
        'query' => 'cotação do dólar hoje',
        'search_query' => 'cotação do dólar hoje',
        'status' => 'in_progress',
        'lease_expires_at' => now()->subSecond(),
    ]);

    $ai = Mockery::mock(\App\Services\SpecializedAiFallbackService::class);
    $ai->shouldReceive('prompt')->once()
        ->with(Mockery::on(fn (User $candidate) => $candidate->id === $user->id), Mockery::type(\App\Ai\ResearchAnswerAgent::class), Mockery::type('string'), 'research')
        ->andReturn(['answer' => 'A fonte consultada informa R$ 5,00 [1].']);
    app()->instance(\App\Services\SpecializedAiFallbackService::class, $ai);

    $delivery = Mockery::mock(\App\Services\WhatsApp\WhatsAppReplyDelivery::class);
    $delivery->shouldReceive('send')->once()->with('5511999000000@s.whatsapp.net', Mockery::on(fn (string $body) => str_contains($body, 'example.test/cotacao')), $user->id);
    app()->instance(\App\Services\WhatsApp\WhatsAppReplyDelivery::class, $delivery);

    $job = new \App\Jobs\RunWebResearch($research->id);
    $job->handle(app(WebResearchService::class), $ai, $delivery, app(\App\Services\PhoneNumberService::class));
    $job->handle(app(WebResearchService::class), $ai, $delivery, app(\App\Services\PhoneNumberService::class));

    expect($research->fresh()->status)->toBe('delivered')
        ->and($research->fresh()->answer)->toContain('[1]')
        ->and($research->fresh()->sources)->toHaveCount(1);
    Http::assertSentCount(1);
});

it('shows only the signed-in user research history and refuses deleting another users entry', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $owned = ResearchQuery::query()->create([
        'user_id' => $owner->id,
        'query' => 'pesquisa pública do proprietário',
        'search_query' => 'pesquisa pública do proprietário',
        'answer' => 'Resposta da própria pesquisa.',
        'sources' => [['title' => 'Fonte', 'domain' => 'example.test', 'url' => 'https://example.test/source', 'snippet' => 'Trecho público']],
        'status' => 'delivered',
    ]);
    $foreign = ResearchQuery::query()->create([
        'user_id' => $other->id,
        'query' => 'pesquisa privada de outra conta',
        'search_query' => 'pesquisa privada de outra conta',
        'status' => 'pending',
    ]);

    $this->actingAs($owner)->get(route('research.index'))
        ->assertOk()
        ->assertSee('pesquisa pública do proprietário')
        ->assertDontSee('pesquisa privada de outra conta')
        ->assertSee('https://example.test/source');

    $this->delete(route('research.destroy', $foreign))->assertNotFound();
    expect($owned->fresh())->not->toBeNull()
        ->and($foreign->fresh())->not->toBeNull();
});
