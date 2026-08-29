<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Motor de validação de qualidade editorial/comercial (Fase 1) — nunca
 * chama IA (sem custo de API): é puramente cálculo sobre dados já
 * confirmados. `Http::fake()` global no setUp() só para provar isso.
 */
class ValidadorQualidadeAnuncioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    private function criarRascunho(array $atributos = []): ImovelStaging
    {
        return ImovelStaging::create(array_merge([
            'corretor_id' => User::factory()->create()->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ], $atributos));
    }

    private function adicionarFotos(ImovelStaging $imovel, int $quantidade): void
    {
        for ($i = 0; $i < $quantidade; $i++) {
            $imovel->fotos()->create(['caminho' => "imoveis/{$imovel->id}/{$i}.jpg", 'ordem' => $i]);
        }
    }

    /**
     * @return array{camposObrigatorios: array<string, mixed>}
     */
    private function camposObrigatoriosCompletos(): array
    {
        return [
            'negociacao' => 'venda',
            'utilizacao' => 'residencial',
            'valor' => 500000,
            'bairro' => 'Centro',
            'titulo_site' => 'Apartamento 78 m², 2 quartos, venda Centro',
            'descricao_gerada' => str_repeat('Texto de descrição de teste com conteúdo suficiente. ', 60), // > 3000 chars
        ];
    }

    public function test_cadastro_bem_incompleto_gera_multiplos_bloqueios_e_pontuacao_baixa(): void
    {
        $imovel = $this->criarRascunho();

        $response = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar");

        $response->assertStatus(200);
        $dados = $response->json();

        $this->assertFalse($dados['aprovado']);
        $this->assertGreaterThanOrEqual(5, count($dados['bloqueios'])); // negociacao, valor, bairro, titulo_site, descricao_gerada, fotos
        $this->assertLessThan(50, $dados['pontuacao']);
        Http::assertNothingSent();

        $imovel->refresh();
        $this->assertSame($dados['pontuacao'], $imovel->pontuacao_qualidade);
        $this->assertNotNull($imovel->data_ultima_validacao);
    }

    public function test_preenchimento_progressivo_reduz_bloqueios_e_aumenta_pontuacao(): void
    {
        $imovel = $this->criarRascunho();
        $r1 = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $imovel->update($this->camposObrigatoriosCompletos());
        $this->adicionarFotos($imovel, 25);

        $r2 = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $this->assertLessThan(count($r1['bloqueios']), count($r2['bloqueios']));
        $this->assertGreaterThan($r1['pontuacao'], $r2['pontuacao']);
        $this->assertTrue($r2['aprovado']);
        $this->assertSame([], $r2['bloqueios']);
    }

    public function test_condominio_isento_sem_valor_nao_gera_bloqueio(): void
    {
        $imovel = $this->criarRascunho(array_merge($this->camposObrigatoriosCompletos(), [
            'em_condominio' => true,
            'condominio_situacao' => 'isento',
            'condominio' => null,
        ]));
        $this->adicionarFotos($imovel, 25);

        $dados = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $this->assertFalse(collect($dados['bloqueios'])->contains(fn ($b) => in_array($b['campo'], ['condominio', 'condominio_situacao'])));
    }

    public function test_condominio_valor_informado_sem_valor_gera_bloqueio(): void
    {
        $imovel = $this->criarRascunho(array_merge($this->camposObrigatoriosCompletos(), [
            'em_condominio' => true,
            'condominio_situacao' => 'valor_informado',
            'condominio' => null,
        ]));
        $this->adicionarFotos($imovel, 25);

        $dados = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $this->assertTrue(collect($dados['bloqueios'])->contains('campo', 'condominio'));
    }

    public function test_confirmar_pendencia_remove_o_alerta_das_proximas_validacoes(): void
    {
        $imovel = $this->criarRascunho();
        $antes = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();
        $alertaVagas = collect($antes['alertas'])->firstWhere('campo', 'vagas');
        $this->assertNotNull($alertaVagas);

        $this->postJson("/api/imoveis-staging/{$imovel->id}/confirmar-pendencia", ['mensagem' => $alertaVagas['mensagem']])
            ->assertStatus(200);

        $depois = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();
        $this->assertFalse(collect($depois['alertas'])->contains('campo', 'vagas'));

        // Nunca duplica ao confirmar a mesma pendência de novo.
        $this->postJson("/api/imoveis-staging/{$imovel->id}/confirmar-pendencia", ['mensagem' => $alertaVagas['mensagem']]);
        $this->assertCount(1, $imovel->fresh()->pendencias_confirmadas);
    }

    public function test_bloqueio_nunca_e_dispensavel_via_confirmar_pendencia(): void
    {
        $imovel = $this->criarRascunho();
        $antes = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();
        $bloqueioValor = collect($antes['bloqueios'])->firstWhere('campo', 'valor');

        $this->postJson("/api/imoveis-staging/{$imovel->id}/confirmar-pendencia", ['mensagem' => $bloqueioValor['mensagem']]);

        $depois = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();
        $this->assertTrue(collect($depois['bloqueios'])->contains('campo', 'valor'));
    }

    public function test_descricao_abaixo_do_minimo_tecnico_bloqueia_e_nao_tambem_alerta(): void
    {
        $imovel = $this->criarRascunho(['descricao_gerada' => 'texto muito curto']);

        $dados = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $this->assertTrue(collect($dados['bloqueios'])->contains('campo', 'descricao_gerada'));
        $this->assertFalse(collect($dados['alertas'])->contains('campo', 'descricao_gerada'));
    }

    public function test_descricao_entre_minimo_e_meta_gera_apenas_alerta(): void
    {
        $imovel = $this->criarRascunho(['descricao_gerada' => str_repeat('a', 500)]);

        $dados = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $this->assertFalse(collect($dados['bloqueios'])->contains('campo', 'descricao_gerada'));
        $this->assertTrue(collect($dados['alertas'])->contains('campo', 'descricao_gerada'));
    }

    public function test_menos_de_25_fotos_bloqueia_com_a_mesma_contagem_de_finalizar(): void
    {
        $imovel = $this->criarRascunho($this->camposObrigatoriosCompletos());
        $this->adicionarFotos($imovel, 24);

        $dados = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $this->assertTrue(collect($dados['bloqueios'])->contains('campo', 'fotos'));
    }

    public function test_alertas_fotos_nao_vazio_gera_alerta_com_a_contagem(): void
    {
        $imovel = $this->criarRascunho(array_merge($this->camposObrigatoriosCompletos(), [
            'alertas_fotos' => [['foto_id' => null, 'mensagem' => 'possível banner publicitário']],
        ]));
        $this->adicionarFotos($imovel, 25);

        $dados = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $alerta = collect($dados['alertas'])->firstWhere('campo', 'alertas_fotos');
        $this->assertNotNull($alerta);
        $this->assertStringContainsString('1 foto(s)', $alerta['mensagem']);
    }

    public function test_locacao_residencial_sem_iptu_e_condominio_gera_sugestao_nao_bloqueante(): void
    {
        $imovel = $this->criarRascunho(['negociacao' => 'locacao', 'utilizacao' => 'residencial']);

        $dados = $this->postJson("/api/imoveis-staging/{$imovel->id}/validar")->json();

        $this->assertTrue(collect($dados['sugestoes'])->contains('campo', 'iptu_situacao'));
        // Sugestão nunca desconta pontuação nem bloqueia.
        $this->assertFalse(collect($dados['bloqueios'])->contains('campo', 'iptu_situacao'));
    }
}
