<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Verificar orçamentos excedidos diariamente
Schedule::command('budgets:check-exceeded')
    ->daily()
    ->at('09:00');

// Processar transações recorrentes diariamente
Schedule::command('transactions:process-recurring')
    ->daily()
    ->at('00:01');

// Atualizar planos de despesas diariamente
Schedule::command('expense-plans:update')
    ->daily()
    ->at('01:00');

// Verificar alertas de metas de economia diariamente
Schedule::command('savings-goals:check-alerts')
    ->daily()
    ->at('08:00');

// Verificar alertas do sistema a cada 5 minutos
Schedule::command('alerts:check')->everyFiveMinutes();

// Enviar notificações proativas diariamente às 9h
Schedule::command('notifications:proactive')->dailyAt('09:00');
