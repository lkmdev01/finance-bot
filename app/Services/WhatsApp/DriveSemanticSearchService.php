<?php

namespace App\Services\WhatsApp;

use App\Models\DriveFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DriveSemanticSearchService
{
    public function __construct(
        private readonly IncomingMessageNormalizer $normalizer,
    ) {}

    /**
     * @param  Collection<int, DriveFile>  $files
     * @return Collection<int, DriveFile>
     */
    public function rank(Collection $files, array $queryData): Collection
    {
        $term = trim((string) ($queryData['term'] ?? ''));
        $mediaKind = $queryData['media_kind'] ?? null;

        if ($term === '' && $mediaKind === null) {
            return $files->values();
        }

        $profile = $this->buildQueryProfile($term, is_string($mediaKind) ? $mediaKind : null);

        return $files
            ->map(function (DriveFile $file) use ($profile) {
                return [
                    'file' => $file,
                    'score' => $this->score($file, $profile),
                ];
            })
            ->filter(fn (array $item) => $item['score'] > 0)
            ->sortByDesc(function (array $item) {
                /** @var DriveFile $file */
                $file = $item['file'];

                return [
                    $item['score'],
                    $file->created_at?->timestamp ?? 0,
                    $file->id,
                ];
            })
            ->pluck('file')
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQueryProfile(string $term, ?string $mediaKind): array
    {
        $normalizedTerm = $this->normalize($term);
        $tokens = $this->tokens($normalizedTerm);
        $expandedTokens = $tokens;

        foreach ($tokens as $token) {
            foreach ($this->synonymsFor($token) as $synonym) {
                $expandedTokens[] = $synonym;
            }
        }

        foreach ($this->mediaTerms($mediaKind) as $mediaTerm) {
            $expandedTokens[] = $mediaTerm;
        }

        $expandedTokens = array_values(array_unique(array_filter($expandedTokens)));

        return [
            'term' => $normalizedTerm,
            'tokens' => $tokens,
            'expanded_tokens' => $expandedTokens,
            'media_kind' => $mediaKind,
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function score(DriveFile $file, array $profile): int
    {
        $haystacks = $this->haystacks($file);
        $score = 0;

        $mediaKind = $profile['media_kind'] ?? null;
        if (is_string($mediaKind) && $mediaKind !== '' && $this->fileMatchesMediaKind($file, $mediaKind)) {
            $score += 15;
        }

        $term = (string) ($profile['term'] ?? '');
        if ($term !== '') {
            $score += $this->scoreExactTerm($term, $haystacks);
        }

        foreach (($profile['tokens'] ?? []) as $token) {
            $score += $this->scoreToken((string) $token, $haystacks, true);
        }

        foreach (($profile['expanded_tokens'] ?? []) as $token) {
            $score += $this->scoreToken((string) $token, $haystacks, false);
        }

        return $score;
    }

    /**
     * @return array<string, string>
     */
    private function haystacks(DriveFile $file): array
    {
        $tags = collect($file->tags ?? [])
            ->filter(fn ($tag) => is_scalar($tag))
            ->map(fn ($tag) => (string) $tag)
            ->implode(' ');

        return [
            'title' => $this->normalize((string) $file->title),
            'original_name' => $this->normalize((string) $file->original_name),
            'drive_path' => $this->normalize((string) $file->drive_path),
            'description' => $this->normalize((string) $file->description),
            'tags' => $this->normalize($tags),
            'extracted_text' => $this->normalize((string) $file->extracted_text),
        ];
    }

    /**
     * @param  array<string, string>  $haystacks
     */
    private function scoreExactTerm(string $term, array $haystacks): int
    {
        $score = 0;

        foreach ($haystacks as $field => $value) {
            if ($value === '') {
                continue;
            }

            if ($value === $term) {
                $score += $field === 'title' || $field === 'original_name' ? 80 : 45;
            } elseif (str_contains($value, $term)) {
                $score += match ($field) {
                    'title', 'original_name' => 50,
                    'tags', 'drive_path', 'description' => 28,
                    default => 18,
                };
            }
        }

        return $score;
    }

    /**
     * @param  array<string, string>  $haystacks
     */
    private function scoreToken(string $token, array $haystacks, bool $primary): int
    {
        if ($token === '' || mb_strlen($token) < 2) {
            return 0;
        }

        $score = 0;

        foreach ($haystacks as $field => $value) {
            if ($value === '') {
                continue;
            }

            if ($this->containsToken($value, $token)) {
                $score += match ($field) {
                    'title', 'original_name' => $primary ? 35 : 18,
                    'tags' => $primary ? 30 : 16,
                    'drive_path', 'description' => $primary ? 24 : 12,
                    default => $primary ? 14 : 7,
                };
            }
        }

        return $score;
    }

    private function containsToken(string $value, string $token): bool
    {
        if (preg_match('/\b'.preg_quote($token, '/').'\b/u', $value) === 1) {
            return true;
        }

        return str_contains($value, $token);
    }

    /**
     * @return array<int, string>
     */
    private function synonymsFor(string $token): array
    {
        $dictionary = [
            'mecanico' => ['oficina', 'carro', 'veiculo', 'automovel', 'manutencao'],
            'oficina' => ['mecanico', 'carro', 'veiculo', 'manutencao'],
            'carro' => ['veiculo', 'automovel', 'mecanico', 'oficina'],
            'veiculo' => ['carro', 'automovel', 'mecanico', 'oficina'],
            'comprovante' => ['recibo', 'nota', 'pagamento', 'boleto'],
            'recibo' => ['comprovante', 'pagamento', 'nota'],
            'contrato' => ['acordo', 'documento', 'locacao', 'aluguel'],
            'aluguel' => ['locacao', 'contrato', 'imovel'],
            'foto' => ['imagem', 'print', 'retrato'],
            'imagem' => ['foto', 'print', 'retrato'],
            'audio' => ['voz', 'gravacao', 'mp3'],
            'voz' => ['audio', 'gravacao'],
            'projeto' => ['ideia', 'brainstorm', 'expansao', 'planejamento'],
            'ideia' => ['projeto', 'brainstorm', 'insight', 'planejamento'],
            'neve' => ['montanha', 'viagem', 'frio'],
        ];

        return $dictionary[$token] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function mediaTerms(?string $mediaKind): array
    {
        return match ($mediaKind) {
            'image' => ['foto', 'imagem', 'print'],
            'audio' => ['audio', 'voz', 'gravacao', 'mp3'],
            'document' => ['documento', 'pdf', 'contrato', 'comprovante', 'recibo'],
            default => [],
        };
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $value): array
    {
        $tokens = preg_split('/\s+/u', trim($value)) ?: [];

        return array_values(array_filter($tokens, fn (string $token) => $token !== '' && mb_strlen($token) >= 2));
    }

    private function normalize(string $value): string
    {
        $value = $this->normalizer->normalize($value);
        $value = str_replace(['_', '-', '/', '.'], ' ', $value);
        $value = preg_replace('/[^a-z0-9\s]+/u', ' ', $value) ?? $value;
        $value = Str::squish($value);

        return trim($value);
    }

    private function fileMatchesMediaKind(DriveFile $file, string $mediaKind): bool
    {
        $mimeType = (string) $file->mime_type;

        return match ($mediaKind) {
            'image' => str_starts_with($mimeType, 'image/'),
            'audio' => str_starts_with($mimeType, 'audio/'),
            'document' => ! str_starts_with($mimeType, 'image/') && ! str_starts_with($mimeType, 'audio/'),
            default => false,
        };
    }
}
