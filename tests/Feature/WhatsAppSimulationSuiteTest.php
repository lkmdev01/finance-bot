<?php

use App\Services\WhatsApp\SimulationSuiteService;

it('executes curated whatsapp simulation suites without manual messaging', function () {
    $report = app(SimulationSuiteService::class)->runAll(persistData: true);

    if (($report['all_passed'] ?? false) !== true) {
        $failed = collect($report['results'] ?? [])->first(fn (array $result) => ($result['passed'] ?? false) !== true);

        throw new RuntimeException(json_encode($failed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    expect($report['suite_count'])->toBeGreaterThan(0)
        ->and($report['failed_count'])->toBe(0)
        ->and($report['passed_count'])->toBe($report['suite_count']);
});
