<?php

namespace App\Services;

use App\Models\ChatSession;

class ChatFlowService
{
    public function handle(ChatSession $session, string $message): array
    {
        return match ($session->state) {
            'active'    => $this->handleActive($session, $message),
            'completed' => $this->handleCompleted(),
            default     => $this->handleFallback(),
        };
    }

    private function handleActive(ChatSession $session, string $message): array
    {
        $gpt = new GptService();

        $context = [
            'session_data' => $session->data ?? [],
            'user_message' => $message,
        ];

        $response = $gpt->handleConversation($context);

        $data = $response['data'] ?? [];

        $isCompleted = $this->isCompleted($data);

        return [
            'reply'    => $response['reply'],
            'state'    => $isCompleted ? 'completed' : 'active',
            'data'     => $data,
            'complete' => $isCompleted,
        ];
    }

    private function isCompleted(array $data): bool
    {
        $required = [
            'nome_pet',
            'raca_pet',
            'porte_pet',
            'data_banho',
        ];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    private function handleCompleted(): array
    {
        return [
            'reply'    => 'Seu agendamento já foi registrado 😊',
            'state'    => null,
            'data'     => null,
            'complete' => false,
        ];
    }

    private function handleFallback(): array
    {
        return [
            'reply'    => 'Desculpe, não consegui entender. Pode repetir?',
            'state'    => 'active',
            'data'     => null,
            'complete' => false,
        ];
    }
}
