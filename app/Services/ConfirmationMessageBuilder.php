<?php

namespace App\Services;

class ConfirmationMessageBuilder
{
    public function build(array $data): string
    {
        return
"Perfeito! 🐾 Confere pra mim se está tudo certo:

🐶 Pet: {$data['nome_pet']}
📏 Porte: {$data['porte_pet']}
📅 Data do banho: {$data['data_banho']}
🆕 Primeira vez: " . ($data['primeira_vez'] === 'sim' ? 'Sim' : 'Não') . "

Posso confirmar o agendamento?";
    }
}
