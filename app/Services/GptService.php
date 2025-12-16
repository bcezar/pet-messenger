<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GptService
{
    public function handleConversation(array $context): array
    {
        $prompt = $this->buildPrompt(
            $context['session_data'] ?? [],
            $context['user_message']
        );

        $response = Http::withToken(config('services.openai.key'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $prompt,
                    ],
                ],
                'temperature' => 0.3,
                'max_tokens' => 300,
            ]);

        Log::info('GPT HTTP response', [
            'status' => $response->status(),
            'body' => $response->body(),
        ]);

        $content = $response->json('choices.0.message.content');

        if (!$content) {
            return $this->fallbackResponse();
        }

        return $this->parseResponse($content);
    }

    /**
     * Prompt principal do sistema
     */
    private function buildPrompt(array $sessionData, string $message): string
    {
        return <<<PROMPT
Você é uma secretária virtual de um banho e tosa.

Seu objetivo é ajudar o cliente a AGENDAR um banho para o pet.

Você deve coletar exatamente estas informações:
- nome_pet
- raca_pet
- porte_pet (pequeno, médio ou grande)
- data_banho (formato dd/mm/yyyy)

Regras IMPORTANTES:
- Use também os dados já existentes da sessão.
- Nunca pergunte algo que já tenha sido informado.
- Se o cliente enviar vários dados na mesma mensagem, extraia todos.
- Se a data for relativa (ex: amanhã, segunda), converta para dd/mm/yyyy.
- Seja educada, clara e objetiva.
- Responda SOMENTE em JSON válido.
- Nunca escreva texto fora do JSON.

Formato obrigatório da resposta:
{
  "reply": "mensagem para o cliente",
  "data": {
    "nome_pet": null|string,
    "raca_pet": null|string,
    "porte_pet": null|string,
    "data_banho": null|string
  },
  "complete": true|false
}

Dados atuais da sessão:
{$this->safeJson($sessionData)}

Mensagem do cliente:
{$message}
PROMPT;
    }

    /**
     * Parse seguro da resposta da IA
     */
    private function parseResponse(string $raw): array
    {
        try {
            $json = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

            return [
                'reply' => $json['reply'] ?? 'Pode me explicar melhor, por favor? 😊',
                'data' => $this->sanitizeData($json['data'] ?? []),
                'complete' => (bool) ($json['complete'] ?? false),
            ];
        } catch (\Throwable $e) {
            Log::error('Erro ao parsear resposta da IA', [
                'raw' => $raw,
                'error' => $e->getMessage(),
            ]);

            return $this->fallbackResponse();
        }
    }

    /**
     * Limpa e restringe os campos permitidos
     */
    private function sanitizeData(array $data): array
    {
        return array_filter(
            array_intersect_key($data, array_flip([
                'nome_pet',
                'raca_pet',
                'porte_pet',
                'data_banho',
            ]))
        );
    }

    /**
     * Fallback padrão
     */
    private function fallbackResponse(): array
    {
        return [
            'reply' => 'Desculpe 😕 não consegui entender muito bem. Pode me explicar com mais detalhes?',
            'data' => [],
            'complete' => false,
        ];
    }

    /**
     * JSON seguro para prompt
     */
    private function safeJson(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
