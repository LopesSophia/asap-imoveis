<?php

namespace App\Services;

use App\Exceptions\ExtracaoImovelException;
use Illuminate\Support\Facades\Http;

class ExtracaoImovelService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const TOOL_NAME = 'extrair_dados_imovel';

    /**
     * Extrai os dados estruturados do imóvel a partir do texto livre do corretor.
     *
     * @return array<string, mixed>
     */
    public function extrair(string $textoLivre): array
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            throw new ExtracaoImovelException('ANTHROPIC_API_KEY não está configurada no .env.');
        }

        $resposta = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->post(self::ENDPOINT, [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 1024,
            'system' => $this->systemPrompt(),
            'messages' => [
                ['role' => 'user', 'content' => $textoLivre],
            ],
            'tools' => [
                [
                    'name' => self::TOOL_NAME,
                    'description' => 'Extrai os dados estruturados do imóvel a partir da fala do corretor.',
                    'input_schema' => $this->inputSchema(),
                ],
            ],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
        ]);

        if ($resposta->failed()) {
            $mensagem = $resposta->json('error.message') ?? $resposta->body();

            throw new ExtracaoImovelException(
                "Falha ao chamar a API da Anthropic (HTTP {$resposta->status()}): {$mensagem}"
            );
        }

        $blocoToolUse = collect($resposta->json('content', []))
            ->firstWhere('type', 'tool_use');

        if (! $blocoToolUse || ! isset($blocoToolUse['input'])) {
            throw new ExtracaoImovelException('A resposta da Anthropic não trouxe o tool_use esperado.');
        }

        return $blocoToolUse['input'];
    }

    private function systemPrompt(): string
    {
        return file_get_contents(resource_path('prompts/extracao_imovel_etapa1.md'));
    }

    /**
     * @return array<string, mixed>
     */
    private function inputSchema(): array
    {
        return json_decode(
            file_get_contents(app_path('Schemas/imovel_extracao.schema.json')),
            true
        );
    }
}
