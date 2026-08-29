<?php

namespace App\Services;

use App\Exceptions\GeracaoTituloDescricaoException;
use App\Models\ImovelStaging;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeracaoTituloDescricaoService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const TOOL_NAME = 'gerar_descricao_imovel';

    /**
     * 3.000+ caracteres de descrição em português ficam bem acima do que
     * 1024 tokens comportam — 4096 dá folga real para o mínimo exigido
     * mais os rótulos e a formatação.
     */
    private const MAX_TOKENS = 4096;

    private const TAMANHO_MINIMO_PARAGRAFO_INICIAL = 350;

    private const TAMANHO_MAXIMO_PARAGRAFO_INICIAL = 400;

    private const TAMANHO_MINIMO_DESCRICAO = 3000;

    /**
     * Uma descrição de 3.000+ caracteres pode legitimamente demorar perto
     * (ou além) do timeout padrão de 30s do Laravel — foi exatamente essa
     * a causa raiz de "cURL error 28" observada em produção. 150s dá folga
     * real, e o job assíncrono (GerarDescricaoImovelJob) que chama este
     * método não tem o limite de 30s de uma requisição HTTP síncrona.
     */
    private const TIMEOUT_SEGUNDOS = 150;

    /**
     * Cada tentativa aqui é INTERNA a uma única execução do job — não gera
     * nem consome um novo job. Cobre tanto falhas HTTP transitórias
     * (timeout/conexão, 429, 5xx) quanto respostas que vieram fora do
     * contrato (parágrafo/tamanho/rótulos errados): neste último caso, a
     * violação é devolvida à IA como instrução corretiva na tentativa
     * seguinte, em vez de simplesmente tentar de novo às cegas.
     */
    private const MAX_TENTATIVAS = 3;

    private const BACKOFF_SEGUNDOS = [3, 8];

    /**
     * Rótulos que aparecem em QUALQUER tipo de imóvel — CONDOMÍNIO e
     * ACEITA PET são tratados à parte por serem condicionais.
     *
     * @var string[]
     */
    private const ROTULOS_SEMPRE_OBRIGATORIOS = [
        'O IMÓVEL',
        'DIFERENCIAIS',
        'VIAS DE ACESSO',
        'SHOPPINGS PRÓXIMOS',
        'COMÉRCIOS PRÓXIMOS',
        'OPÇÕES DE LAZER',
        'COLÉGIOS E UNIVERSIDADES',
    ];

    private const TEXTO_ACEITA_PET = 'Mediante consulta ao proprietário.';

    /**
     * Título DETERMINÍSTICO — nunca depende da IA. Formato exato:
     * "Apartamento 75 m², 2 quartos, venda Tatuapé" (residencial) ou
     * "Comercial 120 m², 3 vagas, locação Centro" (comercial). Retorna
     * null se faltar algum dado obrigatório para montar o título (nunca
     * gera um título incompleto ou com placeholder).
     */
    public function gerarTitulo(ImovelStaging $imovelStaging): ?string
    {
        $tipo = $this->rotuloTipoImovel($imovelStaging->tipo_imovel);
        $metragem = $imovelStaging->metragem;
        $bairro = trim((string) $imovelStaging->bairro);
        $negociacaoTexto = $this->textoNegociacao($imovelStaging->negociacao);

        if ($tipo === null || $metragem === null || $bairro === '' || $negociacaoTexto === null) {
            return null;
        }

        if ($this->ehComercial($imovelStaging)) {
            $quantidade = $imovelStaging->vagas;
            $unidade = 'vaga';
        } else {
            $quantidade = $imovelStaging->quartos;
            $unidade = 'quarto';
        }

        if ($quantidade === null) {
            return null;
        }

        $quantidadeTexto = "{$quantidade} ".($quantidade == 1 ? $unidade : $unidade.'s');
        $metragemTexto = $this->formatarNumero((float) $metragem);

        return "{$tipo} {$metragemTexto} m², {$quantidadeTexto}, {$negociacaoTexto} {$bairro}";
    }

    /**
     * Gera a descrição via IA, mas nunca confia cegamente na resposta: ela
     * é validada programaticamente contra o contrato completo (parágrafo
     * inicial, rótulos aplicáveis, tamanho mínimo, proibições) ANTES de
     * ser devolvida — uma resposta fora do contrato nunca é retornada
     * (e portanto nunca é persistida pelo chamador). Chamado de dentro de
     * GerarDescricaoImovelJob (assíncrono) — nunca síncrono numa requisição
     * HTTP do corretor, já que uma descrição de 3.000+ caracteres pode
     * legitimamente ultrapassar o timeout de uma request comum.
     *
     * Tentativas internas (MAX_TENTATIVAS) cobrem tanto falhas HTTP
     * transitórias (timeout/conexão, 429, 5xx) quanto violação do
     * contrato — 401/403 e erro definitivo de configuração NUNCA são
     * repetidos.
     *
     * @throws GeracaoTituloDescricaoException
     */
    public function gerarDescricao(ImovelStaging $imovelStaging): string
    {
        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            throw new GeracaoTituloDescricaoException('ANTHROPIC_API_KEY não está configurada no .env.');
        }

        $instrucaoCorretiva = null;

        for ($tentativa = 1; $tentativa <= self::MAX_TENTATIVAS; $tentativa++) {
            $ultimaTentativa = $tentativa >= self::MAX_TENTATIVAS;

            try {
                $resposta = Http::timeout(self::TIMEOUT_SEGUNDOS)
                    ->withHeaders([
                        'x-api-key' => $apiKey,
                        'anthropic-version' => self::ANTHROPIC_VERSION,
                        'content-type' => 'application/json',
                    ])
                    ->post(self::ENDPOINT, [
                        'model' => config('services.anthropic.model'),
                        'max_tokens' => self::MAX_TOKENS,
                        'system' => $this->systemPrompt(),
                        'messages' => [
                            ['role' => 'user', 'content' => json_encode($this->contexto($imovelStaging, $instrucaoCorretiva), JSON_UNESCAPED_UNICODE)],
                        ],
                        'tools' => [
                            [
                                'name' => self::TOOL_NAME,
                                'description' => 'Gera a descrição do anúncio a partir de dados confirmados do imóvel.',
                                'input_schema' => $this->inputSchema(),
                            ],
                        ],
                        'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
                    ]);
            } catch (ConnectionException $e) {
                if ($ultimaTentativa) {
                    Log::error('GeracaoTituloDescricaoService: falha de conexão persistente ao chamar a Anthropic, tentativas esgotadas.', [
                        'imovel_staging_id' => $imovelStaging->id,
                        'tentativas' => $tentativa,
                        'mensagem' => $e->getMessage(),
                    ]);

                    throw new GeracaoTituloDescricaoException('Não foi possível gerar a descrição no momento. Tente novamente.');
                }

                Log::warning('GeracaoTituloDescricaoService: falha de conexão ao chamar a Anthropic, tentando novamente.', [
                    'imovel_staging_id' => $imovelStaging->id,
                    'tentativa' => $tentativa,
                    'mensagem' => $e->getMessage(),
                ]);

                $this->aguardar(self::BACKOFF_SEGUNDOS[$tentativa - 1] ?? end(self::BACKOFF_SEGUNDOS));

                continue;
            }

            if ($resposta->failed()) {
                if (! $this->falhaTransitoria($resposta)) {
                    Log::error('GeracaoTituloDescricaoService: falha definitiva ao chamar a Anthropic, sem nova tentativa.', [
                        'imovel_staging_id' => $imovelStaging->id,
                        'status_http' => $resposta->status(),
                        'mensagem' => $resposta->json('error.message') ?? $resposta->body(),
                    ]);

                    throw new GeracaoTituloDescricaoException($this->mensagemAmigavelParaFalhaHttp($resposta->status()));
                }

                if ($ultimaTentativa) {
                    Log::error('GeracaoTituloDescricaoService: falha transitória persistente ao chamar a Anthropic, tentativas esgotadas.', [
                        'imovel_staging_id' => $imovelStaging->id,
                        'status_http' => $resposta->status(),
                    ]);

                    throw new GeracaoTituloDescricaoException('Não foi possível gerar a descrição no momento. Tente novamente.');
                }

                Log::warning('GeracaoTituloDescricaoService: falha transitória ao chamar a Anthropic, tentando novamente.', [
                    'imovel_staging_id' => $imovelStaging->id,
                    'tentativa' => $tentativa,
                    'status_http' => $resposta->status(),
                ]);

                $this->aguardar(self::BACKOFF_SEGUNDOS[$tentativa - 1] ?? end(self::BACKOFF_SEGUNDOS));

                continue;
            }

            $blocoToolUse = collect($resposta->json('content', []))
                ->firstWhere('type', 'tool_use');

            if (! $blocoToolUse || ! isset($blocoToolUse['input']['descricao'])) {
                if ($ultimaTentativa) {
                    Log::error('GeracaoTituloDescricaoService: resposta da Anthropic sem a descrição esperada, tentativas esgotadas.', [
                        'imovel_staging_id' => $imovelStaging->id,
                    ]);

                    throw new GeracaoTituloDescricaoException('Não foi possível gerar a descrição no momento. Tente novamente.');
                }

                Log::warning('GeracaoTituloDescricaoService: resposta da Anthropic sem a descrição esperada, tentando novamente.', [
                    'imovel_staging_id' => $imovelStaging->id,
                    'tentativa' => $tentativa,
                ]);

                $instrucaoCorretiva = 'A tentativa anterior não usou a ferramenta '.self::TOOL_NAME.' para devolver a descrição no formato esperado. Gere a descrição novamente, obrigatoriamente através dessa ferramenta.';

                $this->aguardar(self::BACKOFF_SEGUNDOS[$tentativa - 1] ?? end(self::BACKOFF_SEGUNDOS));

                continue;
            }

            $descricao = trim((string) $blocoToolUse['input']['descricao']);

            $violacoes = $this->validarDescricao($imovelStaging, $descricao);

            if ($violacoes !== []) {
                // Detalhe técnico (inclusive um recorte do texto gerado, nunca
                // dados sensíveis) só no log — a exceção que sobe pro chamador
                // tem mensagem curta e nunca é salva silenciosamente.
                Log::error('GeracaoTituloDescricaoService: descrição gerada fora do contrato, descartada.', [
                    'imovel_staging_id' => $imovelStaging->id,
                    'tentativa' => $tentativa,
                    'violacoes' => $violacoes,
                    'tamanho_descricao' => mb_strlen($descricao),
                ]);

                if ($ultimaTentativa) {
                    throw new GeracaoTituloDescricaoException(
                        'A descrição gerada não atende aos critérios de qualidade exigidos. Tente novamente.'
                    );
                }

                $instrucaoCorretiva = 'A tentativa anterior de gerar a descrição violou as seguintes regras obrigatórias: '
                    .implode('; ', $violacoes)
                    .'. Gere uma nova descrição corrigindo exatamente esses pontos, mantendo tudo o mais que já estava correto.';

                $this->aguardar(self::BACKOFF_SEGUNDOS[$tentativa - 1] ?? end(self::BACKOFF_SEGUNDOS));

                continue;
            }

            return $descricao;
        }

        // Inalcançável na prática (o laço sempre retorna ou lança antes de
        // terminar as iterações), mas mantém o método com tipo de retorno
        // garantido sem depender só do controle de fluxo do for.
        throw new GeracaoTituloDescricaoException('Não foi possível gerar a descrição no momento. Tente novamente.');
    }

    /**
     * Só 429 (limite de taxa) ou 5xx (erro do lado da Anthropic) são
     * transitórios o suficiente para justificar nova tentativa — qualquer
     * outro 4xx (401, 403, 400, 404...) é tratado como falha definitiva,
     * já que repetir não mudaria o resultado.
     */
    private function falhaTransitoria(Response $resposta): bool
    {
        return $resposta->status() === 429 || $resposta->status() >= 500;
    }

    /**
     * Traduz uma falha HTTP definitiva para uma mensagem curta e segura em
     * português — nunca o texto bruto retornado pela Anthropic.
     */
    private function mensagemAmigavelParaFalhaHttp(int $statusHttp): string
    {
        if (in_array($statusHttp, [401, 403], true)) {
            return 'O serviço de geração de descrição não está disponível no momento. Avise o suporte.';
        }

        return 'Não foi possível gerar a descrição no momento. Tente novamente.';
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
     * @return string[] Violações do contrato — vazio significa que a
     *                  descrição está dentro do esperado.
     */
    private function validarDescricao(ImovelStaging $imovelStaging, string $descricao): array
    {
        $violacoes = [];

        $paragrafoInicial = $this->extrairParagrafoInicial($descricao);
        $tamanhoParagrafo = mb_strlen($paragrafoInicial);

        if ($tamanhoParagrafo < self::TAMANHO_MINIMO_PARAGRAFO_INICIAL || $tamanhoParagrafo > self::TAMANHO_MAXIMO_PARAGRAFO_INICIAL) {
            $violacoes[] = sprintf(
                'parágrafo inicial fora do intervalo %d–%d caracteres (tem %d)',
                self::TAMANHO_MINIMO_PARAGRAFO_INICIAL,
                self::TAMANHO_MAXIMO_PARAGRAFO_INICIAL,
                $tamanhoParagrafo
            );
        }

        $tamanhoTotal = mb_strlen($descricao);
        if ($tamanhoTotal < self::TAMANHO_MINIMO_DESCRICAO) {
            $violacoes[] = 'descrição abaixo do mínimo de '.self::TAMANHO_MINIMO_DESCRICAO." caracteres (tem {$tamanhoTotal})";
        }

        if (preg_match('/[\'’`]/u', $descricao)) {
            $violacoes[] = 'descrição contém apóstrofo';
        }

        if (! empty($imovelStaging->nome_edificio) && mb_stripos($descricao, (string) $imovelStaging->nome_edificio) !== false) {
            $violacoes[] = 'descrição menciona o nome do condomínio/edifício';
        }

        foreach (self::ROTULOS_SEMPRE_OBRIGATORIOS as $rotulo) {
            if (! $this->contemRotulo($descricao, $rotulo)) {
                $violacoes[] = "rótulo obrigatório ausente: {$rotulo}";
            } elseif ($this->temLinhaEmBrancoAposRotulo($descricao, $rotulo)) {
                $violacoes[] = "linha em branco logo após o rótulo {$rotulo}";
            }
        }

        $ehComercial = $this->ehComercial($imovelStaging);
        $temCondominio = $this->contemRotulo($descricao, 'CONDOMÍNIO');
        $deveTerCondominio = ! $ehComercial && (bool) $imovelStaging->em_condominio;

        if ($ehComercial && $temCondominio) {
            $violacoes[] = 'rótulo CONDOMÍNIO não deveria aparecer em imóvel comercial';
        } elseif ($deveTerCondominio && ! $temCondominio) {
            $violacoes[] = 'rótulo CONDOMÍNIO ausente (imóvel residencial em condomínio)';
        } elseif (! $ehComercial && ! $deveTerCondominio && $temCondominio) {
            $violacoes[] = 'rótulo CONDOMÍNIO presente sem o imóvel estar marcado como em condomínio';
        }

        $temPet = $this->contemRotulo($descricao, 'ACEITA PET');
        $deveTerPet = ! $ehComercial && in_array($imovelStaging->negociacao, ['locacao', 'venda_e_locacao'], true);

        if ($ehComercial && $temPet) {
            $violacoes[] = 'rótulo ACEITA PET não deveria aparecer em imóvel comercial';
        } elseif (! $deveTerPet && $temPet) {
            $violacoes[] = 'rótulo ACEITA PET presente sem negociação de locação';
        } elseif ($temPet && ! preg_match('/ACEITA PET\s*\n'.preg_quote(self::TEXTO_ACEITA_PET, '/').'/u', $descricao)) {
            $violacoes[] = 'rótulo ACEITA PET presente sem o texto exato exigido logo em seguida';
        }

        return $violacoes;
    }

    private function contemRotulo(string $descricao, string $rotulo): bool
    {
        return (bool) preg_match('/(^|\n)'.preg_quote($rotulo, '/').'\s*(\n|$)/u', $descricao);
    }

    private function temLinhaEmBrancoAposRotulo(string $descricao, string $rotulo): bool
    {
        return (bool) preg_match('/(^|\n)'.preg_quote($rotulo, '/').'[ \t]*\n[ \t]*\n/u', $descricao);
    }

    /**
     * Parágrafo inicial = tudo antes da primeira linha em branco OU antes
     * do primeiro rótulo em maiúsculas colado sem linha em branco — o que
     * vier primeiro.
     */
    private function extrairParagrafoInicial(string $descricao): string
    {
        $descricao = trim($descricao);
        $partes = preg_split('/\n[ \t]*\n/', $descricao, 2);
        $primeiroBloco = $partes[0] ?? $descricao;

        $todosOsRotulos = array_merge(self::ROTULOS_SEMPRE_OBRIGATORIOS, ['CONDOMÍNIO', 'ACEITA PET']);
        $padraoRotulos = implode('|', array_map(fn ($r) => preg_quote($r, '/'), $todosOsRotulos));

        if (preg_match('/\n('.$padraoRotulos.')\s*(\n|$)/u', $primeiroBloco, $m, PREG_OFFSET_CAPTURE)) {
            $primeiroBloco = substr($primeiroBloco, 0, $m[0][1]);
        }

        return trim($primeiroBloco);
    }

    private function ehComercial(ImovelStaging $imovelStaging): bool
    {
        if ($imovelStaging->utilizacao === 'comercial') {
            return true;
        }

        if ($imovelStaging->utilizacao === 'residencial') {
            return false;
        }

        return $imovelStaging->tipo_imovel === 'comercial';
    }

    private function rotuloTipoImovel(?string $tipoImovel): ?string
    {
        $rotulos = [
            'apartamento' => 'Apartamento',
            'casa' => 'Casa',
            'terreno' => 'Terreno',
            'comercial' => 'Comercial',
            'cobertura' => 'Cobertura',
        ];

        return $tipoImovel === null ? null : ($rotulos[$tipoImovel] ?? ucfirst($tipoImovel));
    }

    private function textoNegociacao(?string $negociacao): ?string
    {
        return match ($negociacao) {
            'venda' => 'venda',
            'locacao' => 'locação',
            'venda_e_locacao' => 'venda e locação',
            default => null,
        };
    }

    /**
     * Formata sem decimais quando o valor é inteiro (78 → "78"), com
     * decimais (vírgula, padrão pt-BR) só quando há fração de verdade
     * (75.5 → "75,5").
     */
    private function formatarNumero(float $valor): string
    {
        if ($valor == floor($valor)) {
            return number_format($valor, 0, ',', '.');
        }

        return rtrim(number_format($valor, 2, ',', '.'), '0');
    }

    /**
     * Só dados JÁ CONFIRMADOS — nome_edificio é DELIBERADAMENTE omitido
     * (nunca deve aparecer na descrição do anúncio). Inclui TODOS os dados
     * de localização e entorno confirmados disponíveis, para a descrição
     * poder citar vias/estabelecimentos/distâncias reais sem inventar.
     *
     * $instrucaoCorretiva só é preenchido a partir da 2ª tentativa interna
     * em diante (ver gerarDescricao()) — descreve exatamente por que a
     * tentativa anterior foi rejeitada, para a IA corrigir de forma
     * direcionada em vez de gerar às cegas de novo.
     *
     * @return array<string, mixed>
     */
    private function contexto(ImovelStaging $imovelStaging, ?string $instrucaoCorretiva = null): array
    {
        return [
            'tipo_imovel' => $imovelStaging->tipo_imovel,
            'negociacao' => $imovelStaging->negociacao,
            'utilizacao' => $imovelStaging->utilizacao,
            'bairro' => $imovelStaging->bairro,
            'cidade' => $imovelStaging->cidade,
            'estado' => $imovelStaging->estado,
            'metragem' => $imovelStaging->metragem,
            'area_total' => $imovelStaging->area_total,
            'quartos' => $imovelStaging->quartos,
            'suites' => $imovelStaging->suites,
            'banheiros' => $imovelStaging->banheiros,
            'salas' => $imovelStaging->salas,
            'vagas' => $imovelStaging->vagas,
            'vagas_cobertura' => $imovelStaging->vagas_cobertura,
            'andar' => $imovelStaging->andar,
            'ano_construcao' => $imovelStaging->ano_construcao,
            'mobiliado' => $imovelStaging->mobiliado,
            'estado_conservacao' => $imovelStaging->estado_conservacao,
            'em_condominio' => $imovelStaging->em_condominio,
            'valor' => $imovelStaging->valor,
            'condominio' => $imovelStaging->condominio,
            'diferenciais' => $imovelStaging->diferenciais_uniao,
            'diferenciais_outros' => $imovelStaging->diferenciais_outros_uniao,
            'observacoes_visuais' => $imovelStaging->observacoes_visuais,
            'entorno' => $imovelStaging->localizacao,
            ...($instrucaoCorretiva !== null ? ['instrucao_corretiva' => $instrucaoCorretiva] : []),
        ];
    }

    private function systemPrompt(): string
    {
        return file_get_contents(resource_path('prompts/geracao_titulo_descricao.md'));
    }

    /**
     * @return array<string, mixed>
     */
    private function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'descricao' => ['type' => 'string'],
            ],
            'required' => ['descricao'],
        ];
    }
}
