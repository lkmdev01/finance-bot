<?php

uses(Tests\TestCase::class);

use App\Assistant\Classifiers\IntentClassifier;
use App\Assistant\Context\AssistantContextBuilder;
use App\Assistant\DTO\ActionResultDTO;
use App\Assistant\DTO\AssistantContextDTO;
use App\Assistant\DTO\AssistantResponseDTO;
use App\Assistant\DTO\IncomingMessageDTO;
use App\Assistant\DTO\ParsedIntentDTO;
use App\Assistant\Enums\FinancialIntent;
use App\Assistant\Extractors\FinancialDataExtractor;
use App\Assistant\Orchestrators\FinancialAssistantOrchestrator;
use App\Assistant\Responses\AssistantResponseBuilder;
use App\Assistant\Routing\AssistantActionRouter;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\IncomingMessageNormalizer;

it('orchestrates normalization, context, intent, extraction and routing in order', function () {
    $user = new User(['id' => 1, 'name' => 'Lucas', 'email' => 'lucas@example.com']);
    $contact = new WhatsAppContact(['id' => 1, 'user_id' => 1, 'phone_number' => '5513999999999']);

    $message = new IncomingMessageDTO(rawMessage: '  Gastei 20 no uber  ');

    $context = new AssistantContextDTO(
        user: $user,
        contact: $contact,
        state: [],
        timezone: 'America/Sao_Paulo',
        currentMonth: '2026-06',
        currentYear: 2026,
        lastAction: null,
        pendingAction: null,
    );

    $intent = new ParsedIntentDTO(
        intent: FinancialIntent::CREATE_EXPENSE,
        confidence: 0.96,
        domain: 'transaction',
        legacyKind: 'create_transaction',
        raw: ['kind' => 'create_transaction'],
    );

    $actionResult = new ActionResultDTO(
        preflight: ['handled' => false],
        result: ['action' => 'create_transaction', 'reply' => 'ok'],
        usedAI: true,
    );

    $expectedResponse = new AssistantResponseDTO(
        normalizedMessage: 'Gastei 20 no uber',
        intent: $intent,
        context: $context,
        preflight: $actionResult->preflight,
        result: $actionResult->result,
        usedAI: true,
    );

    $normalizer = Mockery::mock(IncomingMessageNormalizer::class);
    $normalizer->shouldReceive('clean')
        ->once()
        ->with('  Gastei 20 no uber  ')
        ->andReturn('Gastei 20 no uber');

    $contextBuilder = Mockery::mock(AssistantContextBuilder::class);
    $contextBuilder->shouldReceive('build')
        ->once()
        ->with($user, $contact)
        ->andReturn($context);

    $classifier = Mockery::mock(IntentClassifier::class);
    $classifier->shouldReceive('classify')
        ->once()
        ->with('Gastei 20 no uber', $context)
        ->andReturn($intent);

    $extractor = Mockery::mock(FinancialDataExtractor::class);
    $extractor->shouldReceive('extract')
        ->once()
        ->with('Gastei 20 no uber', $intent, $context)
        ->andReturn(['amount' => 20]);

    $router = Mockery::mock(AssistantActionRouter::class);
    $router->shouldReceive('execute')
        ->once()
        ->withArgs(function (IncomingMessageDTO $incoming, AssistantContextDTO $incomingContext, ParsedIntentDTO $incomingIntent, array $data) use ($context, $intent) {
            return $incoming->normalizedMessage === 'Gastei 20 no uber'
                && $incomingContext === $context
                && $incomingIntent === $intent
                && $data['amount'] === 20;
        })
        ->andReturn($actionResult);

    $builder = Mockery::mock(AssistantResponseBuilder::class);
    $builder->shouldReceive('build')
        ->once()
        ->withArgs(function (IncomingMessageDTO $incoming, AssistantContextDTO $incomingContext, ParsedIntentDTO $incomingIntent, ActionResultDTO $incomingResult) use ($context, $intent, $actionResult) {
            return $incoming->normalizedMessage === 'Gastei 20 no uber'
                && $incomingContext === $context
                && $incomingIntent === $intent
                && $incomingResult === $actionResult;
        })
        ->andReturn($expectedResponse);

    $orchestrator = new FinancialAssistantOrchestrator(
        normalizer: $normalizer,
        contextBuilder: $contextBuilder,
        intentClassifier: $classifier,
        dataExtractor: $extractor,
        actionRouter: $router,
        responseBuilder: $builder,
    );

    $response = $orchestrator->handle($user, $contact, $message);

    expect($response)->toBe($expectedResponse);
});
