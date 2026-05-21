<?php

namespace App\Services\WhatsApp;

use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversationLog;

class ConversationTelemetryService
{
    public function record(
        User $user,
        ?WhatsAppContact $contact,
        string $message,
        array $payload = []
    ): WhatsAppConversationLog {
        return WhatsAppConversationLog::query()->create([
            'user_id' => $user->id,
            'whats_app_contact_id' => $contact?->id,
            'phone_number' => $contact?->phone_number ?? $payload['phone_number'] ?? null,
            'message' => $this->sanitize($message),
            'classification' => $payload['classification'] ?? null,
            'action' => $payload['action'] ?? null,
            'handler' => $payload['handler'] ?? null,
            'used_ai' => (bool) ($payload['used_ai'] ?? false),
            'status' => $payload['status'] ?? 'processed',
            'reply' => isset($payload['reply']) ? $this->sanitize((string) $payload['reply']) : null,
            'error_type' => $payload['error_type'] ?? null,
            'error_message' => isset($payload['error_message']) ? $this->sanitize((string) $payload['error_message']) : null,
            'metadata' => $this->sanitizeArray($payload['metadata'] ?? []),
        ]);
    }

    private function sanitizeArray(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitizeArray($value);
                continue;
            }

            if (is_string($value)) {
                $payload[$key] = $this->sanitize($value);
            }
        }

        return $payload;
    }

    private function sanitize(string $value): string
    {
        $sanitized = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

        if ($sanitized === false) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $sanitized;
    }
}
