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
