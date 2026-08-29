<?php

namespace Tests\Feature;

use App\Exceptions\EnriquecimentoLocalizacaoException;
use App\Services\ExtracaoEnderecoService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExtracaoEnderecoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => 'test-key']);
    }

    public function test_extrai_endereco_mesmo_quando_resposta_vem_com_cerca_de_markdown(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => "```json\n{\"logradouro\":\"Rua Serra de Botucatu\",\"numero\":\"800\",\"bairro\":\"Vila Regente Feijó\",\"cidade\":\"São Paulo\",\"cep\":null,\"complemento\":null,\"confianca\":\"alta\"}\n```",
                ]],
            ], 200),
        ]);

        $endereco = app(ExtracaoEnderecoService::class)->extrair('rua serra de botucatu, 800, vila regente feijó');

        $this->assertSame('Rua Serra de Botucatu', $endereco['logradouro']);
        $this->assertSame('alta', $endereco['confianca']);
    }

    /**
     * Regressão do Bug 2: o schema do prompt não tinha "estado" — o campo
     * nunca era extraído, ficando sempre null e bloqueando finalizar()
     * mesmo com o endereço completo. O serviço em si é um pass-through do
     * JSON da IA (não filtra campos), então isto trava o contrato: se a IA
     * retornar "estado", ele tem que chegar inteiro até quem chamou.
     */
    public function test_extrai_estado_quando_presente_na_resposta(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [[
                    'type' => 'text',
                    'text' => '{"logradouro":"Rua Vergueiro","numero":"1000","bairro":"Vila Mariana","cidade":"São Paulo","estado":"SP","cep":"04101-000","complemento":"apartamento 52","confianca":"alta"}',
                ]],
            ], 200),
        ]);

        $endereco = app(ExtracaoEnderecoService::class)->extrair(
            'Rua Vergueiro, 1000, apartamento 52, Vila Mariana, São Paulo - SP, CEP 04101-000'
        );

        $this->assertSame('SP', $endereco['estado']);
        $this->assertSame('São Paulo', $endereco['cidade']);
    }

    public function test_falha_da_api_lanca_excecao_clara(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'algo deu errado']], 500),
        ]);

        $this->expectException(EnriquecimentoLocalizacaoException::class);

        app(ExtracaoEnderecoService::class)->extrair('qualquer texto');
    }

    public function test_resposta_sem_json_valido_lanca_excecao_clara(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'isso não é json']],
            ], 200),
        ]);

        $this->expectException(EnriquecimentoLocalizacaoException::class);

        app(ExtracaoEnderecoService::class)->extrair('qualquer texto');
    }
}
