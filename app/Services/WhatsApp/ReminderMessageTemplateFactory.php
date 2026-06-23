<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

class ReminderMessageTemplateFactory
{
    public static function detect(string $title, string $message): string
    {
        $titleLower = mb_strtolower(trim($title.' '.$message));

        if (self::isAnniversary($titleLower)) {
            return 'anniversary';
        }

        if (self::isPayment($titleLower)) {
            return 'payment';
        }

        if (self::isTask($titleLower)) {
            return 'task';
        }

        if (self::isMeeting($titleLower)) {
            return 'meeting';
        }

        if (self::isCall($titleLower)) {
            return 'call';
        }

        return 'generic';
    }

    public static function buildFriendlyMessage(string $title, string $frequency, string $type = 'generic', ?string $userName = null): string
    {
        $greeting = $userName ? "Ola, {$userName}!" : 'Ola!';

        return match ($type) {
            'anniversary' => self::buildAnniversaryMessage($greeting, $title),
            'payment' => self::buildPaymentMessage($greeting, $title, $frequency),
            'meeting' => self::buildMeetingMessage($greeting, $title, $frequency),
            'call' => self::buildCallMessage($greeting, $title),
            'task' => self::buildTaskMessage($greeting, $title, $frequency),
            default => self::buildGenericMessage($greeting, $title, $frequency),
        };
    }

    private static function isAnniversary(string $titleLower): bool
    {
        return str_contains($titleLower, 'aniversario')
            || str_contains($titleLower, 'niver')
            || str_contains($titleLower, 'parabens')
            || str_contains($titleLower, 'dar parabens');
    }

    private static function isPayment(string $titleLower): bool
    {
        return str_contains($titleLower, 'pagar')
            || str_contains($titleLower, 'pagamento')
            || str_contains($titleLower, 'conta')
            || str_contains($titleLower, 'fatura');
    }

    private static function isMeeting(string $titleLower): bool
    {
        return str_contains($titleLower, 'reuniao')
            || str_contains($titleLower, 'encontro')
            || str_contains($titleLower, 'meeting');
    }

    private static function isCall(string $titleLower): bool
    {
        return str_contains($titleLower, 'ligar')
            || str_contains($titleLower, 'chamada')
            || str_contains($titleLower, 'telefonar')
            || str_contains($titleLower, 'call');
    }

    private static function isTask(string $titleLower): bool
    {
        return str_contains($titleLower, 'fazer')
            || str_contains($titleLower, 'tarefa')
            || str_contains($titleLower, 'todo')
            || str_contains($titleLower, 'tomar')
            || str_contains($titleLower, 'beber')
            || str_contains($titleLower, 'comer')
            || str_contains($titleLower, 'estudar')
            || str_contains($titleLower, 'treinar')
            || str_contains($titleLower, 'exercicio')
            || str_contains($titleLower, 'meditar')
            || str_contains($titleLower, 'ler');
    }

    private static function buildAnniversaryMessage(string $greeting, string $title): string
    {
        $name = $title;
        $name = preg_replace('/\b(?:aniversario|niver)\s+(?:de|da|do)?\s*/iu', '', $name) ?? $name;
        $name = preg_replace('/\b(?:dar\s+)?parabens\s+(?:para|pro|pra)?\s*/iu', '', $name) ?? $name;
        $name = trim((string) $name);

        if ($name === '') {
            $name = 'alguem especial';
        }

        $body = "{$greeting}\n\nLembrete de aniversario\nHoje e aniversario de *{$name}*.\n\nSugestao:\n\"Feliz aniversario, {$name}! Que seu novo ciclo venha com saude, paz e coisas boas.\"";

        // Keep reminders short (<= 255 chars) because this is what we persist and send later.
        return Str::limit($body, 255, '');
    }

    private static function buildPaymentMessage(string $greeting, string $title, string $frequency): string
    {
        $frequencyText = match ($frequency) {
            'daily' => 'todos os dias',
            'weekly' => 'toda semana',
            'monthly' => 'todo mes',
            'yearly' => 'todo ano',
            'once' => 'no dia agendado',
            default => 'conforme agendado',
        };

        $what = preg_replace('/^(?:pagar|pagamento|conta|fatura)\s+(?:de|da|do)?\s*/iu', '', $title) ?? $title;
        $what = trim((string) $what);

        return "{$greeting}\n\nLembrete: pagar {$what}.\n\nEsse lembrete sai {$frequencyText} para voce nao esquecer.";
    }

    private static function buildMeetingMessage(string $greeting, string $title, string $frequency): string
    {
        $frequencyText = match ($frequency) {
            'daily' => 'todos os dias',
            'weekly' => 'toda semana',
            'monthly' => 'todo mes',
            'yearly' => 'todo ano',
            'once' => 'no dia agendado',
            default => 'conforme agendado',
        };

        $who = preg_replace('/^(?:reuniao|encontro|meeting)\s+(?:com|de|da|do)?\s*/iu', '', $title) ?? $title;
        $who = trim((string) $who);

        return "{$greeting}\n\nLembrete: reuniao com {$who}.\n\nEsse lembrete sai {$frequencyText}.";
    }

    private static function buildCallMessage(string $greeting, string $title): string
    {
        $who = preg_replace('/^(?:ligar|chamada|telefonar|call)\s+(?:para|pro|pra)?\s*/iu', '', $title) ?? $title;
        $who = trim((string) $who);

        return "{$greeting}\n\nLembrete: ligar para {$who}.";
    }

    private static function buildTaskMessage(string $greeting, string $title, string $frequency): string
    {
        $frequencyText = match ($frequency) {
            'daily' => 'todos os dias',
            'weekly' => 'toda semana',
            'monthly' => 'todo mes',
            'yearly' => 'todo ano',
            'once' => 'no dia agendado',
            default => 'conforme agendado',
        };

        return "{$greeting}\n\nLembrete: {$title}\n\nEsse lembrete sai {$frequencyText}.";
    }

    private static function buildGenericMessage(string $greeting, string $title, string $frequency): string
    {
        $frequencyText = match ($frequency) {
            'daily' => 'todos os dias',
            'weekly' => 'toda semana',
            'monthly' => 'todo mes',
            'yearly' => 'todo ano',
            'once' => 'no dia agendado',
            default => 'conforme agendado',
        };

        return "{$greeting}\n\nLembrete: {$title}\n\nEsse lembrete sai {$frequencyText}.";
    }
}
