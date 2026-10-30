<?php

use App\Ai\AgendaAgent;
use App\Ai\AgentContextInjector;
use App\Ai\FinancialAgent;
use App\Ai\MeetingAgent;
use App\Ai\ResearchAnswerAgent;
use App\Ai\TaskAgent;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\AIPromptBuilder;
use App\Services\AssistantToneService;
use Livewire\Volt\Volt;

it('offers a per-user assistant tone setting with a friendly default', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);
    $this->withoutVite();

    $this->get(route('assistant.personality.settings'))
        ->assertOk()
        ->assertSee('Tom do assistente')
        ->assertSee('Sarcástico / sem papas na língua');

    Volt::test('settings.assistant-personality')
        ->set('assistant_tone', 'rude')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('assistant-tone-saved');

    expect($user->fresh()->assistant_tone)->toBe('rude');
});

it('rejects unknown tone values and keeps the saved preference unchanged', function (): void {
    $user = User::factory()->create(['assistant_tone' => 'professional']);
    $this->actingAs($user);

    Volt::test('settings.assistant-personality')
        ->set('assistant_tone', 'abusive')
        ->call('save')
        ->assertHasErrors(['assistant_tone']);

    expect($user->fresh()->assistant_tone)->toBe('professional');
});

it('uses a sharper sarcastic tone while keeping care in sensitive contexts', function (): void {
    $service = app(AssistantToneService::class);
    $instruction = $service->instruction(new User(['assistant_tone' => 'rude']));

    expect($instruction)
        ->toContain('sarcástico, rebelde, afiado e sem papas na língua')
        ->toContain('apontar contradições')
        ->toContain('nunca à dignidade da pessoa')
        ->toContain('assédio, humilhação, discriminação')
        ->toContain('assuntos financeiros, de saúde, segurança');
});

it('applies the selected tone to each specialized agent and the legacy prompt', function (): void {
    $user = new User(['assistant_tone' => 'direct']);
    $contact = new WhatsAppContact(['id' => 8, 'user_id' => 7]);

    expect((string) app(AgendaAgent::class)->forUser($user)->instructions())->toContain('tom direto e conciso')
        ->and((string) app(TaskAgent::class)->forUser($user)->instructions())->toContain('tom direto e conciso')
        ->and((string) app(MeetingAgent::class)->forUser($user)->instructions())->toContain('tom direto e conciso')
        ->and((string) app(ResearchAnswerAgent::class)->forUser($user)->instructions())->toContain('tom direto e conciso');

    $contextInjector = Mockery::mock(AgentContextInjector::class);
    $contextInjector->shouldReceive('getContextString')->once()->with($user, $contact)->andReturn('contexto de teste');
    $financial = new FinancialAgent($user, $contact, $contextInjector);
    expect((string) $financial->instructions())->toContain('Preferência de tom: Use um tom direto e conciso');

    $legacyPrompt = app(AIPromptBuilder::class)->build('olá', [
        'user_name' => 'Pessoa Exemplo',
        'assistant_tone_instruction' => 'Use um tom direto e conciso.',
    ]);
    expect($legacyPrompt)->toContain('Use um tom direto e conciso.');
});

it('applies the saved sarcastic tone to every agent domain', function (): void {
    $user = new User(['assistant_tone' => 'rude']);
    $contact = new WhatsAppContact(['id' => 8, 'user_id' => 7]);
    $toneInstruction = 'sarcástico, rebelde, afiado e sem papas na língua';

    expect((string) app(AgendaAgent::class)->forUser($user)->instructions())->toContain($toneInstruction)
        ->and((string) app(TaskAgent::class)->forUser($user)->instructions())->toContain($toneInstruction)
        ->and((string) app(MeetingAgent::class)->forUser($user)->instructions())->toContain($toneInstruction)
        ->and((string) app(ResearchAnswerAgent::class)->forUser($user)->instructions())->toContain($toneInstruction);

    $contextInjector = Mockery::mock(AgentContextInjector::class);
    $contextInjector->shouldReceive('getContextString')->once()->with($user, $contact)->andReturn('contexto de teste');
    $financial = new FinancialAgent($user, $contact, $contextInjector);
    expect((string) $financial->instructions())->toContain('Preferência de tom: Use um tom '.$toneInstruction);

    $legacyPrompt = app(AIPromptBuilder::class)->build('olá', [
        'user_name' => 'Pessoa Exemplo',
        'assistant_tone_instruction' => 'Use um tom '.$toneInstruction.'.',
    ]);
    expect($legacyPrompt)->toContain($toneInstruction);
});

it('uses the friendly instruction when no user preference is set', function (): void {
    expect(app(AssistantToneService::class)->instruction(null))
        ->toBe('Use um tom amigável, natural e prestativo.');
});
