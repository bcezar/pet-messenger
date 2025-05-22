<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\ChatSession;
use App\Models\Agendamento;
use Twilio\Rest\Client;

class WebhookController extends Controller
{
    private const STATE_INITIAL = 'inicio';
    private const STATE_FIRST_TIME = 'esperando_primeira_vez';
    private const STATE_PET_NAME = 'esperando_nome_pet';
    private const STATE_PET_BREED = 'esperando_raca_pet';
    private const STATE_PET_SIZE = 'esperando_porte_pet';
    private const STATE_DATE = 'esperando_data';
    private const STATE_COMPLETED = 'finalizado';

    private const RESTART_COMMAND = 'reiniciar';

    public function handle(Request $request)
    {
        $from = $request->input('From');
        $normalizedMessage = $this->normalizeMessage($request->input('Body'));

        $session = $this->getOrCreateSession($from);
        $state = $session->state;
        $data = $session->data ?? [];

        // Check for direct intent or restart command
        if ($this->shouldProcessDirectIntent($normalizedMessage, $state)) {
            return $this->processDirectIntent($from, $session);
        }

        if ($this->isGreeting($normalizedMessage, $state)) {
            return $this->sendMessage($from, 'Olá! 😊 Você gostaria de agendar um banho para seu pet? Pode me dizer!');
        }

        if ($normalizedMessage === self::RESTART_COMMAND) {
            return $this->restartConversation($from, $session);
        }

        // Process main conversation flow
        return $this->processConversationState($from, $session, $normalizedMessage);
    }

    private function normalizeMessage(string $message): string
    {
        $normalized = strtolower(trim($message));
        return preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
    }

    private function getOrCreateSession(string $phone): ChatSession
    {
        return ChatSession::firstOrCreate(
            ['phone' => $phone],
            ['state' => self::STATE_INITIAL, 'data' => []]
        );
    }

    private function shouldProcessDirectIntent(string $message, string $state): bool
    {
        if ($state !== self::STATE_INITIAL) {
            return false;
        }

        $schedulingPhrases = ['agendar', 'marcar', 'banho', 'tem horario', 'dá pra fazer', 'fazer hoje', 'quero banho'];
        foreach ($schedulingPhrases as $phrase) {
            if (str_contains($message, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private function isGreeting(string $message, string $state): bool
    {
        if ($state !== self::STATE_INITIAL) {
            return false;
        }

        $greetings = ['ola', 'oi', 'bom dia', 'boa tarde', 'boa noite'];
        foreach ($greetings as $greeting) {
            if (str_contains($message, $greeting)) {
                return true;
            }
        }

        return false;
    }

    private function processDirectIntent(string $phone, ChatSession $session)
    {
        $session->update([
            'state' => self::STATE_PET_NAME,
            'data' => ['intencao_agendamento' => true]
        ]);
        return $this->sendMessage($phone, 'Claro! Qual é o nome do seu pet?');
    }

    private function restartConversation(string $phone, ChatSession $session)
    {
        $session->update(['state' => self::STATE_INITIAL, 'data' => []]);
        return $this->sendMessage($phone, 'Vamos começar de novo! É a sua primeira vez conosco? (sim ou não)');
    }

    private function processConversationState(string $phone, ChatSession $session, string $message): null
    {
        $state = $session->state;
        $data = $session->data ?? [];

        switch ($state) {
            case self::STATE_INITIAL:
                return $this->handleInitialState($phone, $session);

            case self::STATE_FIRST_TIME:
                return $this->handleFirstTimeState($phone, $session, $message, $data);

            case self::STATE_PET_NAME:
                return $this->handlePetNameState($phone, $session, $message, $data);

            case self::STATE_PET_BREED:
                return $this->handlePetBreedState($phone, $session, $message, $data);

            case self::STATE_PET_SIZE:
                return $this->handlePetSizeState($phone, $session, $message, $data);

            case self::STATE_DATE:
                return $this->handleDateState($phone, $session, $message, $data);

            case self::STATE_COMPLETED:
                return $this->sendMessage($phone, "Seu agendamento já foi feito! Se precisar de algo, digite *reiniciar* para começar de novo.");

            default:
                return $this->sendMessage($phone, "Desculpe, não entendi. Digite *reiniciar* para começar de novo.");
        }
    }

    // Individual state handlers
    private function handleInitialState(string $phone, ChatSession $session)
    {
        $session->update(['state' => self::STATE_FIRST_TIME]);
        return $this->sendMessage($phone, 'Olá! É a sua primeira vez conosco? (sim ou não)');
    }

    private function handleFirstTimeState(string $phone, ChatSession $session, string $message, array $data)
    {
        $data['primeira_vez'] = $message;
        $session->update(['state' => self::STATE_PET_NAME, 'data' => $data]);
        return $this->sendMessage($phone, 'Legal! Qual é o nome do seu pet?');
    }

    private function handlePetNameState(string $phone, ChatSession $session, string $message, array $data)
    {
        $data['nome_pet'] = $message;
        $session->update(['state' => self::STATE_PET_BREED, 'data' => $data]);
        return $this->sendMessage($phone, 'Que fofinho! Qual é a raça dele?');
    }

    private function handlePetBreedState(string $phone, ChatSession $session, string $message, array $data)
    {
        $data['raca_pet'] = $message;
        $session->update(['state' => self::STATE_PET_SIZE, 'data' => $data]);
        return $this->sendMessage($phone, 'E o porte? (pequeno, médio ou grande)');
    }

    private function handlePetSizeState(string $phone, ChatSession $session, string $message, array $data)
    {
        $data['porte_pet'] = $message;
        $session->update(['state' => self::STATE_DATE, 'data' => $data]);
        return $this->sendMessage($phone, 'Perfeito! Para qual dia você quer agendar o banho?');
    }

    private function handleDateState(string $phone, ChatSession $session, string $message, array $data)
    {
        $data['data_banho'] = $message;
        $session->update(['state' => self::STATE_COMPLETED, 'data' => $data]);

        $this->createAppointment($phone, $data);

        return $this->sendMessage(
            $phone,
            "Agendamento registrado! Obrigado! 🐶\n\nResumo:\nPet: {$data['nome_pet']}\nRaça: {$data['raca_pet']}\nPorte: {$data['porte_pet']}\nData: {$data['data_banho']}"
        );
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
        $sid = config('services.twilio.sid');
        $token = config('services.twilio.token');
        $from = config('services.twilio.from');

        $client = new Client($sid, $token);

        $client->messages->create(
            $to,
            [
                'from' => $from,
                'body' => $message,
            ]
        );

        return Log::info("Mensagem enviada para $to: $message");
    }
}
