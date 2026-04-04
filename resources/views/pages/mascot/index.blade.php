<x-layouts.app.sidebar :title="config('mascot.name', 'Orbita')">
    @if (auth()->user()->hasFeature('mascot'))
        <livewire:mascot.index />
    @else
        <x-billing.feature-paywall
            :feature-title="config('mascot.name', 'Orbita')"
            :feature-description="'O sistema de pontuação, humor e medalhas do '.config('mascot.name', 'Orbita').' faz parte da assinatura Pro.'"
        />
    @endif
</x-layouts.app.sidebar>
