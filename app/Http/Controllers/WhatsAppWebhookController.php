<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Services\AudioTranscriptionService;
use App\Services\BaileysService;
use App\Services\OCRService;
use App\Services\PhoneNumberService;
use App\Services\WhatsAppActivationService;
use App\Services\WhatsAppDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly WhatsAppActivationService $whatsAppActivationService,
        private readonly OCRService $ocrService,
        private readonly AudioTranscriptionService $audioTranscriptionService,
        private readonly WhatsAppDocumentService $whatsAppDocumentService,
        private readonly BaileysService $baileysService
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            $expectedSecret = config('whatsapp.baileys.webhook_secret');
            $receivedSecret = $data['secret'] ?? null;

            if ($receivedSecret !== $expectedSecret) {
                Log::warning('Webhook recebido com secret invalido', [
                    'expected' => $expectedSecret,
                    'received' => $receivedSecret,
                    'match' => $receivedSecret === $expectedSecret,
                ]);

                return response()->json(['status' => 'unauthorized'], 401);
            }

            if (isset($data['signature'], $data['timestamp'])) {
                $payload = json_encode($data['data'] ?? []).$data['timestamp'];
                $expectedSignature = hash_hmac('sha256', $payload, $expectedSecret);

                if (! hash_equals($expectedSignature, $data['signature'])) {
                    Log::warning('Webhook recebido com assinatura HMAC invalida', [
                        'expected' => $expectedSignature,
                        'received' => $data['signature'],
                    ]);

                    return response()->json(['status' => 'invalid_signature'], 401);
                }
            }

            Log::info('Webhook recebido do Baileys', $data);

            if (($data['event'] ?? null) !== 'messages.upsert') {
                return response()->json(['status' => 'ignored']);
            }

            $messageData = $data['data'] ?? null;
            if (! $messageData) {
                return response()->json(['status' => 'no_data']);
            }

            $key = $messageData['key'] ?? [];
            $message = $messageData['message'] ?? [];
            $messageType = $message['messageType'] ?? null;
            $pushName = $messageData['pushName'] ?? $data['pushName'] ?? null;

            if (! in_array($messageType, ['conversation', 'extendedTextMessage', 'imageMessage', 'audioMessage', 'documentMessage'], true)) {
                return response()->json(['status' => 'not_supported_message_type']);
            }

            if (($key['fromMe'] ?? false) === true) {
                Log::debug('Mensagem ignorada: enviada pelo bot', [
                    'remoteJid' => $key['remoteJid'] ?? null,
                ]);

                return response()->json(['status' => 'bot_message_ignored']);
            }

            $phoneNumber = $messageData['key']['phoneNumber'] ?? $key['remoteJid'] ?? null;

            if (! $phoneNumber) {
                Log::warning('Mensagem sem remoteJid', ['key' => $key]);

                return response()->json(['status' => 'no_phone']);
            }

            $phoneNumber = $this->phoneNumberService->removeWhatsAppSuffixes($phoneNumber);
            $phoneNumber = $this->phoneNumberService->clean($phoneNumber);

            Log::debug('Numero processado do WhatsApp', [
                'remoteJid_original' => $key['remoteJid'] ?? null,
                'phoneNumber_processado' => $phoneNumber,
            ]);

            $text = $message['conversation'] ?? $message['extendedTextMessage']['text'] ?? '';

            if ($activation = $this->whatsAppActivationService->verifyCodeFromIncomingMessage($text, $phoneNumber)) {
                $this->sendReply(
                    $key,
                    $phoneNumber,
                    $this->whatsAppActivationService->activationSuccessMessage()
                );

                Log::info('Codigo de ativacao do WhatsApp validado', [
                    'activation_id' => $activation->id,
                    'phone_number' => $phoneNumber,
                    'client_key' => $activation->client_key,
                ]);

                return response()->json(['status' => 'activation_verified']);
            }

            $user = $this->identifyUserByPhoneNumber($phoneNumber);

            if (! $user && $pushName) {
                $user = $this->identifyUserByPushName($pushName);

                if ($user) {
                    Log::info('Usuario identificado por pushName (fallback)', [
                        'pushName' => $pushName,
                        'user_id' => $user->id,
                    ]);
                }
            }

            if (! $user) {
                return $this->respondNoUser($key, $phoneNumber, $pushName);
            }

            if (! $user->whatsapp_verified_at) {
                $this->sendReply(
                    $key,
                    $phoneNumber,
                    'Seu número ainda não foi ativado no InovaFinance. Envie o código que aparece na sua tela de ativação para concluir a conexão.'
                );

                return response()->json([
                    'status' => 'activation_pending',
                ]);
            }

            $imageUrl = null;
            $audioBase64 = $messageData['audioBase64'] ?? null;
            $audioMimeType = $message['audioMessage']['mimetype'] ?? $messageData['audioMimeType'] ?? null;
            $documentBase64 = $messageData['documentBase64'] ?? null;
            $documentMimeType = $message['documentMessage']['mimetype'] ?? $messageData['documentMimeType'] ?? null;
            $documentFileName = $message['documentMessage']['fileName'] ?? $messageData['documentFileName'] ?? 'documento';

            if ($messageType === 'imageMessage') {
                $imageUrl = $message['imageMessage']['url'] ?? $messageData['imageUrl'] ?? null;
                $caption = $message['imageMessage']['caption'] ?? '';

                if ($caption !== '') {
                    $text = $caption;
                } elseif ($imageUrl) {
                    $ocrText = $this->ocrService->extractText($imageUrl);
                    $text = $ocrText
                        ? "Imagem recebida. Texto extraido: {$ocrText}"
                        : 'Imagem recebida. Nao consegui extrair texto. Descreva a transacao na mensagem.';
                }
            }

            if ($messageType === 'audioMessage' && $audioBase64) {
                $text = $this->audioTranscriptionService->transcribeBase64($audioBase64, $audioMimeType) ?? '';
            }

            if ($messageType === 'documentMessage' && $documentBase64) {
                $documentResult = $this->whatsAppDocumentService->processBase64(
                    $user,
                    $documentBase64,
                    $documentMimeType,
                    $documentFileName
                );

                if (($documentResult['status'] ?? null) === 'imported') {
                    $this->sendReply($key, $phoneNumber, $documentResult['message'] ?? 'Documento importado com sucesso.');

                    return response()->json([
                        'status' => 'document_imported',
                        'imported' => $documentResult['result']['imported'] ?? 0,
                    ]);
                }

                if (($documentResult['status'] ?? null) === 'requires_subscription') {
                    $this->sendReply($key, $phoneNumber, $documentResult['message'] ?? 'Seu teste gratuito terminou. Assine um plano para voltar a registrar novas informações.');

                    return response()->json([
                        'status' => 'requires_subscription',
                    ]);
                }

                if (($documentResult['status'] ?? null) === 'text_extracted') {
                    $text = $documentResult['text'] ?? '';
                } else {
                    $this->sendReply($key, $phoneNumber, $documentResult['message'] ?? 'Nao consegui processar o documento enviado.');

                    return response()->json([
                        'status' => $documentResult['status'] ?? 'document_processing_error',
                    ]);
                }
            }

            if (empty($text) && empty($imageUrl)) {
                if ($messageType === 'audioMessage') {
                    $this->sendReply($key, $phoneNumber, 'Nao consegui transcrever sua mensagem de voz. Pode me enviar em texto?');

                    return response()->json(['status' => 'audio_transcription_failed']);
                }

                return response()->json(['status' => 'empty_message']);
            }

            $text = htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8');
            $text = mb_substr($text, 0, 1000);

            $realPhoneNumber = $user->phone_number;

            if (! $realPhoneNumber) {
                $realPhoneNumber = $phoneNumber;

                Log::warning('Usuario sem numero cadastrado, usando JID como fallback', [
                    'user_id' => $user->id,
                    'jid_processado' => $phoneNumber,
                ]);
            }

            Log::info('Mensagem recebida de usuario identificado', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'phone_number_jid' => $phoneNumber,
                'phone_number_real' => $realPhoneNumber,
                'message_preview' => substr($text, 0, 50),
            ]);

            ProcessWhatsAppMessage::dispatch(
                $realPhoneNumber,
                $text,
                $user->id,
                $pushName,
                $key['remoteJid'] ?? null,
                $imageUrl
            );

            return response()->json(['status' => 'queued']);
        } catch (\Exception $e) {
            Log::error('Erro ao processar webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    private function respondNoUser(array $key, string $phoneNumber, ?string $pushName): JsonResponse
    {
        Log::warning('Nenhum usuario encontrado para processar mensagem do WhatsApp', [
            'phone_number' => $phoneNumber,
            'remoteJid_original' => $key['remoteJid'] ?? null,
            'pushName' => $pushName,
        ]);

        $appUrl = config('app.url');
        $registerUrl = $appUrl.'/register';
        $message = "Olá! Seu número ainda não está vinculado a uma conta do InovaFinance.\n\n".
            "Para eu começar a cuidar das suas finanças:\n\n".
            "1. Crie sua conta em: {$registerUrl}\n".
            "2. Conclua a ativação do WhatsApp no próprio cadastro\n\n".
            'Depois disso, basta me enviar seus gastos ou ganhos.';

        $this->sendReply($key, $phoneNumber, $message);

        return response()->json([
            'status' => 'no_user',
            'message' => 'Convite enviado via WhatsApp.',
        ]);
    }

    private function sendReply(array $key, string $phoneNumber, string $message): void
    {
        try {
            $recipientJid = $key['remoteJid'] ?? $this->phoneNumberService->toWhatsAppJid($phoneNumber);
            $this->baileysService->sendTextMessage($recipientJid, $message);
        } catch (\Exception $e) {
            Log::error('Erro ao enviar mensagem via Baileys', [
                'error' => $e->getMessage(),
                'remoteJid' => $key['remoteJid'] ?? null,
            ]);
        }
    }

    private function identifyUserByPhoneNumber(string $phoneNumber): ?User
    {
        $normalized = $this->phoneNumberService->normalize($phoneNumber);
        $user = User::where('phone_number', $normalized)->first();

        if ($user) {
            Log::debug('Usuario encontrado pelo numero exato', ['user_id' => $user->id]);

            return $user;
        }

        $variations = $this->phoneNumberService->getVariations($phoneNumber);

        foreach ($variations as $variation) {
            $user = User::where('phone_number', $variation)->first();
            if ($user) {
                Log::info('Usuario encontrado por variacao do numero', [
                    'original' => $phoneNumber,
                    'variation' => $variation,
                    'user_id' => $user->id,
                ]);

                return $user;
            }
        }

        Log::warning('Usuario nao encontrado por numero de telefone', [
            'phone_number' => $phoneNumber,
            'normalized' => $normalized,
            'variations_tried' => $variations,
        ]);

        return null;
    }

    private function identifyUserByPushName(string $pushName): ?User
    {
        if (strlen($pushName) < 3) {
            return null;
        }

        return User::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($pushName).'%'])->first();
    }
}
