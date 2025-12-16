<?php

namespace App\Services;

use App\Models\ChatSession;
use App\Models\Company;

class ChatSessionResolverService
{
    public function resolve(Company $company, string $clientPhone): ChatSession
    {
        return ChatSession::firstOrCreate(
            [
                'company_id'   => $company->id,
                'client_phone' => $clientPhone,
            ],
            [
                'state' => 'active',
                'data'  => [],
            ]
        );
    }
}
