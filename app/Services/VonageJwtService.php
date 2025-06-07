<?php

namespace App\Services;

use Firebase\JWT\JWT as FirebaseJWT;

class VonageJwtService
{
    public function generate(): string
    {
        $appId = config('services.vonage.app_id');
        $privateKeyPath = config('services.vonage.private_key_path');

        \Illuminate\Support\Facades\Log::info('Vonage JWT generation', [
            'app_id' => $appId,
            'private_key' => $privateKeyPath,
        ]);
        
        $payload = [
            'application_id' => $appId,
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => uniqid(),
            'sub' => $appId,
            'acl' => [
                'paths' => [
                    '/v0.1/messages/**' => [],
                ]
            ]
        ];

        return FirebaseJWT::encode(
            $payload,
            file_get_contents($privateKeyPath),
            'RS256'
        );
    }
}
