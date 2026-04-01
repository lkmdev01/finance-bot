<x-layouts.app.sidebar title="Projeções Financeiras">
    @if (auth()->user()->hasFeature('financial_projections'))
        <livewire:financial-projections.index />
    @else
        <x-billing.feature-paywall
            feature-title="Projeções financeiras"
            feature-description="As projeções de saldo futuro são desbloqueadas nos planos Pro com billing ativo."
        />
    @endif
</x-layouts.app.sidebar>
