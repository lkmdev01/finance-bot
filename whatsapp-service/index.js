import 'dotenv/config';
import makeWASocket, {
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
    makeCacheableSignalKeyStore,
    downloadMediaMessage,
    jidNormalizedUser,
    isPnUser,
} from '@whiskeysockets/baileys';
import { Boom } from '@hapi/boom';
import pino from 'pino';
import qrcode from 'qrcode-terminal';
import express from 'express';
import axios from 'axios';
import { rm, readdir } from 'fs/promises';
import { join } from 'path';

const app = express();
app.use(express.json({ limit: '25mb' }));

const PORT = process.env.WHATSAPP_SERVICE_PORT || 3001;
const LARAVEL_URL = process.env.LARAVEL_URL || 'http://financi-app.test';
const WEBHOOK_SECRET = process.env.WEBHOOK_SECRET || 'your-secret-key';

// Debug: verifica se o secret foi carregado (mostra apenas primeiros e últimos caracteres)
if (WEBHOOK_SECRET && WEBHOOK_SECRET !== 'your-secret-key') {
    console.log(`🔐 Secret carregado: ${WEBHOOK_SECRET.substring(0, 8)}...${WEBHOOK_SECRET.substring(WEBHOOK_SECRET.length - 8)}`);
} else {
    console.warn('⚠️ Secret não carregado do .env! Usando valor padrão.');
}

let sock = null;
let isConnected = false;

// Logger
const logger = pino({ level: 'silent' });

/**
 * Inicializa a conexão com WhatsApp
 */
