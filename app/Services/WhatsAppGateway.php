<?php

namespace App\Services;

class WhatsAppGateway
{
    protected $service;

    public function __construct()
    {
        $gateway = env('WHATSAPP_GATEWAY');

        $this->service = match ($gateway) {
            'vonage' => new VonageWhatsAppService(),
            default => new TwilioWhatsAppService(),
        };
    }

    public function sendText(string $to, string $message)
    {
        return $this->service->sendText($to, $message);
    }
}
