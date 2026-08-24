<?php

namespace App\Services;

use App\Exceptions\EnriquecimentoLocalizacaoException;
use Illuminate\Support\Facades\Http;

class ExtracaoEnderecoService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const MAX_TOKENS = 300;

    /**
     * Extrai o endereço estruturado do texto livre falado pelo corretor.
     * Diferente do ExtracaoImovelService, aqui não se usa tool use forçado —
     * a resposta é o próprio JSON em texto, conforme o prompt da Etapa 3.
     *
     * @return array<string, mixed>
     */
    public function extrair(string $textoLivre): array
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            throw new EnriquecimentoLocalizacaoException('ANTHROPIC_API_KEY não está configurada no .env.');
        }

        $resposta = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->post(self::ENDPOINT, [
            'model' => config('services.anthropic.model'),
            'max_tokens' => self::MAX_TOKENS,
            'system' => $this->systemPrompt(),
            'messages' => [
                ['role' => 'user', 'content' => $textoLivre],
            ],
        ]);

        if ($resposta->failed()) {
            $mensagem = $resposta->json('error.message') ?? $resposta->body();

            throw new EnriquecimentoLocalizacaoException(
                "Falha ao chamar a API da Anthropic para extração de endereço (HTTP {$resposta->status()}): {$mensagem}"
            );
        }

        $blocoTexto = collect($resposta->json('content', []))->firstWhere('type', 'text');

        if (! $blocoTexto || ! isset($blocoTexto['text'])) {
            throw new EnriquecimentoLocalizacaoException('A resposta da Anthropic não trouxe texto para a extração de endereço.');
        }

        $endereco = json_decode($this->limparPossivelMarkdown($blocoTexto['text']), true);

        if (! is_array($endereco)) {
            throw new EnriquecimentoLocalizacaoException('A resposta da Anthropic para extração de endereço não é um JSON válido.');
        }

        return $endereco;
    }

    /**
     * O prompt já pede "sem markdown", mas o modelo ocasionalmente envolve a
     * resposta em ```json ... ``` mesmo assim — remove isso defensivamente.
     */
    private function limparPossivelMarkdown(string $texto): string
    {
        $texto = trim($texto);
        $texto = preg_replace('/^```(?:json)?/i', '', $texto);
        $texto = preg_replace('/```$/', '', $texto);

        return trim($texto);
    }

    private function systemPrompt(): string
    {
        return file_get_contents(resource_path('prompts/extracao_endereco_etapa3.md'));
    }
}
