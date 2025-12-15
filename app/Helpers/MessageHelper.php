<?php

namespace App\Helpers;

use Illuminate\Http\Request;

class MessageHelper
{
    public static function extractMessage(Request $request): string
    {
        return $request->input('Body')
            ?? $request->input('text')
            ?? '';
    }

    public static function extractSender(Request $request): string
    {
        $raw = $request->input('From') 
            ?? $request->input('from') 
            ?? '';

        return self::normalizePhone($raw);
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = str_replace('whatsapp:', '', $phone);
        return preg_replace('/[^\d]/', '', $phone);
    }
}
