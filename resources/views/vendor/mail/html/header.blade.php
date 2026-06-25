<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
@if (trim($slot) === 'Laravel' || trim($slot) === config('app.name'))
<img src="{{ asset('brand/logo-inovafinance-bg.png') }}" class="logo" alt="InovaFinance">
@else
{{ $slot }}
@endif
</a>
</td>
</tr>
