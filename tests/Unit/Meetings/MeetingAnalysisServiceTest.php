<?php

use App\Ai\MeetingAgent;
use App\Models\Meeting;
use App\Models\User;
use App\Services\AiUserTokenBudget;
use App\Services\Meetings\MeetingAnalysisService;
use Illuminate\Support\Carbon;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredAgentResponse;

uses(Tests\TestCase::class);

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'vexa.transcription_min_words' => 1,
        'vexa.transcription_min_speech_seconds' => 1,
    ]);
    app()->forgetInstance('encrypter');
});

function meetingAnalysisModel(array $dates = []): Meeting
{
    $meeting = new class extends Meeting
    {
        public function save(array $options = []): bool
        {
            return true;
        }
    };

    foreach ($dates as $attribute => $value) {
        $meeting->setAttribute($attribute, Carbon::parse($value));
    }

    return $meeting;
}

function analyzeMeetingWithResponse(array $data, array $dates, string $transcriptText, ?Closure $assertPrompt = null): string
{
    $budget = Mockery::mock(AiUserTokenBudget::class);
    $budget->shouldReceive('run')
        ->once()
        ->with(Mockery::type(User::class), Mockery::type(Closure::class), 'meetings')
        ->andReturnUsing(fn (User $user, Closure $operation, string $agent) => $operation());
    app()->instance(AiUserTokenBudget::class, $budget);

    $agent = Mockery::mock(MeetingAgent::class);
    $expectation = $agent->shouldReceive('prompt')->once();
    if ($assertPrompt !== null) {
        $expectation->withArgs(function (string $prompt) use ($assertPrompt): bool {
            $assertPrompt($prompt);

            return true;
        });
    } else {
        $expectation->with(Mockery::type('string'));
    }
    $expectation->andReturn(new StructuredAgentResponse(
        'meeting-analysis-test',
        $data,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        new Usage,
        new Meta,
    ));
    app()->instance(MeetingAgent::class, $agent);

    $meeting = meetingAnalysisModel($dates);
    $meeting->setRelation('user', new User(['id' => 1]));

    return app(MeetingAnalysisService::class)->analyze(
        $meeting,
        [['start' => 0, 'end' => 40, 'text' => $transcriptText]],
    );
}

function meetingReportData(array $tasks = [], array $overrides = []): array
{
    return array_merge([
        'summary' => 'Resumo baseado na transcrição.',
        'decisions' => [],
        'positives' => [],
        'negatives' => [],
        'opportunities' => [],
        'tasks' => $tasks,
    ], $overrides);
}

it('passes started_at as the meeting reference date ahead of completion and creation dates', function () {
    $report = analyzeMeetingWithResponse(
        meetingReportData(),
        ['started_at' => '2026-02-03 10:00:00', 'completed_at' => '2026-02-04 11:00:00', 'created_at' => '2026-02-01 09:00:00'],
        'A equipe discutiu o objetivo comercial e encerrou a reunião.',
        fn (string $prompt) => expect($prompt)->toContain('Data de referência da reunião: 2026-02-03'),
    );

    expect($report)->toContain('*Resumo da reunião*');
});

it('falls back from started_at to completed_at and then created_at for the reference date', function () {
    analyzeMeetingWithResponse(
        meetingReportData(),
        ['completed_at' => '2026-04-12 11:00:00', 'created_at' => '2026-04-10 09:00:00'],
        'A equipe discutiu o objetivo comercial e encerrou a reunião.',
        fn (string $prompt) => expect($prompt)->toContain('Data de referência da reunião: 2026-04-12'),
    );

    analyzeMeetingWithResponse(
        meetingReportData(),
        ['created_at' => '2026-06-07 09:00:00'],
        'A equipe discutiu o objetivo comercial e encerrou a reunião.',
        fn (string $prompt) => expect($prompt)->toContain('Data de referência da reunião: 2026-06-07'),
    );
});

it('preserves a day and month phrase and rejects a year the transcript does not state', function () {
    $report = analyzeMeetingWithResponse(
        meetingReportData([[
            'task' => 'Enviar a proposta até 15 de maio de 2027.',
            'owner' => null,
            'due_date' => '2027-05-15',
        ]]),
        ['started_at' => '2026-10-23'],
        'Enviar a proposta até 15 de maio. A equipe confirmou o trabalho e combinou os próximos passos.',
    );

    expect($report)->toContain('Enviar a proposta até 15 de maio.')
        ->and($report)->not->toContain('15 de maio de 2027')
        ->and($report)->not->toContain('2027-05-15');
});

