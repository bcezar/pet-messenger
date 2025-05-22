<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\ChatSession;
use App\Models\Agendamento;
use Twilio\Rest\Client;

class WebhookController extends Controller
{
    public function handle(Request $request)
    {
        $from = $request->input('From');
        $rawBody = $request->input('Body');

        // Normaliza a mensagem
        $body = strtolower(trim($rawBody));
        $body = preg_replace('/[^\p{L}\p{N}\s]/u', '', $body); // remove pontuação
        $body = iconv('UTF-8', 'ASCII//TRANSLIT', $body); // remove acentos

        // Recupera ou cria sessão do cliente
        $session = ChatSession::firstOrCreate(
            ['phone' => $from],
            ['state' => 'inicio', 'data' => []]
        );

        $state = $session->state;
        $data = $session->data ?? [];

        // Verifica intenção direta de agendar
        $frasesAgendamento = ['agendar', 'marcar', 'banho', 'tem horario', 'dá pra fazer', 'fazer hoje', 'quero banho'];
        foreach ($frasesAgendamento as $frase) {
            if (str_contains($body, $frase) && $state === 'inicio') {
                $session->update([
                    'state' => 'esperando_nome_pet',
                    'data' => ['intencao_agendamento' => true]
                ]);
                return $this->sendMessage($from, 'Claro! Qual é o nome do seu pet?');
            }
        }

        // Saudações
        $frasesSaudacao = ['ola', 'oi', 'bom dia', 'boa tarde', 'boa noite'];
        foreach ($frasesSaudacao as $frase) {
            if (str_contains($body, $frase) && $state === 'inicio') {
                return $this->sendMessage($from, 'Olá! 😊 Você gostaria de agendar um banho para seu pet? Pode me dizer!');
            }
        }

        // Comando de reinício
        if ($body === 'reiniciar') {
            $session->update(['state' => 'inicio', 'data' => []]);
            return $this->sendMessage($from, 'Vamos começar de novo! É a sua primeira vez conosco? (sim ou não)');
        }

        // Fluxo principal de perguntas
        switch ($state) {
            case 'inicio':
                $session->update(['state' => 'esperando_primeira_vez']);
                return $this->sendMessage($from, 'Olá! É a sua primeira vez conosco? (sim ou não)');

            case 'esperando_primeira_vez':
                $data['primeira_vez'] = $body;
                $session->update(['state' => 'esperando_nome_pet', 'data' => $data]);
                return $this->sendMessage($from, 'Legal! Qual é o nome do seu pet?');

            case 'esperando_nome_pet':
                $data['nome_pet'] = $body;
                $session->update(['state' => 'esperando_raca_pet', 'data' => $data]);
                return $this->sendMessage($from, 'Que fofinho! Qual é a raça dele?');

            case 'esperando_raca_pet':
                $data['raca_pet'] = $body;
                $session->update(['state' => 'esperando_porte_pet', 'data' => $data]);
                return $this->sendMessage($from, 'E o porte? (pequeno, médio ou grande)');

            case 'esperando_porte_pet':
                $data['porte_pet'] = $body;
                $session->update(['state' => 'esperando_data', 'data' => $data]);
                return $this->sendMessage($from, 'Perfeito! Para qual dia você quer agendar o banho?');

            case 'esperando_data':
                $data['data_banho'] = $body;
                $session->update(['state' => 'finalizado', 'data' => $data]);

                // salva agendamento
                Agendamento::create([
                    'phone' => $from,
                    'nome_pet' => $data['nome_pet'],
                    'raca_pet' => $data['raca_pet'],
                    'porte_pet' => $data['porte_pet'],
                    'data_banho' => $data['data_banho'],
                    'primeira_vez' => isset($data['primeira_vez']) && $data['primeira_vez'] === 'sim',
                ]);

                return $this->sendMessage($from, "Agendamento registrado! Obrigado! 🐶\n\nResumo:\nPet: {$data['nome_pet']}\nRaça: {$data['raca_pet']}\nPorte: {$data['porte_pet']}\nData: {$data['data_banho']}");            
            case 'finalizado':
                return $this->sendMessage($from, "Seu agendamento já foi feito! Se precisar de algo, digite *reiniciar* para começar de novo.");

            default:
                return $this->sendMessage($from, "Desculpe, não entendi. Digite *reiniciar* para começar de novo.");
        }
    }

    private function sendMessage($to, $message)
    {
        $twilio = new Client(env('TWILIO_SID'), env('TWILIO_AUTH_TOKEN'));

        $twilio->messages->create($to, [
            'from' => env('TWILIO_WHATSAPP_FROM'),
            'body' => $message
        ]);
    }
}
