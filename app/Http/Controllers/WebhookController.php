<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Helpers\MessageHelper;
use App\Models\Agendamento;
use App\Services\WhatsAppGateway;
use App\Services\CompanyResolverService;
use App\Services\ChatSessionResolverService;
use App\Services\ChatFlowService;
use App\Services\RestartIntentService;


class WebhookController extends Controller
{
    const STATE_INITIAL = "active";
    
    public function handle(
        Request $request,
        CompanyResolverService $companyResolver,
        ChatSessionResolverService $chatSessionResolver,
        ChatFlowService $chatFlow,
        RestartIntentService $restartIntent
    )
    {
        $company = $companyResolver->resolve($request);

        Log::info('Company resolved', [
            'company_id' => $company->id,
            'company_name' => $company->name,
        ]);

        $clientPhone = MessageHelper::extractSender($request);
        $message = MessageHelper::extractMessage($request);

        Log::info("Mensagem recebida", [
            'company_id' => $company->id,
            'from' => $clientPhone,
            'message' => $message,
        ]);

        $session = $chatSessionResolver->resolve($company, $clientPhone);

        if ($restartIntent->isRestart($message)) {
            $session->update([
                'state' => self::STATE_INITIAL,
                'data'  => [],
            ]);

            return $this->sendMessage(
                $clientPhone,
                'Tudo bem 😊 Vamos começar de novo. Como posso ajudar?'
            );
        }

        $flow = $chatFlow->handle($session, $message);

        Log::info('ChatFlow result', $flow);

        if (!empty($flow['state'])) {
            $session->state = $flow['state'];
        }

        if (!empty($flow['data'])) {
            $session->data = array_merge($session->data ?? [], $flow['data']);
        }

        $session->save();

        if (!empty($flow['complete']) && $flow['complete'] === true) {
            $this->createAppointment($company->id, $clientPhone, $session->data);
        }

        return $this->sendMessage($clientPhone, $flow['reply']);
    }

    private function createAppointment(
        int $companyId,
        string $clientPhone,
        array $data
    ): void {
        Agendamento::create([
            'company_id'  => $companyId,
            'client_phone'       => $clientPhone,
            'nome_pet'    => $data['nome_pet'] ?? null,
            'raca_pet'    => $data['raca_pet'] ?? null,
            'porte_pet'   => $data['porte_pet'] ?? null,
            'data_banho'  => $data['data_banho'] ?? null,
            'primeira_vez'=> ($data['primeira_vez'] ?? null) === 'sim',
        ]);
    }

    private function sendMessage(string $to, string $message)
    {
        Log::info("Enviando mensagem", [
            'to' => $to,
            'message' => $message,
        ]);

        app(WhatsAppGateway::class)
            ->resolve()
            ->sendText($to, $message);
    }

}