it('accepts an ISO due date only when the task and transcript both state the same full date', function () {
    $report = analyzeMeetingWithResponse(
        meetingReportData([[
            'task' => 'Ana enviará a proposta até 15/05/2027.',
            'owner' => 'Ana',
            'due_date' => '2027-05-15',
        ]]),
        ['started_at' => '2026-10-23'],
        'Ana enviará a proposta até 15/05/2027. A equipe confirmou a entrega e revisará o documento depois.',
    );

    expect($report)->toContain('Ana enviará a proposta até 15/05/2027. (Ana; 2027-05-15)');
});

it('accepts a complete date written out in Portuguese without requiring an ISO date in the transcript', function () {
    $report = analyzeMeetingWithResponse(
        meetingReportData([[
            'task' => 'Enviar a proposta até 10 de novembro de 2026.',
            'owner' => null,
            'due_date' => '2026-11-10',
        ]]),
        ['started_at' => '2026-10-23'],
        'A equipe combinou enviar a proposta até 10 de novembro de 2026.',
    );

    expect($report)->toContain('Enviar a proposta até 10 de novembro de 2026. (2026-11-10)');
});

it('removes an unsupported full date from task wording and marks it unconfirmed', function () {
    $report = analyzeMeetingWithResponse(
        meetingReportData([[
            'task' => 'Enviar a proposta até 18/09/2027.',
            'owner' => null,
            'due_date' => '2027-09-18',
        ]]),
        ['started_at' => '2026-10-23'],
        'A equipe confirmou que a proposta será enviada, mas não definiu uma data.',
    );

    expect($report)->toContain('[data não confirmada na transcrição]')
        ->and($report)->not->toContain('18/09/2027')
        ->and($report)->not->toContain('2027-09-18');
});

it('does not classify a commercial goal as an assumed task and preserves the existing WhatsApp sections', function () {
    $instructions = app(MeetingAgent::class)->instructions();
    expect((string) $instructions)->toContain('Meta é um resultado desejado')
        ->and((string) $instructions)->toContain('Tarefa/compromisso só existe quando')
        ->and((string) $instructions)->toContain('Não transforme sugestões, possibilidades, perguntas ou metas em tarefas');

    $report = analyzeMeetingWithResponse(
        meetingReportData([], ['opportunities' => ['A meta comercial é aumentar as vendas em 20%.']]),
        ['started_at' => '2026-10-23'],
        'A meta comercial é aumentar as vendas em 20%. A equipe não atribuiu nenhuma ação a uma pessoa.',
    );

    expect($report)->toContain('*Oportunidades*')
        ->and($report)->toContain('A meta comercial é aumentar as vendas em 20%.')
        ->and($report)->not->toContain('*Tarefas e compromissos*');
});

it('reports a real task without inventing a responsible person', function () {
    $report = analyzeMeetingWithResponse(
        meetingReportData([[
            'task' => 'Revisar a integração do webhook e enviar o resultado.',
            'owner' => 'Carlos',
            'due_date' => null,
        ]]),
        ['started_at' => '2026-10-23'],
        'A equipe combinou revisar a integração do webhook e enviar o resultado. Nenhum responsável foi definido.',
    );

    expect($report)->toContain('• Revisar a integração do webhook e enviar o resultado.')
        ->and($report)->not->toContain('(;')
        ->and($report)->not->toContain('Responsável:');
});

it('preserves uncertain technical wording in the prompt and report task text', function () {
    expect((string) app(MeetingAgent::class)->instructions())
        ->toContain('Não corrija, expanda, traduza ou substitua silenciosamente');

    $report = analyzeMeetingWithResponse(
        meetingReportData([[
            'task' => 'Validar o “token de conciliação” citado como incerto.',
            'owner' => null,
            'due_date' => null,
        ]]),
        ['started_at' => '2026-10-23'],
        'Foi mencionado um “token de conciliação”, mas a expressão ficou incerta; a equipe combinou validar depois.',
    );

    expect($report)->toContain('“token de conciliação” citado como incerto');
});
