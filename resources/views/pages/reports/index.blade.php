<x-layouts.app.sidebar title="Relatórios">
    @if (auth()->user()->hasFeature('reports'))
        <livewire:reports.index />
    @else
        <x-billing.feature-paywall
            feature-title="Relatórios avançados"
            feature-description="Visualizações, filtros e exportações detalhadas fazem parte dos planos Pro."
        />
    @endif
</x-layouts.app.sidebar>
