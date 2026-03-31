<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppDocumentService
{
    public function __construct(
        private readonly TransactionImportService $transactionImportService
    ) {}

    public function processBase64(User $user, string $documentBase64, ?string $mimeType = null, ?string $fileName = null): array
    {
        $binary = base64_decode($documentBase64, true);

        if ($binary === false || $binary === '') {
            return [
                'status' => 'invalid_document',
                'message' => 'Nao consegui ler o documento enviado. Tente mandar novamente.',
            ];
        }

        $extension = $this->resolveExtension($mimeType, $fileName);
        $tempPath = tempnam(sys_get_temp_dir(), 'wa-doc-');

        if ($tempPath === false) {
            return [
                'status' => 'storage_error',
                'message' => 'Nao consegui preparar o documento para processamento.',
            ];
        }

        $finalPath = $tempPath.'.'.$extension;
        rename($tempPath, $finalPath);
        file_put_contents($finalPath, $binary);

        try {
            return match ($extension) {
                'csv' => $this->importCsv($user, $finalPath, $fileName),
                'ofx' => $this->importOfx($user, $finalPath, $fileName),
                'txt' => $this->extractPlainText($finalPath, $fileName),
                'pdf' => $this->extractPdfText($finalPath, $fileName),
                default => [
                    'status' => 'unsupported_document',
                    'message' => 'Eu consigo processar CSV, OFX, TXT e alguns PDFs com texto. Para recibo em imagem, envie como foto.',
                ],
            };
        } catch (\Throwable $e) {
            Log::error('Erro ao processar documento do WhatsApp', [
                'user_id' => $user->id,
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 'document_processing_error',
                'message' => 'O documento chegou, mas houve um erro ao processar. Tente novamente ou envie em outro formato.',
            ];
        } finally {
            @unlink($finalPath);
        }
    }

    private function importCsv(User $user, string $path, ?string $fileName): array
    {
        $result = $this->transactionImportService->importFromCsv($user, $path, [], [
            'metadata' => [
                'source' => 'whatsapp_document',
                'document_type' => 'csv',
                'document_name' => $fileName,
            ],
        ]);

        return [
            'status' => 'imported',
            'message' => $this->buildImportMessage('CSV', $result),
            'result' => $result,
        ];
    }

    private function importOfx(User $user, string $path, ?string $fileName): array
    {
        $result = $this->transactionImportService->importFromOfx($user, $path, [
            'metadata' => [
                'source' => 'whatsapp_document',
                'document_type' => 'ofx',
                'document_name' => $fileName,
            ],
        ]);

        return [
            'status' => 'imported',
            'message' => $this->buildImportMessage('OFX', $result),
            'result' => $result,
        ];
    }

    private function extractPlainText(string $path, ?string $fileName): array
    {
        $content = trim((string) file_get_contents($path));

        if ($content === '') {
            return [
                'status' => 'empty_document',
                'message' => 'O documento TXT veio vazio. Envie outro arquivo ou descreva a transacao em texto.',
            ];
        }

        return [
            'status' => 'text_extracted',
            'text' => Str::limit($content, 4000, ''),
            'message' => "Texto extraido de {$fileName}.",
        ];
    }

    private function extractPdfText(string $path, ?string $fileName): array
    {
        $content = (string) file_get_contents($path);
        $text = $this->extractTextFromPdfBinary($content);

        if ($text === null) {
            return [
                'status' => 'unsupported_document',
                'message' => 'Recebi o PDF, mas nao consegui extrair texto dele. Se for um extrato, prefira OFX ou CSV. Se for recibo, envie como foto.',
            ];
        }

        return [
            'status' => 'text_extracted',
            'text' => Str::limit($text, 4000, ''),
            'message' => "Texto extraido de {$fileName}.",
        ];
    }

    private function extractTextFromPdfBinary(string $binary): ?string
    {
        $chunks = [];

        if (preg_match_all('/\((.*?)\)/s', $binary, $matches)) {
            foreach ($matches[1] as $match) {
                $decoded = preg_replace('/\\\\([nrtbf\\\\()])/', ' ', stripcslashes($match));
                $decoded = preg_replace('/\s+/', ' ', trim((string) $decoded));

                if (mb_strlen($decoded) >= 4) {
                    $chunks[] = $decoded;
                }
            }
        }

        if (empty($chunks) && preg_match_all('/[A-Za-z0-9À-ÿ$%.,:;\/\-\s]{8,}/u', $binary, $matches)) {
            foreach ($matches[0] as $match) {
                $clean = preg_replace('/\s+/', ' ', trim($match));
                if (mb_strlen($clean) >= 8) {
                    $chunks[] = $clean;
                }
            }
        }

        $text = trim(implode(' ', array_unique($chunks)));

        return mb_strlen($text) >= 20 ? $text : null;
    }

    private function resolveExtension(?string $mimeType, ?string $fileName): string
    {
        $extension = strtolower((string) pathinfo((string) $fileName, PATHINFO_EXTENSION));

        if ($extension !== '') {
            return $extension;
        }

        return match ($mimeType) {
            'text/csv', 'application/csv', 'application/vnd.ms-excel' => 'csv',
            'application/ofx', 'application/x-ofx' => 'ofx',
            'text/plain' => 'txt',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }

    private function buildImportMessage(string $label, array $result): string
    {
        $imported = (int) ($result['imported'] ?? 0);
        $errors = count($result['errors'] ?? []);
        $message = "{$label} processado com sucesso. {$imported} transacao(oes) importada(s).";

        if ($errors > 0) {
            $message .= " {$errors} linha(s) nao puderam ser importadas.";
        }

        return $message;
    }
}
