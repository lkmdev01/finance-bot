<?php

use Illuminate\Support\Facades\File;

it('runs the whatsapp suite command and exports transcripts per journey', function () {
    $outputDirectory = storage_path('app/testing/whatsapp-test-suite-command');
    File::deleteDirectory($outputDirectory);

    $this->artisan('whatsapp:test-suite', [
        '--output-dir' => $outputDirectory,
    ])
        ->expectsOutputToContain('WhatsApp test suite executada.')
        ->expectsOutputToContain('Suites executadas:')
        ->expectsOutputToContain('Transcripts:')
        ->assertSuccessful();

    expect(File::exists($outputDirectory.DIRECTORY_SEPARATOR.'summary.json'))->toBeTrue();

    $files = collect(File::files($outputDirectory))->map->getFilename()->values()->all();

    expect($files)->toContain('summary.json')
        ->and(count($files))->toBeGreaterThan(1);

    $summary = json_decode((string) File::get($outputDirectory.DIRECTORY_SEPARATOR.'summary.json'), true);

    expect($summary['all_passed'])->toBeTrue()
        ->and($summary['suite_count'])->toBeGreaterThan(0)
        ->and($summary['failed_count'])->toBe(0);

    File::deleteDirectory($outputDirectory);
});
