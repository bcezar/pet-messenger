<?php

namespace App\Services;

class RestartIntentService
{
    public function isRestart(string $message): bool
    {
        $normalized = $this->normalize($message);

        /**
         * Palavras-chave centrais
         */
        $patterns = [
            'reiniciar',
            'recomecar',
            'recomeçar',
            'resetar',
            'restart',
            'start over',
            'comecar de novo',
            'começar de novo',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        /**
         * Regex curta (ex: "r", "reset", "re")
         */
        if (preg_match('/\b(r|rs|rst|reset)\b/', $normalized)) {
            return true;
        }

        return false;
    }

    private function normalize(string $message): string
    {
        $message = strtolower(trim($message));
        $message = preg_replace('/[^\p{L}\p{N}\s]/u', '', $message);
        return $message;
    }
}
