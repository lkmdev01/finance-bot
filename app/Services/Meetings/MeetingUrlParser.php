<?php

namespace App\Services\Meetings;

use InvalidArgumentException;

class MeetingUrlParser
{
    /** @return array{platform:string,native_meeting_id:string} */
    public function parse(string $url): array
    {
        if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Envie um link válido do Google Meet, Microsoft Teams ou Zoom.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $path = trim(rawurldecode((string) ($parts['path'] ?? '')), '/');

        if ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('O link precisa usar HTTPS e não pode conter credenciais no endereço.');
        }
        if (isset($parts['port']) && (int) $parts['port'] !== 443) {
            throw new InvalidArgumentException('O link da reunião precisa usar a porta HTTPS padrão.');
        }

        if ($host === 'meet.google.com' && preg_match('/^[a-z]{3}-[a-z]{4}-[a-z]{3}$/i', $path)) {
            return ['platform' => 'google_meet', 'native_meeting_id' => $path];
        }

        if (($host === 'zoom.us' || str_ends_with($host, '.zoom.us'))
            && preg_match('~^(?:j/)?(\d{9,12})$~', $path, $m)) {
            return ['platform' => 'zoom', 'native_meeting_id' => $m[1]];
        }

        if (in_array($host, ['teams.microsoft.com', 'teams.live.com'], true)) {
            if (preg_match('~^l/meetup-join/(19:meeting_[^/]+@thread\.v2)(?:/|$)~i', $path, $m)) {
                return ['platform' => 'teams', 'native_meeting_id' => $m[1]];
            }
            if (preg_match('~^meet/(\d{10,15})$~', $path, $m)) {
                return ['platform' => 'teams', 'native_meeting_id' => $m[1]];
            }
        }

        throw new InvalidArgumentException('Não reconheci o link. São aceitos links do Google Meet, Teams ou Zoom.');
    }
}
