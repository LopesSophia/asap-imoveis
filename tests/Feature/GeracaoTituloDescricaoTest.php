<?php

namespace Tests\Feature;

use App\Jobs\GerarDescricaoImovelJob;
use App\Models\ImovelStaging;
use App\Models\User;
use App\Services\GeracaoTituloDescricaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Endpoint DEDICADO (POST .../gerar-titulo-descricao) — não roda dentro de
 * analisar-fotos() de propósito. Título é DETERMINÍSTICO (sem IA) e sempre
 * síncrono/imediato. Descrição usa IA, é gerada de forma ASSÍNCRONA
 * (GerarDescricaoImovelJob — mesmo padrão de GerarEdicaoFotoJob) e validada
 * programaticamente contra o contrato completo antes de ser persistida.
 *
 * QUEUE_CONNECTION=sync em phpunit.xml faz o job rodar dentro da mesma
 * requisição de teste (exceto quando Queue::fake() é usado de propósito,
 * para testar despacho/idempotência sem esperar o job terminar) — por isso
 * a maioria dos testes abaixo consegue verificar o resultado final logo
 * após o POST, sem polling real.
 */
class GeracaoTituloDescricaoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // aguardar() é o único ponto de sleep() real do serviço — mockado
        // pra os testes de retry não dormirem de verdade (mesmo padrão já
        // usado em EdicaoFotoGeminiServiceTest).
        $this->partialMock(GeracaoTituloDescricaoService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('aguardar')->andReturnNull();
        });
    }

    private function criarRascunho(array $atributos = []): ImovelStaging
    {
        return ImovelStaging::create(array_merge([
            'corretor_id' => User::factory()->create()->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ], $atributos));
    }

    /**
     * @return array<string, mixed>
     */
    private function respostaIaDescricao(string $descricao): array
    {
        return [
            'content' => [[
                'type' => 'tool_use',
                'input' => ['descricao' => $descricao],
            ]],
        ];
    }

    /**
     * Monta uma descrição REALMENTE compatível com o contrato (parágrafo
     * inicial de 350-400 caracteres, 3000+ caracteres totais, rótulos
     * corretos sem linha em branco após cada um) — usada como fixture de
     * "resposta boa da IA" em vários testes.
     */
    private function descricaoValida(bool $comCondominio, bool $comPet): string
    {
        $paragrafoInicial = mb_substr(trim(str_repeat(
            'Ótimo apartamento com excelente localização e muita luz natural durante todo o dia. ',
            5
        )), 0, 375);

        $corpo = str_repeat('Texto de preenchimento realista sobre o imóvel e a região ao redor dele. ', 45);

        $secoes = "O IMÓVEL\n{$corpo}\n\n";
        if ($comCondominio) {
            $secoes .= "CONDOMÍNIO\n{$corpo}\n\n";
        }
        $secoes .= "DIFERENCIAIS\n{$corpo}\n\n";
        if ($comPet) {
            $secoes .= "ACEITA PET\nMediante consulta ao proprietário.\n\n";
        }
        $secoes .= "VIAS DE ACESSO\n{$corpo}\n\n";
        $secoes .= "SHOPPINGS PRÓXIMOS\n{$corpo}\n\n";
        $secoes .= "COMÉRCIOS PRÓXIMOS\n{$corpo}\n\n";
        $secoes .= "OPÇÕES DE LAZER\n{$corpo}\n\n";
        $secoes .= "COLÉGIOS E UNIVERSIDADES\n{$corpo}\n\n";

        return $paragrafoInicial."\n\n".$secoes.'Entre em contato para agendar uma visita.';
    }

    // ======================================================================
    // TÍTULO — determinístico, sem IA, sempre síncrono/imediato
    // ======================================================================

    /**
     * O exemplo exato exigido: dados reais (apartamento, 78 m², 2 quartos,
     * venda, Vila Mariana, marcado como reformado) precisam produzir
     * EXATAMENTE "Apartamento 78 m², 2 quartos, venda Vila Mariana" — sem
     * "reformado", sem nome de condomínio, sem nenhum adjetivo.
     *
     * Descrição pré-preenchida (conteúdo manual do corretor) para isolar
     * este teste na geração do TÍTULO: com a descrição já vazia, o
     * endpoint corretamente também despacharia o job de descrição (título e
     * descrição são independentes) — o que faria Http::assertNothingSent()
     * falhar por um motivo alheio ao que este teste prova.
     */
    public function test_titulo_segue_exatamente_o_formato_do_contrato(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunho([
            'metragem' => 78,
            'quartos' => 2,
            'negociacao' => 'venda',
            'bairro' => 'Vila Mariana',
            'estado_conservacao' => 'reformado',
            'nome_edificio' => 'Edifício Aurora',
            'descricao_gerada' => 'Descrição já preenchida manualmente pelo corretor.',
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        $response->assertStatus(200);
        $this->assertSame(
            'Apartamento 78 m², 2 quartos, venda Vila Mariana',
            $imovelStaging->fresh()->titulo_site
        );

        // Título nunca precisa da IA — nenhuma chamada HTTP para gerá-lo.
        Http::assertNothingSent();
    }

    public function test_titulo_usa_vagas_em_vez_de_quartos_para_imovel_comercial(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunho([
            'tipo_imovel' => 'comercial',
            'utilizacao' => 'comercial',
            'metragem' => 120,
            'quartos' => 99, // nunca deveria aparecer no título comercial
            'vagas' => 3,
            'negociacao' => 'locacao',
            'bairro' => 'Centro',
        ]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $this->assertSame(
            'Comercial 120 m², 3 vagas, locação Centro',
            $imovelStaging->fresh()->titulo_site
        );
    }

    public function test_titulo_nao_e_gerado_quando_falta_dado_obrigatorio(): void
    {
        Http::fake();

        // Sem bairro: não há como montar o título com o formato exigido.
        $imovelStaging = $this->criarRascunho([
            'metragem' => 78,
            'quartos' => 2,
            'negociacao' => 'venda',
            'bairro' => null,
        ]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        $this->assertNull($imovelStaging->fresh()->titulo_site);
    }

    public function test_titulo_ja_preenchido_pelo_corretor_nunca_e_sobrescrito(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunho([
            'titulo_site' => 'Título exato que o corretor escreveu',
            'metragem' => 78,
            'quartos' => 2,
            'negociacao' => 'venda',
            'bairro' => 'Vila Mariana',
        ]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        // Mesmo com todos os dados disponíveis pra gerar um título novo
        // diferente, o valor do corretor precisa permanecer intacto.
        $this->assertSame('Título exato que o corretor escreveu', $imovelStaging->fresh()->titulo_site);
    }

    public function test_titulo_e_devolvido_imediatamente_e_job_de_descricao_e_despachado(): void
    {
        Queue::fake();

        $imovelStaging = $this->criarRascunho([
            'metragem' => 78,
            'quartos' => 2,
            'negociacao' => 'venda',
            'bairro' => 'Vila Mariana',
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        $response->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        // Título já veio pronto na mesma resposta — nunca espera o job.
        $this->assertSame('Apartamento 78 m², 2 quartos, venda Vila Mariana', $fresh->titulo_site);
        $this->assertSame(ImovelStaging::DESCRICAO_PENDENTE, $fresh->descricao_geracao_status);
        $this->assertNull($fresh->descricao_gerada);

        Queue::assertPushed(GerarDescricaoImovelJob::class, function ($job) use ($imovelStaging) {
            return $job->imovelStagingId === $imovelStaging->id;
        });
    }

    // ======================================================================
    // DESCRIÇÃO — via IA (assíncrona), validada programaticamente antes de
    // persistir. Com QUEUE_CONNECTION=sync, o job já rodou por completo
    // quando o postJson() retorna.
    // ======================================================================

    public function test_descricao_valida_e_persistida_com_todas_as_propriedades_do_contrato(): void
    {
        $descricao = $this->descricaoValida(comCondominio: true, comPet: true);
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricao), 200)]);

        $imovelStaging = $this->criarRascunho([
            'negociacao' => 'locacao',
            'em_condominio' => true,
            'bairro' => 'Vila Mariana',
        ]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $salva = $fresh->descricao_gerada;

        $this->assertNotNull($salva);
        $this->assertSame(ImovelStaging::DESCRICAO_CONCLUIDA, $fresh->descricao_geracao_status);
        $this->assertGreaterThanOrEqual(3000, mb_strlen($salva));
        $this->assertStringContainsString("O IMÓVEL\n", $salva);
        $this->assertStringContainsString("CONDOMÍNIO\n", $salva);
        $this->assertStringContainsString("DIFERENCIAIS\n", $salva);
        $this->assertStringContainsString("ACEITA PET\nMediante consulta ao proprietário.", $salva);
        $this->assertStringContainsString("VIAS DE ACESSO\n", $salva);
        $this->assertStringContainsString("SHOPPINGS PRÓXIMOS\n", $salva);
        $this->assertStringContainsString("COMÉRCIOS PRÓXIMOS\n", $salva);
        $this->assertStringContainsString("OPÇÕES DE LAZER\n", $salva);
        $this->assertStringContainsString("COLÉGIOS E UNIVERSIDADES\n", $salva);
        $this->assertStringNotContainsString("'", $salva);
    }

    public function test_descricao_abaixo_do_minimo_de_caracteres_e_rejeitada_e_nao_persistida(): void
    {
        $descricaoCurta = "Parágrafo inicial curto demais para o contrato exigido aqui.\n\nO IMÓVEL\nTexto breve.";
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricaoCurta), 200)]);

        $imovelStaging = $this->criarRascunho();

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        // O endpoint sempre devolve 200 — a descrição é gerada de forma
        // assíncrona; falha na validação do contrato vira status "erro" no
        // staging, nunca uma resposta HTTP de erro para este endpoint.
        $response->assertStatus(200);
        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    public function test_descricao_sem_rotulo_obrigatorio_e_rejeitada_e_nao_persistida(): void
    {
        // Válida em tudo, exceto que "DIFERENCIAIS" foi removido.
        $descricao = str_replace("DIFERENCIAIS\n".str_repeat('Texto de preenchimento realista sobre o imóvel e a região ao redor dele. ', 45)."\n\n", '', $this->descricaoValida(false, false));
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricao), 200)]);

        $imovelStaging = $this->criarRascunho();

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        $response->assertStatus(200);
        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    public function test_descricao_com_apostrofo_e_rejeitada_e_nao_persistida(): void
    {
        $descricao = str_replace('Ótimo apartamento', "Ótimo apartamento d'época", $this->descricaoValida(false, false));
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricao), 200)]);

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    public function test_descricao_mencionando_nome_do_edificio_e_rejeitada_e_nao_persistida(): void
    {
        $descricao = str_replace('Ótimo apartamento', 'Ótimo apartamento no Edifício Aurora Dourada', $this->descricaoValida(false, false));
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricao), 200)]);

        $imovelStaging = $this->criarRascunho(['nome_edificio' => 'Edifício Aurora Dourada']);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    /**
     * Imóvel comercial: CONDOMÍNIO e ACEITA PET NUNCA podem aparecer, mesmo
     * que a IA os inclua — a resposta é rejeitada inteira, não "aparada".
     */
    public function test_descricao_comercial_com_condominio_ou_aceita_pet_e_rejeitada(): void
    {
        $descricao = $this->descricaoValida(comCondominio: true, comPet: true);
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricao), 200)]);

        $imovelStaging = $this->criarRascunho([
            'tipo_imovel' => 'comercial',
            'utilizacao' => 'comercial',
            'negociacao' => 'locacao',
        ]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    /**
     * Residencial em condomínio: se a IA OMITIR o rótulo CONDOMÍNIO, a
     * resposta é rejeitada — o rótulo é obrigatório nesse caso, não opcional.
     */
    public function test_descricao_residencial_em_condominio_sem_rotulo_condominio_e_rejeitada(): void
    {
        $descricao = $this->descricaoValida(comCondominio: false, comPet: false);
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricao), 200)]);

        $imovelStaging = $this->criarRascunho(['em_condominio' => true]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    /**
     * Residencial para locação: ACEITA PET precisa vir com o texto EXATO
     * "Mediante consulta ao proprietário." — qualquer variação é rejeitada.
     */
    public function test_descricao_com_texto_de_aceita_pet_alterado_e_rejeitada(): void
    {
        $descricao = str_replace(
            'Mediante consulta ao proprietário.',
            'Consulte a imobiliária sobre animais de estimação.',
            $this->descricaoValida(comCondominio: false, comPet: true)
        );
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricao), 200)]);

        $imovelStaging = $this->criarRascunho(['negociacao' => 'locacao']);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    public function test_descricao_com_linha_em_branco_apos_rotulo_e_rejeitada(): void
    {
        $corpo = str_repeat('Texto de preenchimento realista sobre o imóvel e a região ao redor dele. ', 45);
        $descricao = str_replace("O IMÓVEL\n{$corpo}", "O IMÓVEL\n\n{$corpo}", $this->descricaoValida(false, false));
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($descricao), 200)]);

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    public function test_max_tokens_da_chamada_comporta_a_descricao_minima_exigida(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($this->descricaoValida(false, false)), 200)]);

        $imovelStaging = $this->criarRascunho();
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        Http::assertSent(function ($request) {
            // 3.000+ caracteres de português não cabem em poucas centenas
            // de tokens — o limite precisa dar folga real.
            return ($request->data()['max_tokens'] ?? 0) >= 3000;
        });
    }

    // ======================================================================
    // Independência entre título e descrição / não sobrescrever / omissões
    // ======================================================================

    public function test_nao_chama_a_ia_quando_titulo_e_descricao_ja_preenchidos(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunho([
            'titulo_site' => 'Título já digitado pelo corretor',
            'descricao_gerada' => 'Descrição já digitada pelo corretor',
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        $response->assertStatus(200);
        Http::assertNothingSent();

        $imovelStaging->refresh();
        $this->assertSame('Título já digitado pelo corretor', $imovelStaging->titulo_site);
        $this->assertSame('Descrição já digitada pelo corretor', $imovelStaging->descricao_gerada);
    }

    public function test_gera_titulo_sem_precisar_de_chave_anthropic_mesmo_com_descricao_ja_preenchida(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();

        $imovelStaging = $this->criarRascunho([
            'metragem' => 78,
            'quartos' => 2,
            'negociacao' => 'venda',
            'bairro' => 'Vila Mariana',
            'descricao_gerada' => 'Descrição já preenchida pelo corretor',
        ]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $this->assertSame('Apartamento 78 m², 2 quartos, venda Vila Mariana', $imovelStaging->fresh()->titulo_site);
        Http::assertNothingSent();
    }

    public function test_titulo_gerado_mesmo_quando_descricao_falha(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'falha']], 500)]);

        $imovelStaging = $this->criarRascunho([
            'metragem' => 78,
            'quartos' => 2,
            'negociacao' => 'venda',
            'bairro' => 'Vila Mariana',
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        // Título teve sucesso (determinístico) mesmo com a descrição falhando.
        $response->assertStatus(200);
        $fresh = $imovelStaging->fresh();
        $this->assertSame('Apartamento 78 m², 2 quartos, venda Vila Mariana', $fresh->titulo_site);
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    public function test_nome_edificio_nunca_e_enviado_para_a_ia(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIaDescricao($this->descricaoValida(false, false)), 200)]);

        $imovelStaging = $this->criarRascunho(['nome_edificio' => 'Edifício Segredo Absoluto']);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        Http::assertSent(function ($request) {
            return ! str_contains(json_encode($request->data()), 'Edifício Segredo Absoluto');
        });
    }

    public function test_falha_total_da_ia_nao_impede_resposta_e_nao_grava_descricao(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => ['message' => 'falha']], 500)]);

        // Sem dados suficientes pro título determinístico também falhar —
        // aqui os dois lados falham, mas o endpoint continua devolvendo 200
        // (título ausente não é erro HTTP; descrição falhando é assíncrono).
        $imovelStaging = $this->criarRascunho();

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        $response->assertStatus(200);
        $fresh = $imovelStaging->fresh();
        $this->assertNull($fresh->titulo_site);
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    public function test_ausencia_de_chave_anthropic_bloqueia_so_a_descricao(): void
    {
        config(['services.anthropic.key' => null]);
        Http::fake();

        // Título tem TODOS os dados necessários — precisa ter sucesso
        // mesmo sem chave Anthropic nenhuma, porque não depende da IA.
        $imovelStaging = $this->criarRascunho([
            'metragem' => 78,
            'quartos' => 2,
            'negociacao' => 'venda',
            'bairro' => 'Vila Mariana',
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao");

        // Resposta OK: o título teve sucesso, mesmo a descrição falhando.
        $response->assertStatus(200);
        Http::assertNothingSent();
        $fresh = $imovelStaging->fresh();
        $this->assertSame('Apartamento 78 m², 2 quartos, venda Vila Mariana', $fresh->titulo_site);
        $this->assertNull($fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
    }

    // ======================================================================
    // Geração ASSÍNCRONA da descrição — retry interno, idempotência, status,
    // preservação de edição humana e endpoint de polling.
    // ======================================================================

    public function test_endpoint_de_status_devolve_o_progresso_atual_da_descricao(): void
    {
        $imovelStaging = $this->criarRascunho([
            'titulo_site' => 'Título já pronto',
            'descricao_geracao_status' => ImovelStaging::DESCRICAO_PROCESSANDO,
        ]);

        $this->getJson("/api/imoveis-staging/{$imovelStaging->id}/status-descricao")
            ->assertStatus(200)
            ->assertJson([
                'titulo_site' => 'Título já pronto',
                'descricao_gerada' => null,
                'descricao_geracao_status' => ImovelStaging::DESCRICAO_PROCESSANDO,
                'descricao_geracao_erro' => null,
            ]);
    }

    public function test_timeout_de_conexao_e_seguido_de_sucesso_na_tentativa_interna_seguinte(): void
    {
        $descricaoBoa = $this->descricaoValida(false, false);
        $tentativas = 0;

        Http::fake(function () use (&$tentativas, $descricaoBoa) {
            $tentativas++;
            if ($tentativas === 1) {
                throw new ConnectionException('Connection timed out');
            }

            return Http::response($this->respostaIaDescricao($descricaoBoa), 200);
        });

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertSame(ImovelStaging::DESCRICAO_CONCLUIDA, $fresh->descricao_geracao_status);
        $this->assertNotNull($fresh->descricao_gerada);
        $this->assertSame(2, $tentativas);
    }

    public function test_tres_falhas_de_conexao_consecutivas_esgotam_as_tentativas_e_marcam_erro_sanitizado(): void
    {
        $tentativas = 0;

        Http::fake(function () use (&$tentativas) {
            $tentativas++;
            throw new ConnectionException('Connection timed out');
        });

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
        $this->assertNull($fresh->descricao_gerada);
        $this->assertNotNull($fresh->descricao_geracao_erro);
        $this->assertStringNotContainsString('Connection timed out', $fresh->descricao_geracao_erro);
        $this->assertSame(3, $tentativas);
    }

    public function test_resposta_sem_tool_use_e_seguida_de_sucesso_na_tentativa_interna_seguinte(): void
    {
        $descricaoBoa = $this->descricaoValida(false, false);
        $tentativas = 0;

        Http::fake(function () use (&$tentativas, $descricaoBoa) {
            $tentativas++;
            if ($tentativas === 1) {
                return Http::response(['content' => [['type' => 'text', 'text' => 'não posso ajudar com isso']]], 200);
            }

            return Http::response($this->respostaIaDescricao($descricaoBoa), 200);
        });

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertSame(ImovelStaging::DESCRICAO_CONCLUIDA, $fresh->descricao_geracao_status);
        $this->assertNotNull($fresh->descricao_gerada);
        $this->assertSame(2, $tentativas);
    }

    public function test_403_da_anthropic_e_erro_definitivo_e_nunca_e_repetido(): void
    {
        $tentativas = 0;

        Http::fake(function () use (&$tentativas) {
            $tentativas++;

            return Http::response(['error' => ['message' => 'forbidden']], 403);
        });

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $fresh = $imovelStaging->fresh();
        $this->assertSame(ImovelStaging::DESCRICAO_ERRO, $fresh->descricao_geracao_status);
        $this->assertSame(1, $tentativas);
    }

    public function test_mensagem_de_erro_da_descricao_e_sanitizada_nunca_expoe_detalhe_tecnico(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'error' => ['message' => 'Detalhe interno sensível da Anthropic que não deve vazar para o corretor'],
        ], 500)]);

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")
            ->assertStatus(200);

        $erro = $imovelStaging->fresh()->descricao_geracao_erro;
        $this->assertNotNull($erro);
        $this->assertStringNotContainsString('Detalhe interno sensível', $erro);
    }

    public function test_chamadas_duplicadas_nao_despacham_um_segundo_job_enquanto_a_primeira_esta_em_andamento(): void
    {
        Queue::fake();

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")->assertStatus(200);
        // Clique duplo: com Queue::fake(), o primeiro job nunca chega a
        // rodar de verdade, então o status continua "pendente" — é
        // exatamente essa a situação real que a idempotência precisa cobrir.
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/gerar-titulo-descricao")->assertStatus(200);

        Queue::assertPushed(GerarDescricaoImovelJob::class, 1);
    }

    public function test_job_nunca_sobrescreve_descricao_ja_preenchida_manualmente_ao_iniciar(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunho([
            'descricao_geracao_status' => ImovelStaging::DESCRICAO_PENDENTE,
            'descricao_gerada' => 'Texto que o corretor já digitou.',
        ]);

        (new GerarDescricaoImovelJob($imovelStaging->id))->handle(app(GeracaoTituloDescricaoService::class));

        Http::assertNothingSent();

        $fresh = $imovelStaging->fresh();
        $this->assertSame('Texto que o corretor já digitou.', $fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_CONCLUIDA, $fresh->descricao_geracao_status);
    }

    /**
     * As tentativas internas do serviço podem levar dezenas de segundos —
     * o corretor pode salvar manualmente ENQUANTO a chamada à IA ainda está
     * em andamento. O fake do HTTP simula exatamente esse instante: grava o
     * texto humano DENTRO do próprio callback, no exato momento em que a
     * chamada à IA aconteceria de verdade.
     */
    public function test_edicao_humana_feita_durante_a_chamada_a_ia_prevalece_sobre_o_resultado_gerado(): void
    {
        $descricaoBoa = $this->descricaoValida(false, false);

        $imovelStaging = $this->criarRascunho([
            'descricao_geracao_status' => ImovelStaging::DESCRICAO_PENDENTE,
        ]);

        Http::fake(function () use ($imovelStaging, $descricaoBoa) {
            $imovelStaging->fresh()->update(['descricao_gerada' => 'Texto humano salvo durante a chamada.']);

            return Http::response($this->respostaIaDescricao($descricaoBoa), 200);
        });

        (new GerarDescricaoImovelJob($imovelStaging->id))->handle(app(GeracaoTituloDescricaoService::class));

        $fresh = $imovelStaging->fresh();
        $this->assertSame('Texto humano salvo durante a chamada.', $fresh->descricao_gerada);
        $this->assertSame(ImovelStaging::DESCRICAO_CONCLUIDA, $fresh->descricao_geracao_status);
    }

    public function test_tela_de_revisao_tem_status_e_botao_de_tentar_novamente_para_a_descricao(): void
    {
        $html = $this->get('/asap')->assertStatus(200)->getContent();

        $this->assertStringContainsString('id="status-descricao"', $html);
        $this->assertStringContainsString('tentarNovamenteDescricao', $html);
        $this->assertStringContainsString('Tentar novamente', $html);
        $this->assertStringContainsString('/status-descricao', $html);
    }
}
