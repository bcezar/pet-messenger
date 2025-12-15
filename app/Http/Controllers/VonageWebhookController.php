<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Services\VonageWhatsAppSandboxService;

class VonageWebhookController extends Controller
{
    public function inbound(Request $request)
{
    Log::info('Vonage inbound', $request->all());

    $from = $request->input('from');
    $text = $request->input('text');

    if (!$from || !$text) {
        return response()->json(['ok' => true]);
    }

    app(\App\Services\VonageWhatsAppSandboxService::class)
        ->sendText($from, 'Recebi: ' . $text);

    return response()->json(['ok' => true]);
}


}

