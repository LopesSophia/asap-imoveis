<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * finalizar() agora é enxuto de propósito: NUNCA chama a IA (isso é
 * responsabilidade exclusiva de analisar-fotos(), ver AnalisarFotosTest).
 * Aqui testamos só os portões de finalizar(): campos obrigatórios, mínimo de
 * fotos, e a exigência de análise válida (fotos_analisadas_em preenchido).
 */
class ImovelStagingFinalizarTest extends TestCase
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

    // Nota: não há teste para "campo obrigatório tipo_imovel ausente" aqui —
    // a coluna é NOT NULL no banco (CHECK constraint do enum), então esse
    // portão em finalizar() é defensivo/inalcançável pela API atual, mesma
    // observação já registrada quando o campo foi implementado.

    public function test_finalizar_com_menos_de_25_fotos_retorna_422_e_nao_chama_a_ia(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(24);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'Faltam 1 fotos para completar o mínimo de 25 (você tem 24).',
                'total_fotos' => 24,
            ]);

        Http::assertNothingSent();

        $this->assertDatabaseHas('imovel_stagings', [
            'id' => $imovelStaging->id,
            'status_propagacao' => 'rascunho',
        ]);
    }

    public function test_finalizar_bloqueado_sem_analise_de_fotos(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(25);
        // 25 fotos, mas nunca chamou POST .../analisar-fotos.
        $this->assertNull($imovelStaging->fresh()->fotos_analisadas_em);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar");

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'É necessário analisar as fotos antes de concluir o cadastro.',
        ]);

        Http::assertNothingSent();
        $this->assertSame('rascunho', $imovelStaging->fresh()->status_propagacao);
    }

    public function test_finalizar_com_analise_valida_conclui_o_cadastro_sem_chamar_a_ia(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(25);
        $imovelStaging->update(['fotos_analisadas_em' => now()]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar");

        $response->assertStatus(200)->assertJsonFragment(['status_propagacao' => 'pendente']);

        // finalizar() nunca chama a IA — isso é só responsabilidade de analisar-fotos().
        Http::assertNothingSent();
        $this->assertSame('pendente', $imovelStaging->fresh()->status_propagacao);
    }
}
