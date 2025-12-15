<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class VonageWhatsAppService
{
    public function sendText(string $to, string $text)
    {
        $jwt = app(VonageJwtService::class)->generate();

        return Http::withToken($jwt)
            ->post('https://api.nexmo.com/v1/messages', [
                'to' => $to,
                'from' => config('services.vonage.whatsapp_from'),
                'channel' => 'whatsapp',
                'message' => [
                    'content' => [
                        'type' => 'text',
                        'text' => $text
                    ]
                ]
            ]);
    }
}
