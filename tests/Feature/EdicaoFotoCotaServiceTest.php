<?php

namespace Tests\Feature;

use App\Models\GeminiUsoMensal;
use App\Models\ImovelStaging;
use App\Models\ImovelStagingFoto;
use App\Models\ImovelStagingFotoEdicao;
use App\Models\User;
use App\Services\EdicaoFotoGeminiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cobre os três limitadores de custo da edição de fotos (por foto, por
 * imóvel, mensal/global) e a reserva atômica de cota — ver
 * EdicaoFotoCotaService. Todos os testes rodam contra os endpoints reais
 * (não a classe isolada) porque o que importa é o comportamento
 * ponta-a-ponta: nunca chamar/enfileirar o Gemini quando o limite já foi
 * atingido.
 */
class EdicaoFotoCotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function criarRascunhoComFotos(int $quantidadeFotos): ImovelStaging
    {
        Storage::fake('public');

        $imovelStaging = ImovelStaging::create([
            'corretor_id' => User::factory()->create()->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ]);

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
     * Sugere e IMEDIATAMENTE solicita uma edição para a foto (satisfaz o
     * FormRequest, que só aceita item sugerido e persistido) — usa um
     * descricao ÚNICO por chamada pra nunca colidir com uma tentativa
     * anterior da mesma foto.
     */
    private function solicitarEdicao(ImovelStaging $imovelStaging, ImovelStagingFoto $foto, string $descricaoUnica): TestResponse
    {
        $foto->update(['itens_removiveis_sugeridos' => [
            ['categoria' => 'pessoa', 'descricao' => $descricaoUnica, 'confianca' => 0.9],
        ]]);

        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaGeminiComImagem(), 200)]);

        return $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => $descricaoUnica]],
        ]);
    }

    private function anoMesAtual(): string
    {
        return now()->format('Y-m');
    }

    // ---- Limite por foto ----

    public function test_limite_de_tentativas_por_foto_bloqueia_apos_o_teto(): void
    {
        config(['services.gemini.limite_tentativas_por_foto' => 2]);

        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();

        $this->solicitarEdicao($imovelStaging, $foto, 'tentativa 1')->assertStatus(202);
        $this->solicitarEdicao($imovelStaging, $foto, 'tentativa 2')->assertStatus(202);

        $resposta = $this->solicitarEdicao($imovelStaging, $foto, 'tentativa 3');
        $resposta->assertStatus(429);
        $this->assertNotEmpty($resposta->json('message'));

        $this->assertSame(2, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $foto->id)->count());
    }

    // ---- Limite por imóvel ----

    public function test_limite_de_tentativas_por_imovel_bloqueia_apos_o_teto(): void
    {
        config(['services.gemini.limite_tentativas_por_imovel' => 2]);

        $imovelStaging = $this->criarRascunhoComFotos(3);
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();

        $this->solicitarEdicao($imovelStaging, $fotos[0], 'tentativa foto 1')->assertStatus(202);
        $this->solicitarEdicao($imovelStaging, $fotos[1], 'tentativa foto 2')->assertStatus(202);

        // Terceira tentativa, numa TERCEIRA foto (nenhum limite por-foto
        // interferindo) — é o limite POR IMÓVEL que precisa barrar.
        $resposta = $this->solicitarEdicao($imovelStaging, $fotos[2], 'tentativa foto 3');
        $resposta->assertStatus(429);

        $this->assertSame(0, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $fotos[2]->id)->count());
        $this->assertSame(2, $imovelStaging->edicoesFotos()->count());
    }

    // ---- Limite mensal (global) ----

    public function test_limite_mensal_bloqueia_apos_o_teto_mesmo_em_imoveis_diferentes(): void
    {
        config([
            'services.gemini.limite_chamadas_mensal' => 2,
            'services.gemini.limite_tentativas_por_foto' => 10,
            'services.gemini.limite_tentativas_por_imovel' => 10,
        ]);

        $imovelA = $this->criarRascunhoComFotos(1);
        $fotoA = $imovelA->fotos()->first();
        $imovelB = $this->criarRascunhoComFotos(1);
        $fotoB = $imovelB->fotos()->first();

        $this->solicitarEdicao($imovelA, $fotoA, 'consumo 1')->assertStatus(202);
        $this->solicitarEdicao($imovelA, $fotoA, 'consumo 2')->assertStatus(202);

        // Terceira chamada do MÊS, num imóvel totalmente diferente — o
        // limite mensal é global, não por imóvel/foto.
        $resposta = $this->solicitarEdicao($imovelB, $fotoB, 'consumo 3');
        $resposta->assertStatus(429);

        $this->assertSame(0, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $fotoB->id)->count());
        $this->assertSame(2, GeminiUsoMensal::where('ano_mes', $this->anoMesAtual())->value('quantidade'));
    }

    // ---- Validação rejeitada não consome cota ----

    public function test_item_rejeitado_pela_validacao_nao_consome_cota(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        // Sem sugestão persistida — qualquer item enviado é rejeitado pelo FormRequest.

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'item inventado']],
        ])->assertStatus(422);

        $this->assertSame(0, (int) (GeminiUsoMensal::where('ano_mes', $this->anoMesAtual())->value('quantidade') ?? 0));
        $this->assertSame(0, ImovelStagingFotoEdicao::count());
    }

    // ---- Tentativa despachada conta mesmo se falhar ou for rejeitada ----

    public function test_tentativa_que_falha_no_provider_ja_consumiu_cota(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $foto->update(['itens_removiveis_sugeridos' => [
            ['categoria' => 'pessoa', 'descricao' => 'pessoa', 'confianca' => 0.9],
        ]]);

        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => ['message' => 'erro']], 500)]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'pessoa']],
        ])->assertStatus(202);

        $this->assertSame('erro', ImovelStagingFotoEdicao::first()->status);
        $this->assertSame(1, GeminiUsoMensal::where('ano_mes', $this->anoMesAtual())->value('quantidade'));
    }

    public function test_tentativa_rejeitada_pelo_corretor_ja_consumiu_cota(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();

        $edicaoId = $this->solicitarEdicao($imovelStaging, $foto, 'tentativa que sera rejeitada')
            ->assertStatus(202)->json('id');

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes/{$edicaoId}/rejeitar")
            ->assertStatus(200);

        // Rejeitar é uma decisão do corretor DEPOIS da tentativa já ter
        // sido despachada — a cota já foi consumida na criação, não muda.
        $this->assertSame(1, GeminiUsoMensal::where('ano_mes', $this->anoMesAtual())->value('quantidade'));
    }

    // ---- Reserva atômica / consumo auditável ----

    public function test_cota_mensal_e_registrada_de_forma_auditavel(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();

        $this->solicitarEdicao($imovelStaging, $foto, 'tentativa auditavel')->assertStatus(202);

        $registro = GeminiUsoMensal::where('ano_mes', $this->anoMesAtual())->first();
        $this->assertNotNull($registro);
        $this->assertSame(1, $registro->quantidade);
        $this->assertNotNull($registro->updated_at);
    }

    /**
     * SQLite (usado nos testes) não tem conexões concorrentes reais dentro
     * de um único processo PHPUnit, então esta suíte não consegue disparar
     * duas requisições HTTP simultâneas de verdade. O que se verifica aqui
     * é a INVARIANTE que a reserva atômica garante: uma sequência de
     * chamadas exatamente no limite nunca ultrapassa o teto, mesmo
     * chamando o serviço repetidamente sem nenhuma pausa entre as
     * chamadas — o mecanismo é o mesmo lockForUpdate() usado em produção
     * (EdicaoFotoCotaService::reservarEDespachar()), só a concorrência real
     * de conexões que não é exercida por este ambiente de teste.
     */
    public function test_reserva_de_cota_nunca_ultrapassa_o_limite_em_chamadas_consecutivas(): void
    {
        config(['services.gemini.limite_tentativas_por_foto' => 3]);

        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();

        $statusCodes = [];
        for ($i = 1; $i <= 5; $i++) {
            $statusCodes[] = $this->solicitarEdicao($imovelStaging, $foto, "tentativa {$i}")->status();
        }

        $this->assertSame([202, 202, 202, 429, 429], $statusCodes);
        $this->assertSame(3, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $foto->id)->count());
    }

    // ---- Retries internos (falha transitória do Gemini) não consomem cota extra ----

    public function test_retries_internos_por_falha_transitoria_nao_consomem_cota_extra(): void
    {
        $this->partialMock(EdicaoFotoGeminiService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('aguardar')->andReturnNull();
        });

        $imagem = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($imagem);
        $bytesImagem = ob_get_clean();
        imagedestroy($imagem);

        $respostaIndisponivel = ['error' => ['message' => 'This model is currently experiencing high demand.', 'status' => 'UNAVAILABLE']];
        $respostaComImagem = ['candidates' => [['content' => ['parts' => [
            ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode($bytesImagem)]],
        ]]]]];

        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($respostaIndisponivel, 503)
            ->push($respostaIndisponivel, 503)
            ->push($respostaComImagem, 200)]);

        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $foto->update(['itens_removiveis_sugeridos' => [
            ['categoria' => 'pessoa', 'descricao' => 'pessoa', 'confianca' => 0.9],
        ]]);

        $resposta = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos/{$foto->id}/edicoes", [
            'itens' => [['categoria' => 'pessoa', 'descricao' => 'pessoa']],
        ]);
        $resposta->assertStatus(202);

        $edicao = ImovelStagingFotoEdicao::find($resposta->json('id'));
        $this->assertSame('gerada', $edicao->status);

        // 3 chamadas HTTP internas (2 falhas transitórias + sucesso), mas
        // continuam sendo A MESMA tentativa/reserva de cota.
        Http::assertSentCount(3);
        $this->assertSame(1, ImovelStagingFotoEdicao::where('imovel_staging_foto_id', $foto->id)->count());
        $this->assertSame(1, GeminiUsoMensal::where('ano_mes', $this->anoMesAtual())->value('quantidade'));
    }
}
