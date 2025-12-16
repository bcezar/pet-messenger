<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Http\Request;
use App\Helpers\MessageHelper;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CompanyResolverService
{
    public function resolve(Request $request): Company
    {
        // Número que recebeu a mensagem (WhatsApp da empresa)
        $rawTo = $request->input('To')
            ?? $request->input('to')
            ?? '';

        $companyPhone = MessageHelper::normalizePhone($rawTo);

        if (!$companyPhone) {
            throw new \RuntimeException('Número de destino (company) não informado no webhook');
        }

        $company = Company::where('whatsapp_number', $companyPhone)->first();

        if (!$company) {
            throw new ModelNotFoundException(
                "Empresa não encontrada para o número {$companyPhone}"
            );
        }

        return $company;
    }
}
