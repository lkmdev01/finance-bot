<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppContact;
use Illuminate\Support\Carbon;

class ConversationStateService
{
    private const MAX_CONTEXT_ITEMS = 20;
    private const MAX_RECENT_CONTEXTS = 16;
    private const MAX_UNDO_STACK = 8;

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

    public function replaceUndoStack(WhatsAppContact $contact, array $undoStack): void
    {
        $state = $this->getState($contact);
        $state['undo_stack'] = array_slice($this->sanitizeValue($undoStack), 0, self::MAX_UNDO_STACK);
        $state['updated_at'] = now()->toIso8601String();
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

    public function markAwaitingClarification(WhatsAppContact $contact, string $intent, array $payload = []): void
    {
        $state = $this->getState($contact);
        $state['mode'] = 'awaiting_clarification';
        $state['pending_intent'] = $intent;
        $state['pending_payload'] = $payload;
        $state['updated_at'] = now()->toIso8601String();
        $this->persistState($contact, $state);
    }

    public function rememberLastAction(WhatsAppContact $contact, ?string $action, array $entities = [], ?string $replyKind = null): void
    {
        $state = $this->getState($contact);
        $state['last_action'] = $action;
        $state['last_entities'] = $this->sanitizeValue($entities);
        $state['last_reply_kind'] = $replyKind;
        $state['updated_at'] = now()->toIso8601String();

        if ($action !== null || $entities !== []) {
            $recentContexts = is_array($state['recent_contexts'] ?? null) ? $state['recent_contexts'] : [];
            array_unshift($recentContexts, [
                'action' => $action,
                'entities' => $this->sanitizeValue($entities),
                'reply_kind' => $replyKind,
                'timestamp' => now()->toIso8601String(),
            ]);

            $state['recent_contexts'] = array_slice($recentContexts, 0, self::MAX_RECENT_CONTEXTS);
        }

        $this->persistState($contact, $state);
    }

    public function rememberIncomingMedia(WhatsAppContact $contact, int $incomingMediaId, array $mediaMeta = []): void
    {
        $state = $this->getState($contact);
        $state['last_entities'] = is_array($state['last_entities'] ?? null) ? $state['last_entities'] : [];
        $state['last_entities']['incoming_media_id'] = $incomingMediaId;
        if ($mediaMeta !== []) {
            $state['last_entities']['incoming_media_meta'] = $this->sanitizeValue($mediaMeta);
        }
        $state['updated_at'] = now()->toIso8601String();
        $this->persistState($contact, $state);
    }

    public function rememberInteraction(WhatsAppContact $contact, string $message, string $reply, ?string $action, array $meta = []): void
    {
        $context = $this->sanitizeValue($contact->context ?? []);
        $context[] = [
            'message' => $this->sanitizeValue($message),
            'reply' => $this->sanitizeValue($reply),
            'action' => $action,
            'meta' => $this->sanitizeValue($meta),
            'timestamp' => now()->toIso8601String(),
        ];

        if (count($context) > self::MAX_CONTEXT_ITEMS) {
            $context = array_slice($context, -self::MAX_CONTEXT_ITEMS);
        }

        $contact->forceFill(['context' => $this->sanitizeValue($context)])->save();
    }

    public function recordProactiveMessage(WhatsAppContact $contact, string $message, string $reply, string $key): void
    {
        $this->rememberInteraction($contact, $message, $reply, null, [
            'reply_kind' => 'proactive',
            'proactive_key' => $key,
        ]);

        $state = $this->getState($contact);
        $state['last_proactive_key'] = $key;
        $state['last_proactive_at'] = now()->toIso8601String();
        $this->persistState($contact, $state);
    }

    public function wasRecentlyDispatched(WhatsAppContact $contact, string $key, int $minutes = 60): bool
    {
        $state = $this->getState($contact);

        if (($state['last_proactive_key'] ?? null) !== $key) {
            return false;
        }

        if (blank($state['last_proactive_at'] ?? null)) {
            return false;
        }

        return Carbon::parse($state['last_proactive_at'])->greaterThanOrEqualTo(now()->subMinutes($minutes));
    }

    public function applyHandledResult(WhatsAppContact $contact, string $message, ?string $action, string $reply, array $metadata = []): void
    {
        $replyKind = $metadata['reply_kind'] ?? $this->inferReplyKind($action, $reply);
        $entities = $metadata['entities'] ?? [];

        // Merge any preference updates from handlers/resolvers.
        if (! empty($metadata['preferences']) && is_array($metadata['preferences'])) {
            $state = $this->getState($contact);
            $state['preferences'] = $this->mergePreferences($state['preferences'] ?? [], $metadata['preferences']);
            $state['updated_at'] = now()->toIso8601String();
            $this->persistState($contact, $state);
        }

        // Push undo entries (if any) produced by deterministic handlers.
        if (! empty($metadata['undo'])) {
            $state = $this->getState($contact);
            $state['undo_stack'] = $this->pushUndoEntry($state['undo_stack'] ?? [], $metadata['undo']);
            $state['updated_at'] = now()->toIso8601String();
            $this->persistState($contact, $state);
        }

        if (($metadata['pending_intent'] ?? null) !== null) {
            $pendingMode = $metadata['pending_mode'] ?? 'awaiting_confirmation';

            if ($pendingMode === 'awaiting_clarification') {
                $this->markAwaitingClarification($contact, $metadata['pending_intent'], $metadata['pending_payload'] ?? []);
            } else {
                $this->markAwaitingConfirmation($contact, $metadata['pending_intent'], $metadata['pending_payload'] ?? []);
            }
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
        $contact->forceFill([
            'conversation_state' => $this->sanitizeValue($this->normalizeState($state)),
        ])->save();
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
            'recent_contexts' => is_array($state['recent_contexts'] ?? null) ? array_slice($state['recent_contexts'], 0, self::MAX_RECENT_CONTEXTS) : [],
            'preferences' => is_array($state['preferences'] ?? null) ? $state['preferences'] : [],
            'undo_stack' => is_array($state['undo_stack'] ?? null) ? array_slice($state['undo_stack'], 0, self::MAX_UNDO_STACK) : [],
            'last_proactive_key' => $state['last_proactive_key'] ?? null,
            'last_proactive_at' => $state['last_proactive_at'] ?? null,
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    private function mergePreferences(array $existing, array $incoming): array
    {
        $existing = $this->sanitizeValue($existing);
        $incoming = $this->sanitizeValue($incoming);

        foreach ($incoming as $key => $value) {
            if (is_array($value) && is_array($existing[$key] ?? null)) {
                $existing[$key] = $this->mergePreferences($existing[$key], $value);
                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    private function pushUndoEntry(array $stack, mixed $undo): array
    {
        // Accept either a single entry or a list of entries.
        $entries = $undo;
        if (! is_array($entries)) {
            return $stack;
        }

        $isList = array_is_list($entries);
        if (! $isList || (isset($entries['kind']) || isset($entries['action']))) {
            $entries = [$entries];
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $sanitized = $this->sanitizeValue($entry);
            array_unshift($stack, $sanitized);
        }

        return array_slice($stack, 0, self::MAX_UNDO_STACK);
    }

    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->sanitizeValue($item);
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($sanitized === false) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $sanitized;
    }
}
