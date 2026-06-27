@php
    $bodyLines = preg_split('/\r\n|\r|\n/', (string) $payload['body']);
@endphp

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $payload['subject'] }}</title>
</head>
<body style="margin:0;background:#050b14;color:#e5eefb;font-family:Arial,Helvetica,sans-serif;">
    <span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;">
        {{ $payload['preheader'] ?: $payload['headline'] }}
    </span>

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#050b14;padding:32px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:640px;border-radius:28px;overflow:hidden;border:1px solid rgba(255,255,255,0.10);background:#08111f;">
                    <tr>
                        <td style="padding:28px 28px 18px;background:linear-gradient(135deg,rgba(16,185,129,0.24),rgba(34,211,238,0.14),rgba(8,17,31,0));">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td style="vertical-align:middle;">
                                        <img src="{{ asset('brand/logo-inovafinance-icon.png') }}" width="44" height="44" alt="InovaFinance" style="border-radius:14px;vertical-align:middle;margin-right:10px;">
                                        <span style="font-size:18px;font-weight:800;color:#ffffff;vertical-align:middle;">InovaFinance</span>
                                    </td>
                                </tr>
                            </table>
                            <p style="margin:28px 0 0;color:#67e8f9;font-size:12px;font-weight:800;letter-spacing:0.18em;text-transform:uppercase;">Comunicado oficial</p>
                            <h1 style="margin:10px 0 0;color:#ffffff;font-size:30px;line-height:1.15;font-weight:900;">{{ $payload['headline'] }}</h1>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:28px;">
                            @foreach ($bodyLines as $line)
                                @if(trim((string) $line) === '')
                                    <div style="height:12px;line-height:12px;">&nbsp;</div>
                                @else
                                    <p style="margin:0 0 14px;color:#cbd5e1;font-size:16px;line-height:1.7;">{{ $line }}</p>
                                @endif
                            @endforeach

                            @if(filled($payload['cta_label']) && filled($payload['cta_url']))
                                <table role="presentation" cellspacing="0" cellpadding="0" style="margin-top:26px;">
                                    <tr>
                                        <td style="border-radius:18px;background:#34d399;">
                                            <a href="{{ $payload['cta_url'] }}" style="display:inline-block;padding:14px 22px;color:#031018;text-decoration:none;font-size:14px;font-weight:900;">
                                                {{ $payload['cta_label'] }}
                                            </a>
                                        </td>
                                    </tr>
                                </table>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 28px;border-top:1px solid rgba(255,255,255,0.08);background:rgba(255,255,255,0.03);">
                            <p style="margin:0;color:#94a3b8;font-size:12px;line-height:1.6;">
                                Voce recebeu este e-mail porque possui relacionamento com o InovaFinance.
                                Precisa de ajuda? Acesse <a href="{{ $payload['support_url'] }}" style="color:#67e8f9;">nosso suporte</a>.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
