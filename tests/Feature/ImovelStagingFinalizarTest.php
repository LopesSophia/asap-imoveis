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

    private function enderecoCompletoParaFinalizar(): array
    {
        return [
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'cep' => '04101-000',
            'estado' => 'SP',
        ];
    }

    public function test_finalizar_com_analise_valida_conclui_o_cadastro_sem_chamar_a_ia(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(25);
        $imovelStaging->update(array_merge($this->enderecoCompletoParaFinalizar(), [
            'fotos_analisadas_em' => now(),
            'titulo_site' => 'Apartamento 2 quartos na Vila Mariana',
            'descricao_gerada' => 'Descrição gerada de teste.',
        ]));

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar");

        $response->assertStatus(200)->assertJsonFragment(['status_propagacao' => 'pendente']);

        // finalizar() nunca chama a IA — isso é só responsabilidade de analisar-fotos().
        Http::assertNothingSent();
        $this->assertSame('pendente', $imovelStaging->fresh()->status_propagacao);
    }

    // ---- Endereço completo é obrigatório para concluir ----
    // A mensagem lista SÓ os campos realmente ausentes (regressão do Bug 2:
    // antes a mensagem era uma lista genérica fixa, citando até campos que
    // já estavam preenchidos).

    public function test_finalizar_bloqueado_com_endereco_incompleto_lista_so_os_campos_ausentes(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(25);
        $imovelStaging->update([
            'fotos_analisadas_em' => now(),
            'titulo_site' => 'Título',
            'descricao_gerada' => 'Descrição',
            // Endereço deliberadamente incompleto: falta CEP e estado.
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar");

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'Endereço incompleto: preencha CEP, estado antes de concluir.',
        ]);
        $this->assertSame('rascunho', $imovelStaging->fresh()->status_propagacao);
    }

    /**
     * Reproduz EXATAMENTE o cenário relatado no Bug 2: logradouro, número,
     * complemento e bairro preenchidos (via extração + revisão); cidade,
     * CEP e estado ausentes (a extração de endereço não tinha "estado" no
     * schema, e o merge no frontend podia zerar "cidade"). A mensagem
     * precisa citar SÓ esses três campos.
     */
    public function test_finalizar_bloqueado_reproduzindo_o_cenario_relatado_no_bug_2(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(25);
        $imovelStaging->update([
            'fotos_analisadas_em' => now(),
            'titulo_site' => 'Título',
            'descricao_gerada' => 'Descrição',
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'complemento' => 'apartamento 52',
            'bairro' => 'Vila Mariana',
            'cidade' => null,
            'cep' => null,
            'estado' => null,
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar");

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'Endereço incompleto: preencha cidade, CEP, estado antes de concluir.',
        ]);
    }

    /**
     * Regressão do Bug 2 ponta a ponta: cria o rascunho só com os campos
     * básicos (como o POST inicial faz), depois faz um PUT separado com o
     * endereço completo (como a revisão humana faz ao salvar), confirma
     * que os 8 campos foram PERSISTIDOS de verdade no banco (não só
     * aceitos na resposta), e só então finaliza com sucesso.
     */
    public function test_endereco_completo_persiste_via_put_e_permite_finalizar(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(25);

        $respostaUpdate = $this->putJson("/api/imoveis-staging/{$imovelStaging->id}", [
            'corretor_id' => $imovelStaging->corretor_id,
            'tipo_imovel' => 'apartamento',
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'sem_numero' => false,
            'complemento' => 'apartamento 52',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'estado' => 'SP',
            'cep' => '04101-000',
        ]);

        $respostaUpdate->assertStatus(200)->assertJsonFragment([
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'sem_numero' => false,
            'complemento' => 'apartamento 52',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'estado' => 'SP',
            'cep' => '04101-000',
        ]);

        // Persistência de verdade no banco, não só o retorno da request.
        $this->assertDatabaseHas('imovel_stagings', [
            'id' => $imovelStaging->id,
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'sem_numero' => false,
            'complemento' => 'apartamento 52',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'estado' => 'SP',
            'cep' => '04101-000',
        ]);

        $imovelStaging->update([
            'fotos_analisadas_em' => now(),
            'titulo_site' => 'Apartamento 2 quartos na Vila Mariana',
            'descricao_gerada' => 'Descrição gerada de teste.',
        ]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar")
            ->assertStatus(200)
            ->assertJsonFragment(['status_propagacao' => 'pendente']);
    }

    public function test_finalizar_aceita_endereco_sem_numero_quando_marcado_explicitamente(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(25);
        $imovelStaging->update(array_merge($this->enderecoCompletoParaFinalizar(), [
            'numero' => null,
            'sem_numero' => true,
            'fotos_analisadas_em' => now(),
            'titulo_site' => 'Título',
            'descricao_gerada' => 'Descrição',
        ]));

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar")
            ->assertStatus(200);
    }

    // ---- Título e descrição são obrigatórios para concluir ----

    public function test_finalizar_bloqueado_sem_titulo_ou_descricao(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunhoComFotos(25);
        $imovelStaging->update(array_merge($this->enderecoCompletoParaFinalizar(), [
            'fotos_analisadas_em' => now(),
            'titulo_site' => null,
            'descricao_gerada' => null,
        ]));

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/finalizar");

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'Título e descrição do anúncio são obrigatórios para concluir o cadastro.',
        ]);
        $this->assertSame('rascunho', $imovelStaging->fresh()->status_propagacao);
    }
}
