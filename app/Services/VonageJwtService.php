<?php

namespace App\Services;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Log;

class VonageJwtService
{
    public function generate(): string
    {
        $appId = config('services.vonage.application_id');
        $privateKeyPath = config('services.vonage.private_key_path');

        Log::info('Vonage JWT generation', [
            'application_id' => $appId,
            'private_key_path' => $privateKeyPath,
        ]);

        $payload = [
            'application_id' => $appId,
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => uniqid('', true),
            'acl' => [
                'paths' => [
                    '/v1/messages/**' => [],
                ]
            ]
        ];

        return JWT::encode(
            $payload,
            file_get_contents($privateKeyPath),
            'RS256'
        );
    }
}
