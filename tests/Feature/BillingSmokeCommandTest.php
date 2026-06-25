<?php

test('billing smoke passa com mensal e anual recorrentes configurados', function () {
    config([
        'billing.subscription_methods' => ['CARD'],
        'billing.plans.pro_monthly.product_id' => 'prod_monthly',
        'billing.plans.pro_monthly.checkout_flow' => 'subscription',
        'billing.plans.pro_monthly.frequency' => 'MONTHLY',
        'billing.plans.pro_yearly.product_id' => 'prod_yearly',
        'billing.plans.pro_yearly.checkout_flow' => 'subscription',
        'billing.plans.pro_yearly.frequency' => 'YEARLY',
    ]);

    $this->artisan('billing:smoke')
        ->assertExitCode(0);
});

test('billing smoke falha quando plano anual nao esta recorrente', function () {
    config([
        'billing.subscription_methods' => ['CARD'],
        'billing.plans.pro_monthly.product_id' => 'prod_monthly',
        'billing.plans.pro_monthly.checkout_flow' => 'subscription',
        'billing.plans.pro_yearly.product_id' => 'prod_yearly',
        'billing.plans.pro_yearly.checkout_flow' => 'checkout',
    ]);

    $this->artisan('billing:smoke', ['--json' => true])
        ->expectsOutputToContain('"ok": false')
        ->assertExitCode(1);
});

test('billing smoke permite ignorar product ids em ambientes locais', function () {
    config([
        'billing.subscription_methods' => ['CARD'],
        'billing.plans.pro_monthly.product_id' => null,
        'billing.plans.pro_monthly.checkout_flow' => 'subscription',
        'billing.plans.pro_yearly.product_id' => null,
        'billing.plans.pro_yearly.checkout_flow' => 'subscription',
    ]);

    $this->artisan('billing:smoke', ['--allow-missing-products' => true])
        ->assertExitCode(0);
});
