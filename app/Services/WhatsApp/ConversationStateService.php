<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppContact;

class ConversationStateService
{
    private const MAX_CONTEXT_ITEMS = 12;

    public function getState(?WhatsAppContact $contact): array
    {
        return $this->normalizeState($contact?->conversation_state ?? []);
    }

    public function clearPending(WhatsAppContact $contact): void
    {
        $state = $this->getState($contact);
        $state['mode'] = 'idle';
        $state['pending_intent'] = null;
        $state['pending_payload'] = [];
        $this->persistState($contact, $state);
    }

    public function markAwaitingConfirmation(WhatsAppContact $contact, string $intent, array $payload = []): void
    {
        $state = $this->getState($contact);
        $state['mode'] = 'awaiting_confirmation';
        $state['pending_intent'] = $intent;
        $state['pending_payload'] = $payload;
        $state['updated_at'] = now()->toIso8601String();
        $this->persistState($contact, $state);
    }

    public function rememberLastAction(WhatsAppContact $contact, ?string $action, array $entities = [], ?string $replyKind = null): void
    {
        $state = $this->getState($contact);
        $state['last_action'] = $action;
        $state['last_entities'] = $entities;
        $state['last_reply_kind'] = $replyKind;
        $state['updated_at'] = now()->toIso8601String();
        $this->persistState($contact, $state);
    }

    public function rememberInteraction(WhatsAppContact $contact, string $message, string $reply, ?string $action, array $meta = []): void
    {
        $context = $contact->context ?? [];
        $context[] = [
            'message' => $message,
            'reply' => $reply,
            'action' => $action,
            'meta' => $meta,
            'timestamp' => now()->toIso8601String(),
        ];

        if (count($context) > self::MAX_CONTEXT_ITEMS) {
            $context = array_slice($context, -self::MAX_CONTEXT_ITEMS);
        }

        $contact->forceFill(['context' => $context])->save();
    }

    public function applyHandledResult(WhatsAppContact $contact, string $message, ?string $action, string $reply, array $metadata = []): void
    {
        $replyKind = $metadata['reply_kind'] ?? $this->inferReplyKind($action, $reply);
        $entities = $metadata['entities'] ?? [];

        if (($metadata['pending_intent'] ?? null) !== null) {
            $this->markAwaitingConfirmation($contact, $metadata['pending_intent'], $metadata['pending_payload'] ?? []);
        } elseif (($metadata['clear_pending'] ?? true) === true) {
            $this->clearPending($contact);
        }

        $this->rememberLastAction($contact, $action, $entities, $replyKind);
        $this->rememberInteraction($contact, $message, $reply, $action, [
            'reply_kind' => $replyKind,
            'entities' => $entities,
        ]);
    }

    private function inferReplyKind(?string $action, string $reply): string
    {
        if ($action === 'confirm_large_transaction' || str_contains(mb_strtolower($reply), 'confirma')) {
            return 'confirmation_request';
        }

        if ($action !== null && str_starts_with($action, 'query_')) {
            return 'query';
        }

        if ($action !== null) {
            return 'action';
        }

        return 'message';
    }

    private function persistState(WhatsAppContact $contact, array $state): void
    {
        $contact->forceFill(['conversation_state' => $this->normalizeState($state)])->save();
    }

    private function normalizeState(array $state): array
    {
        return [
            'mode' => $state['mode'] ?? 'idle',
            'pending_intent' => $state['pending_intent'] ?? null,
            'pending_payload' => is_array($state['pending_payload'] ?? null) ? $state['pending_payload'] : [],
            'last_action' => $state['last_action'] ?? null,
            'last_entities' => is_array($state['last_entities'] ?? null) ? $state['last_entities'] : [],
            'last_reply_kind' => $state['last_reply_kind'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }
}
