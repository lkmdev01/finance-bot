<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Services\BaileysService;
use App\Services\OCRService;
use App\Services\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly PhoneNumberService $phoneNumberService,
        private readonly OCRService $ocrService,
        private readonly BaileysService $baileysService
    ) {}

    /**
     * Recebe webhook do serviço Baileys
     *
     * IMPORTANTE: Há dois números envolvidos:
     * 1. Número do BOT (fixo): Número conectado no whatsapp-service que RECEBE mensagens
     * 2. Número do USUÁRIO: Número cadastrado em users.phone_number que ENVIA mensagens
     *
     * Quando um usuário envia mensagem para o bot:
     * - remoteJid = número do USUÁRIO (quem enviou)
     * - fromMe = false (mensagem recebida pelo bot)
     *
     * Quando o bot envia mensagem:
     * - fromMe = true (mensagem enviada pelo bot)
     * - Não devemos processar essas mensagens
     */
    public function handle(Request $request): JsonResponse
    {
        try {
            $data = $request->all();

            // Valida secret do webhook
            $expectedSecret = config('whatsapp.baileys.webhook_secret');
            $receivedSecret = $data['secret'] ?? null;

            if ($receivedSecret !== $expectedSecret) {
                Log::warning('Webhook recebido com secret inválido', [
                    'expected' => $expectedSecret,
                    'received' => $receivedSecret,
                    'match' => $receivedSecret === $expectedSecret,
                ]);

                return response()->json(['status' => 'unauthorized'], 401);
            }

            // Valida assinatura HMAC se fornecida (opcional - depende do serviço Node.js)
            if (isset($data['signature']) && isset($data['timestamp'])) {
                $payload = json_encode($data['data'] ?? []).$data['timestamp'];
                $expectedSignature = hash_hmac('sha256', $payload, $expectedSecret);

                if (! hash_equals($expectedSignature, $data['signature'])) {
                    Log::warning('Webhook recebido com assinatura HMAC inválida', [
                        'expected' => $expectedSignature,
                        'received' => $data['signature'],
                    ]);

                    return response()->json(['status' => 'invalid_signature'], 401);
                }
            }

            // Log para debug
            Log::info('Webhook recebido do Baileys', $data);

            // Verifica se é uma mensagem recebida
            if (! isset($data['event']) || $data['event'] !== 'messages.upsert') {
                return response()->json(['status' => 'ignored']);
            }

            $messageData = $data['data'] ?? null;
            if (! $messageData) {
                return response()->json(['status' => 'no_data']);
            }

            // Verifica se é uma mensagem de texto ou imagem recebida (não enviada)
            $key = $messageData['key'] ?? [];
            $message = $messageData['message'] ?? [];
            $messageType = $message['messageType'] ?? null;

            // Suporta mensagens de texto e imagem (para OCR)
            if (! in_array($messageType, ['conversation', 'extendedTextMessage', 'imageMessage'])) {
                return response()->json(['status' => 'not_supported_message_type']);
            }

            // IMPORTANTE: fromMe indica se a mensagem foi enviada PELO BOT (número fixo conectado)
            // Se fromMe === true, é uma mensagem que o BOT enviou, não devemos processar
            $fromMe = $key['fromMe'] ?? false;

            // Ignora TODAS as mensagens enviadas pelo bot (número fixo)
            // Apenas processa mensagens RECEBIDAS de usuários (fromMe === false)
            if ($fromMe) {
                Log::debug('Mensagem ignorada: enviada pelo bot', [
                    'remoteJid' => $key['remoteJid'] ?? null,
                ]);

                return response()->json(['status' => 'bot_message_ignored']);
            }

            // Extrai número do remetente (usuário que enviou a mensagem)
            // remoteJid = número do USUÁRIO que enviou a mensagem para o bot
            // Se o serviço Node.js já processou o número, usa ele, senão processa o remoteJid
            $phoneNumber = $messageData['key']['phoneNumber'] ?? $key['remoteJid'] ?? null;

            if (! $phoneNumber) {
                Log::warning('Mensagem sem remoteJid', ['key' => $key]);

                return response()->json(['status' => 'no_phone']);
            }

            // Remove sufixos do WhatsApp e normaliza
            $phoneNumber = $this->phoneNumberService->removeWhatsAppSuffixes($phoneNumber);
            $phoneNumber = $this->phoneNumberService->clean($phoneNumber);

            Log::debug('Número processado do WhatsApp', [
                'remoteJid_original' => $key['remoteJid'] ?? null,
                'phoneNumber_processado' => $phoneNumber,
            ]);

            // Extrai e sanitiza texto da mensagem
            $text = $message['conversation'] ?? $message['extendedTextMessage']['text'] ?? '';
            $imageUrl = null;

            // Se for imagem, processa OCR
            if ($messageType === 'imageMessage') {
                $imageUrl = $message['imageMessage']['url'] ?? $messageData['imageUrl'] ?? null;
                $caption = $message['imageMessage']['caption'] ?? '';

                // Se tiver legenda, usa ela
                if (! empty($caption)) {
                    $text = $caption;
                } elseif ($imageUrl) {
                    // Se não tiver legenda, processa OCR
                    $ocrText = $this->ocrService->extractText($imageUrl);
                    if ($ocrText) {
                        $text = "📷 Imagem recebida. Texto extraído: {$ocrText}";
                    } else {
                        $text = '📷 Imagem recebida. Não consegui extrair texto. Descreva a transação na mensagem.';
                    }
                }
            }

            if (empty($text) && empty($imageUrl)) {
                return response()->json(['status' => 'empty_message']);
            }

            // Sanitiza mensagem: remove caracteres perigosos e limita tamanho
            $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            $text = trim($text);
            $text = mb_substr($text, 0, 1000); // Limita a 1000 caracteres

            // Identifica usuário pelo número de telefone (remoteJid = número do usuário que enviou)
            $user = $this->identifyUserByPhoneNumber($phoneNumber);

            // Se não encontrou por número e temos pushName, tenta identificar por nome
            if (! $user && isset($data['pushName']) && $data['pushName']) {
                $user = $this->identifyUserByPushName($data['pushName']);
                if ($user) {
                    Log::info('Usuário identificado por pushName (fallback)', [
                        'pushName' => $data['pushName'],
                        'user_id' => $user->id,
                    ]);
                }
            }

            if (! $user) {
                Log::warning('Nenhum usuário encontrado para processar mensagem do WhatsApp', [
                    'phone_number' => $phoneNumber,
                    'remoteJid_original' => $key['remoteJid'] ?? null,
                    'pushName' => $data['pushName'] ?? null,
                ]);

                // Envia mensagem de convite para registro
                $appUrl = config('app.url');
                $registerUrl = $appUrl . '/register';
                $whatsappUrl = $appUrl . '/settings/whatsapp';

                $unregisteredMessage = "Olá! 👋 Identificamos que seu número ainda não está vinculado a uma conta no *InovaFinance*.\n\n".
                    "Para que eu possa gerenciar suas finanças, siga estes passos:\n\n".
                    "1️⃣ Crie sua conta em: $registerUrl\n".
                    "2️⃣ Nas configurações, vincule este número em: $whatsappUrl\n\n".
                    "Depois disso, basta me enviar seus gastos ou ganhos! 🚀";

                try {
                    $recipientJid = $key['remoteJid'] ?? $this->phoneNumberService->toWhatsAppJid($phoneNumber);
                    $this->baileysService->sendTextMessage($recipientJid, $unregisteredMessage);
                } catch (\Exception $e) {
                    Log::error('Erro ao enviar mensagem de convite', ['error' => $e->getMessage()]);
                }

                return response()->json([
                    'status' => 'no_user',
                    'message' => 'Convite enviado via WhatsApp.',
                ]);
            }

            // IMPORTANTE: Usa o número REAL do usuário cadastrado, não o JID processado
            // O JID pode ser um ID interno (@lid), mas o número real está em users.phone_number
            // Se o usuário não tiver número cadastrado, usa o JID processado como fallback
            $realPhoneNumber = $user->phone_number;

            // Se não tiver número real cadastrado, tenta extrair do JID ou usa o JID processado
            if (! $realPhoneNumber) {
                // Se o JID não for @lid, tenta extrair o número
                if (! str_contains($phoneNumber, '@lid') && preg_match('/^\d+$/', $phoneNumber)) {
                    $realPhoneNumber = $phoneNumber;
                } else {
                    // Se for @lid ou não conseguir extrair, usa o JID processado como fallback
                    $realPhoneNumber = $phoneNumber;
                }

                Log::warning('Usuário sem número cadastrado, usando JID como fallback', [
                    'user_id' => $user->id,
                    'jid_processado' => $phoneNumber,
                ]);
            }

            Log::info('Mensagem recebida de usuário identificado', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'phone_number_jid' => $phoneNumber, // JID processado (pode ser @lid)
                'phone_number_real' => $realPhoneNumber, // Número real do usuário ou fallback
                'message_preview' => substr($text, 0, 50),
            ]);

            // Despacha job para processar mensagem
            // Passa o número real do usuário (ou fallback), o pushName (se disponível) e o remoteJid original
            ProcessWhatsAppMessage::dispatch(
                $realPhoneNumber, // Número real do usuário ou fallback
                $text,
                $user->id,
                $data['pushName'] ?? null, // Nome do WhatsApp
                $key['remoteJid'] ?? null, // JID original para referência
                $imageUrl // URL da imagem se houver (para OCR)
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

    /**
     * Identifica o usuário pelo número de telefone
     *
     * O phoneNumber é o remoteJid, que representa o número do USUÁRIO
     * que enviou a mensagem para o bot (não o número do bot).
     *
     * @param  string  $phoneNumber  Número do usuário que enviou a mensagem (remoteJid)
     * @return User|null Usuário encontrado ou null
     */
    private function identifyUserByPhoneNumber(string $phoneNumber): ?User
    {
        // Normaliza o número
        $normalized = $this->phoneNumberService->normalize($phoneNumber);

        // Tenta encontrar usuário pelo número exato (ou LID)
        $user = User::where('phone_number', $normalized)->first();

        if ($user) {
            Log::debug('Usuário encontrado pelo número exato', ['user_id' => $user->id]);
            return $user;
        }

        // Tenta encontrar por variações do número
        $variations = $this->phoneNumberService->getVariations($phoneNumber);

        foreach ($variations as $variation) {
            $user = User::where('phone_number', $variation)->first();
            if ($user) {
                Log::info('Usuário encontrado por variação do número', [
                    'original' => $phoneNumber,
                    'variation' => $variation,
                    'user_id' => $user->id,
                ]);

                return $user;
            }
        }

        // Não retorna fallback - usuário deve ter número cadastrado
        Log::warning('Usuário não encontrado por número de telefone', [
            'phone_number' => $phoneNumber,
            'normalized' => $normalized,
            'variations_tried' => $variations,
        ]);

        return null;
    }

    /**
     * Identifica usuário pelo pushName (nome exibido no WhatsApp)
     * Usado como fallback quando o número não está disponível (ex: @lid)
     *
     * @param  string  $pushName  Nome exibido no WhatsApp
     * @return User|null Usuário encontrado ou null
     */
    private function identifyUserByPushName(string $pushName): ?User
    {
        // Tenta encontrar usuário pelo nome (case-insensitive, parcial)
        // Adiciona proteção contra nomes vazios ou muito curtos
        if (strlen($pushName) < 3) return null;

        $user = User::whereRaw('LOWER(name) LIKE ?', ['%'.strtolower($pushName).'%'])->first();

        if ($user) {
            return $user;
        }

        // Se não encontrou, retorna null (não usa fallback aqui para evitar erros)
        return null;
    }
}