async function startWhatsApp() {
    const { state, saveCreds } = await useMultiFileAuthState('auth_info');

    const { version } = await fetchLatestBaileysVersion();
    
    sock = makeWASocket({
        version,
        logger,
        auth: {
            creds: state.creds,
            keys: makeCacheableSignalKeyStore(state.keys, logger),
        },
        generateHighQualityLinkPreview: true,
        // getMessage é chamado quando o WhatsApp precisa buscar uma mensagem (ex: resposta, citação)
        // Segundo a documentação do Baileys, retornar null/undefined pode causar "Aguardando mensagem"
        // Retornar uma mensagem vazia válida evita esse problema
        getMessage: async (key) => {
            // Retorna uma mensagem vazia válida para evitar "Aguardando mensagem"
            // Isso é melhor que retornar undefined, que faz o WhatsApp aguardar indefinidamente
            return {
                conversation: '',
            };
        },
        // Configurações recomendadas pela documentação do Baileys
        markOnlineOnConnect: true, // Marca como online ao conectar
        syncFullHistory: false, // Não sincroniza histórico completo (melhor performance)
        shouldIgnoreJid: (jid) => {
            // Ignora JIDs que não devem ser processados (broadcasts, etc)
            return jid.endsWith('@g.us') && jid.includes('broadcast');
        },
    });

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr, isNewLogin } = update;

        // Exibe o QR code quando disponível
        if (qr) {
            console.log('\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
            console.log('📱 ESCANEIE O QR CODE COM SEU WHATSAPP');
            console.log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n');
            qrcode.generate(qr, { small: true });
            console.log('\n💡 WhatsApp > Configurações > Aparelhos conectados > Conectar um aparelho\n');
        }

        if (connection === 'close') {
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const errorMsg = lastDisconnect?.error?.message || 'Erro desconhecido';
            const shouldReconnect = statusCode !== DisconnectReason.loggedOut;
            
            console.log(`❌ Conexão fechada. Motivo: ${errorMsg} (Status: ${statusCode})`);

            if (statusCode === DisconnectReason.loggedOut) {
                console.log('❌ Sessão encerrada (Logged Out). Limpando dados e tentando novo login...');
                isConnected = false;
                
                // Em vez de remover a pasta (que pode ser um volume mount), remove apenas o conteúdo
                const authPath = join(process.cwd(), 'auth_info');
                readdir(authPath)
                    .then(files => Promise.all(files.map(file => rm(join(authPath, file), { recursive: true, force: true }))))
                    .then(() => {
                        console.log('🧹 Conteúdo de auth_info limpo com sucesso.');
                        setTimeout(() => startWhatsApp(), 3000);
                    })
                    .catch((err) => {
                        console.error('⚠️ Erro ao limpar conteúdo de auth_info:', err.message);
                        // Tenta reconectar mesmo se falhar a limpeza
                        setTimeout(() => startWhatsApp(), 3000);
                    });
            } else if (shouldReconnect) {
                console.log('🔄 Reconectando automaticamente...');
                setTimeout(() => startWhatsApp(), 3000);
            } else {
                console.log('❌ Desconectado definitivamente. Requer intervenção manual.');
                isConnected = false;
            }
        } else if (connection === 'open') {
            console.log('✅ WhatsApp conectado e pronto!\n');
            isConnected = true;
        } else if (connection === 'connecting') {
            // Silencioso - não loga connecting repetidamente
        }
    });

    // Baileys 7.0.0: Listener para atualizações de mapeamento LID/PN
    sock.ev.on('lid-mapping.update', (update) => {
        if (update.lid && update.pn) {
            console.log(`\n🔄 Novo mapeamento LID/PN descoberto:`);
            console.log(`   LID: ${update.lid}`);
            console.log(`   PN: ${update.pn}\n`);
            
            // O Baileys já armazena automaticamente no lidMapping store
            // Este listener é apenas para logging e possíveis ações customizadas
        }
    });

    sock.ev.on('messages.upsert', async (m) => {
        const message = m.messages[0];
        const messageType = Object.keys(message.message || {})[0];
        
        // Ignora mensagens de status
        if (message.key.remoteJid === 'status@broadcast') {
            return;
        }
        
        // IMPORTANTE: Ignora mensagens enviadas PELO BOT (fromMe === true)
        // Essas são mensagens que o próprio bot enviou, não devemos processar
        if (message.key.fromMe) {
            // Ignora mensagens protocolMessage silenciosamente (são mensagens internas do WhatsApp)
            if (messageType === 'protocolMessage') {
                return; // Não loga, são mensagens internas
            }
            // Loga outras mensagens do bot apenas se necessário para debug
            return;
        }
        
        // Log detalhado apenas para mensagens recebidas de usuários
        const remoteJid = message.key.remoteJid;
        const pushName = message.pushName || 'sem nome';
        
        console.log(`\n📨 Mensagem recebida:`);
        console.log(`   De: ${pushName} (${remoteJid})`);
        console.log(`   Tipo: ${messageType || 'desconhecido'}`);
        
        // Extrai informações da mensagem (apenas mensagens recebidas de usuários)
        // Baileys 7.0.0: Usa remoteJidAlt/participantAlt e lidMapping store para LIDs
        // Prioriza remoteJidAlt se disponível (número real), senão usa remoteJid
        let phoneNumber = message.key.remoteJidAlt || message.key.remoteJid;
        let isLid = false;
        
        // Verifica se é um LID (não é um número de telefone tradicional)
        if (phoneNumber && phoneNumber.includes('@lid')) {
            isLid = true;
            
            // Baileys 7.0.0: Usa o lidMapping store para obter o número real
            if (sock?.signalRepository?.lidMapping) {
                try {
                    const lidMapping = sock.signalRepository.lidMapping;
                    const pn = lidMapping.getPNForLID(phoneNumber);
                    
                    if (pn && isPnUser(pn)) {
                        phoneNumber = pn;
                        console.log(`   ✅ Número obtido do lidMapping: ${phoneNumber.replace('@s.whatsapp.net', '')}`);
                    } else {
                        // Tenta usar remoteJidAlt se disponível
                        if (message.key.remoteJidAlt && isPnUser(message.key.remoteJidAlt)) {
                            phoneNumber = message.key.remoteJidAlt;
                            console.log(`   ✅ Número obtido do remoteJidAlt: ${phoneNumber.replace('@s.whatsapp.net', '')}`);
                        } else {
                            // Tenta participantAlt para grupos
                            const participantAlt = message.key.participantAlt;
                            if (participantAlt && isPnUser(participantAlt)) {
                                phoneNumber = participantAlt;
                                console.log(`   ✅ Número obtido do participantAlt: ${phoneNumber.replace('@s.whatsapp.net', '')}`);
                            } else {
                                console.log(`   ⚠️  LID não mapeado - usando LID como fallback`);
                            }
                        }
                    }
                } catch (e) {
                    // Fallback: tenta usar remoteJidAlt ou participantAlt
                    if (message.key.remoteJidAlt && isPnUser(message.key.remoteJidAlt)) {
                        phoneNumber = message.key.remoteJidAlt;
                    } else if (message.key.participantAlt && isPnUser(message.key.participantAlt)) {
                        phoneNumber = message.key.participantAlt;
                    }
                }
            } else {
                // Fallback para versões anteriores ou se lidMapping não estiver disponível
                if (message.key.remoteJidAlt && isPnUser(message.key.remoteJidAlt)) {
                    phoneNumber = message.key.remoteJidAlt;
                } else if (message.key.participantAlt && isPnUser(message.key.participantAlt)) {
                    phoneNumber = message.key.participantAlt;
                }
            }
        }
        
        // Remove sufixos do WhatsApp para obter apenas o número
        // Garante que phoneNumber existe antes de processar
        if (!phoneNumber) {
            console.log(`   ⚠️  Não foi possível determinar o número do remetente\n`);
            return;
        }
        
        phoneNumber = phoneNumber.replace('@s.whatsapp.net', '').replace('@lid', '').replace('@g.us', '').replace('@c.us', '');
        
        let text = '';
        let audioPayload = {};
        let documentPayload = {};
        
        // Extrai texto da mensagem dependendo do tipo
        if (messageType === 'conversation') {
            text = message.message.conversation;
        } else if (messageType === 'extendedTextMessage') {
            text = message.message.extendedTextMessage.text;
        } else if (messageType === 'imageMessage' || messageType === 'videoMessage' || messageType === 'audioMessage') {
            // Mensagens de mídia podem ter legenda
            const mediaMessage = message.message[messageType];
            text = mediaMessage?.caption || '';
        }

        if (messageType === 'audioMessage') {
            try {
                const audioBuffer = await downloadMediaMessage(
                    message,
                    'buffer',
                    {},
                    {
                        logger,
                        reuploadRequest: sock.updateMediaMessage,
                    }
                );

                if (audioBuffer?.length) {
                    audioPayload = {
                        audioBase64: audioBuffer.toString('base64'),
                        audioMimeType: message.message.audioMessage?.mimetype || 'audio/ogg; codecs=opus',
                        audioSeconds: message.message.audioMessage?.seconds || null,
                    };

                    console.log(`   🎙️ Áudio baixado para transcrição (${audioBuffer.length} bytes)`);
                }
            } catch (mediaError) {
                console.log(`   ⚠️  Não foi possível baixar o áudio: ${mediaError.message}`);
            }
        }

        if (messageType === 'documentMessage') {
            try {
                const documentBuffer = await downloadMediaMessage(
                    message,
                    'buffer',
                    {},
                    {
                        logger,
                        reuploadRequest: sock.updateMediaMessage,
                    }
                );

                if (documentBuffer?.length) {
                    documentPayload = {
                        documentBase64: documentBuffer.toString('base64'),
                        documentMimeType: message.message.documentMessage?.mimetype || 'application/octet-stream',
                        documentFileName: message.message.documentMessage?.fileName || 'documento',
                    };

                    console.log(`   📄 Documento baixado para processamento (${documentBuffer.length} bytes)`);
                }
            } catch (mediaError) {
                console.log(`   ⚠️  Não foi possível baixar o documento: ${mediaError.message}`);
            }
        }
        
        // Se não houver texto, verifica se é uma mensagem de mídia ou outro tipo suportado
        if (!text) {
            const mediaTypes = ['imageMessage', 'videoMessage', 'stickerMessage', 'documentMessage'];
            if (messageType === 'audioMessage') {
                console.log(`   🎙️  Áudio recebido sem legenda; Laravel vai tentar transcrever`);
            } else if (mediaTypes.includes(messageType)) {
                text = `[Mídia: ${messageType}]`;
                console.log(`   🖼️  Mensagem de mídia recebida: ${messageType}`);
            } else if (messageType && messageType !== 'protocolMessage') {
                console.log(`   ⚠️  Mensagem do tipo '${messageType}' sem texto, ignorando\n`);
                return;
            } else {
                return;
            }
        }

        // Remove sufixos para exibição
        const displayPhone = phoneNumber.replace('@s.whatsapp.net', '').replace('@lid', '');
        console.log(`   💬 Texto: ${text.substring(0, 100)}${text.length > 100 ? '...' : ''}`);

        // Envia webhook para Laravel
        try {
            const webhookUrl = `${LARAVEL_URL}/webhook/whatsapp`;
            console.log(`   📤 Enviando para Laravel...`);
            
            const response = await axios.post(webhookUrl, {
                event: 'messages.upsert',
                data: {
                    key: {
                        remoteJid: message.key.remoteJid,
                        fromMe: message.key.fromMe,
                        // Envia também o número processado para facilitar identificação
                        phoneNumber: phoneNumber,
                    },
                    message: {
                        messageType: messageType,
                        conversation: messageType === 'conversation' ? text : undefined,
                        extendedTextMessage: messageType === 'extendedTextMessage' ? { text } : undefined,
                        audioMessage: messageType === 'audioMessage' ? {
                            mimetype: message.message.audioMessage?.mimetype || null,
                            seconds: message.message.audioMessage?.seconds || null,
                        } : undefined,
                        documentMessage: messageType === 'documentMessage' ? {
                            mimetype: message.message.documentMessage?.mimetype || null,
                            fileName: message.message.documentMessage?.fileName || null,
                            caption: message.message.documentMessage?.caption || null,
                        } : undefined,
                    },
                    // Envia informações adicionais para ajudar na identificação
                    pushName: message.pushName || null,
                    participant: message.key.participant || null,
                    // Baileys 7.0.0: Envia também os campos alternativos para LIDs
                    remoteJidAlt: message.key.remoteJidAlt || null,
                    participantAlt: message.key.participantAlt || null,
                    isLid: isLid || false,
                    ...audioPayload,
                    ...documentPayload,
                },
                secret: WEBHOOK_SECRET,
            }, {
                timeout: 10000,
            });
            
            console.log(`   ✅ Processado com sucesso (Status: ${response.status})\n`);
        } catch (error) {
            console.log(`   ❌ Erro ao processar: ${error.message}`);
            if (error.response) {
                console.log(`   Status: ${error.response.status} - ${JSON.stringify(error.response.data)}`);
            } else if (error.request) {
                console.log(`   ⚠️  Laravel não respondeu (verifique se está rodando)\n`);
            } else {
                console.log(`   ⚠️  ${error.message}\n`);
            }
        }
    });
}

