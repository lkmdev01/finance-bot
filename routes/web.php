<?php

use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Livewire\Volt\Volt;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::view('/politica-de-privacidade', 'pages.privacy-policy')->name('privacy-policy');
Route::view('/termos-de-uso', 'pages.terms-of-use')->name('terms-of-use');

Route::get('/sitemap.xml', function () {
    $urls = collect([
        [
            'loc' => route('home'),
            'lastmod' => now()->toDateString(),
            'changefreq' => 'weekly',
            'priority' => '1.0',
        ],
        [
            'loc' => route('privacy-policy'),
            'lastmod' => now()->toDateString(),
            'changefreq' => 'yearly',
            'priority' => '0.4',
        ],
        [
            'loc' => route('terms-of-use'),
            'lastmod' => now()->toDateString(),
            'changefreq' => 'yearly',
            'priority' => '0.4',
        ],
    ]);

    if (Route::has('login')) {
        $urls->push([
            'loc' => route('login'),
            'lastmod' => now()->toDateString(),
            'changefreq' => 'monthly',
            'priority' => '0.7',
        ]);
    }

    if (Route::has('register')) {
        $urls->push([
            'loc' => route('register'),
            'lastmod' => now()->toDateString(),
            'changefreq' => 'monthly',
            'priority' => '0.8',
        ]);
    }

    $xml = view('sitemap', ['urls' => $urls]);

    return response($xml, 200)->header('Content-Type', 'application/xml');
})->name('sitemap');

Route::middleware('guest')->group(function () {
    Route::get('/auth/google/redirect', [App\Http\Controllers\Auth\GoogleAuthController::class, 'redirect'])
        ->name('google.redirect');

    Route::get('/auth/google/callback', [App\Http\Controllers\Auth\GoogleAuthController::class, 'callback'])
        ->name('google.callback');
});

Route::middleware('auth')->group(function () {
    Route::get('/auth/whatsapp-activation', [App\Http\Controllers\Auth\WhatsAppActivationController::class, 'show'])
        ->name('whatsapp.activation.show');
    Route::post('/auth/whatsapp-activation/phone', [App\Http\Controllers\Auth\WhatsAppActivationController::class, 'updatePhone'])
        ->name('whatsapp.activation.phone');
    Route::post('/auth/whatsapp-activation/complete', [App\Http\Controllers\Auth\WhatsAppActivationController::class, 'complete'])
        ->name('whatsapp.activation.complete');

    // Google Drive integration (separate OAuth flow to request Drive scopes).
    Route::get('/auth/google-drive/redirect', [App\Http\Controllers\Auth\GoogleDriveAuthController::class, 'redirect'])
        ->name('google-drive.redirect');
    Route::get('/auth/google-drive/callback', [App\Http\Controllers\Auth\GoogleDriveAuthController::class, 'callback'])
        ->name('google-drive.callback');
});

Route::view('dashboard', 'pages.dashboard')
    ->middleware(['auth', 'verified', 'whatsapp.activated'])
    ->name('dashboard');

