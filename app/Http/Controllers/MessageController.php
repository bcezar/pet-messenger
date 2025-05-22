<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Twilio\Rest\Client;

class MessageController extends Controller
{
    public function send(Request $request)
    {
        $request->validate([
            'to' => 'required|string',
            'message' => 'required|string',
        ]);

        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        $client = new Client($sid, $token);

        $client->messages->create(
            "whatsapp:" . $request->to,
            [
                'from' => $from,
                'body' => $request->message,
            ]
        );

        return response()->json(['status' => 'Message sent']);
    }
}

