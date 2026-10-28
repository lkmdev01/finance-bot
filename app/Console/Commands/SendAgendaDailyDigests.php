<?php

namespace App\Console\Commands;

use App\Models\AgendaDigestPreference;
use App\Services\AgendaDailySummaryService;
use App\Services\PhoneNumberService;
use App\Services\WhatsApp\WhatsAppReplyDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendAgendaDailyDigests extends Command
{
    protected $signature = 'agenda:send-daily-digests';

    protected $description = 'Envia resumos diários da agenda solicitados pelos usuários.';

    public function handle(
        AgendaDailySummaryService $summaries,
        PhoneNumberService $phoneNumbers,
        WhatsAppReplyDelivery $delivery,
    ): int {
        AgendaDigestPreference::query()
            ->with('user.googleCalendarConnection')
            ->where('enabled', true)
            ->orderBy('id')
            ->chunkById(100, function ($preferences) use ($summaries, $phoneNumbers, $delivery): void {
                foreach ($preferences as $preference) {
                    $user = $preference->user;
                    if (! $user || blank($user->phone_number)) {
                        continue;
                    }

                    $timezone = (string) $preference->timezone;
                    if (! in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
                        Log::warning('Preferência de resumo diário contém fuso horário inválido', [
                            'user_id' => $user->id,
                            'preference_id' => $preference->id,
                        ]);
                        continue;
                    }

                    $now = CarbonImmutable::now($timezone);
                    $today = $now->toDateString();
                    if ($now->format('H:i') < substr((string) $preference->send_time, 0, 5)) {
                        continue;
                    }

                    $claimed = DB::transaction(function () use ($preference, $today, $now): bool {
                        $locked = AgendaDigestPreference::query()->whereKey($preference->id)->lockForUpdate()->first();
                        if (! $locked || ! $locked->enabled || $locked->last_sent_for?->format('Y-m-d') === $today) {
                            return false;
                        }
                        if ($now->format('H:i') < substr((string) $locked->send_time, 0, 5)) {
                            return false;
                        }

                        $locked->last_sent_for = $today;
                        $locked->save();

                        return true;
                    });

                    if (! $claimed) {
                        continue;
                    }

                    $recipient = $phoneNumbers->toWhatsAppJid($user->phone_number);
                    try {
                        $message = $summaries->summarize($user, $now);
                    } catch (Throwable $exception) {
                        Log::warning('Falha ao consultar dados para o resumo diário da agenda', [
                            'user_id' => $user->id,
                            'preference_id' => $preference->id,
                            'exception' => $exception::class,
                        ]);

                        try {
                            $delivery->send($recipient, 'Não consegui consultar sua agenda para montar o resumo de hoje. Você pode tentar novamente mais tarde ou revisar a conexão Google.', $user->id);
                        } catch (Throwable $deliveryException) {
                            $this->releaseClaim($preference->id, $today);
                            Log::warning('Falha ao enfileirar aviso sobre o resumo diário', [
                                'user_id' => $user->id,
                                'preference_id' => $preference->id,
                                'exception' => $deliveryException::class,
                            ]);
                        }

                        continue;
                    }

                    try {
                        $delivery->send($recipient, $message, $user->id);
                    } catch (Throwable $exception) {
                        $this->releaseClaim($preference->id, $today);
                        Log::warning('Falha ao preparar envio do resumo diário da agenda', [
                            'user_id' => $user->id,
                            'preference_id' => $preference->id,
                            'exception' => $exception::class,
                        ]);
                    }
                }
            });

        return self::SUCCESS;
    }

    private function releaseClaim(int $preferenceId, string $today): void
    {
        AgendaDigestPreference::query()
            ->whereKey($preferenceId)
            ->whereDate('last_sent_for', $today)
            ->update(['last_sent_for' => null]);
    }
}