Route::middleware(['auth', 'whatsapp.activated'])->group(function () {
    Route::get('integrations/google-drive', [App\Http\Controllers\Integrations\GoogleDriveController::class, 'index'])
        ->name('integrations.google-drive');
    Route::post('integrations/google-drive/disconnect', [App\Http\Controllers\Integrations\GoogleDriveController::class, 'disconnect'])
        ->name('integrations.google-drive.disconnect');
    Route::get('integrations/open-finance', [App\Http\Controllers\Integrations\OpenFinanceController::class, 'index'])
        ->name('integrations.open-finance');
    Route::post('integrations/open-finance/connect-token', [App\Http\Controllers\Integrations\OpenFinanceController::class, 'createConnectToken'])
        ->name('integrations.open-finance.connect-token');
    Route::post('integrations/open-finance/connections', [App\Http\Controllers\Integrations\OpenFinanceController::class, 'storeConnection'])
        ->name('integrations.open-finance.connections.store');
    Route::post('integrations/open-finance/{openFinanceConnection}/sync', [App\Http\Controllers\Integrations\OpenFinanceController::class, 'sync'])
        ->name('integrations.open-finance.sync');
    Route::post('integrations/open-finance/{openFinanceConnection}/disconnect', [App\Http\Controllers\Integrations\OpenFinanceController::class, 'disconnect'])
        ->name('integrations.open-finance.disconnect');

    Route::get('billing/plans', [App\Http\Controllers\BillingPlanController::class, 'index'])->name('billing.plans');
    Route::get('billing/plans/{planCode}/checkout-data', [App\Http\Controllers\BillingPlanController::class, 'showCheckoutData'])->name('billing.checkout-data.show');
    Route::post('billing/plans/{planCode}/checkout-data', [App\Http\Controllers\BillingPlanController::class, 'storeCheckoutData'])->name('billing.checkout-data.store');
    Route::post('billing/plans/{planCode}/subscribe', [App\Http\Controllers\BillingPlanController::class, 'subscribe'])->name('billing.subscribe');
    Route::post('billing/subscription/cancel', [App\Http\Controllers\BillingSubscriptionController::class, 'cancel'])->name('billing.subscription.cancel');
    Route::post('billing/abacatepay/transparents/pix', [App\Http\Controllers\AbacatePayChargeController::class, 'createTransparentPix'])
        ->name('billing.abacatepay.transparents.pix');
    // Transacoes
    Route::get('transactions', function () {
        return view('pages.transactions.index');
    })->name('transactions.index');

    Route::get('transactions/create', function () {
        return view('pages.transactions.create');
    })->middleware('billing.writable')->name('transactions.create');

    Route::get('transactions/{transaction}/edit', function (App\Models\Transaction $transaction) {
        return view('pages.transactions.edit', ['transaction' => $transaction]);
    })->name('transactions.edit');

    Route::get('transactions/import', function () {
        return view('pages.transactions.import');
    })->middleware('billing.writable')->name('transactions.import');

    Route::get('transactions/duplicates', function () {
        return view('pages.transactions.duplicates');
    })->name('transactions.duplicates');

    // Categorias
    Route::get('categories', function () {
        return view('pages.categories.index');
    })->name('categories.index');

    Route::get('categories/create', function () {
        return view('pages.categories.create');
    })->middleware('billing.writable')->name('categories.create');

    Route::get('categories/{category}/edit', function (App\Models\Category $category) {
        return view('pages.categories.edit', ['category' => $category]);
    })->name('categories.edit');

    // Orcamentos
    Route::get('budgets', function () {
        return view('pages.budgets.index');
    })->name('budgets.index');

    Route::get('budgets/create', function () {
        return view('pages.budgets.create');
    })->middleware('billing.writable')->name('budgets.create');

    Route::get('budgets/{budget}/edit', function (App\Models\Budget $budget) {
        return view('pages.budgets.edit', ['budget' => $budget]);
    })->name('budgets.edit');

    // Relatorios
    Route::get('reports', function () {
        return view('pages.reports.index');
    })->name('reports.index');

    Route::middleware('can:viewAssistantObservability')->group(function () {
        Route::get('assistant/observability', \App\Http\Controllers\AssistantObservabilityController::class)
            ->name('assistant.observability');
        Route::post('assistant/observability/sync-fixtures', [\App\Http\Controllers\AssistantObservabilityController::class, 'syncFixtures'])
            ->name('assistant.observability.sync-fixtures');
        Route::get('assistant/observability/export-fixtures', [\App\Http\Controllers\AssistantObservabilityController::class, 'exportFixtures'])
            ->name('assistant.observability.export-fixtures');
        Route::post('assistant/observability/run-review', [\App\Http\Controllers\AssistantObservabilityController::class, 'runReview'])
            ->name('assistant.observability.run-review');
    });
    Route::middleware('can:manageWhatsAppBroadcasts')->group(function () {
        Route::get('admin/whatsapp-broadcasts', [\App\Http\Controllers\Admin\WhatsAppBroadcastController::class, 'index'])
            ->name('admin.whatsapp-broadcasts.index');
        Route::post('admin/whatsapp-broadcasts', [\App\Http\Controllers\Admin\WhatsAppBroadcastController::class, 'store'])
            ->name('admin.whatsapp-broadcasts.store');
    });

    // Projecoes Financeiras
    Route::get('financial-projections', function () {
        return view('pages.financial-projections.index');
    })->name('financial-projections.index');

    Route::get(config('mascot.route_path', 'orbita'), function () {
        return view('pages.mascot.index');
    })->name(config('mascot.route_name', 'mascot.index'));

    // Tags
    Volt::route('tags', 'tags.index')->name('tags.index');

    // Drive Inteligente
    Route::get('drive', function () {
        return view('pages.drive.index');
    })->name('drive.index');

    Route::get('reports/export/pdf', [App\Http\Controllers\ReportsExportController::class, 'exportPdf'])->name('reports.export.pdf');
    Route::get('reports/export/excel', [App\Http\Controllers\ReportsExportController::class, 'exportExcel'])->name('reports.export.excel');

    // Exportacao de Transacoes
    Route::get('transactions/export/csv', [App\Http\Controllers\TransactionExportController::class, 'exportCsv'])->name('transactions.export.csv');
    Route::get('transactions/export/excel', [App\Http\Controllers\TransactionExportController::class, 'exportExcel'])->name('transactions.export.excel');
    Route::get('transactions/export/pdf', [App\Http\Controllers\TransactionExportController::class, 'exportPdf'])->name('transactions.export.pdf');
    Route::get('transactions/export/json', [App\Http\Controllers\TransactionExportController::class, 'exportJson'])->name('transactions.export.json');

    // Metas de Economia
    Route::get('savings-goals', function () {
        return view('pages.savings-goals.index');
    })->name('savings-goals.index');

    Route::get('savings-goals/create', function () {
        return view('pages.savings-goals.create');
    })->middleware('billing.writable')->name('savings-goals.create');

    Route::get('savings-goals/{savingsGoal}/edit', function (App\Models\SavingsGoal $savingsGoal) {
        return view('pages.savings-goals.edit', ['savingsGoal' => $savingsGoal]);
    })->name('savings-goals.edit');

    Route::get('savings-goals/{savingsGoal}/deposit', function (App\Models\SavingsGoal $savingsGoal) {
        return view('pages.savings-goals.deposit', ['savingsGoal' => $savingsGoal]);
    })->middleware('billing.writable')->name('savings-goals.deposit');

    // Transacoes
    Route::get('recurring-transactions', function () {
        return view('pages.recurring-transactions.index');
    })->name('recurring-transactions.index');

    Route::get('recurring-transactions/create', function () {
        return view('pages.recurring-transactions.create');
    })->middleware('billing.writable')->name('recurring-transactions.create');

    Route::get('recurring-transactions/{recurringTransaction}/edit', function (App\Models\RecurringTransaction $recurringTransaction) {
        return view('pages.recurring-transactions.edit', ['recurringTransaction' => $recurringTransaction]);
    })->name('recurring-transactions.edit');

    // Contas bancarias
    Route::get('bank-accounts', function () {
        return view('pages.bank-accounts.index');
    })->name('bank-accounts.index');
    Route::get('bank-accounts/create', function () {
        return view('pages.bank-accounts.create');
    })->middleware('billing.writable')->name('bank-accounts.create');
    Route::get('bank-accounts/{bankAccount}/edit', function (App\Models\BankAccount $bankAccount) {
        return view('pages.bank-accounts.edit', ['bankAccount' => $bankAccount]);
    })->name('bank-accounts.edit');

    // Cartoes de credito
    Route::get('credit-cards', function () {
        return view('pages.credit-cards.index');
    })->name('credit-cards.index');
    Route::get('credit-cards/create', function () {
        return view('pages.credit-cards.create');
    })->middleware('billing.writable')->name('credit-cards.create');
    Route::get('credit-cards/{creditCard}/edit', function (App\Models\CreditCard $creditCard) {
        return view('pages.credit-cards.edit', ['creditCard' => $creditCard]);
    })->name('credit-cards.edit');

    // Lembretes
    Route::get('reminders', function () {
        return view('pages.reminders.index');
    })->name('reminders.index');
    Route::get('reminders/create', function () {
        return view('pages.reminders.create');
    })->middleware('billing.writable')->name('reminders.create');
    Route::get('reminders/{reminder}/edit', function (App\Models\Reminder $reminder) {
        return view('pages.reminders.edit', ['reminder' => $reminder]);
    })->middleware('billing.writable')->name('reminders.edit');

    // Notas
    Route::get('notes', function () {
        return view('pages.notes.index');
    })->name('notes.index');
    Route::get('notes/create', function () {
        return view('pages.notes.create');
    })->middleware('billing.writable')->name('notes.create');
    Route::get('notes/{note}/edit', function (App\Models\Note $note) {
        return view('pages.notes.edit', ['note' => $note]);
    })->middleware('billing.writable')->name('notes.edit');

    // Assinaturas e contas recorrentes
    Route::get('subscriptions', function () {
        return view('pages.subscriptions.index');
    })->name('subscriptions.index');
    Route::get('subscriptions/create', function () {
        return view('pages.subscriptions.create');
    })->middleware('billing.writable')->name('subscriptions.create');
    Route::get('subscriptions/{subscription}/edit', function (App\Models\Subscription $subscription) {
        return view('pages.subscriptions.edit', ['subscription' => $subscription]);
    })->name('subscriptions.edit');

    // Planejamento de Gastos
    Volt::route('expense-plans', 'expense-plans.index')->name('expense-plans.index');
    Volt::route('expense-plans/create', 'expense-plans.create')->middleware('billing.writable')->name('expense-plans.create');
    Volt::route('expense-plans/{expensePlan}/edit', 'expense-plans.edit')->name('expense-plans.edit');

    // Alertas de Metas
    Volt::route('savings-goals/{savingsGoal}/alerts', 'savings-goals.alerts')->middleware('billing.writable')->name('savings-goals.alerts');

    // Webhooks
    Volt::route('webhooks', 'webhooks.index')->name('webhooks.index');
    Volt::route('webhooks/create', 'webhooks.create')->middleware('billing.writable')->name('webhooks.create');
    Volt::route('webhooks/{webhook}/edit', 'webhooks.edit')->name('webhooks.edit');

    // Monitoramento
    Route::get('monitoring', [App\Http\Controllers\MonitoringController::class, 'index'])->name('monitoring.index');

    // Gerenciamento WhatsApp
    Route::get('whatsapp', function () {
        return view('pages.whatsapp.index');
    })->name('whatsapp.index');

    // Configuracoes
    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('profile.edit');
    Volt::route('settings/password', 'settings.password')->name('user-password.edit');
    Volt::route('settings/appearance', 'settings.appearance')->name('appearance.edit');
    Volt::route('settings/whatsapp', 'settings.whatsapp')->name('whatsapp.settings');
    Volt::route('settings/assistant-operations', 'settings.assistant-operations')
        ->middleware('can:viewAssistantObservability')
        ->name('assistant.operations.settings');

    Volt::route('settings/two-factor', 'settings.two-factor')
        ->middleware(
            when(
                Features::canManageTwoFactorAuthentication()
                    && Features::optionEnabled(Features::twoFactorAuthentication(), 'confirmPassword'),
                ['password.confirm'],
                [],
            ),
        )
        ->name('two-factor.show');
});

// Webhook do WhatsApp (sem autenticacao)
Route::post('/webhook/whatsapp', [App\Http\Controllers\WhatsAppWebhookController::class, 'handle'])
    ->middleware('throttle:60,1') // 60 requisicoes por minuto
    ->name('webhook.whatsapp');

Route::post('/webhook/abacatepay', [App\Http\Controllers\AbacatePayWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('webhook.abacatepay');

Route::post('/webhook/pluggy', [App\Http\Controllers\PluggyWebhookController::class, 'handle'])
    ->middleware('throttle:120,1')
    ->name('webhook.pluggy');
