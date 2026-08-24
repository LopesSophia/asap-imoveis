<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class BuscaAnoConstrucaoService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const TIPOS_EM_PREDIO = ['apartamento', 'cobertura'];

    /**
     * Busca o ano de construção na web via tool de busca nativa da Anthropic.
     * Só executa para apartamento/cobertura com ano_construcao ainda null —
     * qualquer outra condição retorna null sem gastar nenhuma chamada.
     * Nunca lança exceção: falha de rede, timeout, resposta inesperada etc.
     * são logadas e resultam em null, para nunca travar o cadastro por causa
     * de um enriquecimento que é só um "bônus".
     *
     * @param  array<string, mixed>  $imovel
     */
    public function buscar(array $imovel): ?int
    {
        $tipoImovel = $imovel['tipo_imovel'] ?? null;
        $anoConstrucao = $imovel['ano_construcao'] ?? null;

        if (! in_array($tipoImovel, self::TIPOS_EM_PREDIO, true) || $anoConstrucao !== null) {
            return null;
        }

        $query = $this->montarQuery($imovel);

        if ($query === null) {
            return null;
        }

        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            Log::error('BuscaAnoConstrucaoService: ANTHROPIC_API_KEY não configurada, busca ignorada.', [
                'query' => $query,
            ]);

            return null;
        }

        try {
            $resposta = Http::timeout(30)->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'content-type' => 'application/json',
            ])->post(self::ENDPOINT, [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 300,
                'system' => $this->systemPrompt(),
                'messages' => [
                    ['role' => 'user', 'content' => $query],
                ],
                'tools' => [
                    ['type' => 'web_search_20250305', 'name' => 'web_search'],
                ],
            ]);

            if ($resposta->failed()) {
                Log::error('BuscaAnoConstrucaoService: falha HTTP ao buscar ano de construção.', [
                    'query' => $query,
                    'status' => $resposta->status(),
                    'body' => $resposta->body(),
                ]);

                return null;
            }

            return $this->extrairAno($resposta->json('content', []), $query);
        } catch (Throwable $e) {
            Log::error('BuscaAnoConstrucaoService: erro inesperado ao buscar ano de construção.', [
                'query' => $query,
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $imovel
     */
    private function montarQuery(array $imovel): ?string
    {
        $nomeEdificio = trim((string) ($imovel['nome_edificio'] ?? ''));
        $logradouro = trim((string) ($imovel['logradouro'] ?? ''));
        $bairro = trim((string) ($imovel['bairro'] ?? ''));
        $cidade = trim((string) ($imovel['cidade'] ?? ''));

        if ($nomeEdificio !== '') {
            return implode(' ', array_filter([$nomeEdificio, $bairro, 'ano de construção']));
        }

        if ($logradouro !== '' || $bairro !== '' || $cidade !== '') {
            return implode(' ', array_filter([$logradouro, $bairro, $cidade, 'ano de construção prédio']));
        }

        // Sem nome de edifício nem nenhum dado de endereço — não há o que buscar.
        return null;
    }

    /**
     * A resposta pode trazer blocos de tool_use/tool_result da busca web antes
     * do texto final — pega o ÚLTIMO bloco de texto e faz parse do JSON nele.
     *
     * @param  array<int, array<string, mixed>>  $blocos
     */
    private function extrairAno(array $blocos, string $query): ?int
    {
        $blocoTexto = collect($blocos)->filter(fn ($b) => ($b['type'] ?? null) === 'text')->last();

        if (! $blocoTexto || ! isset($blocoTexto['text'])) {
            Log::info('BuscaAnoConstrucaoService: resposta sem bloco de texto final, nenhum ano encontrado.', [
                'query' => $query,
            ]);

            return null;
        }

        $ano = $this->extrairAnoDoTexto($blocoTexto['text']);

        if ($ano === null) {
            Log::info('BuscaAnoConstrucaoService: nenhum ano de construção confiável encontrado.', [
                'query' => $query,
            ]);

            return null;
        }

        Log::info('BuscaAnoConstrucaoService: ano de construção encontrado.', [
            'query' => $query,
            'ano_construcao' => $ano,
        ]);

        return $ano;
    }

    /**
     * O prompt pede "responda APENAS com um JSON", mas na prática — sobretudo
     * quando a busca não acha nada — o modelo às vezes explica antes de
     * concluir com o bloco JSON (ex.: "Nenhuma busca encontrou... ```json
     * {"ano_construcao": null}```"). Por isso não confiamos que a string
     * inteira seja JSON: primeiro tentamos achar um bloco ```json ... ```
     * em qualquer posição do texto, depois tentamos o texto inteiro como
     * fallback, e só então desistimos.
     */
    private function extrairAnoDoTexto(string $texto): ?int
    {
        if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $texto, $matches)) {
            $decodificado = json_decode($matches[1], true);
        } else {
            $decodificado = json_decode(trim($texto), true);
        }

        if (! is_array($decodificado)) {
            return null;
        }

        $ano = $decodificado['ano_construcao'] ?? null;

        return ($ano !== null && is_numeric($ano)) ? (int) $ano : null;
    }

    private function systemPrompt(): string
    {
        return file_get_contents(resource_path('prompts/busca_ano_construcao.md'));
    }
}
