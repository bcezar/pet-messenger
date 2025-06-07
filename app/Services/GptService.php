<?php
namespace App\Services;

use Illuminate\Container\Attributes\Log;
use Illuminate\Support\Facades\Http;

class GptService
{
    public function extractPetData(string $message): array
    {
        $prompt = <<<EOT
Extraia os seguintes dados de um pedido de agendamento de banho e tosa:
- nome_pet
- raca_pet
- porte_pet

Mensagem do cliente: "$message"

Se conseguir identificar algum desses dados, responda com um JSON contendo apenas os campos que conseguiu extrair. Por exemplo:
{"nome_pet": "Thor", "raca_pet": "Labrador"}

Se não for possível identificar nenhum desses dados, envie uma mensagem educada solicitando que o cliente informe as informações faltantes.

Não diga nada além do JSON ou da mensagem direta ao cliente.
EOT;

        $response = Http::withToken(env('OPENAI_API_KEY'))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-3.5-turbo',
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.2,
                'max_tokens' => 200,
            ]);
        
        Log::resolve(new Log(), app())
            ->info('GPT Response', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        $content = $response->json()['choices'][0]['message']['content'] ?? null;

        if (!$content) {
            return ['message' => 'Desculpe, não consegui entender sua mensagem. Pode tentar reformular?'];
        }

        $json = json_decode($content, true);

        if (is_array($json)) {
            return ['data' => $json]; // campos extraídos com sucesso
        }

        return ['message' => trim($content)]; // resposta textual da IA
    }
}
