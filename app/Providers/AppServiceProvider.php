<?php

namespace App\Providers;

use App\Models\EmailLog;
use App\Models\User;
use App\Notifications\LoginAlertNotification;
use App\Services\AbacatePayService;
use App\Services\AbacatePayWebhookProcessor;
use App\Services\AIContextBuilder;
use App\Services\AIPromptBuilder;
use App\Services\AIResponseParser;
use App\Services\AIService;
use App\Services\BaileysService;
use App\Services\BillingPlanService;
use App\Services\WhatsApp\ActionHandlerFactory;
use App\Services\WhatsApp\Handlers\CancelRecurringTransactionHandler;
use App\Services\WhatsApp\Handlers\CancelSubscriptionHandler;
use App\Services\WhatsApp\Handlers\CreateBudgetHandler;
use App\Services\WhatsApp\Handlers\CreateCreditCardHandler;
use App\Services\WhatsApp\Handlers\CreateDriveFileHandler;
use App\Services\WhatsApp\Handlers\CreateInstallmentTransactionHandler;
use App\Services\WhatsApp\Handlers\CreateNoteHandler;
use App\Services\WhatsApp\Handlers\CreateRecurringTransactionHandler;
use App\Services\WhatsApp\Handlers\CreateReminderHandler;
use App\Services\WhatsApp\Handlers\CreateSavingsGoalHandler;
use App\Services\WhatsApp\Handlers\CreateSubscriptionHandler;
use App\Services\WhatsApp\Handlers\CreateTransactionHandler;
use App\Services\WhatsApp\Handlers\DeleteBudgetHandler;
use App\Services\WhatsApp\Handlers\DeleteNoteHandler;
use App\Services\WhatsApp\Handlers\DeleteReminderHandler;
use App\Services\WhatsApp\Handlers\DeleteTransactionHandler;
use App\Services\WhatsApp\Handlers\EditNoteHandler;
use App\Services\WhatsApp\Handlers\EditReminderHandler;
use App\Services\WhatsApp\Handlers\EditTransactionHandler;
use App\Services\WhatsApp\Handlers\QueryHandler;
use App\Services\WhatsApp\Handlers\ReportHandler;
use App\Services\WhatsApp\Handlers\SplitTransactionHandler;
use App\Services\WhatsApp\Handlers\UndoLastActionHandler;
use App\Services\WhatsApp\Handlers\UpdateBudgetHandler;
use App\Services\WhatsApp\Handlers\UpdateRecurringTransactionHandler;
use App\Services\WhatsApp\Handlers\UpdateSavingsGoalHandler;
use App\Services\WhatsApp\Handlers\UpdateSubscriptionHandler;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AbacatePayService::class, function () {
            return new AbacatePayService(
                baseUrl: (string) config('abacatepay.base_url'),
                legacyBaseUrl: (string) config('abacatepay.legacy_base_url'),
                apiKey: (string) config('abacatepay.api_key'),
                timeout: config('abacatepay.timeout'),
            );
        });

        $this->app->singleton(AbacatePayWebhookProcessor::class, function ($app) {
            return new AbacatePayWebhookProcessor(
                billingPlanService: $app->make(BillingPlanService::class),
            );
        });

        $this->app->singleton(BillingPlanService::class, fn () => new BillingPlanService);

        $this->app->singleton(BaileysService::class, function () {
            return new BaileysService(
                baseUrl: (string) config('whatsapp.baileys.base_url'),
                webhookSecret: (string) config('whatsapp.baileys.webhook_secret'),
            );
        });

        $this->app->singleton(AIService::class, function ($app) {
            // Para Ollama, a API key nao e necessaria.
            $apiKey = config('ai.provider') === 'ollama' ? '' : (string) config('ai.api_key');

            return new AIService(
                apiKey: $apiKey,
                provider: (string) config('ai.provider'),
                contextBuilder: $app->make(AIContextBuilder::class),
                promptBuilder: $app->make(AIPromptBuilder::class),
                responseParser: $app->make(AIResponseParser::class),
            );
        });

        $this->app->singleton(ActionHandlerFactory::class, function ($app) {
            $handlerClasses = [
                UndoLastActionHandler::class,
                CreateBudgetHandler::class,
                UpdateBudgetHandler::class,
                DeleteBudgetHandler::class,
                CreateSavingsGoalHandler::class,
                UpdateSavingsGoalHandler::class,
                CreateSubscriptionHandler::class,
                CreateCreditCardHandler::class,
                UpdateSubscriptionHandler::class,
                CancelSubscriptionHandler::class,
                CreateRecurringTransactionHandler::class,
                UpdateRecurringTransactionHandler::class,
                CancelRecurringTransactionHandler::class,
                CreateInstallmentTransactionHandler::class,
                CreateReminderHandler::class,
                DeleteReminderHandler::class,
                EditReminderHandler::class,
                CreateNoteHandler::class,
                CreateDriveFileHandler::class,
                DeleteNoteHandler::class,
                EditNoteHandler::class,
                CreateTransactionHandler::class,
                EditTransactionHandler::class,
                DeleteTransactionHandler::class,
                SplitTransactionHandler::class,
                QueryHandler::class,
                ReportHandler::class,
            ];

            return new ActionHandlerFactory(
                array_map(static fn (string $handlerClass) => $app->make($handlerClass), $handlerClasses)
            );
        });
    }

    public function boot(): void
    {
        Gate::define('viewAssistantObservability', function (User $user): bool {
            return $user->isAdmin();
        });

        Gate::define('manageWhatsAppBroadcasts', function (User $user): bool {
            return $user->isAdmin();
        });

        Gate::define('viewBetaDashboard', function (User $user): bool {
            return $user->isAdmin();
        });

        VerifyEmail::toMailUsing(function (User $notifiable, string $url): MailMessage {
            return (new MailMessage)
                ->subject('Confirme seu e-mail no InovaFinance')
                ->greeting("Oi, {$notifiable->name}.")
                ->line('Falta pouco para ativar sua conta no InovaFinance.')
                ->line('Confirme seu e-mail para proteger sua conta e continuar o cadastro com seguranca.')
                ->action('Confirmar e-mail', $url)
                ->line('Se voce nao criou essa conta, ignore esta mensagem.');
        });

        ResetPassword::toMailUsing(function (User $notifiable, string $token): MailMessage {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ], false));

            return (new MailMessage)
                ->subject('Redefina sua senha do InovaFinance')
                ->greeting("Oi, {$notifiable->name}.")
                ->line('Recebemos uma solicitacao para redefinir a senha da sua conta.')
                ->line('Use o botao abaixo para criar uma nova senha. Este link expira por seguranca.')
                ->action('Redefinir senha', $url)
                ->line('Se voce nao pediu essa alteracao, nenhuma acao e necessaria.');
        });

        Event::listen(Login::class, function (Login $event): void {
            if (! $event->user instanceof User || ! $event->user->wantsEmail('login_alerts')) {
                return;
            }

            try {
                $event->user->notify(new LoginAlertNotification(
                    ipAddress: request()?->ip(),
                    userAgent: request()?->userAgent(),
                ));
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        Event::listen(MessageSent::class, function (MessageSent $event): void {
            try {
                $message = $event->message;
                $notificationType = $message->getHeaders()->get('X-Laravel-Notification')?->getBodyAsString();

                foreach ($message->getTo() as $address) {
                    $email = $address->getAddress();

                    EmailLog::query()->create([
                        'user_id' => User::query()->where('email', $email)->value('id'),
                        'to_email' => $email,
                        'subject' => $message->getSubject(),
                        'notification_type' => $notificationType,
                        'mailer' => config('mail.default'),
                        'status' => 'sent',
                        'metadata' => [
                            'from' => collect($message->getFrom())->map(fn ($from) => $from->getAddress())->values()->all(),
                        ],
                    ]);
                }
            } catch (\Throwable $exception) {
                report($exception);
            }
        });

        if (config('app.env') === 'production') {
            URL::forceScheme('https');
        }
    }
}
