<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Services\Contracts\WhatsAppServiceInterface;

class VonageWhatsAppSandboxService implements WhatsAppServiceInterface
{
    public function sendText(string $to, string $text)
    {
        return Http::withBasicAuth(
            config('services.vonage.api_key'),
            config('services.vonage.api_secret')
        )->post('https://messages-sandbox.nexmo.com/v1/messages', [
            'from' => config('services.vonage.whatsapp_from'),
            'to' => $to,
            'message_type' => 'text',
            'text' => $text,
            'channel' => 'whatsapp',
        ]);
    }
}


