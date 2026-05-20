<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\WhatsApp\Handlers\ActionHandlerInterface;

class ActionHandlerFactory
{
    /** @var ActionHandlerInterface[] */
    protected array $handlers = [];

    public function __construct(iterable $handlers = [])
    {
        // Register built-in handlers (order matters — first match wins)
        $this->registerHandler(new \App\Services\WhatsApp\Handlers\CreateBudgetHandler());
        $this->registerHandler(new \App\Services\WhatsApp\Handlers\CreateTransactionHandler());
        $this->registerHandler(new \App\Services\WhatsApp\Handlers\EditTransactionHandler());
        $this->registerHandler(new \App\Services\WhatsApp\Handlers\DeleteTransactionHandler());
        $this->registerHandler(new \App\Services\WhatsApp\Handlers\QueryHandler());
        $this->registerHandler(new \App\Services\WhatsApp\Handlers\ReportHandler());

        // Allow callers to inject additional handlers (e.g. for testing)
        foreach ($handlers as $handler) {
            $this->registerHandler($handler);
        }
    }


    public function registerHandler(ActionHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Tenta processar a ação com os handlers registrados.
     * Retorna true se algum handler processou a ação, false caso contrário.
     */
    public function process(
        ?string $action,
        array &$result,
        User $user,
        WhatsAppContact $contact,
        ProcessWhatsAppMessage $job
    ): bool {
        foreach ($this->handlers as $handler) {
            if ($handler->canHandle($action)) {
                $shouldStop = $handler->handle($action, $result, $user, $contact, $job);
                if ($shouldStop) {
                    return true; // Um handler assumiu a responsabilidade e finalizou
                }
            }
        }
        
        return false;
    }
}
