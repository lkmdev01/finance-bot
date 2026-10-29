<?php

namespace App\Services\Research;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebResearchService
{
    /** @return array<int, array{title:string,url:string,domain:string,snippet:string}> */
    public function search(string $query): array
    {
        $baseUrl = rtrim((string) config('services.searxng.base_url', ''), '/');
        if (! config('services.searxng.enabled') || $baseUrl === '') {
            throw new RuntimeException('research_provider_not_configured');
        }

        if (! filter_var($baseUrl, FILTER_VALIDATE_URL) || ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new RuntimeException('research_provider_not_configured');
        }

        $response = Http::acceptJson()
            ->timeout(12)
            ->get($baseUrl.'/search', [
                'q' => mb_substr($query, 0, 300),
                'format' => 'json',
                'language' => 'pt-BR',
                'safesearch' => 1,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('research_provider_unavailable');
        }

        return collect($response->json('results', []))
            ->take(5)
            ->map(function (array $item): ?array {
                $url = (string) ($item['url'] ?? '');
                if (! filter_var($url, FILTER_VALIDATE_URL) || ! in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)) {
                    return null;
                }

                return [
                    'title' => mb_substr(strip_tags((string) ($item['title'] ?? 'Fonte')), 0, 180),
                    'url' => $url,
                    'domain' => (string) parse_url($url, PHP_URL_HOST),
                    'snippet' => mb_substr(strip_tags((string) ($item['content'] ?? $item['description'] ?? '')), 0, 700),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
