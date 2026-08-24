<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnriquecimentoLocalizacaoControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_maps.key' => 'test-key']);
    }

    private function criarRascunho(array $atributos = []): ImovelStaging
    {
        return ImovelStaging::create(array_merge([
            'corretor_id' => User::factory()->create()->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ], $atributos));
    }

    public function test_enriquecer_com_endereco_incompleto_retorna_422_sem_chamar_google(): void
    {
        Http::fake();

        $imovelStaging = $this->criarRascunho(['bairro' => 'Vila Regente Feijó', 'cidade' => 'São Paulo']);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/enriquecer-localizacao");

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_enriquecer_com_endereco_nao_encontrado_retorna_422(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []], 200),
        ]);

        $imovelStaging = $this->criarRascunho([
            'logradouro' => 'Rua Inexistente',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/enriquecer-localizacao");

        $response->assertStatus(422);
        $this->assertNull($imovelStaging->fresh()->localizacao);
    }

    public function test_enriquecer_com_sucesso_persiste_localizacao(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'geometry' => ['location' => ['lat' => -23.5505, 'lng' => -46.6333]],
                    'formatted_address' => 'Rua Serra de Botucatu, 800 - Vila Regente Feijó, São Paulo - SP',
                ]],
            ], 200),
            'places.googleapis.com/*' => Http::response(['places' => []], 200),
        ]);

        $imovelStaging = $this->criarRascunho([
            'logradouro' => 'Rua Serra de Botucatu',
            'numero' => '800',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
        ]);

        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/enriquecer-localizacao");

        $response->assertStatus(200);
        $this->assertNotNull($imovelStaging->fresh()->localizacao);
        $this->assertSame(-23.5505, $imovelStaging->fresh()->localizacao['lat']);
    }

    public function test_segunda_chamada_usa_cache_e_nao_chama_google_de_novo(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'geometry' => ['location' => ['lat' => -23.5505, 'lng' => -46.6333]],
                    'formatted_address' => 'Rua Serra de Botucatu, 800 - Vila Regente Feijó, São Paulo - SP',
                ]],
            ], 200),
            'places.googleapis.com/*' => Http::response(['places' => []], 200),
        ]);

        $imovelStaging = $this->criarRascunho([
            'logradouro' => 'Rua Serra de Botucatu',
            'numero' => '800',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
        ]);

        $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/enriquecer-localizacao")->assertStatus(200);

        Http::fake(); // zera o histórico e passa a falhar qualquer chamada nova
        $response = $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/enriquecer-localizacao");

        $response->assertStatus(200);
        Http::assertNothingSent();
    }

    public function test_editar_endereco_invalida_cache_de_localizacao(): void
    {
        $imovelStaging = $this->criarRascunho([
            'logradouro' => 'Rua Serra de Botucatu',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
            'localizacao' => ['lat' => -23.5505, 'lng' => -46.6333],
        ]);

        $this->putJson("/api/imoveis-staging/{$imovelStaging->id}", [
            'corretor_id' => $imovelStaging->corretor_id,
            'tipo_imovel' => 'apartamento',
            'logradouro' => 'Rua Diferente',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
        ])->assertStatus(200);

        $this->assertNull($imovelStaging->fresh()->localizacao);
    }
}
