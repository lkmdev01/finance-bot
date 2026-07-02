<?php

test('billing smoke passa com oferta unica mensal configurada', function () {
    config([
        'billing.subscription_methods' => ['CARD'],
        'billing.plans.pro_monthly.product_id' => 'prod_monthly',
        'billing.plans.pro_monthly.checkout_flow' => 'subscription',
        'billing.plans.pro_monthly.frequency' => 'MONTHLY',
        'billing.plans.pro_monthly.price_cents' => 1997,
        'billing.plans.pro_monthly.sellable' => true,
        'billing.plans.pro_monthly.visible' => true,
        'billing.plans.pro_yearly.sellable' => false,
        'billing.plans.pro_yearly.visible' => false,
    ]);

    $this->artisan('billing:smoke')
        ->assertExitCode(0);
});

test('billing smoke falha quando plano anual continua vendavel', function () {
    config([
        'billing.subscription_methods' => ['CARD'],
        'billing.plans.pro_monthly.product_id' => 'prod_monthly',
        'billing.plans.pro_monthly.checkout_flow' => 'subscription',
        'billing.plans.pro_monthly.frequency' => 'MONTHLY',
        'billing.plans.pro_monthly.price_cents' => 1997,
        'billing.plans.pro_monthly.sellable' => true,
        'billing.plans.pro_monthly.visible' => true,
        'billing.plans.pro_yearly.product_id' => 'prod_yearly',
        'billing.plans.pro_yearly.checkout_flow' => 'checkout',
        'billing.plans.pro_yearly.sellable' => true,
        'billing.plans.pro_yearly.visible' => true,
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
        'billing.plans.pro_monthly.frequency' => 'MONTHLY',
        'billing.plans.pro_monthly.price_cents' => 1997,
        'billing.plans.pro_monthly.sellable' => true,
        'billing.plans.pro_monthly.visible' => true,
        'billing.plans.pro_yearly.product_id' => null,
        'billing.plans.pro_yearly.checkout_flow' => 'subscription',
        'billing.plans.pro_yearly.sellable' => false,
        'billing.plans.pro_yearly.visible' => false,
    ]);

    $this->artisan('billing:smoke', ['--allow-missing-products' => true])
        ->assertExitCode(0);
});
