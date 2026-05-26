@php
    $mascotName = config('mascot.name', 'Orbita');
@endphp

<x-layouts.app.sidebar :title="$mascotName">
    @if (auth()->user()->hasFeature('mascot'))
        <livewire:mascot.index />
    @else
        <x-billing.feature-paywall
            :feature-title="$mascotName"
            :feature-description="'O sistema de pontuacao, humor e medalhas do '.$mascotName.' faz parte da assinatura Pro.'"
        />
    @endif
</x-layouts.app.sidebar>
