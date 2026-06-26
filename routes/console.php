<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('budgets:check-exceeded')
    ->daily()
    ->at('09:00');

Schedule::command('transactions:process-recurring')
    ->daily()
    ->at('00:01');

Schedule::command('subscriptions:process-due')
    ->daily()
    ->at('00:05');

Schedule::command('reminders:send-due')
    ->everyFiveMinutes();

Schedule::command('expense-plans:update')
    ->daily()
    ->at('01:00');

Schedule::command('savings-goals:check-alerts')
    ->daily()
    ->at('08:00');

Schedule::command('alerts:check')->everyFiveMinutes();

Schedule::command('notifications:proactive')->dailyAt('09:00');

Schedule::command('assistant:weekly-review --days=7 --sync')
    ->weeklyOn(1, '09:30')
    ->withoutOverlapping();

Schedule::command('assistant:send-weekly-summary')
    ->weeklyOn(1, '09:40')
    ->withoutOverlapping();

Schedule::command('billing:send-expiring-emails --days=3 --max-per-cycle=2')
    ->dailyAt('10:00')
    ->withoutOverlapping();
