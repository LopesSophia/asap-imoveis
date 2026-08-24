<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImovelStagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_post_creates_imovel_staging_record(): void
    {
        $corretor = User::factory()->create();

        $payload = [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'negociacao' => 'venda',
            'bairro' => 'Moema',
            'cidade' => 'São Paulo',
            'metragem' => 75.5,
            'quartos' => 2,
            'vagas' => 1,
            'valor' => 650000,
            'diferenciais' => ['portaria', 'garagem'],
        ];

        $response = $this->postJson('/api/imoveis-staging', $payload);

        $response->assertStatus(201)
            ->assertJsonFragment([
                'tipo_imovel' => 'apartamento',
                'status_propagacao' => 'rascunho',
            ]);

        $this->assertDatabaseHas('imovel_stagings', [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ]);
    }

    public function test_put_updates_existing_draft_without_changing_status(): void
    {
        $corretor = User::factory()->create();

        $imovelStaging = ImovelStaging::create([
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ]);

        $response = $this->putJson("/api/imoveis-staging/{$imovelStaging->id}", [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'casa',
            'bairro' => 'Tatuapé',
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'tipo_imovel' => 'casa',
                'bairro' => 'Tatuapé',
                'status_propagacao' => 'rascunho',
            ]);

        $this->assertDatabaseHas('imovel_stagings', [
            'id' => $imovelStaging->id,
            'tipo_imovel' => 'casa',
            'status_propagacao' => 'rascunho',
        ]);
    }

    public function test_valid_post_persiste_estado_conservacao_vagas_cobertura_e_nome_edificio(): void
    {
        $corretor = User::factory()->create();

        $payload = [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'estado_conservacao' => 'reformado',
            'vagas_cobertura' => 'coberta',
            'nome_edificio' => 'Edifício Villa Real',
        ];

        $response = $this->postJson('/api/imoveis-staging', $payload);

        $response->assertStatus(201)->assertJsonFragment([
            'estado_conservacao' => 'reformado',
            'vagas_cobertura' => 'coberta',
            'nome_edificio' => 'Edifício Villa Real',
        ]);

        $this->assertDatabaseHas('imovel_stagings', [
            'estado_conservacao' => 'reformado',
            'vagas_cobertura' => 'coberta',
            'nome_edificio' => 'Edifício Villa Real',
        ]);
    }

    public function test_estado_conservacao_invalido_retorna_erro_de_validacao(): void
    {
        $corretor = User::factory()->create();

        $response = $this->postJson('/api/imoveis-staging', [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'estado_conservacao' => 'impecavel',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['estado_conservacao']);
    }

    public function test_iptu_isento_persiste_com_iptu_null(): void
    {
        $corretor = User::factory()->create();

        $response = $this->postJson('/api/imoveis-staging', [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'iptu_isento' => true,
            'iptu' => null,
        ]);

        $response->assertStatus(201)->assertJsonFragment([
            'iptu_isento' => true,
            'iptu' => null,
        ]);

        $this->assertDatabaseHas('imovel_stagings', [
            'iptu_isento' => true,
            'iptu' => null,
        ]);
    }

    public function test_iptu_isento_default_false_quando_nao_informado(): void
    {
        $corretor = User::factory()->create();

        $imovelStaging = ImovelStaging::create([
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ]);

        $this->assertFalse($imovelStaging->fresh()->iptu_isento);
    }

    public function test_iptu_isento_true_com_valor_de_iptu_preenchido_retorna_422(): void
    {
        $corretor = User::factory()->create();

        $response = $this->postJson('/api/imoveis-staging', [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'iptu_isento' => true,
            'iptu' => 500,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['iptu']);

        $this->assertDatabaseCount('imovel_stagings', 0);
    }

    public function test_iptu_isento_false_com_valor_de_iptu_preenchido_e_valido(): void
    {
        $corretor = User::factory()->create();

        $response = $this->postJson('/api/imoveis-staging', [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'iptu_isento' => false,
            'iptu' => 350.50,
        ]);

        $response->assertStatus(201)->assertJsonFragment([
            'iptu_isento' => false,
        ]);
    }

    public function test_update_para_iptu_isento_com_iptu_preenchido_tambem_e_rejeitado(): void
    {
        $corretor = User::factory()->create();

        $imovelStaging = ImovelStaging::create([
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'iptu' => 300,
            'status_propagacao' => 'rascunho',
        ]);

        $response = $this->putJson("/api/imoveis-staging/{$imovelStaging->id}", [
            'corretor_id' => $corretor->id,
            'tipo_imovel' => 'apartamento',
            'iptu' => 300,
            'iptu_isento' => true,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['iptu']);

        $this->assertDatabaseHas('imovel_stagings', [
            'id' => $imovelStaging->id,
            'iptu' => 300,
            'iptu_isento' => false,
        ]);
    }

    public function test_post_without_tipo_imovel_returns_validation_error(): void
    {
        $corretor = User::factory()->create();

        $payload = [
            'corretor_id' => $corretor->id,
            'bairro' => 'Moema',
        ];

        $response = $this->postJson('/api/imoveis-staging', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tipo_imovel']);

        $this->assertDatabaseCount('imovel_stagings', 0);
    }
}
