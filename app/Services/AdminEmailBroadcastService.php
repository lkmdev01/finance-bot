<?php

namespace App\Services;

use App\Mail\AdminBroadcastMail;
use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AdminEmailBroadcastService
{
    /**
     * @param  array<int, int>  $selectedUserIds
     * @return Collection<int, User>
     */
    public function recipientsFor(string $audience, array $selectedUserIds = [], ?string $manualEmail = null): Collection
    {
        if ($audience === 'manual') {
            return collect([
                new User([
                    'name' => 'Contato manual',
                    'email' => (string) $manualEmail,
                ]),
            ])->filter(fn (User $user) => filled($user->email))->values();
        }

        return $this->baseQuery($audience, $selectedUserIds)
            ->orderByDesc('updated_at')
            ->limit($audience === 'selected' ? 250 : 1000)
            ->get();
    }

    /**
     * @param  array<int, int>  $selectedUserIds
     * @return array{total:int,sent:int,failed:int}
     */
    public function send(User $admin, array $data, array $selectedUserIds = []): array
    {
        $recipients = $this->recipientsFor(
            (string) $data['audience'],
            $selectedUserIds,
            $data['manual_email'] ?? null,
        );

        $result = [
            'total' => $recipients->count(),
            'sent' => 0,
            'failed' => 0,
        ];

        foreach ($recipients as $recipient) {
            $payload = $this->personalizePayload($data, $recipient, $admin);

            try {
                Mail::to($recipient->email)->send(new AdminBroadcastMail($payload));
                $result['sent']++;
            } catch (\Throwable $exception) {
                $result['failed']++;

                EmailLog::query()->create([
                    'user_id' => $recipient->exists ? $recipient->id : null,
                    'to_email' => $recipient->email,
                    'subject' => $payload['subject'],
                    'notification_type' => AdminBroadcastMail::class,
                    'mailer' => config('mail.default'),
                    'status' => 'failed',
                    'metadata' => [
                        'source' => 'admin_email_broadcast',
                        'admin_id' => $admin->id,
                        'audience' => $data['audience'],
                        'error' => $exception->getMessage(),
                    ],
                ]);

                report($exception);
            }
        }

        return $result;
    }

    /**
     * @param  array<int, int>  $selectedUserIds
     */
    private function baseQuery(string $audience, array $selectedUserIds): Builder
    {
        return User::query()
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->when($audience === 'marketing_opt_in', fn (Builder $query) => $query->where('email_preferences->marketing', true))
            ->when($audience === 'verified_email', fn (Builder $query) => $query->whereNotNull('email_verified_at'))
            ->when($audience === 'paid_active', function (Builder $query) {
                $query->whereNotNull('billing_plan_code')
                    ->whereIn('billing_plan_status', ['active', 'renewed', 'cancelled'])
                    ->where('billing_access_ends_at', '>', now());
            })
            ->when($audience === 'whatsapp_verified', fn (Builder $query) => $query->whereNotNull('whatsapp_verified_at'))
            ->when($audience === 'selected', fn (Builder $query) => $query->whereIn('id', $selectedUserIds));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function personalizePayload(array $data, User $recipient, User $admin): array
    {
        $variables = [
            '{{nome}}' => $recipient->name ?: 'cliente',
            '{{primeiro_nome}}' => Str::of($recipient->name ?: 'cliente')->before(' ')->toString(),
            '{{email}}' => (string) $recipient->email,
            '{{plano}}' => (string) ($recipient->billing_plan_code ?: 'gratuito'),
            '{{status_plano}}' => (string) ($recipient->billing_plan_status ?: 'sem plano ativo'),
            '{{data_acesso}}' => $recipient->billing_access_ends_at?->format('d/m/Y') ?: 'sem data definida',
            '{{link_dashboard}}' => route('dashboard'),
            '{{link_suporte}}' => route('support'),
        ];

        return [
            'subject' => strtr((string) $data['subject'], $variables),
            'preheader' => strtr((string) ($data['preheader'] ?? ''), $variables),
            'headline' => strtr((string) $data['headline'], $variables),
            'body' => strtr((string) $data['body'], $variables),
            'cta_label' => strtr((string) ($data['cta_label'] ?? ''), $variables),
            'cta_url' => strtr((string) ($data['cta_url'] ?? ''), $variables),
            'recipient_name' => $recipient->name,
            'support_url' => route('support'),
            'dashboard_url' => route('dashboard'),
            'admin_id' => $admin->id,
            'audience' => $data['audience'],
        ];
    }
}