/**
 * API REST para enviar mensagens
 */
app.post('/send-message', async (req, res) => {
    try {
        const { phone, message, secret } = req.body;

        if (secret !== WEBHOOK_SECRET) {
            return res.status(401).json({ error: 'Unauthorized' });
        }

        if (!isConnected || !sock) {
            return res.status(503).json({ error: 'WhatsApp não está conectado' });
        }

        // Baileys 7.0.0: Aceita tanto números quanto JIDs (incluindo LIDs)
        // Se já for um JID completo (contém @), usa diretamente
        // Caso contrário, assume que é um número e adiciona @s.whatsapp.net
        let jid = phone;
        if (!phone.includes('@')) {
            // Remove caracteres não numéricos e adiciona @s.whatsapp.net
            const cleanPhone = phone.replace(/[^0-9]/g, '');
            jid = `${cleanPhone}@s.whatsapp.net`;
        }
        
        // Baileys 7.0.0: Se for um LID, tenta obter o PN do lidMapping store
        // Mas também aceita LIDs diretamente (WhatsApp permite enviar para LIDs)
        if (jid.includes('@lid') && sock?.signalRepository?.lidMapping) {
            try {
                const lidMapping = sock.signalRepository.lidMapping;
                const pn = lidMapping.getPNForLID(jid);
                
                if (pn && isPnUser(pn)) {
                    // Prefere usar o PN se disponível (mais confiável)
                    jid = pn;
                    console.log(`\n📤 Enviando mensagem (LID convertido para PN):`);
                } else {
                    // Usa o LID diretamente (WhatsApp permite isso)
                    console.log(`\n📤 Enviando mensagem (usando LID):`);
                }
            } catch (e) {
                // Se falhar, usa o JID original (pode ser LID ou PN)
                console.log(`\n📤 Enviando mensagem:`);
            }
        } else {
            console.log(`\n📤 Enviando mensagem:`);
        }
        
        const displayPhone = jid.replace('@s.whatsapp.net', '').replace('@lid', '');
        console.log(`   Para: ${displayPhone}`);
        console.log(`   Texto: ${message.substring(0, 50)}${message.length > 50 ? '...' : ''}`);

        // Baileys 7.0.0: Envia mensagem (não envia ACKs automaticamente - isso é feito pelo WhatsApp)
        // Segundo a documentação, sendMessage retorna uma Promise com o resultado
        const result = await sock.sendMessage(jid, { text: message });
        
        // Verifica se a mensagem foi enviada com sucesso
        if (result?.key?.id) {
            console.log(`   ✅ Mensagem enviada (ID: ${result.key.id})\n`);
        } else {
            console.log(`   ✅ Mensagem enviada com sucesso\n`);
        }

        res.json({ success: true, message: 'Mensagem enviada', messageId: result?.key?.id });
    } catch (error) {
        console.error(`\n❌ Erro ao enviar mensagem:`);
        console.error(`   Para: ${req.body.phone || jid || 'desconhecido'}`);
        console.error(`   Erro: ${error.message}\n`);
        res.status(500).json({ error: error.message });
    }
});

/**
 * Status da conexão
 */
app.get('/status', (req, res) => {
    res.json({
        connected: isConnected,
        qr: !isConnected && sock ? 'Gerando QR Code...' : null,
    });
});

/**
 * Iniciar servidor
 */
app.listen(PORT, () => {
    console.log(`🚀 Serviço WhatsApp rodando na porta ${PORT}`);
    console.log(`📡 Webhook URL: ${LARAVEL_URL}/webhook/whatsapp`);
    startWhatsApp();
});

