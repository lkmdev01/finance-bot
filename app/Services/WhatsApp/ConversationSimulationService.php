<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Models\WhatsAppConversationLog;
use App\Models\WhatsAppIncomingMedia;
use App\Services\BaileysService;
use App\Services\WhatsAppIncomingMediaService;
use Illuminate\Support\Arr;

class ConversationSimulationService
{
    public function __construct(
        private readonly WhatsAppIncomingMediaService $incomingMediaService,
    ) {}

    public function prepareContact(User $user, string $phoneNumber, string $pushName = 'Codex Local', bool $resetState = false): WhatsAppContact
    {
        $contact = WhatsAppContact::firstOrCreate(
            [
                'user_id' => $user->id,
                'phone_number' => $phoneNumber,
            ],
            [
                'name' => $pushName,
                'context' => [],
                'conversation_state' => [],
            ],
        );

        if ($resetState) {
            $contact->forceFill([
                'conversation_state' => [],
                'context' => [],
            ])->save();
        }

        return $contact->fresh();
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function simulate(User $user, WhatsAppContact $contact, string $phoneNumber, array $entries, array $options = []): array
    {
        $transport = new SimulatedBaileysTransport();
        app()->instance(BaileysService::class, $transport);

        $rounds = [];

        foreach (array_values($entries) as $index => $entry) {
            $preparedEntry = $this->prepareEntry($user, $phoneNumber, $entry, $options);
            $this->applyContactOverrides($contact, $preparedEntry);
            $beforeSentCount = count($transport->allMessages());

            $job = new ProcessWhatsAppMessage(
                phoneNumber: $phoneNumber,
                message: (string) ($preparedEntry['message'] ?? ''),
                userId: $user->id,
                pushName: (string) ($preparedEntry['push_name'] ?? ($options['push_name'] ?? 'Codex Local')),
                remoteJid: (string) ($preparedEntry['remote_jid'] ?? $this->resolveRemoteJid($phoneNumber, $options)),
                imageUrl: $preparedEntry['image_url'] ?? null,
                incomingMediaId: isset($preparedEntry['incoming_media_id']) ? (int) $preparedEntry['incoming_media_id'] : null,
            );

            app()->call([$job, 'handle']);

            $contact->refresh();
            $lastLog = WhatsAppConversationLog::query()
                ->where('user_id', $user->id)
                ->where('whats_app_contact_id', $contact->id)
                ->latest('id')
                ->first();

            $sentMessages = array_slice($transport->allMessages(), $beforeSentCount);
            $reply = $job->getFinalReply() ?? ($sentMessages !== [] ? end($sentMessages)['message'] : '');
            $assertions = $this->evaluateAssertions($preparedEntry, $reply, $lastLog, $contact);

            $rounds[] = [
                'index' => $index + 1,
                'label' => $preparedEntry['label'] ?? null,
                'input' => [
                    'message' => $preparedEntry['message'] ?? '',
                    'image_url' => $preparedEntry['image_url'] ?? null,
                    'incoming_media_id' => $preparedEntry['incoming_media_id'] ?? null,
                    'media_path' => $preparedEntry['media_path'] ?? null,
                    'media_kind' => $preparedEntry['media_kind'] ?? null,
                    'mime_type' => $preparedEntry['mime_type'] ?? null,
                    'file_name' => $preparedEntry['file_name'] ?? null,
                ],
                'reply' => $reply,
                'sent_messages' => $sentMessages,
                'state' => $contact->conversation_state ?? [],
                'log' => $this->summarizeLog($lastLog),
                'assertions' => $assertions,
            ];
        }

        return [
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'phone_number' => $phoneNumber,
            ],
            'contact_id' => $contact->id,
            'generated_at' => now()->toIso8601String(),
            'rounds' => $rounds,
            'all_passed' => collect($rounds)->every(fn (array $round) => ($round['assertions']['passed'] ?? true) === true),
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function prepareEntry(User $user, string $phoneNumber, array $entry, array $options): array
    {
        $entry['message'] = trim((string) ($entry['message'] ?? ''));
        $entry['push_name'] = $entry['push_name'] ?? ($options['push_name'] ?? 'Codex Local');
        $entry['remote_jid'] = $entry['remote_jid'] ?? $this->resolveRemoteJid($phoneNumber, $options);

        if (! empty($entry['media_path']) && empty($entry['incoming_media_id'])) {
            $incomingMedia = $this->storeMediaFromPath($user, $phoneNumber, $entry);
            if ($incomingMedia instanceof WhatsAppIncomingMedia) {
                $entry['incoming_media_id'] = $incomingMedia->id;
            }
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function applyContactOverrides(WhatsAppContact $contact, array $entry): void
    {
        $attributes = [];

        if (is_array($entry['state'] ?? null)) {
            $currentState = is_array($contact->conversation_state) ? $contact->conversation_state : [];
            $attributes['conversation_state'] = ($entry['replace_state'] ?? false) === true
                ? $entry['state']
                : array_replace_recursive($currentState, $entry['state']);
        }

        if (is_array($entry['context'] ?? null)) {
            $currentContext = is_array($contact->context) ? $contact->context : [];
            $attributes['context'] = ($entry['replace_context'] ?? false) === true
                ? $entry['context']
                : array_replace_recursive($currentContext, $entry['context']);
        }

        if ($attributes !== []) {
            $contact->forceFill($attributes)->save();
            $contact->refresh();
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function storeMediaFromPath(User $user, string $phoneNumber, array $entry): ?WhatsAppIncomingMedia
    {
        $path = (string) ($entry['media_path'] ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        $binary = file_get_contents($path);
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $mimeType = (string) ($entry['mime_type'] ?? '');
        if ($mimeType === '') {
            $mimeType = mime_content_type($path) ?: 'application/octet-stream';
        }

        $fileName = (string) ($entry['file_name'] ?? basename($path));
        $kind = strtolower((string) ($entry['media_kind'] ?? ''));
        $base64 = base64_encode($binary);
        $metadata = is_array($entry['media_metadata'] ?? null) ? $entry['media_metadata'] : [];

        if ($kind === '') {
            $kind = str_starts_with($mimeType, 'image/') ? 'image' : (str_starts_with($mimeType, 'audio/') ? 'audio' : 'document');
        }

        return match ($kind) {
            'image' => $this->incomingMediaService->storeFromImageBase64($user, $phoneNumber, $base64, $mimeType, $fileName, $metadata),
            'audio' => $this->incomingMediaService->storeFromAudioBase64($user, $phoneNumber, $base64, $mimeType, $metadata),
            default => $this->incomingMediaService->storeFromDocumentBase64($user, $phoneNumber, $base64, $mimeType, $fileName, $metadata),
        };
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function evaluateAssertions(array $entry, string $reply, ?WhatsAppConversationLog $lastLog, WhatsAppContact $contact): array
    {
        $violations = [];

        $expectedIntent = $entry['expected_intent'] ?? null;
        if (is_string($expectedIntent) && $expectedIntent !== '') {
            $actualIntent = data_get($lastLog?->metadata, 'assistant_intent');

            if ($actualIntent !== $expectedIntent) {
                $violations[] = [
                    'field' => 'expected_intent',
                    'expected' => $expectedIntent,
                    'actual' => $actualIntent,
                ];
            }
        }

        $expectedClassification = $entry['expected_classification'] ?? null;
        if (is_string($expectedClassification) && $expectedClassification !== '') {
            $actualClassification = $lastLog?->classification;

            if ($actualClassification !== $expectedClassification) {
                $violations[] = [
                    'field' => 'expected_classification',
                    'expected' => $expectedClassification,
                    'actual' => $actualClassification,
                ];
            }
        }

        $expectedAction = $entry['expected_action'] ?? null;
        if (is_string($expectedAction) && $expectedAction !== '') {
            $actualAction = $lastLog?->action;

            if ($actualAction !== $expectedAction) {
                $violations[] = [
                    'field' => 'expected_action',
                    'expected' => $expectedAction,
                    'actual' => $actualAction,
                ];
            }
        }

        $expectedMissingFields = array_values(array_filter(Arr::wrap($entry['expected_missing_fields'] ?? $entry['expected_missing_field'] ?? []), fn ($value) => is_string($value) && $value !== ''));
        if ($expectedMissingFields !== []) {
            $actualMissingFields = Arr::wrap(data_get($lastLog?->metadata, 'assistant_missing_fields', []));

            foreach ($expectedMissingFields as $field) {
                if (! in_array($field, $actualMissingFields, true)) {
                    $violations[] = [
                        'field' => 'expected_missing_fields',
                        'expected' => $field,
                        'actual' => $actualMissingFields,
                    ];
                }
            }
        }

        foreach (Arr::wrap($entry['expected_reply_contains'] ?? []) as $needle) {
            if (! is_string($needle) || $needle === '') {
                continue;
            }

            if (! str_contains(mb_strtolower($reply), mb_strtolower($needle))) {
                $violations[] = [
                    'field' => 'expected_reply_contains',
                    'expected' => $needle,
                    'actual' => $reply,
                ];
            }
        }

        foreach (Arr::wrap($entry['expected_reply_not_contains'] ?? []) as $needle) {
            if (! is_string($needle) || $needle === '') {
                continue;
            }

            if (str_contains(mb_strtolower($reply), mb_strtolower($needle))) {
                $violations[] = [
                    'field' => 'expected_reply_not_contains',
                    'expected' => $needle,
                    'actual' => $reply,
                ];
            }
        }

        $expectedPendingIntent = $entry['expected_pending_intent'] ?? null;
        if (is_string($expectedPendingIntent) && $expectedPendingIntent !== '') {
            $actualPendingIntent = $contact->conversation_state['pending_intent'] ?? null;

            if ($actualPendingIntent !== $expectedPendingIntent) {
                $violations[] = [
                    'field' => 'expected_pending_intent',
                    'expected' => $expectedPendingIntent,
                    'actual' => $actualPendingIntent,
                ];
            }
        }

        $expectedStateTopic = $entry['expected_state_topic'] ?? null;
        if (is_string($expectedStateTopic) && $expectedStateTopic !== '') {
            $actualStateTopic = data_get($contact->conversation_state, 'last_entities.topic');

            if ($actualStateTopic !== $expectedStateTopic) {
                $violations[] = [
                    'field' => 'expected_state_topic',
                    'expected' => $expectedStateTopic,
                    'actual' => $actualStateTopic,
                ];
            }
        }

        $expectedLastAction = $entry['expected_last_action'] ?? null;
        if (is_string($expectedLastAction) && $expectedLastAction !== '') {
            $actualLastAction = data_get($contact->conversation_state, 'last_action');

            if ($actualLastAction !== $expectedLastAction) {
                $violations[] = [
                    'field' => 'expected_last_action',
                    'expected' => $expectedLastAction,
                    'actual' => $actualLastAction,
                ];
            }
        }

        return [
            'passed' => $violations === [],
            'violations' => $violations,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function summarizeLog(?WhatsAppConversationLog $log): ?array
    {
        if (! $log) {
            return null;
        }

        return [
            'id' => $log->id,
            'classification' => $log->classification,
            'action' => $log->action,
            'handler' => $log->handler,
            'status' => $log->status,
            'used_ai' => $log->used_ai,
            'assistant_intent' => data_get($log->metadata, 'assistant_intent'),
            'assistant_domain' => data_get($log->metadata, 'assistant_domain'),
            'assistant_missing_fields' => data_get($log->metadata, 'assistant_missing_fields', []),
            'reply_kind' => data_get($log->metadata, 'reply_kind'),
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function resolveRemoteJid(string $phoneNumber, array $options): string
    {
        $explicitRemoteJid = trim((string) ($options['remote_jid'] ?? ''));
        if ($explicitRemoteJid !== '') {
            return $explicitRemoteJid;
        }

        if (str_contains($phoneNumber, '@')) {
            return $phoneNumber;
        }

        return "{$phoneNumber}@s.whatsapp.net";
    }
}
