<?php

namespace Tests\Feature;

use App\Jobs\GerarEdicaoFotoJob;
use App\Models\ImovelStaging;
use App\Models\ImovelStagingFoto;
use App\Models\ImovelStagingFotoEdicao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EdicaoFotoTest extends TestCase
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

    private function imagemValidaBase64(): string
    {
        $imagem = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($imagem);
        $bytes = ob_get_clean();
        imagedestroy($imagem);

        return base64_encode($bytes);
    }

    /**
     * @return array<string, mixed>
     */
    private function respostaGeminiComImagem(): array
    {
        return [
            'candidates' => [[
                'content' => ['parts' => [
                    ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $this->imagemValidaBase64()]],
                ]],
            ]],
        ];
    }

    /**
     * @return array{categoria: string, descricao: string, confianca: float}
     */
    private function sugestaoPessoa(string $descricao = 'pessoa em pé perto da porta'): array
    {
        return ['categoria' => 'pessoa', 'descricao' => $descricao, 'confianca' => 0.9];
    }

    /**
     * @return array{categoria: string, descricao: string, confianca: float}
     */
    private function sugestaoAnimal(string $descricao = 'cachorro no quintal'): array
    {
        return ['categoria' => 'animal', 'descricao' => $descricao, 'confianca' => 0.8];
    }

    /**
     * Marca a foto como tendo essa sugestão persistida (simula o resultado
     * de uma análise prévia) — o backend só aceita, em POST .../edicoes,
     * itens que batam EXATAMENTE com o que está aqui.
     *
     * @param  array<int, array{categoria: string, descricao: string, confianca?: float}>  $sugestoes
     */
    private function sugerirItensRemoviveis(ImovelStagingFoto $foto, array $sugestoes): void
    {
        $foto->update(['itens_removiveis_sugeridos' => $sugestoes]);
    }

    /**
     * Cria uma tentativa (a partir de sugestões já persistidas na foto,
     * seguindo exatamente o contrato do FormRequest — {categoria,
     * descricao}, sem "confianca") e deixa o job (fila "sync" em testes)
     * rodar de verdade contra um Gemini fakeado — chega em "gerada" pelo
     * mesmo caminho que a produção usaria. Sempre confirma pelo endpoint de
     * consulta (GET), nunca confiando na resposta "quente" do POST — o
     * mesmo que o polling do frontend faz.
     *
     * @param  array<int, array{categoria: string, descricao: string}>|null  $itens
     * @return array<string, mixed>
     */
    private function criarEdicaoGerada(ImovelStaging $imovelStaging, ImovelStagingFoto $foto, ?array $itens = null): array
    {
        $itens ??= [$this->sugestaoPessoa()];
        $itensParaSubmeter = array_map(fn ($item) => ['categoria' => $item['categoria'], 'descricao' => $item['descricao']], $itens);

        $this->sugerirItensRemoviveis($foto, $itens);

        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGeminiComImagem(), 200)]);

        $criada = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", ['itens' => $itensParaSubmeter])
            ->assertStatus(202)
            ->json();

        $edicao = $this->getJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$criada['id']}")
            ->assertStatus(200)
            ->json();

        $this->assertSame('gerada', $edicao['status']);

        return $edicao;
    }

    private function marcarComoAnalisado(ImovelStaging $imovelStaging, int $fotoCapaId): void
    {
        $imovelStaging->update([
            'fotos_analisadas_em' => now(),
            'diferenciais_fotos' => ['garagem'],
            'observacoes_visuais' => ['sala ampla'],
            'alertas_fotos' => ['1 foto parece banner'],
            'foto_capa_sugerida_id' => $fotoCapaId,
            'foto_capa_motivo' => 'boa fachada',
            'foto_capa_id' => $fotoCapaId,
        ]);
    }

    // ---- Pertencimento ----

    public function test_criar_edicao_para_foto_de_outro_staging_retorna_404(): void
    {
        $imovelStagingA = $this->criarRascunhoComFotos(1);
        $imovelStagingB = $this->criarRascunhoComFotos(1);
        $fotoDeA = $imovelStagingA->fotos()->first();
        $this->sugerirItensRemoviveis($fotoDeA, [$this->sugestaoPessoa()]);

        $this->postJson("/api/imoveis-staging/{$imovelStagingB->id}/fotos/{$fotoDeA->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'pessoa em pé perto da porta']],
        ])->assertStatus(404);
    }

    public function test_aprovar_edicao_que_nao_pertence_a_foto_da_url_retorna_404(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(2);
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();
        $edicao = $this->criarEdicaoGerada($imovelStaging, $fotos[0]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$fotos[1]->id}/edicoes/{$edicao['id']}/aprovar")
            ->assertStatus(404);
    }

    // ---- Backend só aceita item sugerido e persistido para AQUELA foto ----

    public function test_item_nao_sugerido_para_a_foto_e_rejeitado(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $this->sugerirItensRemoviveis($foto, [$this->sugestaoPessoa('pessoa perto da porta')]);

        // Item plausível, mas que NÃO é a sugestão persistida (descrição diferente).
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'uma pessoa qualquer inventada']],
        ])->assertStatus(422)->assertJsonValidationErrors(['itens.0']);

        $this->assertSame(0, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $foto->id)->count());
    }

    public function test_item_sugerido_para_outra_foto_e_rejeitado(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(2);
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();
        $this->sugerirItensRemoviveis($fotos[0], [$this->sugestaoPessoa('pessoa na foto 1')]);
        $this->sugerirItensRemoviveis($fotos[1], [$this->sugestaoAnimal('cachorro na foto 2')]);

        // Tenta usar, na foto 2, uma sugestão que só existe na foto 1.
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$fotos[1]->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'pessoa na foto 1']],
        ])->assertStatus(422);

        $this->assertSame(0, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $fotos[1]->id)->count());
    }

    public function test_item_manual_digitado_livremente_e_rejeitado(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        // Foto SEM nenhuma sugestão persistida.

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'algo que o corretor digitou na mão']],
        ])->assertStatus(422);

        $this->assertSame(0, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $foto->id)->count());
    }

    // ---- Transições válidas/inválidas ----

    public function test_nao_e_possivel_aprovar_edicao_ainda_pendente(): void
    {
        Queue::fake();
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $this->sugerirItensRemoviveis($foto, [$this->sugestaoPessoa()]);

        $criada = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'pessoa em pé perto da porta']],
        ])->assertStatus(202)->json();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$criada['id']}/aprovar")
            ->assertStatus(422);
    }

    public function test_nao_e_possivel_aprovar_edicao_ja_rejeitada(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $edicao = $this->criarEdicaoGerada($imovelStaging, $foto);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicao['id']}/rejeitar")
            ->assertStatus(200);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicao['id']}/aprovar")
            ->assertStatus(422);
    }

    public function test_nao_e_possivel_rejeitar_edicao_ja_aprovada(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $edicao = $this->criarEdicaoGerada($imovelStaging, $foto);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicao['id']}/aprovar")
            ->assertStatus(200);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicao['id']}/rejeitar")
            ->assertStatus(422);
    }

    // ---- Idempotência contra duplo clique ----

    public function test_duplo_clique_em_gerar_edicao_nao_cria_duas_tentativas(): void
    {
        Queue::fake();
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $this->sugerirItensRemoviveis($foto, [$this->sugestaoPessoa()]);

        $body = ['itens' => [['categoria' => 'pessoa', 'descricao' => 'pessoa em pé perto da porta']]];

        $primeira = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", $body)
            ->assertStatus(202)->json();

        $segunda = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", $body)
            ->assertStatus(200)->json();

        $this->assertSame($primeira['id'], $segunda['id']);
        $this->assertSame(1, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $foto->id)->count());
        Queue::assertPushed(GerarEdicaoFotoJob::class, 1);
    }

    // ---- Concorrência de duas aprovações ----

    public function test_aprovar_duas_edicoes_da_mesma_foto_deixa_so_a_ultima_como_ativa_sem_perder_historico(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();

        $edicaoA = $this->criarEdicaoGerada($imovelStaging, $foto, [$this->sugestaoPessoa()]);
        $edicaoB = $this->criarEdicaoGerada($imovelStaging, $foto, [$this->sugestaoAnimal()]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicaoA['id']}/aprovar")
            ->assertStatus(200);
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicaoB['id']}/aprovar")
            ->assertStatus(200);

        $foto->refresh();
        $this->assertSame($edicaoB['id'], $foto->edicao_ativa_id);

        // Histórico preservado: A continua "aprovada", só deixou de ser a ATIVA.
        $this->assertSame('aprovada', ImovelStagingFotoEdicao::find($edicaoA['id'])->status);
        $this->assertSame('aprovada', ImovelStagingFotoEdicao::find($edicaoB['id'])->status);
    }

    // ---- Original preservado ----

    public function test_original_preservado_apos_aprovar_e_apos_rejeitar(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $caminhoOriginalAntes = $foto->caminho;

        $edicaoRejeitada = $this->criarEdicaoGerada($imovelStaging, $foto, [$this->sugestaoPessoa()]);
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicaoRejeitada['id']}/rejeitar")
            ->assertStatus(200);
        $this->assertSame($caminhoOriginalAntes, $foto->fresh()->caminho);

        $edicaoAprovada = $this->criarEdicaoGerada($imovelStaging, $foto, [$this->sugestaoAnimal()]);
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicaoAprovada['id']}/aprovar")
            ->assertStatus(200);
        $this->assertSame($caminhoOriginalAntes, $foto->fresh()->caminho);
    }

    // ---- Rejeitada nunca ativa ----

    public function test_edicao_rejeitada_nunca_fica_como_edicao_ativa(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $edicao = $this->criarEdicaoGerada($imovelStaging, $foto);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicao['id']}/rejeitar")
            ->assertStatus(200);

        $this->assertNull($foto->fresh()->edicao_ativa_id);
    }

    // ---- Edição nunca fica ativa antes da aprovação ----

    public function test_edicao_gerada_ainda_nao_esta_ativa_antes_de_aprovar(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $edicao = $this->criarEdicaoGerada($imovelStaging, $foto);

        $this->assertSame('gerada', $edicao['status']);
        $this->assertNull($foto->fresh()->edicao_ativa_id);
    }

    // ---- Invalidação de análise só após aprovação ----

    public function test_rejeitar_edicao_nao_invalida_analise_nem_capa(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $this->marcarComoAnalisado($imovelStaging, $foto->id);

        $edicao = $this->criarEdicaoGerada($imovelStaging, $foto);
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicao['id']}/rejeitar")
            ->assertStatus(200);

        $imovelStaging->refresh();
        $this->assertNotNull($imovelStaging->fotos_analisadas_em);
        $this->assertSame(['garagem'], $imovelStaging->diferenciais_fotos);
        $this->assertSame($foto->id, $imovelStaging->foto_capa_id);
        $this->assertSame($foto->id, $imovelStaging->foto_capa_sugerida_id);
    }

    public function test_aprovar_edicao_invalida_analise_e_sugestao_de_capa_mas_preserva_capa_manual(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $this->marcarComoAnalisado($imovelStaging, $foto->id);

        $edicao = $this->criarEdicaoGerada($imovelStaging, $foto);
        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicao['id']}/aprovar")
            ->assertStatus(200);

        $imovelStaging->refresh();

        // Invalidado: resultado exclusivamente fotográfico + sugestão automática de capa.
        $this->assertNull($imovelStaging->fotos_analisadas_em);
        $this->assertSame([], $imovelStaging->diferenciais_fotos);
        $this->assertSame([], $imovelStaging->observacoes_visuais);
        $this->assertSame([], $imovelStaging->alertas_fotos);
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNull($imovelStaging->foto_capa_motivo);

        // Preservado: a escolha MANUAL de capa (a foto lógica continua existindo).
        $this->assertSame($foto->id, $imovelStaging->foto_capa_id);
    }

    // ---- Voltar ao original (desativar edição ativa) ----

    public function test_desativar_edicao_ativa_volta_a_expor_o_original_sem_apagar_historico(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $edicao = $this->criarEdicaoGerada($imovelStaging, $foto);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicao['id']}/aprovar")
            ->assertStatus(200);
        $this->assertSame($edicao['id'], $foto->fresh()->edicao_ativa_id);

        $this->deleteJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicao-ativa")
            ->assertStatus(204);

        $this->assertNull($foto->fresh()->edicao_ativa_id);
        // Histórico preservado — a linha continua "aprovada", só não é mais a ATIVA.
        $this->assertSame('aprovada', ImovelStagingFotoEdicao::find($edicao['id'])->status);

        // Idempotente: sem edição ativa, repetir a chamada não dá erro.
        $this->deleteJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicao-ativa")
            ->assertStatus(204);
    }

    // ---- Falha da API do provider marca a tentativa como "erro" (mensagem sanitizada) ----

    public function test_falha_do_provider_marca_a_tentativa_como_erro_sem_expor_texto_bruto_do_google(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'quota exceeded for project 123456, billing account xyz', 'status' => 'RESOURCE_EXHAUSTED'],
        ], 429)]);

        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $this->sugerirItensRemoviveis($foto, [$this->sugestaoPessoa()]);

        $criada = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'pessoa em pé perto da porta']],
        ])->assertStatus(202)->json();

        $edicao = $this->getJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$criada['id']}")
            ->assertStatus(200)->json();

        $this->assertSame('erro', $edicao['status']);
        $this->assertNotNull($edicao['mensagem_erro']);
        $this->assertStringNotContainsString('quota exceeded for project', $edicao['mensagem_erro']);
        $this->assertStringNotContainsString('123456', $edicao['mensagem_erro']);
        $this->assertStringNotContainsString('RESOURCE_EXHAUSTED', $edicao['mensagem_erro']);
        $this->assertNull($foto->fresh()->edicao_ativa_id);
    }
}
