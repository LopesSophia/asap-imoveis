<?php

namespace Tests\Feature;

use App\Services\NearbySearchService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NearbySearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google_maps.key' => 'test-key']);
    }

    private function lugar(string $nome, float $lat, float $lng, string $endereco): array
    {
        return [
            'displayName' => ['text' => $nome],
            'location' => ['latitude' => $lat, 'longitude' => $lng],
            'formattedAddress' => $endereco,
        ];
    }

    public function test_monta_localizacao_com_contagens_listas_e_distancias(): void
    {
        // Propriedade em -23.5505,-46.6333. Um "lugar" 0.01 grau ao sul
        // (~1.11km de distância real) é usado em todas as categorias fake
        // só para ter um valor de distância verificável.
        $lat = -23.5505;
        $lng = -46.6333;

        // Ordem das chamadas segue CATEGORIAS: shoppings, padarias, farmacias,
        // mercados, academias, lazer, educacao, metro.
        Http::fake([
            'places.googleapis.com/*' => Http::sequence()
                ->push(['places' => [$this->lugar('Shopping Teste', -23.5605, $lng, 'Av. Teste, 1 - Bairro, São Paulo - SP')]])
                ->push(['places' => array_fill(0, 7, $this->lugar('Padaria', -23.5605, $lng, 'Rua das Padarias, 2 - Bairro, São Paulo - SP'))])
                ->push(['places' => array_fill(0, 3, $this->lugar('Farmácia', -23.5605, $lng, 'Rua das Farmácias, 3 - Bairro, São Paulo - SP'))])
                ->push(['places' => array_fill(0, 2, $this->lugar('Mercado', -23.5605, $lng, 'Rua dos Mercados, 4 - Bairro, São Paulo - SP'))])
                ->push(['places' => array_fill(0, 1, $this->lugar('Academia', -23.5605, $lng, 'Rua das Academias, 5 - Bairro, São Paulo - SP'))])
                ->push(['places' => [$this->lugar('Parque Teste', -23.5605, $lng, 'Rua do Parque, 6 - Bairro, São Paulo - SP')]])
                ->push(['places' => [$this->lugar('Escola Teste', -23.5605, $lng, 'Rua da Escola, 7 - Bairro, São Paulo - SP')]])
                ->push(['places' => [$this->lugar('Estação Teste', -23.5605, $lng, 'Rua da Estação, 8 - Bairro, São Paulo - SP')]]),
        ]);

        $resultado = app(NearbySearchService::class)->buscar($lat, $lng);

        $this->assertSame($lat, $resultado['lat']);
        $this->assertSame($lng, $resultado['lng']);

        $this->assertCount(1, $resultado['shoppings']);
        $this->assertSame('Shopping Teste', $resultado['shoppings'][0]['nome']);
        $this->assertEqualsWithDelta(1.11, $resultado['shoppings'][0]['distancia_km'], 0.05);

        $this->assertSame([
            'padarias' => 7,
            'farmacias' => 3,
            'mercados' => 2,
            'academias' => 1,
        ], $resultado['comercios']);

        $this->assertCount(1, $resultado['lazer']);
        $this->assertCount(1, $resultado['educacao']);

        $this->assertNotNull($resultado['metro']);
        $this->assertSame('Estação Teste', $resultado['metro']['nome']);

        $this->assertNotEmpty($resultado['vias_acesso']);
    }

    public function test_uma_categoria_falhando_nao_derruba_as_outras(): void
    {
        $lat = -23.5505;
        $lng = -46.6333;

        Http::fake([
            'places.googleapis.com/*' => Http::sequence()
                ->push(['places' => []], 500) // shoppings falha
                ->push(['places' => [$this->lugar('Padaria', -23.5605, $lng, 'Rua X, 1 - Bairro, São Paulo - SP')]])
                ->push(['places' => []])
                ->push(['places' => []])
                ->push(['places' => []])
                ->push(['places' => []])
                ->push(['places' => []])
                ->push(['places' => []]),
        ]);

        $resultado = app(NearbySearchService::class)->buscar($lat, $lng);

        $this->assertSame([], $resultado['shoppings']);
        $this->assertSame(1, $resultado['comercios']['padarias']);
        $this->assertNull($resultado['metro']);
    }
}
