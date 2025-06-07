<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class VonageWhatsAppService
{
    public function sendText(string $to, string $message)
    {
        $jwt = (new VonageJwtService())->generate();

        $from = config('services.vonage.wa_number');

        return Http::withToken($jwt)->post('https://api.nexmo.com/v0.1/messages', [
            "from" => ["type" => "whatsapp", "number" => $from],
            "to" => ["type" => "whatsapp", "number" => $to],
            "message" => [
                "content" => [
                    "type" => "text",
                    "text" => $message
                ]
            ]
        ]);
    }
}
