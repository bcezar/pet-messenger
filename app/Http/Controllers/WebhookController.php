<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Helpers\MessageHelper;
use App\Models\Agendamento;
use App\Models\Message;
use App\Services\WhatsAppGateway;
use App\Services\CompanyResolverService;
use App\Services\ChatSessionResolverService;
use App\Services\ChatFlowService;
use App\Services\RestartIntentService;
use Carbon\Carbon;


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
        try {
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

            // Wrap entire flow in transaction for consistency
            DB::transaction(function () use ($request, $company, $clientPhone, $message, $chatSessionResolver, $restartIntent, $chatFlow) {
                
                // Step 1: Resolve or create ChatSession
                $session = $chatSessionResolver->resolve($company, $clientPhone);

                Log::info('ChatSession resolved', [
                    'chat_session_id' => $session->id,
                    'status' => $session->status,
                ]);

                // Step 2: Persist inbound message (client message)
                $inboundMessage = Message::create([
                    'company_id' => $company->id,
                    'chat_session_id' => $session->id,
                    'content' => $message,
                    'direction' => Message::DIRECTION_INBOUND,
                    'sender_type' => Message::SENDER_CLIENT,
                    'status' => Message::STATUS_DELIVERED,
                    'channel' => Message::CHANNEL_WHATSAPP,
                    'client_phone' => $clientPhone,
                    'external_id' => $request->input('MessageSid'), // Twilio's unique message ID
                ]);

                Log::info('Inbound message persisted', [
                    'message_id' => $inboundMessage->id,
                    'chat_session_id' => $session->id,
                ]);

                // Step 3: Handle restart intent
                if ($restartIntent->isRestart($message)) {
                    $session->update([
                        'state' => self::STATE_INITIAL,
                        'data'  => [],
                    ]);

                    $response = 'Tudo bem 😊 Vamos começar de novo. Como posso ajudar?';
                    $this->sendAndPersistMessage($company, $session, $clientPhone, $response);
                    return;
                }

                // Step 4: Process message through ChatFlow
                $flow = $chatFlow->handle($session, $message);

                Log::info('ChatFlow result', $flow);

                // Step 5: Update session state and data
                if (!empty($flow['state'])) {
                    $session->state = $flow['state'];
                }

                if (!empty($flow['data'])) {
                    $session->data = array_merge($session->data ?? [], $flow['data']);
                }

                $session->save();

                // Step 6: Create appointment if flow is complete
                if (!empty($flow['complete']) && $flow['complete'] === true) {
                    $this->updateOrCreateAppointment($company->id, $clientPhone, $session->data);
                }

                // Step 7: Send and persist bot response
                if (!empty($flow['reply'])) {
                    $this->sendAndPersistMessage($company, $session, $clientPhone, $flow['reply']);
                }
            });

            return response()->json(['success' => true], 200);

        } catch (\Exception $e) {
            Log::error('Error processing WhatsApp webhook', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    private function updateOrCreateAppointment(
        int $companyId,
        string $clientPhone,
        array $data
    ): void {

        $dataFormatada = Carbon::createFromFormat(
            'd/m/Y',
            $data['data_banho']
        )->format('Y-m-d');

        Agendamento::updateOrCreate(
            [
                'company_id'   => $companyId,
                'client_phone' => $clientPhone,
                'data_banho'   => $dataFormatada,
            ],
            [
                'nome_pet'     => $data['nome_pet'] ?? null,
                'raca_pet'     => $data['raca_pet'] ?? null,
                'porte_pet'    => $data['porte_pet'] ?? null,
                'primeira_vez' => ($data['primeira_vez'] ?? null) === 'sim',
            ]
        );
    }

    /**
     * Send message via WhatsApp and persist to database
     */
    private function sendAndPersistMessage($company, $session, $clientPhone, $messageContent)
    {
        Log::info("Enviando mensagem", [
            'to' => $clientPhone,
            'message' => $messageContent,
        ]);

        // Send via WhatsApp
        $sendResult = null;
        try {
            $twilioResponse = app(WhatsAppGateway::class)
                ->resolve()
                ->sendText($clientPhone, $messageContent);
            
            $sendResult = [
                'success' => true,
                'sid' => $twilioResponse->sid ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp message', [
                'error' => $e->getMessage(),
            ]);
            $sendResult = ['success' => false];
        }

        // Persist outbound message
        $outboundMessage = Message::create([
            'company_id' => $company->id,
            'chat_session_id' => $session->id,
            'content' => $messageContent,
            'direction' => Message::DIRECTION_OUTBOUND,
            'sender_type' => $session->status === 'human' ? Message::SENDER_HUMAN : Message::SENDER_BOT,
            'status' => $sendResult['success'] ? Message::STATUS_SENT : Message::STATUS_FAILED,
            'channel' => Message::CHANNEL_WHATSAPP,
            'client_phone' => $clientPhone,
            'external_id' => $sendResult['sid'] ?? null,
        ]);

        Log::info('Outbound message persisted', [
            'message_id' => $outboundMessage->id,
            'chat_session_id' => $session->id,
            'status' => $outboundMessage->status,
        ]);

        return $sendResult;
    }
}