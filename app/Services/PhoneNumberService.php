<?php

namespace App\Services;

class PhoneNumberService
{
    /**
     * Normaliza um número de telefone removendo caracteres especiais
     */
    public function normalize(string $phone): string
    {
        // Remove caracteres não numéricos, exceto +
        return preg_replace('/[^0-9+]/', '', $phone);
    }

    /**
     * Limpa um número de telefone removendo todos os caracteres não numéricos
     */
    public function clean(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Prepara o número para armazenamento no banco de dados.
     * Remove tudo que não é número e garante o prefixo 55 para BR.
     */
    public function formatForStorage(string $phone): string
    {
        $clean = $this->clean($phone);

        if (empty($clean)) {
            return '';
        }

        // Se o número tem 10 ou 11 dígitos, assumimos que é Brasil sem o 55
        if (strlen($clean) === 10 || strlen($clean) === 11) {
            $clean = '55' . $clean;
        }

        return $clean;
    }

    /**
     * Formata um número de telefone para exibição
     */
    public function format(string $phone, string $format = 'BR'): string
    {
        $clean = $this->clean($phone);
        
        if ($format === 'BR') {
            // Formato: (11) 99999-9999 ou (11) 9999-9999
            if (strlen($clean) === 11) {
                return sprintf('(%s) %s-%s', 
                    substr($clean, 0, 2),
                    substr($clean, 2, 5),
                    substr($clean, 7)
                );
            } elseif (strlen($clean) === 10) {
                return sprintf('(%s) %s-%s', 
                    substr($clean, 0, 2),
                    substr($clean, 2, 4),
                    substr($clean, 6)
                );
            }
        }
        
        return $clean;
    }

    /**
     * Valida se um número de telefone é válido
     */
    public function isValid(string $phone): bool
    {
        $clean = $this->clean($phone);
        
        // Número brasileiro: 10 ou 11 dígitos (com/sem DDD)
        // Ou número internacional: 10-15 dígitos
        $length = strlen($clean);
        
        return $length >= 10 && $length <= 15;
    }

    /**
     * Gera variações de um número de telefone para busca
     */
    public function getVariations(string $phone): array
    {
        $clean = $this->normalize($phone);
        $variations = [
            $clean,
            ltrim($clean, '+'),
            '+'.$clean,
        ];

        // Se for um número brasileiro (10 ou 11 dígitos) e não começar com 55, adiciona 55 como variação
        if ((strlen($clean) === 10 || strlen($clean) === 11) && !str_starts_with($clean, '55')) {
            $variations[] = '55' . $clean;
        }

        // Se tiver 12 ou 13 dígitos e começar com 55, tenta a versão sem o 55
        if (str_starts_with($clean, '55') && (strlen($clean) === 12 || strlen($clean) === 13)) {
            $variations[] = substr($clean, 2);
        }

        // Remove código do país se tiver 13+ dígitos (assumindo formato +5511999999999)
        if (strlen($clean) >= 13) {
            $variations[] = substr($clean, -11); // Últimos 11 dígitos (DDD + número)
            $variations[] = substr($clean, -10); // Últimos 10 dígitos (sem DDD)
        }

        return array_unique($variations);
    }

    /**
     * Remove sufixos do WhatsApp (@s.whatsapp.net, @lid, etc)
     */
    public function removeWhatsAppSuffixes(string $phone): string
    {
        return str_replace(['@s.whatsapp.net', '@lid', '@g.us', '@c.us'], '', $phone);
    }

    /**
     * Converte um número para JID do WhatsApp
     */
    public function toWhatsAppJid(string $phone): string
    {
        $clean = $this->clean($phone);
        
        // Se for um número brasileiro (10 ou 11 dígitos) e não começar com 55, adiciona 55
        // Isso evita enviar para JIDs inválidos como 13991290256@s.whatsapp.net
        if ((strlen($clean) === 10 || strlen($clean) === 11) && !str_starts_with($clean, '55')) {
            $clean = '55' . $clean;
        }
        
        return $clean.'@s.whatsapp.net';
    }
}
