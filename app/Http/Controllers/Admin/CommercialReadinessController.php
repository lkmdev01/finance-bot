<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbacatePayWebhookEvent;
use App\Models\EmailLog;
use App\Models\User;
use App\Models\WhatsAppConversationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class CommercialReadinessController extends Controller
{
    public function __invoke(): View
    {
        $checks = $this->checks();

        return view('pages.admin.commercial-readiness', [
            'checks' => $checks,
            'summary' => [
                'passed' => collect($checks)->where('status', 'pass')->count(),
                'warning' => collect($checks)->where('status', 'warning')->count(),
                'failed' => collect($checks)->where('status', 'fail')->count(),
            ],
            'signals' => $this->signals(),
            'support' => [
                'email' => config('support.email'),
                'whatsapp_url' => $this->supportWhatsAppUrl(),
                'response_time' => config('support.response_time'),
            ],
        ]);
    }

    private function checks(): array
    {
        $plans = (array) config('billing.plans');

        return [
            $this->check('SMTP configurado', config('mail.default') === 'smtp' && filled(config('mail.mailers.smtp.host')) && filled(config('mail.from.address')), 'Envio real de e-mails esta configurado.'),
            $this->check('Fila nao sincronizada', config('queue.default') !== 'sync', 'QUEUE_CONNECTION deve rodar em database/redis com worker ativo.'),
            $this->check('AbacatePay v2', Str::startsWith((string) config('abacatepay.base_url'), 'https://api.abacatepay.com/v2'), 'Base URL aponta para API v2.'),
            $this->check('API key AbacatePay', filled(config('abacatepay.api_key')), 'Chave da API esta preenchida.'),
            $this->check('Webhook secret', filled(config('abacatepay.webhook_secret')), 'Secret do webhook esta preenchido.'),
            $this->check('HMAC webhook', filled(config('abacatepay.public_hmac_key')), 'Chave HMAC/public key esta preenchida.'),
            $this->check('Plano mensal recorrente', ($plans['pro_monthly']['checkout_flow'] ?? null) === 'subscription' && filled($plans['pro_monthly']['product_id'] ?? null), 'Plano mensal usa assinatura e tem product_id.'),
            $this->check('Plano anual recorrente', ($plans['pro_yearly']['checkout_flow'] ?? null) === 'subscription' && filled($plans['pro_yearly']['product_id'] ?? null), 'Plano anual usa assinatura e tem product_id.'),
            $this->check('Suporte visivel', filled(config('support.email')) || filled(config('support.whatsapp_url')) || filled(config('support.whatsapp_number')), 'Ha pelo menos um canal de suporte configurado.'),
            $this->check('Scheduler configurado no codigo', true, 'Comando billing:send-expiring-emails esta agendado no Laravel; confirme o cron php artisan schedule:run no Coolify.'),
        ];
    }

    private function signals(): array
    {
        return [
            'latest_webhook' => AbacatePayWebhookEvent::query()->latest('received_at')->first(),
            'failed_webhooks_24h' => AbacatePayWebhookEvent::query()
                ->where('created_at', '>=', now()->subDay())
                ->where('status', 'failed')
                ->count(),
            'latest_email' => EmailLog::query()->latest()->first(),
            'emails_24h' => EmailLog::query()->where('created_at', '>=', now()->subDay())->count(),
            'whatsapp_errors_24h' => WhatsAppConversationLog::query()
                ->where('created_at', '>=', now()->subDay())
                ->where(function ($query) {
                    $query->where('status', 'error')->orWhereNotNull('error_message');
                })
                ->count(),
            'pending_jobs' => rescue(fn () => DB::table(config('queue.connections.database.table', 'jobs'))->count(), null, false),
            'failed_jobs' => rescue(fn () => DB::table('failed_jobs')->count(), null, false),
            'active_paid_users' => User::query()
                ->whereNotNull('billing_plan_code')
                ->whereIn('billing_plan_status', ['active', 'renewed', 'cancelled'])
                ->where('billing_access_ends_at', '>', now())
                ->count(),
        ];
    }

    private function check(string $label, bool $passes, string $message): array
    {
        return [
            'label' => $label,
            'status' => $passes ? 'pass' : 'fail',
            'message' => $message,
        ];
    }

    private function supportWhatsAppUrl(): ?string
    {
        if (filled(config('support.whatsapp_url'))) {
            return config('support.whatsapp_url');
        }

        $number = preg_replace('/\D+/', '', (string) config('support.whatsapp_number'));

        return $number ? "https://wa.me/{$number}" : null;
    }
}
