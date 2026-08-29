<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FotoCapaTest extends TestCase
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

    /**
     * @param  int[]  $idsDoLote
     */
    private function respostaLote(array $idsDoLote, ?array $candidataCapa = null): array
    {
        return [
            'content' => [
                [
                    'type' => 'tool_use',
                    'input' => [
                        'diferenciais' => [],
                        'diferenciais_outros' => [],
                        'observacoes_visuais' => [],
                        'alertas_fotos' => [],
                        'candidata_capa' => $candidataCapa,
                    ],
                ],
            ],
        ];
    }

    /**
     * Quebra os ids em lotes de 3, na mesma ordem que AnaliseFotosService usa.
     *
     * @param  int[]  $fotoIds
     * @return array<int, int[]>
     */
    private function lotes(array $fotoIds): array
    {
        return array_chunk($fotoIds, 3);
    }

    // ---- Sugestão da IA (foto_capa_sugerida_id / foto_capa_motivo) ----

    public function test_sugestao_da_ia_vira_capa_ativa_quando_nao_ha_escolha_previa(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $lotes = $this->lotes($imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all());

        $respostas = [];
        foreach ($lotes as $i => $idsDoLote) {
            $candidata = $i === 0 ? ['identificador_foto' => (string) $idsDoLote[1], 'pontuacao' => 7, 'motivo' => 'boa fachada'] : null;
            $respostas[] = Http::response($this->respostaLote($idsDoLote, $candidata), 200);
        }

        Http::fake(['api.anthropic.com/*' => Http::sequence($respostas)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");
        $response->assertStatus(200);

        $imovelStaging->refresh();
        // Sem escolha prévia, a capa ATIVA se torna igual à sugestão.
        $this->assertSame($lotes[0][1], $imovelStaging->foto_capa_sugerida_id);
        $this->assertSame('boa fachada', $imovelStaging->foto_capa_motivo);
        $this->assertSame($lotes[0][1], $imovelStaging->foto_capa_id);
    }

    public function test_escolhe_maior_pontuacao_entre_lotes(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $lotes = $this->lotes($imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all());

        $respostas = [];
        foreach ($lotes as $i => $idsDoLote) {
            $candidata = match ($i) {
                0 => ['identificador_foto' => (string) $idsDoLote[0], 'pontuacao' => 5, 'motivo' => 'razoável'],
                2 => ['identificador_foto' => (string) $idsDoLote[0], 'pontuacao' => 9, 'motivo' => 'excelente fachada'],
                4 => ['identificador_foto' => (string) $idsDoLote[0], 'pontuacao' => 8, 'motivo' => 'boa sala'],
                default => null,
            };
            $respostas[] = Http::response($this->respostaLote($idsDoLote, $candidata), 200);
        }

        Http::fake(['api.anthropic.com/*' => Http::sequence($respostas)]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        $imovelStaging->refresh();
        $this->assertSame($lotes[2][0], $imovelStaging->foto_capa_sugerida_id);
        $this->assertSame('excelente fachada', $imovelStaging->foto_capa_motivo);
        $this->assertSame($lotes[2][0], $imovelStaging->foto_capa_id);
    }

    public function test_empate_de_pontuacao_prefere_a_foto_processada_primeiro(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $lotes = $this->lotes($imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all());

        $respostas = [];
        foreach ($lotes as $i => $idsDoLote) {
            $candidata = match ($i) {
                1 => ['identificador_foto' => (string) $idsDoLote[0], 'pontuacao' => 8, 'motivo' => 'primeira com nota 8'],
                5 => ['identificador_foto' => (string) $idsDoLote[0], 'pontuacao' => 8, 'motivo' => 'segunda com nota 8, empatada'],
                default => null,
            };
            $respostas[] = Http::response($this->respostaLote($idsDoLote, $candidata), 200);
        }

        Http::fake(['api.anthropic.com/*' => Http::sequence($respostas)]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        $imovelStaging->refresh();
        // Critério documentado: em empate, vence a candidata do lote processado
        // primeiro — não é decidido por nova chamada de IA.
        $this->assertSame($lotes[1][0], $imovelStaging->foto_capa_sugerida_id);
        $this->assertSame('primeira com nota 8', $imovelStaging->foto_capa_motivo);
    }

    public function test_nenhuma_candidata_em_nenhum_lote_deixa_campos_null(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $lotes = $this->lotes($imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all());

        $respostas = array_map(fn ($idsDoLote) => Http::response($this->respostaLote($idsDoLote, null), 200), $lotes);
        Http::fake(['api.anthropic.com/*' => Http::sequence($respostas)]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        $imovelStaging->refresh();
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNull($imovelStaging->foto_capa_motivo);
        $this->assertNull($imovelStaging->foto_capa_id);
    }

    public function test_candidata_com_identificador_fora_do_lote_e_ignorada(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $lotes = $this->lotes($imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all());

        $respostas = [];
        foreach ($lotes as $i => $idsDoLote) {
            // No lote 0, o identificador retornado (999999) não pertence a
            // nenhuma foto deste lote — precisa ser descartado silenciosamente.
            $candidata = $i === 0 ? ['identificador_foto' => '999999', 'pontuacao' => 10, 'motivo' => 'alucinação'] : null;
            $respostas[] = Http::response($this->respostaLote($idsDoLote, $candidata), 200);
        }

        Http::fake(['api.anthropic.com/*' => Http::sequence($respostas)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");
        $response->assertStatus(200);

        $imovelStaging->refresh();
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNull($imovelStaging->foto_capa_motivo);
    }

    public function test_candidata_malformada_nao_quebra_a_analise(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $lotes = $this->lotes($imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all());

        $respostas = [];
        foreach ($lotes as $i => $idsDoLote) {
            // Faltando "motivo" e "pontuacao" não numérica — resposta inválida.
            $candidata = $i === 0 ? ['identificador_foto' => (string) $idsDoLote[0], 'pontuacao' => 'alta'] : null;
            $respostas[] = Http::response($this->respostaLote($idsDoLote, $candidata), 200);
        }

        Http::fake(['api.anthropic.com/*' => Http::sequence($respostas)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");

        $response->assertStatus(200);
        $imovelStaging->refresh();
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        // Análise não finaliza — status continua rascunho.
        $this->assertSame('rascunho', $imovelStaging->status_propagacao);
    }

    // ---- Interação entre sugestão da IA e escolha manual (foto_capa_id) ----

    public function test_analisar_fotos_preserva_capa_manual_mas_registra_sugestao_da_ia(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();
        $lotes = $this->lotes($fotoIds);

        // Corretor já escolheu manualmente a primeira foto como capa antes de analisar.
        $this->putJson("/api/imoveis-staging/{$imovelStaging->id}/foto-capa", ['foto_id' => $fotoIds[0]])
            ->assertStatus(200);

        $respostas = [];
        foreach ($lotes as $i => $idsDoLote) {
            $candidata = $i === 0 ? null : ['identificador_foto' => (string) end($idsDoLote), 'pontuacao' => 9, 'motivo' => 'sugestão da IA'];
            $respostas[] = Http::response($this->respostaLote($idsDoLote, $candidata), 200);
        }

        Http::fake(['api.anthropic.com/*' => Http::sequence($respostas)]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos")->assertStatus(200);

        $imovelStaging->refresh();

        // Escolha manual preservada como capa ATIVA...
        $this->assertSame($fotoIds[0], $imovelStaging->foto_capa_id);
        // ...mas a sugestão da IA é registrada separadamente, não descartada.
        $this->assertNotNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNotSame($fotoIds[0], $imovelStaging->foto_capa_sugerida_id);
        $this->assertSame('sugestão da IA', $imovelStaging->foto_capa_motivo);
    }

    // ---- Endpoint de seleção manual ----

    public function test_selecao_manual_altera_somente_foto_capa_id(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(25);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();

        // Simula uma sugestão da IA já registrada.
        $imovelStaging->update([
            'foto_capa_sugerida_id' => $fotoIds[0],
            'foto_capa_motivo' => 'sugestão original da IA',
            'foto_capa_id' => $fotoIds[0],
        ]);

        $response = $this->putJson("/api/imoveis-staging/{$imovelStaging->id}/foto-capa", [
            'foto_id' => $fotoIds[5],
        ]);

        $response->assertStatus(200)->assertJsonFragment([
            'foto_capa_id' => $fotoIds[5],
            'foto_capa_sugerida_id' => $fotoIds[0],
            'foto_capa_motivo' => 'sugestão original da IA',
        ]);

        $imovelStaging->refresh();
        $this->assertSame($fotoIds[5], $imovelStaging->foto_capa_id);
        // Sugestão da IA nunca é tocada pela seleção manual.
        $this->assertSame($fotoIds[0], $imovelStaging->foto_capa_sugerida_id);
        $this->assertSame('sugestão original da IA', $imovelStaging->foto_capa_motivo);
    }

    public function test_selecao_manual_rejeita_foto_de_outro_staging(): void
    {
        $imovelStagingA = $this->criarRascunhoComFotos(1);
        $imovelStagingB = $this->criarRascunhoComFotos(1);

        $fotoDeB = $imovelStagingB->fotos()->first();

        $response = $this->putJson("/api/imoveis-staging/{$imovelStagingA->id}/foto-capa", [
            'foto_id' => $fotoDeB->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['foto_id']);

        $this->assertNull($imovelStagingA->fresh()->foto_capa_id);
    }

    public function test_selecao_manual_em_staging_inexistente_retorna_404(): void
    {
        $response = $this->putJson('/api/imoveis-staging/999999/foto-capa', ['foto_id' => 1]);

        $response->assertStatus(404);
    }

    // ---- Remoção de foto limpa cada referência independentemente ----

    public function test_remover_qualquer_foto_apos_analise_tambem_limpa_sugestao_de_capa(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(3);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();

        // fotos_analisadas_em preenchido: única forma real de foto_capa_sugerida_id
        // existir (só analisarFotos() o define, sempre junto com este timestamp).
        $imovelStaging->update([
            'fotos_analisadas_em' => now(),
            'foto_capa_id' => $fotoIds[0],
            'foto_capa_sugerida_id' => $fotoIds[1],
            'foto_capa_motivo' => 'sugestão da IA, foto diferente da ativa',
        ]);

        $this->deleteJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$fotoIds[0]}")->assertStatus(200);

        $imovelStaging->refresh();
        $this->assertNull($imovelStaging->foto_capa_id);
        // Remoção invalida a análise por inteiro — a sugestão também é limpa,
        // mesmo sendo de uma foto diferente da removida (ela pode não ser mais
        // a melhor candidata agora que o conjunto de fotos mudou).
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNull($imovelStaging->foto_capa_motivo);
    }

    public function test_remover_foto_sugerida_limpa_id_e_motivo(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(3);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();

        $imovelStaging->update([
            'fotos_analisadas_em' => now(),
            'foto_capa_id' => $fotoIds[0],
            'foto_capa_sugerida_id' => $fotoIds[1],
            'foto_capa_motivo' => 'sugestão que vai ser removida',
        ]);

        $this->deleteJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$fotoIds[1]}")->assertStatus(200);

        $imovelStaging->refresh();
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNull($imovelStaging->foto_capa_motivo);
        // Capa manual (ATIVA) preservada — a foto dela continua existindo, e
        // remoção nunca mexe em foto_capa_id a não ser via FK (foto removida = a própria capa).
        $this->assertSame($fotoIds[0], $imovelStaging->foto_capa_id);
    }

    public function test_remover_foto_que_e_ativa_e_sugerida_ao_mesmo_tempo_limpa_tudo(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(3);
        $fotoIds = $imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all();

        $imovelStaging->update([
            'fotos_analisadas_em' => now(),
            'foto_capa_id' => $fotoIds[0],
            'foto_capa_sugerida_id' => $fotoIds[0],
            'foto_capa_motivo' => 'sugestão que também é a capa ativa',
        ]);

        $this->deleteJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$fotoIds[0]}")->assertStatus(200);

        $imovelStaging->refresh();
        $this->assertNull($imovelStaging->foto_capa_id);
        $this->assertNull($imovelStaging->foto_capa_sugerida_id);
        $this->assertNull($imovelStaging->foto_capa_motivo);
    }

    // ---- Convivência da capa com a separação diferenciais x diferenciais_fotos ----

    public function test_capa_convive_com_a_separacao_entre_diferenciais_e_diferenciais_fotos(): void
    {
        // "portaria" é da fala (preservado tal qual); "garagem" é só da foto.
        $imovelStaging = $this->criarRascunhoComFotos(25, [
            'diferenciais' => ['portaria'],
            'observacoes_visuais' => ['já existia — não é mais válido após reanálise'],
            'alertas_fotos' => ['alerta antigo — não é mais válido após reanálise'],
        ]);
        $lotes = $this->lotes($imovelStaging->fotos()->orderBy('ordem')->pluck('id')->all());

        $respostas = [];
        foreach ($lotes as $i => $idsDoLote) {
            if ($i === 0) {
                $respostas[] = Http::response([
                    'content' => [[
                        'type' => 'tool_use',
                        'input' => [
                            'diferenciais' => ['garagem'],
                            'diferenciais_outros' => [],
                            'observacoes_visuais' => ['sala ampla'],
                            'alertas_fotos' => [
                                ['identificador_foto' => null, 'mensagem' => '1 foto parece banner'],
                            ],
                            'candidata_capa' => ['identificador_foto' => (string) $idsDoLote[0], 'pontuacao' => 8, 'motivo' => 'boa capa'],
                        ],
                    ]],
                ], 200);
            } else {
                $respostas[] = Http::response($this->respostaLote($idsDoLote, null), 200);
            }
        }

        Http::fake(['api.anthropic.com/*' => Http::sequence($respostas)]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/analisar-fotos");
        $response->assertStatus(200);

        $imovelStaging->refresh();
        // "diferenciais" (fala) não muda; "diferenciais_fotos" é exclusivo da IA.
        $this->assertSame(['portaria'], $imovelStaging->diferenciais);
        $this->assertSame(['garagem'], $imovelStaging->diferenciais_fotos);
        $this->assertEqualsCanonicalizing(['portaria', 'garagem'], $response->json('diferenciais_uniao'));

        // observacoes_visuais/alertas_fotos SUBSTITUÍDOS, não mesclados com o antigo.
        $this->assertSame(['sala ampla'], $imovelStaging->observacoes_visuais);
        $this->assertSame(
            [['foto_id' => null, 'mensagem' => '1 foto parece banner']],
            $imovelStaging->alertas_fotos
        );

        $this->assertSame($lotes[0][0], $imovelStaging->foto_capa_sugerida_id);
        $this->assertSame('boa capa', $imovelStaging->foto_capa_motivo);
        $this->assertSame($lotes[0][0], $imovelStaging->foto_capa_id);
    }
}
