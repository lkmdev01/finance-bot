<?php

namespace App\Services;

use App\Models\ProductEvent;

class ProductFunnelService
{
    /**
     * @return array<int, array{event: string, label: string, count: int, conversion: float|null}>
     */
    public function summary(): array
    {
        $definitions = [
            ProductEventService::REGISTERED => 'Cadastros',
            ProductEventService::WHATSAPP_ACTIVATED => 'WhatsApp ativado',
            ProductEventService::FIRST_WHATSAPP_MESSAGE => 'Primeira conversa',
            ProductEventService::FIRST_TRANSACTION => 'Primeira transação',
            ProductEventService::FIRST_BUDGET => 'Primeiro orçamento',
            ProductEventService::RETAINED_D7 => 'Retenção D7',
            ProductEventService::CHECKOUT_STARTED => 'Checkout iniciado',
            ProductEventService::SUBSCRIPTION_ACTIVATED => 'Assinatura ativa',
        ];

        $counts = ProductEvent::query()
            ->whereIn('event_name', array_keys($definitions))
            ->selectRaw('event_name, count(*) as total')
            ->groupBy('event_name')
            ->pluck('total', 'event_name');

        $previous = null;
        $stages = [];

        foreach ($definitions as $event => $label) {
            $count = (int) ($counts[$event] ?? 0);
            $conversion = $previous === null
                ? null
                : ($previous > 0 ? round(($count / $previous) * 100, 1) : 0.0);

            $stages[] = compact('event', 'label', 'count', 'conversion');
            $previous = $count;
        }

        return $stages;
    }
}
