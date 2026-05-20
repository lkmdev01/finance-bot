<?php

namespace App\Services\WhatsApp\Handlers;

use App\Jobs\ProcessWhatsAppMessage;
use App\Models\User;
use App\Services\BaileysService;
use App\Services\PhoneNumberService;
use App\Services\WhatsAppFormatter;

abstract class BaseHandler implements ActionHandlerInterface
{
    protected function sendResponse(ProcessWhatsAppMessage $job, string $message, User $user): void
    {
        $baileysService = app(BaileysService::class);
        $phoneNumberService = app(PhoneNumberService::class);
        $formattedMessage = WhatsAppFormatter::format($message);

        $job->rememberFinalReply($formattedMessage);
        $job->sendResponse($baileysService, $phoneNumberService, $formattedMessage, $user);
    }

    protected function sendErrorMessage(ProcessWhatsAppMessage $job, string $message): void
    {
        $baileysService = app(BaileysService::class);
        $phoneNumberService = app(PhoneNumberService::class);

        $job->rememberFinalReply($message);
        $job->sendErrorMessage($baileysService, $phoneNumberService, $message);
    }
}
