<?php

namespace App\Services\Contracts;

interface WhatsAppServiceInterface
{
    public function sendText(string $to, string $message);
}
