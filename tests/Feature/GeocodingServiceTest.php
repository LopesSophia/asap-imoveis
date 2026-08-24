<?php

namespace Tests\Feature;

use App\Exceptions\EnderecoNaoEncontradoException;
use App\Exceptions\EnriquecimentoLocalizacaoException;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodingServiceTest extends TestCase
{
    private array $endereco = [
        'logradouro' => 'Rua Serra de Botucatu',
        'numero' => '800',
        'bairro' => 'Vila Regente Feijó',
        'cidade' => 'São Paulo',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_maps.key' => 'test-key']);
    }

    public function test_status_ok_retorna_lat_lng(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([
                'status' => 'OK',
                'results' => [[
                    'geometry' => ['location' => ['lat' => -23.5507, 'lng' => -46.5678]],
                    'formatted_address' => 'R. Serra de Botucatu, 800 - Vila Regente Feijó, São Paulo - SP, Brasil',
                ]],
            ], 200),
        ]);

        $resultado = app(GeocodingService::class)->geocodificar($this->endereco);

        $this->assertSame(-23.5507, $resultado['lat']);
        $this->assertSame(-46.5678, $resultado['lng']);
        $this->assertStringContainsString('Vila Regente Feijó', $resultado['formatted_address']);
    }

    public function test_zero_results_lanca_excecao_de_endereco_nao_encontrado(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'ZERO_RESULTS', 'results' => []], 200),
        ]);

        $this->expectException(EnderecoNaoEncontradoException::class);

        app(GeocodingService::class)->geocodificar($this->endereco);
    }

    public function test_over_query_limit_lanca_excecao_generica(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response(['status' => 'OVER_QUERY_LIMIT', 'results' => []], 200),
        ]);

        $this->expectException(EnriquecimentoLocalizacaoException::class);
        $this->expectExceptionMessageMatches('/OVER_QUERY_LIMIT/');

        app(GeocodingService::class)->geocodificar($this->endereco);
    }

    public function test_falha_http_lanca_excecao_generica(): void
    {
        Http::fake([
            'maps.googleapis.com/*' => Http::response([], 500),
        ]);

        $this->expectException(EnriquecimentoLocalizacaoException::class);

        app(GeocodingService::class)->geocodificar($this->endereco);
    }
}
