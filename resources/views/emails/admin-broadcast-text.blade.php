{{ $payload['headline'] }}

{{ $payload['body'] }}

@if(filled($payload['cta_label']) && filled($payload['cta_url']))
{{ $payload['cta_label'] }}: {{ $payload['cta_url'] }}
@endif

Suporte InovaFinance: {{ $payload['support_url'] }}
