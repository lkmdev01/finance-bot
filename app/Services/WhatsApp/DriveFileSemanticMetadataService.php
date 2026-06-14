<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Str;

class DriveFileSemanticMetadataService
{
    public function __construct(
        private readonly IncomingMessageNormalizer $normalizer,
    ) {}

    /**
     * @param  array<int, string>  $labels
     * @return array{description: string|null, tags: array<int, string>}
     */
    public function build(
        ?string $title,
        string $fileName,
        ?string $folderName,
        string $kind,
        array $labels = [],
        ?string $extractedText = null,
    ): array {
        $sources = array_filter([
            $title,
            (string) Str::of($fileName)->beforeLast('.'),
            $folderName,
            $kind,
            $extractedText,
            ...$labels,
        ], fn ($value) => is_string($value) && trim($value) !== '');

        $normalizedSources = array_map(fn (string $source) => $this->normalize($source), $sources);
        $tokens = $this->extractTokens($normalizedSources);
        $tags = array_values(array_unique(array_merge(
            [$this->normalize($kind)],
            $tokens,
            $this->semanticTags($tokens),
            $this->translatedLabelTags($labels),
        )));

        $tags = array_values(array_filter($tags, fn (string $tag) => $tag !== '' && mb_strlen($tag) >= 3));

        return [
            'description' => $this->description($title, $fileName, $folderName, $kind, $labels, $extractedText, $tags),
            'tags' => $tags,
        ];
    }

    /**
     * @param  array<int, string>  $sources
     * @return array<int, string>
     */
    private function extractTokens(array $sources): array
    {
        $tokens = [];

        foreach ($sources as $source) {
            if ($source === '') {
                continue;
            }

            $tokens[] = $source;
            foreach (preg_split('/[^a-z0-9]+/u', $source) ?: [] as $token) {
                $token = trim($token);
                if ($token === '' || mb_strlen($token) < 3) {
                    continue;
                }

                $tokens[] = $token;
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array<int, string>
     */
    private function semanticTags(array $tokens): array
    {
        $joined = ' '.implode(' ', $tokens).' ';
        $tags = [];

        $rules = [
            ['terms' => ['comprovante', 'recibo', 'boleto', 'pagamento', 'nota fiscal'], 'tags' => ['comprovante', 'recibo', 'pagamento', 'documento']],
            ['terms' => ['mecanico', 'oficina', 'veiculo', 'carro', 'automovel', 'oleo'], 'tags' => ['mecanico', 'oficina', 'veiculo', 'carro', 'manutencao']],
            ['terms' => ['contrato', 'locacao', 'aluguel', 'imovel', 'apartamento'], 'tags' => ['contrato', 'locacao', 'aluguel', 'documento']],
            ['terms' => ['foto', 'imagem', 'print', 'retrato'], 'tags' => ['foto', 'imagem']],
            ['terms' => ['audio', 'voz', 'gravacao', 'mp3'], 'tags' => ['audio', 'voz', 'gravacao']],
            ['terms' => ['projeto', 'ideia', 'brainstorm', 'expansao', 'planejamento'], 'tags' => ['projeto', 'ideia', 'brainstorm', 'planejamento']],
            ['terms' => ['neve', 'montanha', 'viagem', 'frio'], 'tags' => ['neve', 'montanha', 'viagem']],
        ];

        foreach ($rules as $rule) {
            foreach ($rule['terms'] as $term) {
                if (str_contains($joined, ' '.$term.' ')) {
                    array_push($tags, ...$rule['tags']);
                    break;
                }
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, string>
     */
    private function translatedLabelTags(array $labels): array
    {
        $dictionary = [
            'automotive' => ['veiculo', 'carro'],
            'car' => ['veiculo', 'carro'],
            'vehicle' => ['veiculo', 'carro'],
            'document' => ['documento'],
            'receipt' => ['recibo', 'comprovante'],
            'invoice' => ['nota fiscal', 'comprovante'],
            'contract' => ['contrato'],
            'snow' => ['neve'],
            'mountain' => ['montanha', 'viagem'],
            'landscape' => ['paisagem', 'viagem'],
            'person' => ['pessoa'],
            'audio' => ['audio'],
            'music' => ['musica', 'audio'],
        ];

        $tags = [];
        foreach ($labels as $label) {
            $normalized = $this->normalize((string) $label);
            if (isset($dictionary[$normalized])) {
                array_push($tags, ...$dictionary[$normalized]);
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<int, string>  $tags
     */
    private function description(?string $title, string $fileName, ?string $folderName, string $kind, array $labels, ?string $extractedText, array $tags): ?string
    {
        $label = match ($kind) {
            'image' => 'Imagem',
            'audio' => 'Audio',
            default => 'Documento',
        };

        $name = trim((string) ($title ?: Str::of($fileName)->beforeLast('.')));
        $parts = [$label.($name !== '' ? " {$name}" : '')];

        if ($folderName) {
            $parts[] = "pasta {$folderName}";
        }

        $importantTags = array_slice(array_values(array_filter($tags, fn (string $tag) => ! in_array($tag, [$kind, 'documento', 'imagem', 'audio'], true))), 0, 8);
        if ($importantTags !== []) {
            $parts[] = 'assuntos: '.implode(', ', $importantTags);
        }

        $cleanLabels = array_slice(array_values(array_filter(array_map(fn ($label) => trim((string) $label), $labels))), 0, 5);
        if ($cleanLabels !== []) {
            $parts[] = 'labels: '.implode(', ', $cleanLabels);
        }

        $text = trim((string) $extractedText);
        if ($text !== '') {
            $parts[] = 'texto: '.Str::limit(Str::squish($text), 220, '');
        }

        $description = trim(implode('. ', array_filter($parts))).'.';

        return $description !== '.' ? Str::limit($description, 1000, '') : null;
    }

    private function normalize(string $value): string
    {
        $value = $this->normalizer->normalize($value);
        $value = str_replace(['_', '-', '/', '.'], ' ', $value);
        $value = preg_replace('/[^a-z0-9\s]+/u', ' ', $value) ?? $value;

        return trim(Str::squish($value));
    }
}
