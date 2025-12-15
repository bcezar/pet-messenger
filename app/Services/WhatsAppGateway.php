<?php

namespace App\Services;

use App\Services\Contracts\WhatsAppServiceInterface;

class WhatsAppGateway
{
    protected $service;

    public function resolve(): WhatsAppServiceInterface
    {
        return match (config('services.whatsapp.gateway')) {
            'vonage_sandbox' => app(VonageWhatsAppSandboxService::class),
            'vonage_prod' => app(VonageWhatsAppService::class),
            'twilio' => app(TwilioWhatsAppService::class),
            default => throw new \Exception('WhatsApp gateway inválido'),
        };
    }

    public function sendText(string $to, string $message)
    {
        return $this->service->sendText($to, $message);
    }
}
