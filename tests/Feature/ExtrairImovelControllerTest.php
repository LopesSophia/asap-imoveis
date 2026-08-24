<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtrairImovelControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => 'test-key']);
    }

    private function respostaExtracao(array $input): array
    {
        return [
            'content' => [
                ['type' => 'tool_use', 'name' => 'extrair_dados_imovel', 'input' => $input],
            ],
        ];
    }

    private function respostaBusca(?int $ano): array
    {
        return [
            'content' => [
                ['type' => 'text', 'text' => json_encode(['ano_construcao' => $ano])],
            ],
        ];
    }

    public function test_preenche_ano_construcao_automaticamente_quando_extracao_nao_traz_e_busca_encontra(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->respostaExtracao([
                    'tipo_imovel' => 'apartamento',
                    'nome_edificio' => 'Edifício Solar das Flores',
                    'bairro' => 'Vila Regente Feijó',
                    'quartos' => 2,
                    'metragem' => 80,
                ]))
                ->push($this->respostaBusca(2005)),
        ]);

        $response = $this->postJson('/api/extrair-imovel', [
            'texto' => 'apartamento de 80 metros no Edifício Solar das Flores, Vila Regente Feijó, 2 quartos',
        ]);

        $response->assertStatus(200)->assertJsonFragment([
            'ano_construcao' => 2005,
            'nome_edificio' => 'Edifício Solar das Flores',
        ]);

        Http::assertSentCount(2);
    }

    public function test_mantem_ano_construcao_null_quando_busca_nao_encontra_nada_sem_quebrar_o_fluxo(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->respostaExtracao([
                    'tipo_imovel' => 'apartamento',
                    'nome_edificio' => 'Edifício Fictício Sem Registro',
                    'bairro' => 'Vila Regente Feijó',
                    'quartos' => 2,
                ]))
                ->push($this->respostaBusca(null)),
        ]);

        $response = $this->postJson('/api/extrair-imovel', [
            'texto' => 'apartamento de 80 metros no Edifício Solar das Flores, Vila Regente Feijó, 2 quartos',
        ]);

        $response->assertStatus(200)->assertJsonFragment([
            'tipo_imovel' => 'apartamento',
            'quartos' => 2,
        ]);
        $response->assertJsonMissing(['ano_construcao' => 0]);
        $this->assertNull($response->json('ano_construcao'));

        Http::assertSentCount(2);
    }

    public function test_nao_dispara_busca_quando_extracao_ja_traz_ano_construcao(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->respostaExtracao([
                    'tipo_imovel' => 'apartamento',
                    'nome_edificio' => 'Edifício Solar das Flores',
                    'ano_construcao' => 1990,
                ])),
        ]);

        $response = $this->postJson('/api/extrair-imovel', [
            'texto' => 'apartamento no Edifício Solar das Flores, construído em 1990',
        ]);

        $response->assertStatus(200)->assertJsonFragment(['ano_construcao' => 1990]);

        // Só a chamada de extração — a busca de ano nunca é acionada porque
        // ano_construcao já veio preenchido pela fala do corretor.
        Http::assertSentCount(1);
    }

    public function test_nao_dispara_busca_para_tipo_imovel_fora_de_predio(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push($this->respostaExtracao([
                    'tipo_imovel' => 'casa',
                    'bairro' => 'Vila Regente Feijó',
                ])),
        ]);

        $response = $this->postJson('/api/extrair-imovel', [
            'texto' => 'casa na Vila Regente Feijó',
        ]);

        $response->assertStatus(200);

        Http::assertSentCount(1);
    }
}
