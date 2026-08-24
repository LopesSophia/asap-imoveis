<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
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
                'alertas_fotos' => ['2 fotos parecem ser banners publicitários, não fotos do imóvel'],
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
            ['2 fotos parecem ser banners publicitários, não fotos do imóvel'],
            $imovelStaging->alertas_fotos
        );
        $this->assertNotNull($imovelStaging->fotos_analisadas_em);

        // Análise NUNCA finaliza o cadastro — status continua rascunho.
        $this->assertSame('rascunho', $imovelStaging->status_propagacao);
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
                    'alertas_fotos' => ['3 fotos são banners publicitários'],
                ]),
                $this->lote([]),
            )),
        ]);

        $imovelStaging = $this->criarRascunhoComFotos(25);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);
        Http::assertSentCount(9);
        $this->assertSame(['sala com porcelanato'], $imovelStaging->fresh()->observacoes_visuais);
        $this->assertSame(['3 fotos são banners publicitários'], $imovelStaging->fresh()->alertas_fotos);

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
                'alertas_fotos' => ['1 foto parece print de WhatsApp'],
            ]), 200),
        ]);

        $imovelStaging = $this->criarRascunhoComFotos(25);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        // Contrato consumido por aplicarResultadoAnalise() no frontend:
        // fotos (pra popular a grade), diferenciais_uniao (chips), alertas_fotos.
        $response->assertStatus(200)->assertJsonStructure([
            'fotos' => ['*' => ['id', 'caminho', 'ordem', 'url']],
            'diferenciais_uniao',
            'alertas_fotos',
        ]);
        $this->assertCount(25, $response->json('fotos'));
        $this->assertContains('piscina', $response->json('diferenciais_uniao'));
        $this->assertContains('1 foto parece print de WhatsApp', $response->json('alertas_fotos'));
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
