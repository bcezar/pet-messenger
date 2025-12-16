<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Helpers\MessageHelper;

class CompanyResolverService
{
    public function resolveFromWebhook(Request $request): Company
    {
        $botNumber = MessageHelper::extractBotNumber($request);

        Log::info('Resolving company for bot number', [
            'bot_number' => $botNumber,
        ]);

        return Company::where('whatsapp_number', $botNumber)->firstOrFail();
    }
}
