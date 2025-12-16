<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Helpers\MessageHelper;
use App\Models\ChatSession;
use App\Models\Agendamento;
use App\Services\GptService;
use App\Services\WhatsAppGateway;
use App\Services\CompanyResolverService;

class WebhookController extends Controller
{
    const STATE_INITIAL = "active";
    
    public function handle(Request $request)
    {
        $company = app(CompanyResolverService::class)
            ->resolveFromWebhook($request);

        Log::info('Company resolved', [
            'company_id' => $company->id,
            'company_name' => $company->name,
        ]);

        $from = MessageHelper::extractSender($request);
        $message = MessageHelper::extractMessage($request);

        Log::info("Mensagem recebida de $from: $message");

        $session = ChatSession::firstOrCreate(
            [
                'company_id' => $company->id,
                'client_phone' => $from,
            ],
            [
                'state' => self::STATE_INITIAL,
                'data' => [],
            ]
        );


        if ($this->isRestart($message)) {
            $session->update(['state' => 'active', 'data' => []]);
            return $this->sendMessage($from, 'Tudo bem 😊 Vamos começar de novo. Como posso ajudar?');
        }

        return $this->handleWithAI($from, $session, $message);
    }

    private function isRestart(string $message): bool
    {
        $normalized = $this->normalizeMessage($message);

        return in_array($normalized, [
            'reiniciar',
            'resetar',
            'recomeçar',
            'comecar de novo',
            'começar de novo',
            'restart',
        ]);
    }

    private function normalizeMessage(string $message): string
    {
        $normalized = strtolower(trim($message));
        return preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
    }

    private function createAppointment(string $phone, array $data): void
    {
        Agendamento::create([
            'phone' => $phone,
            'nome_pet' => $data['nome_pet'],
            'raca_pet' => $data['raca_pet'],
            'porte_pet' => $data['porte_pet'],
            'data_banho' => $data['data_banho'],
            'primeira_vez' => isset($data['primeira_vez']) && $data['primeira_vez'] === 'sim',
        ]);
    }

    private function sendMessage(string $to, string $message)
    {
        Log::info("Enviando mensagem para $to: $message");

        app(WhatsAppGateway::class)
            ->resolve()
            ->sendText($to, $message);
    }

    private function handleWithAI(string $to, ChatSession $session, string $message)
    {
        $gpt = new GptService();

        $context = [
            'session_data' => $session->data ?? [],
            'user_message' => $message,
        ];

        $response = $gpt->handleConversation($context);

        Log::info('Resposta IA', $response);

        if (!empty($response['data'])) {
            $session->update([
            'data' => array_merge($session->data ?? [], $response['data'])
        ]);
        }

        if (!empty($response['complete']) && $response['complete'] === true) {
            $this->createAppointment($to, $session->data);

            $session->update(['state' => 'completed']);
        }

        return $this->sendMessage($to, $response['reply']);
    }

}
