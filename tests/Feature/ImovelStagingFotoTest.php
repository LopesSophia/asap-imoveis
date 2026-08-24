<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImovelStagingFotoTest extends TestCase
{
    use RefreshDatabase;

    private function criarRascunho(): ImovelStaging
    {
        return ImovelStaging::create([
            'corretor_id' => User::factory()->create()->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ]);
    }

    public function test_upload_de_fotos_cria_registros_com_ordem_incremental(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [
                UploadedFile::fake()->image('foto1.jpg'),
                UploadedFile::fake()->image('foto2.jpg'),
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonFragment(['total_fotos' => 2]);

        $this->assertDatabaseCount('imovel_staging_fotos', 2);
        $this->assertDatabaseHas('imovel_staging_fotos', [
            'imovel_staging_id' => $imovelStaging->id,
            'ordem' => 1,
        ]);
        $this->assertDatabaseHas('imovel_staging_fotos', [
            'imovel_staging_id' => $imovelStaging->id,
            'ordem' => 2,
        ]);
    }

    public function test_upload_em_duas_levas_continua_a_ordem(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('a.jpg')],
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('b.jpg')],
        ]);

        $response->assertStatus(201)->assertJsonFragment(['total_fotos' => 2]);

        $this->assertDatabaseHas('imovel_staging_fotos', [
            'imovel_staging_id' => $imovelStaging->id,
            'ordem' => 2,
        ]);
    }

    public function test_upload_rejeita_arquivo_que_nao_e_imagem(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->create('documento.pdf', 100)],
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['fotos.0']);
    }

    /**
     * Trava o contrato consumido pelo frontend (resources/views/asap.blade.php,
     * handler de 'input-fotos' → dados.fotos.forEach(...) e dados.total_fotos).
     * Um response que não bata com este formato é a causa raiz documentada do
     * bug "Cannot read properties of undefined (reading 'forEach')".
     */
    public function test_resposta_do_upload_tem_o_formato_consumido_pelo_frontend(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('foto.jpg')],
        ]);

        $response->assertStatus(201)->assertJsonStructure([
            'fotos' => [
                '*' => ['id', 'imovel_staging_id', 'caminho', 'ordem', 'url'],
            ],
            'total_fotos',
        ]);

        $this->assertIsArray($response->json('fotos'));
        $this->assertIsInt($response->json('total_fotos'));
        $this->assertIsInt($response->json('fotos.0.id'));
        $this->assertIsString($response->json('fotos.0.url'));
        $this->assertStringStartsWith('http', $response->json('fotos.0.url'));
    }

    public function test_25_fotos_enviadas_em_multiplas_levas_sao_todas_preservadas(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();

        // Primeira leva: 15 fotos.
        $response1 = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => array_map(fn ($i) => UploadedFile::fake()->image("foto{$i}.jpg"), range(1, 15)),
        ]);
        $response1->assertStatus(201)->assertJsonFragment(['total_fotos' => 15]);

        // Segunda leva: mais 10 fotos, completando 25.
        $response2 = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => array_map(fn ($i) => UploadedFile::fake()->image("foto{$i}.jpg"), range(16, 25)),
        ]);
        $response2->assertStatus(201)->assertJsonFragment(['total_fotos' => 25]);

        $this->assertDatabaseCount('imovel_staging_fotos', 25);
        $this->assertSame(25, $imovelStaging->fotos()->count());

        // Ordem contínua de 1 a 25, sem lacunas nem repetição entre as levas.
        $ordens = $imovelStaging->fotos()->orderBy('ordem')->pluck('ordem')->all();
        $this->assertSame(range(1, 25), $ordens);
    }

    /**
     * Estado "análise já feita" completo, usado pelos testes de invalidação
     * abaixo: todo campo exclusivamente fotográfico preenchido, mais um
     * diferencial de origem fala/digitação e uma capa manual — pra provar
     * que a invalidação limpa só o que é derivado das fotos.
     */
    private function marcarComoAnalisado(ImovelStaging $imovelStaging, int $fotoCapaAtivaId): void
    {
        $imovelStaging->update([
            'fotos_analisadas_em' => now(),
            'diferenciais' => ['portaria'],
            'diferenciais_fotos' => ['garagem'],
            'diferenciais_outros_fotos' => ['ar condicionado'],
            'observacoes_visuais' => ['sala ampla'],
            'alertas_fotos' => ['1 foto parece banner'],
            'foto_capa_sugerida_id' => $fotoCapaAtivaId,
            'foto_capa_motivo' => 'boa fachada',
            'foto_capa_id' => $fotoCapaAtivaId,
        ]);
    }

    public function test_upload_de_nova_foto_invalida_analise_e_limpa_so_dados_fotograficos(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();
        $fotoExistente = $imovelStaging->fotos()->create(['caminho' => 'imoveis/x/foto.jpg', 'ordem' => 1]);
        $this->marcarComoAnalisado($imovelStaging, $fotoExistente->id);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('nova.jpg')],
        ])->assertStatus(201);

        $imovelStaging->refresh();

        // Limpo: tudo que é exclusivamente derivado da análise de fotos.
        $this->assertNull($imovelStaging->fotos_analisadas_em);
        $this->assertSame([], $imovelStaging->diferenciais_fotos);
        $this->assertSame([], $imovelStaging->diferenciais_outros_fotos);
        $this->assertSame([], $imovelStaging->observacoes_visuais);
        $this->assertSame([], $imovelStaging->alertas_fotos);
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNull($imovelStaging->foto_capa_motivo);

        // Preservado: diferencial de fala/digitação e a capa manual (a foto
        // ainda existe, não foi ela que mudou).
        $this->assertSame(['portaria'], $imovelStaging->diferenciais);
        $this->assertSame($fotoExistente->id, $imovelStaging->foto_capa_id);
    }

    public function test_upload_sem_analise_previa_nao_mexe_em_fotos_analisadas_em(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();
        $this->assertNull($imovelStaging->fresh()->fotos_analisadas_em);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertStatus(201);

        // Continua null (não vira uma data por engano) — só é setado por analisar-fotos().
        $this->assertNull($imovelStaging->fresh()->fotos_analisadas_em);
    }

    public function test_remover_foto_invalida_analise_e_limpa_so_dados_fotograficos(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();

        $uploadCapa = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('capa.jpg')],
        ])->json();
        $uploadOutra = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('outra.jpg')],
        ])->json();

        $fotoCapaId = $uploadCapa['fotos'][0]['id'];
        $this->marcarComoAnalisado($imovelStaging, $fotoCapaId);

        // Remove uma foto QUE NÃO é a capa — a capa manual precisa sobreviver.
        $this->deleteJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$uploadOutra['fotos'][0]['id']}")
            ->assertStatus(200);

        $imovelStaging->refresh();

        $this->assertNull($imovelStaging->fotos_analisadas_em);
        $this->assertSame([], $imovelStaging->diferenciais_fotos);
        $this->assertSame([], $imovelStaging->diferenciais_outros_fotos);
        $this->assertSame([], $imovelStaging->observacoes_visuais);
        $this->assertSame([], $imovelStaging->alertas_fotos);
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNull($imovelStaging->foto_capa_motivo);

        $this->assertSame(['portaria'], $imovelStaging->diferenciais);
        // Capa manual preservada — a foto dela continua existindo.
        $this->assertSame($fotoCapaId, $imovelStaging->foto_capa_id);
    }

    public function test_remover_a_propria_foto_de_capa_limpa_foto_capa_id_via_fk(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();

        $upload = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('capa.jpg')],
        ])->json();
        $fotoCapaId = $upload['fotos'][0]['id'];
        $this->marcarComoAnalisado($imovelStaging, $fotoCapaId);

        // Desta vez remove exatamente a foto que é a capa ativa.
        $this->deleteJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$fotoCapaId}")
            ->assertStatus(200);

        // Aqui sim foto_capa_id vira null — mas via FK nullOnDelete (a foto
        // não existe mais), não pela invalidação da análise.
        $this->assertNull($imovelStaging->fresh()->foto_capa_id);
    }

    public function test_remover_foto_apaga_arquivo_e_registro(): void
    {
        Storage::fake('public');

        $imovelStaging = $this->criarRascunho();

        $upload = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
            'fotos' => [UploadedFile::fake()->image('foto.jpg')],
        ])->json();

        $fotoId = $upload['fotos'][0]['id'];
        $caminho = $upload['fotos'][0]['caminho'];

        Storage::disk('public')->assertExists($caminho);

        $response = $this->deleteJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$fotoId}");

        $response->assertStatus(200)->assertJsonFragment(['total_fotos' => 0]);

        $this->assertDatabaseCount('imovel_staging_fotos', 0);
        Storage::disk('public')->assertMissing($caminho);
    }
}
