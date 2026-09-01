<?php

use Illuminate\Support\Facades\Http;

it('mostra configuracao de ia sem chamada externa', function () {
    config()->set('ai.provider', 'groq');
    config()->set('ai.api_key', 'test-key');
    config()->set('ai.groq.model', 'llama-3.1-8b-instant');
    config()->set('ai.drive_metadata.provider', 'groq');
    config()->set('ai.drive_metadata.api_key', 'test-drive-key');
    config()->set('ai.drive_metadata.vision_model', 'meta-llama/llama-4-scout-17b-16e-instruct');
    config()->set('ai.drive_metadata.metadata_model', 'llama-3.1-8b-instant');

    Http::fake();

    $this->artisan('ai:smoke --drive')
        ->expectsOutput('AI smoke check')
        ->expectsOutput('[principal] provider=groq model=openai/gpt-oss-20b key=configurada')
        ->expectsOutput('[drive_metadata] provider=groq model=openai/gpt-oss-20b key=configurada')
        ->expectsOutput('[drive_vision] provider=groq model=qwen/qwen3.6-27b key=configurada')
        ->expectsOutput('Use --live para fazer uma chamada real na API.')
        ->assertExitCode(0);

    Http::assertNothingSent();
});

it('faz smoke live usando api compativel com openai', function () {
    config()->set('ai.provider', 'groq');
    config()->set('ai.api_key', 'test-key');
    config()->set('ai.groq.model', 'openai/gpt-oss-20b');
    config()->set('ai.drive_metadata.provider', 'groq');
    config()->set('ai.drive_metadata.api_key', 'test-drive-key');
    config()->set('ai.drive_metadata.vision_model', 'qwen/qwen3.6-27b');
    config()->set('ai.drive_metadata.metadata_model', 'openai/gpt-oss-20b');

    Http::fake([
        'api.groq.com/openai/v1/chat/completions' => Http::response([
            'choices' => [
                ['message' => ['content' => '{"ok":true}']],
            ],
        ]),
    ]);

    $this->artisan('ai:smoke --drive --live')
        ->expectsOutputToContain('[OK] principal')
        ->expectsOutputToContain('[OK] drive_metadata')
        ->expectsOutputToContain('[OK] drive_vision')
        ->assertExitCode(0);

    Http::assertSentCount(3);
});

it('aceita retry simples quando json mode falha no smoke live', function () {
    config()->set('ai.provider', 'groq');
    config()->set('ai.api_key', 'test-key');
    config()->set('ai.groq.model', 'openai/gpt-oss-20b');
    config()->set('ai.drive_metadata.provider', 'groq');
    config()->set('ai.drive_metadata.api_key', 'test-drive-key');
    config()->set('ai.drive_metadata.vision_model', 'qwen/qwen3.6-27b');
    config()->set('ai.drive_metadata.metadata_model', 'openai/gpt-oss-20b');

    Http::fake([
        'api.groq.com/openai/v1/chat/completions' => Http::sequence()
            ->push([
                'choices' => [
                    ['message' => ['content' => 'ok']],
                ],
            ], 200)
            ->push([
                'error' => ['message' => 'Failed to validate JSON. Please adjust your prompt.'],
            ], 400)
            ->push([
                'choices' => [
                    ['message' => ['content' => '{"ok":true}']],
                ],
            ], 200)
            ->push([
                'error' => ['message' => 'Failed to validate JSON. Please adjust your prompt.'],
            ], 400)
            ->push([
                'choices' => [
                    ['message' => ['content' => '{"ok":true}']],
                ],
            ], 200),
    ]);

    $this->artisan('ai:smoke --drive --live')
        ->expectsOutputToContain('[OK] principal')
        ->expectsOutputToContain('[WARN] drive_metadata')
        ->expectsOutputToContain('[WARN] drive_vision')
        ->assertExitCode(0);

    Http::assertSentCount(5);
});
