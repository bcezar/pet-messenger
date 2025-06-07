<?php

namespace App\Services;

use Twilio\Rest\Client;

class TwilioWhatsAppService
{
    public function sendText(string $to, string $message)
    {
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');
        $to = "whatsapp:" . $to;

        $client = new Client($sid, $token);

        return $client->messages->create(
            $to,
            [
                'from' => $from,
                'body' => $message,
            ]
        );
    }
}
