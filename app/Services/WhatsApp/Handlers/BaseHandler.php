<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Models\WhatsAppContact;
use App\Services\BaileysService;
use App\Services\PhoneNumberService;
use App\Services\WhatsAppFormatter;
use Illuminate\Support\Facades\Log;

abstract class BaseHandler implements ActionHandlerInterface
{
    /**
     * Envia uma resposta normal.
     */
    protected function sendResponse(ProcessWhatsAppMessage $job, string $message, User $user): void
    {
        $baileysService = app(BaileysService::class);
        $phoneNumberService = app(PhoneNumberService::class);
        
        $formattedMessage = WhatsAppFormatter::format($message);
        
        // Chamamos o método público do Job (precisaremos alterar a visibilidade no Job)
        $job->sendResponse($baileysService, $phoneNumberService, $formattedMessage, $user);
    }
    
    /**
     * Envia uma mensagem de erro.
     */
    protected function sendErrorMessage(ProcessWhatsAppMessage $job, string $message): void
    {
        $baileysService = app(BaileysService::class);
        $phoneNumberService = app(PhoneNumberService::class);
        
        // Chamamos o método público do Job
        $job->sendErrorMessage($baileysService, $phoneNumberService, $message);
    }
}
