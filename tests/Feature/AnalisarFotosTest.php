<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
use App\Models\ImovelStagingFoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AnalisarFotosTest extends TestCase
{
    use RefreshDatabase;

    private function criarRascunhoComFotos(int $quantidadeFotos, array $atributos = []): ImovelStaging
    {
        Storage::fake('public');

        $imovelStaging = ImovelStaging::create(array_merge([
            'corretor_id' => User::factory()->create()->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ], $atributos));

        for ($i = 0; $i < $quantidadeFotos; $i++) {
            $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
                'fotos' => [UploadedFile::fake()->image("foto{$i}.jpg")],
            ]);
        }

        return $imovelStaging->fresh();
    }

    private function respostaIa(array $overrides = []): array
    {
        return [
            'content' => [[
                'type' => 'tool_use',
                'input' => array_merge([
                    'diferenciais' => [],
                    'diferenciais_outros' => [],
                    'observacoes_visuais' => [],
                    'alertas_fotos' => [],
                ], $overrides),
            ]],
        ];
    }

    /**
     * Uma análise de 25 fotos faz 9 chamadas HTTP (FOTOS_POR_LOTE = 3). Para
     * testar duas análises sucessivas (ex.: normal + forçada) dentro do
     * MESMO teste, não dá pra chamar Http::fake() de novo com o mesmo
     * padrão de URL: Http::fake() acumula stubs em vez de substituí-los
     * (Factory::fake() → stubUrl() → merge() em PendingRequest::$stubCallbacks),
     * e buildStubHandler() resolve por ->filter()->first(), ou seja, o
     * PRIMEIRO stub registrado que casar com a URL sempre vence — o
     * Http::fake() mais recente nunca chega a ser consultado. Por isso
     * concatenamos aqui, numa ÚNICA chamada Http::fake(), as N respostas de
     * cada análise em sequência.
     */
    private function lote(array $overrides, int $vezes = 9): array
    {
        return array_fill(0, $vezes, Http::response($this->respostaIa($overrides), 200));
    }

    public function test_analisar_com_menos_de_25_fotos_retorna_422_e_nao_chama_a_ia(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(24);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'Faltam 1 fotos para completar o mínimo de 25 (você tem 24).',
            'total_fotos' => 24,
        ]);

        Http::assertNothingSent();
    }

    public function test_analise_grava_resultado_fotografico_separado_e_nao_finaliza(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respostaIa([
                'diferenciais' => ['garagem', 'quintal'],
                'diferenciais_outros' => ['ar condicionado na sala'],
                'observacoes_visuais' => ['sala ampla e bem iluminada'],
                'alertas_fotos' => [
                    ['identificador_foto' => null, 'mensagem' => '2 fotos parecem ser banners publicitários, não fotos do imóvel'],
                ],
            ]), 200),
        ]);

        $imovelStaging = $this->criarRascunhoComFotos(25, ['diferenciais' => ['portaria']]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $response->assertStatus(200);

        $imovelStaging->refresh();

        // Resultado fotográfico vai para as colunas _fotos, não para as
        // colunas de origem fala/digitação.
        $this->assertEqualsCanonicalizing(['garagem', 'quintal'], $imovelStaging->diferenciais_fotos);
        $this->assertEqualsCanonicalizing(['ar condicionado na sala'], $imovelStaging->diferenciais_outros_fotos);
        $this->assertEqualsCanonicalizing(['sala ampla e bem iluminada'], $imovelStaging->observacoes_visuais);
        $this->assertEqualsCanonicalizing(
            [['foto_id' => null, 'mensagem' => '2 fotos parecem ser banners publicitários, não fotos do imóvel']],
            $imovelStaging->alertas_fotos
        );
        $this->assertNotNull($imovelStaging->fotos_analisadas_em);

        // Análise NUNCA finaliza o cadastro — status continua rascunho.
        $this->assertSame('rascunho', $imovelStaging->status_propagacao);
    }

    // ---- Alertas estruturados e vinculados à foto (Problema 1) ----

    /**
     * Vínculo: um alerta com identificador_foto válido precisa preservar
     * esse foto_id tanto na coluna crua quanto na representação normalizada
     * — e esse foto_id precisa corresponder a uma foto de verdade dentro
     * de "fotos" na mesma resposta (é isso que permite ao frontend montar
     * "Foto N" e rolar até a miniatura certa).
     */
    public function test_alerta_vinculado_a_foto_especifica_preserva_o_foto_id(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();
        $fotoAlvo = $fotoIds[0];

        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'alertas_fotos' => [
                ['identificador_foto' => (string) $fotoAlvo, 'mensagem' => 'parece um banner publicitário'],
            ],
        ]), 200)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");
        $response->assertStatus(200);

        $this->assertSame(
            [['foto_id' => $fotoAlvo, 'mensagem' => 'parece um banner publicitário']],
            $imovelStaging->fresh()->alertas_fotos
        );

        $normalizado = $response->json('alertas_fotos_normalizados');
        $this->assertSame($fotoAlvo, $normalizado[0]['foto_id']);
        $this->assertSame('parece um banner publicitário', $normalizado[0]['mensagem']);

        // O vínculo é real: o foto_id do alerta corresponde a uma foto de
        // verdade presente em "fotos" na mesma resposta — é isso que
        // permite ao frontend numerar ("Foto N") e rolar até a miniatura.
        $idsDasFotosNaResposta = array_column($response->json('fotos'), 'id');
        $this->assertContains($normalizado[0]['foto_id'], $idsDasFotosNaResposta);
    }

    /**
     * Ausência de ID interno na apresentação: a mensagem do alerta nunca
     * deve conter o identificador da foto embutido como texto — o
     * identificador só existe no campo separado "foto_id".
     */
    public function test_alerta_nunca_expoe_o_id_interno_dentro_do_texto_da_mensagem(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();
        $fotoAlvo = $fotoIds[0];

        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'alertas_fotos' => [
                ['identificador_foto' => (string) $fotoAlvo, 'mensagem' => 'parece um banner publicitário'],
            ],
        ]), 200)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $mensagem = $response->json('alertas_fotos_normalizados')[0]['mensagem'];
        $this->assertStringNotContainsString((string) $fotoAlvo, $mensagem);
        $this->assertStringNotContainsString('id=', $mensagem);
        $this->assertStringNotContainsString('Foto id', $mensagem);
    }

    /**
     * Alertas gerais (sem foto específica) continuam permitidos — nunca
     * tratados como erro nem descartados.
     */
    public function test_alerta_geral_sem_foto_especifica_e_aceito(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);

        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'alertas_fotos' => [
                ['identificador_foto' => null, 'mensagem' => 'várias fotos estão com qualidade baixa'],
            ],
        ]), 200)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $this->assertSame(
            [['foto_id' => null, 'mensagem' => 'várias fotos estão com qualidade baixa']],
            $response->json('alertas_fotos_normalizados')
        );
    }

    /**
     * Descarte de identificador alucinado: a IA referenciando um id que não
     * existe entre as fotos realmente enviadas no lote precisa ter o
     * IDENTIFICADOR descartado (nunca repassado ao frontend como se fosse
     * válido) — mas a mensagem em si, que pode conter informação útil,
     * é preservada como alerta geral.
     */
    public function test_identificador_alucinado_em_alerta_e_descartado_mas_mensagem_e_preservada(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);

        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'alertas_fotos' => [
                ['identificador_foto' => '999999999', 'mensagem' => 'foto suspeita de ser print de tela'],
            ],
        ]), 200)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $normalizado = $response->json('alertas_fotos_normalizados');
        $this->assertCount(1, $normalizado);
        $this->assertNull($normalizado[0]['foto_id']);
        $this->assertSame('foto suspeita de ser print de tela', $normalizado[0]['mensagem']);
    }

    public function test_alerta_sem_mensagem_valida_e_descartado_por_completo(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);

        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'alertas_fotos' => [
                ['identificador_foto' => null, 'mensagem' => ''],
                ['identificador_foto' => null],
            ],
        ]), 200)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $this->assertSame([], $response->json('alertas_fotos_normalizados'));
    }

    /**
     * Regressão: o controller aplicava array_unique() sobre alertas_fotos,
     * que agora é um array de arrays associativos {foto_id, mensagem} —
     * array_unique() converte cada elemento pra string ("Array") pra
     * comparar, então TODOS os elementos colidiam e só o primeiro
     * sobrevivia. Com dois alertas vinculados a fotos DIFERENTES, os dois
     * precisam sobreviver.
     */
    public function test_varios_alertas_vinculados_a_fotos_diferentes_sao_todos_preservados(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();
        [$fotoA, $fotoB] = [$fotoIds[0], $fotoIds[1]];

        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'alertas_fotos' => [
                ['identificador_foto' => (string) $fotoA, 'mensagem' => 'parece um banner publicitário'],
                ['identificador_foto' => (string) $fotoB, 'mensagem' => 'foto suspeita de ser print de tela'],
            ],
        ]), 200)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $normalizado = $response->json('alertas_fotos_normalizados');
        $this->assertCount(2, $normalizado);

        $porFotoId = collect($normalizado)->keyBy('foto_id');
        $this->assertSame('parece um banner publicitário', $porFotoId[$fotoA]['mensagem']);
        $this->assertSame('foto suspeita de ser print de tela', $porFotoId[$fotoB]['mensagem']);
    }

    /**
     * Numeração visual ("Foto 1", "Foto 2"...) é montada no frontend a
     * partir da POSIÇÃO da foto no array — nunca do id interno. Isso só
     * funciona de forma contínua e correta se o backend sempre devolver as
     * fotos ordenadas por "ordem": aqui garantimos essa ordenação na
     * resposta de analisar-fotos.
     */
    public function test_fotos_sao_devolvidas_em_ordem_permitindo_numeracao_visual_continua(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa(), 200)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $ordens = array_column($response->json('fotos'), 'ordem');

        $this->assertSame($ordens, collect($ordens)->sort()->values()->all());
        // Contígua: "Foto 1" = 1ª posição do array, "Foto 2" = 2ª, etc. —
        // sem buracos nem repetição, senão a numeração visual quebraria.
        $this->assertSame(range(1, 25), $ordens);
    }

    /**
     * Compatibilidade com análises antigas em formato string: se o texto
     * livre contiver literalmente "Foto id=NNN" (rótulo interno que a IA às
     * vezes ecoava) e esse id ainda pertencer a uma foto de verdade deste
     * imóvel, o alerta é promovido a vinculado — com o rótulo bruto removido
     * do texto apresentado.
     */
    public function test_alerta_legado_em_string_com_foto_id_existente_e_convertido_para_alerta_vinculado(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $fotoId = $imovelStaging->fotos()->first()->id;

        $imovelStaging->update([
            'alertas_fotos' => ["Foto id={$fotoId}: parece um banner publicitário"],
        ]);

        $normalizado = $imovelStaging->fresh()->alertas_fotos_normalizados;

        $this->assertSame(
            [['foto_id' => $fotoId, 'mensagem' => 'parece um banner publicitário']],
            $normalizado
        );
        $this->assertStringNotContainsString('id=', $normalizado[0]['mensagem']);
        $this->assertStringNotContainsString((string) $fotoId, $normalizado[0]['mensagem']);
    }

    /**
     * Mesmo padrão "Foto id=NNN", mas o id não corresponde a nenhuma foto
     * real deste imóvel (ex.: foto removida depois da análise antiga) — vira
     * alerta geral, sem número interno nenhum, nunca um id "meio-vinculado".
     */
    public function test_alerta_legado_em_string_com_foto_id_inexistente_vira_alerta_geral_sem_numero(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $idInexistente = $imovelStaging->fotos()->first()->id + 999999;

        $imovelStaging->update([
            'alertas_fotos' => ["Foto id={$idInexistente}: parece um banner publicitário"],
        ]);

        $normalizado = $imovelStaging->fresh()->alertas_fotos_normalizados;

        $this->assertSame(
            [['foto_id' => null, 'mensagem' => 'parece um banner publicitário']],
            $normalizado
        );
        $this->assertStringNotContainsString('id=', $normalizado[0]['mensagem']);
    }

    /**
     * String legada SEM o padrão "Foto id=" nenhum continua virando alerta
     * geral, como já acontecia antes desta mudança — nunca quebra.
     */
    public function test_alerta_legado_em_string_sem_padrao_de_id_continua_como_alerta_geral(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);

        $imovelStaging->update([
            'alertas_fotos' => ['3 fotos parecem banners publicitários'],
        ]);

        $this->assertSame(
            [['foto_id' => null, 'mensagem' => '3 fotos parecem banners publicitários']],
            $imovelStaging->fresh()->alertas_fotos_normalizados
        );
    }

    // ---- Sugestão de itens removíveis (por foto, usada pela edição com Gemini) ----

    public function test_itens_removiveis_sugeridos_sao_gravados_por_foto(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();
        $fotoAlvo = $fotoIds[0];
        $outraFoto = $fotoIds[count($fotoIds) - 1];

        // Resposta uniforme aplicada a todos os lotes: só o lote que
        // realmente contém $fotoAlvo aceita a sugestão (os demais descartam
        // por "identificador fora do lote", silenciosamente).
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'itens_removiveis' => [
                ['identificador_foto' => (string) $fotoAlvo, 'itens' => [
                    ['categoria' => 'pessoa', 'descricao' => 'pessoa perto da porta', 'confianca' => 0.9],
                    ['categoria' => 'animal', 'descricao' => 'cachorro no quintal', 'confianca' => 0.7],
                ]],
            ],
        ]), 200)]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        $this->assertSame([
            ['categoria' => 'pessoa', 'descricao' => 'pessoa perto da porta', 'confianca' => 0.9],
            ['categoria' => 'animal', 'descricao' => 'cachorro no quintal', 'confianca' => 0.7],
        ], ImovelStagingFoto::find($fotoAlvo)->itens_removiveis_sugeridos);
        $this->assertSame([], ImovelStagingFoto::find($outraFoto)->itens_removiveis_sugeridos);
    }

    public function test_itens_removiveis_com_identificador_fora_do_lote_e_descartado(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();

        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'itens_removiveis' => [
                ['identificador_foto' => '999999999', 'itens' => [
                    ['categoria' => 'pessoa', 'descricao' => 'pessoa qualquer', 'confianca' => 0.9],
                ]],
            ],
        ]), 200)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $response->assertStatus(200);
        foreach ($fotoIds as $fotoId) {
            $this->assertSame([], ImovelStagingFoto::find($fotoId)->itens_removiveis_sugeridos);
        }
    }

    public function test_itens_removiveis_com_categoria_fora_da_lista_permitida_e_descartado(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();
        $fotoAlvo = $fotoIds[0];

        // "mofo" e "movel" nunca são categorias válidas — mesmo vindo com
        // identificador_foto correto e confianca válida, a sugestão inteira
        // é descartada porque a categoria não está na lista fechada.
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'itens_removiveis' => [
                ['identificador_foto' => (string) $fotoAlvo, 'itens' => [
                    ['categoria' => 'mofo', 'descricao' => 'mancha de mofo na parede', 'confianca' => 0.95],
                    ['categoria' => 'movel', 'descricao' => 'sofá da sala', 'confianca' => 0.9],
                    ['categoria' => 'pessoa', 'descricao' => 'pessoa válida', 'confianca' => 0.8],
                ]],
            ],
        ]), 200)]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        // Só a sugestão de categoria permitida sobrevive.
        $this->assertSame(
            [['categoria' => 'pessoa', 'descricao' => 'pessoa válida', 'confianca' => 0.8]],
            ImovelStagingFoto::find($fotoAlvo)->itens_removiveis_sugeridos
        );
    }

    public function test_itens_removiveis_com_confianca_invalida_e_descartado(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();
        $fotoAlvo = $fotoIds[0];

        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa([
            'itens_removiveis' => [
                ['identificador_foto' => (string) $fotoAlvo, 'itens' => [
                    ['categoria' => 'pessoa', 'descricao' => 'confianca fora do intervalo', 'confianca' => 1.5],
                    ['categoria' => 'pessoa', 'descricao' => 'confianca nao numerica', 'confianca' => 'alta'],
                ]],
            ],
        ]), 200)]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        $this->assertSame([], ImovelStagingFoto::find($fotoAlvo)->itens_removiveis_sugeridos);
    }

    // ---- Substituição, não acumulação (regra central desta correção) ----

    public function test_diferencial_detectado_numa_analise_desaparece_se_nao_vier_na_seguinte(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence(array_merge(
                $this->lote(['diferenciais' => ['garagem']]),
                $this->lote(['diferenciais' => ['piscina']]),
            )),
        ]);

        $imovelStaging = $this->criarRascunhoComFotos(25);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);
        Http::assertSentCount(9);
        $this->assertSame(['garagem'], $imovelStaging->fresh()->diferenciais_fotos);

        // Reanálise forçada (ex.: corretor trocou as fotos) não detecta mais garagem.
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos?forcar=1")->assertStatus(200);
        Http::assertSentCount(18);

        $diferenciaisFotos = $imovelStaging->fresh()->diferenciais_fotos;
        $this->assertSame(['piscina'], $diferenciaisFotos);
        $this->assertNotContains('garagem', $diferenciaisFotos);
    }

    public function test_alerta_e_observacao_antigos_nao_permanecem_apos_reanalise(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence(array_merge(
                $this->lote([
                    'observacoes_visuais' => ['sala com porcelanato'],
                    'alertas_fotos' => [
                        ['identificador_foto' => null, 'mensagem' => '3 fotos são banners publicitários'],
                    ],
                ]),
                $this->lote([]),
            )),
        ]);

        $imovelStaging = $this->criarRascunhoComFotos(25);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);
        Http::assertSentCount(9);
        $this->assertSame(['sala com porcelanato'], $imovelStaging->fresh()->observacoes_visuais);
        $this->assertSame(
            [['foto_id' => null, 'mensagem' => '3 fotos são banners publicitários']],
            $imovelStaging->fresh()->alertas_fotos
        );

        // Reanálise forçada não reporta mais nada digno de observação/alerta.
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos?forcar=1")->assertStatus(200);
        Http::assertSentCount(18);

        $imovelStaging->refresh();
        $this->assertSame([], $imovelStaging->observacoes_visuais);
        $this->assertSame([], $imovelStaging->alertas_fotos);
    }

    public function test_diferencial_informado_pela_fala_e_preservado_apos_analise(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa(['diferenciais' => ['garagem']]), 200)]);

        $imovelStaging = $this->criarRascunhoComFotos(25, ['diferenciais' => ['portaria']]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        $imovelStaging->refresh();
        // "diferenciais" (fala/digitação) nunca é tocado pela análise de fotos.
        $this->assertSame(['portaria'], $imovelStaging->diferenciais);
        $this->assertSame(['garagem'], $imovelStaging->diferenciais_fotos);
    }

    public function test_uniao_apresentada_na_revisao_nao_contem_duplicidades(): void
    {
        // "portaria" foi dito pelo corretor E também detectado na foto — não
        // pode aparecer duas vezes na união.
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa(['diferenciais' => ['portaria', 'garagem']]), 200)]);

        $imovelStaging = $this->criarRascunhoComFotos(25, ['diferenciais' => ['portaria']]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");
        $response->assertStatus(200);

        $uniao = $response->json('diferenciais_uniao');
        $this->assertEqualsCanonicalizing(['portaria', 'garagem'], $uniao);
        $this->assertSame(count($uniao), count(array_unique($uniao)), 'A união não pode ter duplicatas.');

        // Também via o model diretamente (accessor), não só na resposta HTTP.
        $this->assertEqualsCanonicalizing(['portaria', 'garagem'], $imovelStaging->fresh()->diferenciais_uniao);
    }

    public function test_resposta_da_analise_traz_os_dados_que_a_revisao_final_precisa_exibir(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->respostaIa([
                'diferenciais' => ['piscina'],
                'alertas_fotos' => [
                    ['identificador_foto' => null, 'mensagem' => '1 foto parece print de WhatsApp'],
                ],
            ]), 200),
        ]);

        $imovelStaging = $this->criarRascunhoComFotos(25);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        // Contrato consumido por aplicarResultadoAnalise() no frontend:
        // fotos (pra popular a grade), diferenciais_uniao (chips),
        // alertas_fotos_normalizados (nunca o id interno — ver Problema 1).
        $response->assertStatus(200)->assertJsonStructure([
            'fotos' => ['*' => ['id', 'caminho', 'ordem', 'url']],
            'diferenciais_uniao',
            'alertas_fotos_normalizados' => ['*' => ['foto_id', 'mensagem']],
        ]);
        $this->assertCount(25, $response->json('fotos'));
        $this->assertContains('piscina', $response->json('diferenciais_uniao'));
        $this->assertContains(
            ['foto_id' => null, 'mensagem' => '1 foto parece print de WhatsApp'],
            $response->json('alertas_fotos_normalizados')
        );
    }

    public function test_analisar_com_27_fotos_faz_nove_lotes_de_chamada(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa(), 200)]);

        $imovelStaging = $this->criarRascunhoComFotos(27);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        // 27 fotos em lotes de 3 (FOTOS_POR_LOTE) => 9 chamadas.
        Http::assertSentCount(9);
    }

    public function test_segunda_chamada_sem_mudanca_nas_fotos_nao_chama_a_ia_de_novo(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->respostaIa(['diferenciais' => ['garagem']]), 200)]);

        $imovelStaging = $this->criarRascunhoComFotos(25);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);
        Http::assertSentCount(9); // 25 fotos / 3 por lote = 9 chamadas na primeira análise.

        $primeiraAnaliseEm = $imovelStaging->fresh()->fotos_analisadas_em;

        // Segunda chamada: nenhuma foto mudou desde a primeira análise.
        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");
        $response->assertStatus(200);

        // Continua com exatamente 9 chamadas registradas — nenhuma nova foi feita.
        Http::assertSentCount(9);
        $this->assertEquals($primeiraAnaliseEm, $imovelStaging->fresh()->fotos_analisadas_em);
        $this->assertSame(['garagem'], $imovelStaging->fresh()->diferenciais_fotos);
    }

    public function test_forcar_1_refaz_e_substitui_a_analise_mesmo_com_fotos_analisadas_em_preenchido(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence(array_merge(
                $this->lote(['diferenciais' => ['garagem']]),
                $this->lote(['diferenciais' => ['piscina']]),
            )),
        ]);

        $imovelStaging = $this->criarRascunhoComFotos(25);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);
        Http::assertSentCount(9);
        $this->assertSame(['garagem'], $imovelStaging->fresh()->diferenciais_fotos);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos?forcar=1")->assertStatus(200);

        // Substituiu, não acumulou — e a contagem prova que a Anthropic foi
        // chamada de novo (9 novas requisições, 18 no total), não que o
        // resultado anterior foi apenas reaproveitado.
        Http::assertSentCount(18);
        $this->assertSame(['piscina'], $imovelStaging->fresh()->diferenciais_fotos);
    }
}
