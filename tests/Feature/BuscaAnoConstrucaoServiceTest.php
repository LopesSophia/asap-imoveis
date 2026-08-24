<?php

namespace Tests\Feature;

use App\Services\BuscaAnoConstrucaoService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BuscaAnoConstrucaoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.anthropic.key' => 'test-key']);
    }

    private function respostaComTexto(string $texto): array
    {
        return [
            'content' => [
                ['type' => 'server_tool_use', 'name' => 'web_search', 'input' => ['query' => 'irrelevante']],
                ['type' => 'web_search_tool_result', 'content' => []],
                ['type' => 'text', 'text' => $texto],
            ],
        ];
    }

    public function test_nao_busca_para_tipo_imovel_fora_de_predio(): void
    {
        Http::fake();

        $ano = app(BuscaAnoConstrucaoService::class)->buscar([
            'tipo_imovel' => 'casa',
            'ano_construcao' => null,
            'bairro' => 'Vila Regente Feijó',
        ]);

        $this->assertNull($ano);
        Http::assertNothingSent();
    }

    public function test_nao_busca_quando_ano_construcao_ja_preenchido(): void
    {
        Http::fake();

        $ano = app(BuscaAnoConstrucaoService::class)->buscar([
            'tipo_imovel' => 'apartamento',
            'ano_construcao' => 2010,
            'bairro' => 'Vila Regente Feijó',
        ]);

        $this->assertSame(null, $ano);
        Http::assertNothingSent();
    }

    public function test_nao_busca_sem_nenhum_dado_de_localizacao(): void
    {
        Http::fake();

        $ano = app(BuscaAnoConstrucaoService::class)->buscar([
            'tipo_imovel' => 'apartamento',
            'ano_construcao' => null,
        ]);

        $this->assertNull($ano);
        Http::assertNothingSent();
    }

    public function test_encontra_ano_com_sucesso_priorizando_nome_edificio(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                $this->respostaComTexto('{"ano_construcao": 1998}'),
                200
            ),
        ]);

        $ano = app(BuscaAnoConstrucaoService::class)->buscar([
            'tipo_imovel' => 'apartamento',
            'ano_construcao' => null,
            'nome_edificio' => 'Edifício Solar das Flores',
            'bairro' => 'Vila Regente Feijó',
        ]);

        $this->assertSame(1998, $ano);

        Http::assertSent(function ($request) {
            return str_contains($request['messages'][0]['content'], 'Edifício Solar das Flores')
                && str_contains($request['messages'][0]['content'], 'Vila Regente Feijó');
        });
    }

    public function test_retorna_null_quando_busca_nao_encontra_fonte_confiavel(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                $this->respostaComTexto('{"ano_construcao": null}'),
                200
            ),
        ]);

        $ano = app(BuscaAnoConstrucaoService::class)->buscar([
            'tipo_imovel' => 'cobertura',
            'ano_construcao' => null,
            'nome_edificio' => 'Edifício Fictício Sem Registro Nenhum',
            'bairro' => 'Vila Regente Feijó',
        ]);

        $this->assertNull($ano);
    }

    public function test_falha_http_retorna_null_sem_lancar_excecao(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([], 500),
        ]);

        $ano = app(BuscaAnoConstrucaoService::class)->buscar([
            'tipo_imovel' => 'apartamento',
            'ano_construcao' => null,
            'bairro' => 'Vila Regente Feijó',
        ]);

        $this->assertNull($ano);
    }

    public function test_resposta_sem_json_valido_retorna_null_sem_lancar_excecao(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                $this->respostaComTexto('não encontrei nada sobre isso'),
                200
            ),
        ]);

        $ano = app(BuscaAnoConstrucaoService::class)->buscar([
            'tipo_imovel' => 'apartamento',
            'ano_construcao' => null,
            'bairro' => 'Vila Regente Feijó',
        ]);

        $this->assertNull($ano);
    }

    public function test_usa_fallback_de_endereco_quando_sem_nome_de_edificio(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                $this->respostaComTexto('{"ano_construcao": 2005}'),
                200
            ),
        ]);

        $ano = app(BuscaAnoConstrucaoService::class)->buscar([
            'tipo_imovel' => 'apartamento',
            'ano_construcao' => null,
            'logradouro' => 'Rua Serra de Botucatu',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
        ]);

        $this->assertSame(2005, $ano);

        Http::assertSent(function ($request) {
            return str_contains($request['messages'][0]['content'], 'Rua Serra de Botucatu')
                && str_contains($request['messages'][0]['content'], 'prédio');
        });
    }
}
