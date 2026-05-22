<?php

namespace App\Services\WhatsApp;

class ReminderMessageTemplateFactory
{
    public static function detect(string $title, string $message): string
    {
        $titleLower = strtolower($title);

        if ($this->isAnniversary($titleLower)) {
            return 'anniversary';
        }

        if ($this->isPayment($titleLower)) {
            return 'payment';
        }

        if ($this->isMeeting($titleLower)) {
            return 'meeting';
        }

        if ($this->isCall($titleLower)) {
            return 'call';
        }

        if ($this->isTask($titleLower)) {
            return 'task';
        }

        return 'generic';
    }

    public static function buildFriendlyMessage(string $title, string $frequency, string $type = 'generic', ?string $userName = null): string
    {
        $greeting = $userName ? "Olá, {$userName}! 👋" : "Olá! 👋";

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
            || str_contains($titleLower, 'aniversário')
            || str_contains($titleLower, 'niver');
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
            || str_contains($titleLower, 'reunião')
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
            || str_contains($titleLower, 'fazer')
            || str_contains($titleLower, 'tarefa')
            || str_contains($titleLower, 'todo');
    }

    private static function buildAnniversaryMessage(string $greeting, string $title): string
    {
        $title = preg_replace('/^aniversario\s+(?:de|da|do)?\s*/iu', '', $title);
        $title = preg_replace('/^niver\s+(?:de|da|do)?\s*/iu', '', $title);

        return "{$greeting}\n\n🎉 Vim aqui te lembrar que hoje é o aniversário de {$title}!\n\nNão esqueça de:\n✨ Ligar ou mandar uma mensagem\n🎂 Desejar um feliz aniversário\n🎁 Talvez marcar um café ou encontro\n\nAproveita o dia! 🎊";
    }

    private static function buildPaymentMessage(string $greeting, string $title, string $frequency): string
    {
        $frequencyText = match ($frequency) {
            'daily' => 'todos os dias',
            'weekly' => 'toda semana',
            'monthly' => 'todo mês',
            'yearly' => 'todo ano',
            default => 'conforme agendado',
        };

        $title = preg_replace('/^(?:pagar|pagamento|conta|fatura)\s+(?:de|da|do)?\s*/iu', '', $title);

        return "{$greeting}\n\n💰 Não esqueça! Precisa pagar {$title}.\n\nEsse lembrete sai $frequencyText para você não deixar escapar. Melhor não deixar para última hora! 😉";
    }

    private static function buildMeetingMessage(string $greeting, string $title, string $frequency): string
    {
        $frequencyText = match ($frequency) {
            'daily' => 'todos os dias',
            'weekly' => 'toda semana',
            'monthly' => 'todo mês',
            'yearly' => 'todo ano',
            default => 'conforme agendado',
        };

        $title = preg_replace('/^(?:reuniao|reunião|encontro|meeting)\s+(?:com|de|da|do)?\s*/iu', '', $title);

        return "{$greeting}\n\n📅 Lembrete: Você tem uma reunião com {$title}.\n\nSe ainda não confirmou presença ou precisa se preparar, agora é a hora! Boa sorte! 💼";
    }

    private static function buildCallMessage(string $greeting, string $title): string
    {
        $title = preg_replace('/^(?:ligar|chamada|telefonar|call)\s+(?:para|para o|para a|para o)?\s*/iu', '', $title);

        return "{$greeting}\n\n☎️ Hora de ligar para {$title}!\n\nAguarde um bom momento, respire fundo e faça a chamada. Boa sorte na conversa! 😊";
    }

    private static function buildTaskMessage(string $greeting, string $title, string $frequency): string
    {
        $frequencyText = match ($frequency) {
            'daily' => 'todos os dias',
            'weekly' => 'toda semana',
            'monthly' => 'todo mês',
            'yearly' => 'todo ano',
            default => 'conforme agendado',
        };

        $title = preg_replace('/^(?:fazer|tarefa|todo)\s+\s*/iu', '', $title);

        return "{$greeting}\n\n✅ Tarefa do dia: {$title}\n\nSe conseguir fazer agora, melhor ainda! A sensação de completar uma tarefa é sempre boa. Bora lá! 🚀";
    }

    private static function buildGenericMessage(string $greeting, string $title, string $frequency): string
    {
        $frequencyText = match ($frequency) {
            'daily' => 'todos os dias',
            'weekly' => 'toda semana',
            'monthly' => 'todo mês',
            'yearly' => 'todo ano',
            'once' => 'neste momento',
            default => 'conforme agendado',
        };

        return "{$greeting}\n\n⏰ Lembrete: {$title}\n\nEsse lembrete sai $frequencyText. Não esqueça! 💭";
    }
}
