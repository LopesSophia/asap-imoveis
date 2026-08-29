<?php

namespace App\Services;

use App\Exceptions\EdicaoFotoException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EdicaoFotoGeminiService
{
    private const ENDPOINT_BASE = 'https://generativelanguage.googleapis.com/v1beta/models';

    private const TIMEOUT_SEGUNDOS = 90;

    /**
     * "Chamadas internas" — pertencem à MESMA tentativa do usuário (a
     * mesma linha em imovel_staging_foto_edicoes, já reservada em cota
     * ANTES do job rodar). Nunca reserva cota nova nem cria outra linha.
     */
    private const MAX_TENTATIVAS_HTTP = 3;

    /**
     * Backoff entre uma chamada interna e a próxima (índice = tentativa -
     * 1). Só usado para falhas TRANSITÓRIAS (503/UNAVAILABLE, timeout/erro
     * de conexão) — nunca para 403, limite de cota (429/RESOURCE_EXHAUSTED)
     * ou resposta inválida, que falham na primeira tentativa mesmo.
     */
    private const BACKOFF_SEGUNDOS = [1, 2];

    /**
     * @param  array<int, array{categoria: string, descricao: string}>  $itens
     */
    public function montarPrompt(array $itens): string
    {
        $template = file_get_contents(resource_path('prompts/edicao_fotos.md'));

        $listaItens = collect($itens)
            ->map(fn (array $item) => "- {$item['categoria']}: {$item['descricao']}")
            ->implode("\n");

        return str_replace('{{ITENS}}', $listaItens, $template);
    }

    /**
     * Gera a edição (remoção dos itens indicados) de UMA foto e grava o
     * resultado no disco "public", sem nunca tocar no arquivo original.
     * Recebe o prompt já montado (não os itens) para que o que é
     * efetivamente enviado seja EXATAMENTE o que foi persistido como
     * auditoria no momento da criação da tentativa — nunca reconstruído.
     *
     * @return array{caminho: string, provider: string, modelo: string}
     */
    public function editar(string $caminhoOriginal, string $promptEnviado, string $caminhoDestino): array
    {
        $apiKey = config('services.gemini.key');
        $modelo = config('services.gemini.model');

        if (empty($apiKey)) {
            throw new EdicaoFotoException('GOOGLE_GEMINI_API_KEY não está configurada no .env.');
        }

        $imagemBase64 = base64_encode(Storage::disk('public')->get($caminhoOriginal));
        $mediaType = $this->detectarMediaType($caminhoOriginal);

        $payload = [
            'contents' => [[
                'parts' => [
                    ['text' => $promptEnviado],
                    ['inlineData' => ['mimeType' => $mediaType, 'data' => $imagemBase64]],
                ],
            ]],
        ];

        $resposta = $this->chamarGeminiComRetry($apiKey, $modelo, $payload);

        if ($resposta->failed()) {
            // Detalhe técnico SÓ no log (nunca a chave, nunca o base64 da
            // imagem) — o que vira exceção/mensagem_erro é sempre um texto
            // curto e seguro em português, nunca o corpo bruto do Google.
            Log::error('EdicaoFotoGeminiService: falha ao chamar a API do Gemini.', [
                'status_http' => $resposta->status(),
                'status_google' => $resposta->json('error.status'),
                'mensagem_google' => $resposta->json('error.message'),
            ]);

            throw new EdicaoFotoException($this->mensagemAmigavelParaFalhaHttp(
                $resposta->status(),
                $resposta->json('error.status')
            ));
        }

        $imagemGeradaBase64 = $this->extrairImagem($resposta->json());

        $bytesImagem = base64_decode($imagemGeradaBase64, true);

        if ($bytesImagem === false || @imagecreatefromstring($bytesImagem) === false) {
            throw new EdicaoFotoException('A API do Gemini retornou um arquivo que não é uma imagem válida.');
        }

        $gravado = Storage::disk('public')->put($caminhoDestino, $bytesImagem);

        if (! $gravado) {
            throw new EdicaoFotoException('Não foi possível gravar o arquivo da foto editada no disco.');
        }

        return [
            'caminho' => $caminhoDestino,
            'provider' => 'gemini',
            'modelo' => $modelo,
        ];
    }

    /**
     * Chama o Gemini com até MAX_TENTATIVAS_HTTP tentativas internas, mas
     * SÓ repete falhas transitórias (503/UNAVAILABLE ou erro de
     * conexão/timeout) — 403, limite de cota (429), resposta inválida ou
     * qualquer outra falha retornam já na primeira tentativa, sem repetir.
     * Todas as tentativas aqui dentro pertencem à MESMA reserva de cota do
     * job que chamou editar() (a linha já foi criada e a cota já foi
     * incrementada antes disso) — nada aqui cria uma nova tentativa nem
     * consome cota de novo. A edição continua "processando" durante todo
     * este método; só o retorno (sucesso ou falha definitiva) decide o
     * status final, decidido por editar().
     */
    private function chamarGeminiComRetry(string $apiKey, string $modelo, array $payload): Response
    {
        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS_HTTP; $tentativa++) {
            try {
                $resposta = Http::timeout(self::TIMEOUT_SEGUNDOS)
                    ->withHeaders(['x-goog-api-key' => $apiKey])
                    ->post(self::ENDPOINT_BASE."/{$modelo}:generateContent", $payload);
            } catch (ConnectionException $e) {
                if ($tentativa >= self::MAX_TENTATIVAS_HTTP) {
                    Log::error('EdicaoFotoGeminiService: falha de conexão persistente ao chamar o Gemini, tentativas internas esgotadas.', [
                        'tentativas' => $tentativa,
                        'mensagem' => $e->getMessage(),
                    ]);

                    throw new EdicaoFotoException('Não foi possível conectar ao serviço de edição de fotos. Tente novamente.');
                }

                Log::warning('EdicaoFotoGeminiService: falha de conexão ao chamar o Gemini, tentando novamente (mesma tentativa do usuário).', [
                    'tentativa_interna' => $tentativa,
                    'mensagem' => $e->getMessage(),
                ]);

                $this->aguardar(self::BACKOFF_SEGUNDOS[$tentativa - 1] ?? end(self::BACKOFF_SEGUNDOS));

                continue;
            }

            $ultimaTentativa = $tentativa >= self::MAX_TENTATIVAS_HTTP;

            if ($this->falhaTransitoria($resposta) && ! $ultimaTentativa) {
                Log::warning('EdicaoFotoGeminiService: falha transitória do Gemini, tentando novamente (mesma tentativa do usuário).', [
                    'tentativa_interna' => $tentativa,
                    'status_http' => $resposta->status(),
                    'status_google' => $resposta->json('error.status'),
                ]);

                $this->aguardar(self::BACKOFF_SEGUNDOS[$tentativa - 1] ?? end(self::BACKOFF_SEGUNDOS));

                continue;
            }

            return $resposta;
        }
    }

    /**
     * Só 503 (HTTP) ou status "UNAVAILABLE" do Google contam como
     * transitório — o diagnóstico real que motivou este retry ("This model
     * is currently experiencing high demand") vem exatamente assim.
     */
    private function falhaTransitoria(Response $resposta): bool
    {
        return $resposta->failed()
            && ($resposta->status() === 503 || $resposta->json('error.status') === 'UNAVAILABLE');
    }

    /**
     * Isolado num método próprio (em vez de sleep() direto) só para poder
     * ser mockado nos testes — sem isso, os testes de retry realmente
     * dormiriam alguns segundos a cada execução.
     */
    protected function aguardar(int $segundos): void
    {
        if ($segundos > 0) {
            sleep($segundos);
        }
    }

    /**
     * Traduz uma falha HTTP do Gemini para uma mensagem curta e segura em
     * português — nunca o texto bruto retornado pelo Google. Cobre em
     * especial falta de cota/faturamento (429/RESOURCE_EXHAUSTED) e acesso
     * negado (403/PERMISSION_DENIED), que são os casos mais prováveis de
     * aparecer pro corretor sem uma conta Google Cloud com billing ativo.
     */
    private function mensagemAmigavelParaFalhaHttp(int $statusHttp, ?string $statusGoogle): string
    {
        if ($statusHttp === 429 || $statusGoogle === 'RESOURCE_EXHAUSTED') {
            return 'O serviço de edição de fotos atingiu o limite de uso no momento. Tente novamente mais tarde.';
        }

        if ($statusHttp === 403 || in_array($statusGoogle, ['PERMISSION_DENIED', 'UNAUTHENTICATED'], true)) {
            return 'O serviço de edição de fotos não está disponível no momento. Avise o suporte.';
        }

        return 'Não foi possível gerar a edição da foto no momento. Tente novamente.';
    }

    private function detectarMediaType(string $caminho): string
    {
        return Str::endsWith(strtolower($caminho), '.png') ? 'image/png' : 'image/jpeg';
    }

    /**
     * Percorre as partes da resposta em busca do primeiro bloco de imagem
     * (inlineData). Nunca confia que a IA obedeceu — se não vier nenhuma
     * imagem (ex.: só texto recusando o pedido), trata como falha explícita
     * em vez de gravar lixo no disco.
     *
     * @param  array<string, mixed>|null  $corpo
     */
    private function extrairImagem(?array $corpo): string
    {
        $partes = $corpo['candidates'][0]['content']['parts'] ?? [];

        foreach ($partes as $parte) {
            $dados = $parte['inlineData']['data'] ?? null;

            if (is_string($dados) && $dados !== '') {
                return $dados;
            }
        }

        Log::error('EdicaoFotoGeminiService: resposta do Gemini não trouxe nenhuma imagem.', [
            'finish_reason' => $corpo['candidates'][0]['finishReason'] ?? null,
        ]);

        throw new EdicaoFotoException('A resposta do Gemini não trouxe a imagem editada esperada.');
    }
}
