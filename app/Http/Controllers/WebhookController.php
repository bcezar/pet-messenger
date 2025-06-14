<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Helpers\MessageHelper;
use App\Models\ChatSession;
use App\Models\Agendamento;
use App\Services\GptService;
use App\Services\WhatsAppGateway;
use Carbon\Carbon;


class WebhookController extends Controller
{
    private const STATE_INITIAL = 'inicio';
    private const STATE_PET_NAME = 'esperando_nome_pet';
    private const STATE_PET_BREED = 'esperando_raca_pet';
    private const STATE_PET_SIZE = 'esperando_porte_pet';
    private const STATE_DATE = 'esperando_data';
    private const STATE_DATE_CONFIRMATION = 'confirmando_data';
    private const STATE_COMPLETED = 'finalizado';

    public function handle(Request $request)
    {
        $from = MessageHelper::extractSender($request);
        $normalizedMessage = $this->normalizeMessage(MessageHelper::extractMessage($request));
        Log::info("Mensagem recebida de $from: $normalizedMessage");

        $session = $this->getOrCreateSession($from);
        $state = $session->state;
        $data = $session->data ?? [];

        Log::info("Sessão atual: $state, Dados: " . json_encode($data));

        // Check for direct intent or restart command
        if ($this->shouldProcessDirectIntent($normalizedMessage, $state)) {
            return $this->processDirectIntent($from, $session, $normalizedMessage);
        }

        if ($this->isGreeting($normalizedMessage, $state)) {
            return $this->sendMessage($from, 'Olá! 😊 Sou um assistente virtual. Como posso ajudar você hoje?');
        }

        if (str_contains($normalizedMessage, 'reiniciar')) {
            $session->update(['state' => self::STATE_INITIAL, 'data' => []]);
            return $this->sendMessage($from, 'Sessão reiniciada! Vamos começar de novo. 😊');
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

        $schedulingPhrases = [
            'agendar',
            'marcar',
            'banho',
            'tem horario',
            'fazer hoje',
            'quero banho',
            'tem horario hoje',
            'posso agendar',
            'dar banho',
            'banho hoje',
            'agendar banho',
            'horário'
        ];

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

    private function processDirectIntent(string $phone, ChatSession $session, $message)
    {
        return $this->contactIA($phone, $session, $message);
    }

    private function processConversationState(string $phone, ChatSession $session, string $message): null
    {
        $state = $session->state;
        $data = $session->data ?? [];

        switch ($state) {
            case self::STATE_INITIAL:
                return $this->handleInitialState($phone, $session);

            case self::STATE_PET_NAME:
                return $this->handlePetNameState($phone, $session, $message, $data);

            case self::STATE_PET_BREED:
                return $this->handlePetBreedState($phone, $session, $message, $data);

            case self::STATE_PET_SIZE:
                return $this->handlePetSizeState($phone, $session, $message, $data);

            case self::STATE_DATE:
                return $this->handleDateState($phone, $session, $message, $data);

            case self::STATE_DATE_CONFIRMATION:
                return $this->handleDateConfirmationState($phone, $session, $message, $data);

            case self::STATE_COMPLETED:
                return $this->sendMessage($phone, "Seu agendamento já foi feito! Se precisar de algo, digite *reiniciar* para começar de novo.");

            default:
                return $this->sendMessage($phone, "Desculpe, não entendi. Digite *reiniciar* para começar de novo.");
        }
    }

    // Individual state handlers
    private function handleInitialState(string $phone, ChatSession $session)
    {
        $session->update(['state' => self::STATE_INITIAL, 'data' => []]);
        return $this->sendMessage($phone, 'Olá! Sou um assistente virtual. Você pode dizer em poucas palavras o que você precisa?');
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
        $normalized = $this->normalizeMessage($message);

        // Se for uma data válida no formato dd/mm/yyyy
        if (preg_match('/^(0?[1-9]|[12][0-9]|3[01])[\/\-](0?[1-9]|1[012])[\/\-]\d{2,4}$/', $normalized)) {
            $data['data_banho'] = $normalized;
            $session->update(['state' => self::STATE_COMPLETED, 'data' => $data]);
            $this->createAppointment($phone, $data);

            return $this->sendMessage($phone, "Agendamento registrado! Obrigado! 🐶\n\nResumo:\nPet: {$data['nome_pet']}\nRaça: {$data['raca_pet']}\nPorte: {$data['porte_pet']}\nData: {$data['data_banho']}");
        }

        // Se for um dia da semana ou algo como "amanhã"
        $possibleDate = $this->tryParseRelativeDate($normalized);
        if ($possibleDate) {
            $data['data_sugerida'] = $possibleDate->format('d/m/Y');
            $session->update(['state' => 'confirmando_data', 'data' => $data]);

            return $this->sendMessage($phone, "Você quis dizer *" . $data['data_sugerida'] . "*? (responda sim ou não)");
        }

        return $this->sendMessage($phone, 'Por favor, envie a data no formato *dd/mm/aaaa*, ou diga "amanhã", "segunda", etc.');
    }

    private function tryParseRelativeDate(string $text): ?Carbon
    {
        $text = strtolower($text);
        $today = Carbon::today();

        if (in_array($text, ['amanha', 'amanhã'])) return $today->copy()->addDay();
        if ($text === 'depois de amanha') return $today->copy()->addDays(2);

        $weekDays = [
            'segunda' => Carbon::MONDAY,
            'terca' => Carbon::TUESDAY,
            'terça' => Carbon::TUESDAY,
            'quarta' => Carbon::WEDNESDAY,
            'quinta' => Carbon::THURSDAY,
            'sexta' => Carbon::FRIDAY,
            'sabado' => Carbon::SATURDAY,
            'sábado' => Carbon::SATURDAY,
            'domingo' => Carbon::SUNDAY,
        ];

        foreach ($weekDays as $dia => $carbonDia) {
            if (str_contains($text, $dia)) {
                return $today->copy()->next($carbonDia);
            }
        }

        return null;
    }


    private function handleDateConfirmationState(string $phone, ChatSession $session, string $message, array $data)
    {
        $yes = ['sim', 's', 'claro', 'isso'];
        $no = ['nao', 'não', 'n'];

        $normalized = $this->normalizeMessage($message);

        if (in_array($normalized, $yes)) {
            $data['data_banho'] = $data['data_sugerida'];
            unset($data['data_sugerida']);

            $session->update(['state' => self::STATE_COMPLETED, 'data' => $data]);
            $this->createAppointment($phone, $data);

            return $this->sendMessage($phone, "Agendamento confirmado para *{$data['data_banho']}*! 🐾\n\nResumo:\nPet: {$data['nome_pet']}\nRaça: {$data['raca_pet']}\nPorte: {$data['porte_pet']}");
        } elseif (in_array($normalized, $no)) {
            unset($data['data_sugerida']);
            $session->update(['state' => self::STATE_DATE, 'data' => $data]);
            return $this->sendMessage($phone, 'Ok! Me diga então a data no formato *dd/mm/aaaa* ou algo como "amanhã".');
        }

        return $this->sendMessage($phone, 'Por favor, responda com *sim* ou *não* para confirmar a data sugerida.');
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

        $wa = new WhatsAppGateway();
        $wa->sendText($to, $message);
    }

    private function contactIA(string $to, ChatSession $session, string $message)
    {
        Log::info("Enviando mensagem para IA: $message");

        $gptService = new GptService();
        $result = $gptService->extractPetData($message);
        Log::info("Resposta da IA: " . json_encode($result));
        if (isset($result['data'])) {
            // GPT retornou JSON com dados
            $parsedData = $result['data'];
            $newData = array_merge($session->data ?? [], $parsedData);
            $session->update(['data' => $newData]);

            // Verifica se já temos todos os dados necessários
            $requiredFields = ['nome_pet', 'raca_pet', 'porte_pet', 'data_banho'];
            $missingFields = array_diff($requiredFields, array_keys($newData));

            if (empty($missingFields)) {
                $this->createAppointment($to, $newData);
                $session->update(['state' => self::STATE_COMPLETED]);
                return $this->sendMessage($to, "Tudo certo! 🐾 Agendamento confirmado para *{$newData['data_banho']}*.\n\nPet: {$newData['nome_pet']}, Raça: {$newData['raca_pet']}, Porte: {$newData['porte_pet']}");
            }

            $updatedState = $this->getCurrentState($missingFields, $session);
            $session->update(['state' => $updatedState]);
            return $this->sendMessage($to, "Legal! Agora me diga o(s) seguinte(s): *" . implode(', ', $missingFields) . "*.");
        }
        if (isset($result['message'])) {
            // GPT retornou uma mensagem de texto ao invés de JSON
            return $this->sendMessage($to, $result['message']);
        }
        // Fallback caso nada funcione
        return $this->sendMessage($to, "Desculpe, não consegui entender. Você pode repetir com mais detalhes?");
    }

    public function getCurrentState(array $missingFields, ChatSession $session): string
    {
        if (in_array('nome_pet', $missingFields)) {
            return self::STATE_PET_NAME;
        }
        if (in_array('raca_pet', $missingFields)) {
            return self::STATE_PET_BREED;
        }
        if (in_array('porte_pet', $missingFields)) {
            return self::STATE_PET_SIZE;
        }
        if (in_array('data_banho', $missingFields)) {
            return self::STATE_DATE;
        }
        return self::STATE_INITIAL; // fallback
    }

    public function handleAiMessage(string $from, $session, $normalizedMessage)
    {
        
    }
}
