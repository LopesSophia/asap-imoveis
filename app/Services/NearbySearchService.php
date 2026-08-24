<?php

namespace App\Services;

use App\Exceptions\EnriquecimentoLocalizacaoException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NearbySearchService
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/places:searchNearby';

    private const FIELD_MASK = 'places.displayName,places.location,places.formattedAddress';

    /**
     * Uma chamada por categoria — nunca agrupar tipos diferentes na mesma
     * chamada, senão a contagem por categoria (COMÉRCIOS PRÓXIMOS) fica
     * incorreta (ver nota no prompt de especificação da Etapa 3).
     */
    private const CATEGORIAS = [
        'shoppings' => ['tipos' => ['shopping_mall'], 'raio' => 5000, 'max' => 5],
        'padarias' => ['tipos' => ['bakery'], 'raio' => 3000, 'max' => 20],
        'farmacias' => ['tipos' => ['pharmacy'], 'raio' => 3000, 'max' => 20],
        'mercados' => ['tipos' => ['supermarket'], 'raio' => 3000, 'max' => 20],
        'academias' => ['tipos' => ['gym'], 'raio' => 3000, 'max' => 20],
        'lazer' => ['tipos' => ['park', 'movie_theater', 'tourist_attraction'], 'raio' => 3000, 'max' => 10],
        'educacao' => ['tipos' => ['school', 'university'], 'raio' => 3000, 'max' => 10],
        'metro' => ['tipos' => ['subway_station'], 'raio' => 2000, 'max' => 3],
    ];

    /**
     * Roda as 8 buscas por categoria e monta a seção "localizacao" do schema.
     * Uma categoria falhando não derruba as demais nem o enriquecimento
     * inteiro — "nunca travar o cadastro por causa do enriquecimento".
     *
     * @return array<string, mixed>
     */
    public function buscar(float $lat, float $lng, ?string $logradouroPropriedade = null): array
    {
        $apiKey = config('services.google_maps.key');

        if (empty($apiKey)) {
            throw new EnriquecimentoLocalizacaoException('GOOGLE_MAPS_API_KEY não está configurada no .env.');
        }

        $resultadosPorCategoria = [];

        foreach (self::CATEGORIAS as $nomeCategoria => $config) {
            try {
                $resultadosPorCategoria[$nomeCategoria] = $this->buscarCategoria(
                    $config['tipos'], $config['raio'], $config['max'], $lat, $lng, $apiKey
                );
            } catch (\Throwable $e) {
                Log::error("NearbySearchService: falha ao buscar categoria \"{$nomeCategoria}\".", [
                    'mensagem' => $e->getMessage(),
                    'exception' => $e,
                ]);

                $resultadosPorCategoria[$nomeCategoria] = [];
            }
        }

        foreach ($resultadosPorCategoria as &$resultados) {
            foreach ($resultados as &$item) {
                $item['distancia_km'] = self::distanciaKm($lat, $lng, $item['lat'], $item['lng']);
            }
            usort($resultados, fn ($a, $b) => $a['distancia_km'] <=> $b['distancia_km']);
        }
        unset($resultados, $item);

        return [
            'lat' => $lat,
            'lng' => $lng,
            'shoppings' => $this->paraListaSimples($resultadosPorCategoria['shoppings']),
            'comercios' => [
                'padarias' => count($resultadosPorCategoria['padarias']),
                'farmacias' => count($resultadosPorCategoria['farmacias']),
                'mercados' => count($resultadosPorCategoria['mercados']),
                'academias' => count($resultadosPorCategoria['academias']),
            ],
            'lazer' => $this->paraListaSimples($resultadosPorCategoria['lazer']),
            'educacao' => $this->paraListaSimples($resultadosPorCategoria['educacao']),
            'metro' => $resultadosPorCategoria['metro'] === [] ? null : $this->paraListaSimples($resultadosPorCategoria['metro'])[0],
            'vias_acesso' => $this->inferirViasDeAcesso($resultadosPorCategoria, $logradouroPropriedade),
        ];
    }

    /**
     * @param  string[]  $tipos
     * @return array<int, array{nome: string, lat: float, lng: float, formatted_address: string}>
     */
    private function buscarCategoria(array $tipos, int $raioMetros, int $maxResultCount, float $lat, float $lng, string $apiKey): array
    {
        $resposta = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Goog-Api-Key' => $apiKey,
            'X-Goog-FieldMask' => self::FIELD_MASK,
        ])->post(self::ENDPOINT, [
            'includedTypes' => $tipos,
            'maxResultCount' => $maxResultCount,
            'locationRestriction' => [
                'circle' => [
                    'center' => ['latitude' => $lat, 'longitude' => $lng],
                    'radius' => $raioMetros,
                ],
            ],
        ]);

        if ($resposta->failed()) {
            throw new EnriquecimentoLocalizacaoException(
                "Falha na Nearby Search (HTTP {$resposta->status()}): ".($resposta->json('error.message') ?? $resposta->body())
            );
        }

        return collect($resposta->json('places', []))->map(fn (array $lugar) => [
            'nome' => $lugar['displayName']['text'] ?? 'Sem nome',
            'lat' => (float) $lugar['location']['latitude'],
            'lng' => (float) $lugar['location']['longitude'],
            'formatted_address' => $lugar['formattedAddress'] ?? '',
        ])->all();
    }

    /**
     * @param  array<int, array{nome: string, distancia_km: float}>  $resultados
     * @return array<int, array{nome: string, distancia_km: float}>
     */
    private function paraListaSimples(array $resultados): array
    {
        return array_map(fn ($item) => [
            'nome' => $item['nome'],
            'distancia_km' => $item['distancia_km'],
        ], $resultados);
    }

    /**
     * Heurística simples (sem Roads API): usa o primeiro trecho do
     * formattedAddress de cada lugar próximo como um nome de via provável,
     * deduplicado e limitado a 5 — ver pendência registrada na especificação
     * da Etapa 3 sobre trocar isso pela Roads API se a precisão não bastar.
     *
     * @param  array<string, array<int, array{formatted_address: string}>>  $resultadosPorCategoria
     */
    private function inferirViasDeAcesso(array $resultadosPorCategoria, ?string $logradouroPropriedade): array
    {
        $vias = [];

        foreach ($resultadosPorCategoria as $resultados) {
            foreach ($resultados as $item) {
                $via = trim(explode(',', $item['formatted_address'] ?? '')[0] ?? '');

                if ($via === '' || ($logradouroPropriedade && str_contains(mb_strtolower($via), mb_strtolower($logradouroPropriedade)))) {
                    continue;
                }

                $vias[$via] = true;
            }
        }

        return array_slice(array_keys($vias), 0, 5);
    }

    private static function distanciaKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $raioTerraKm = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($raioTerraKm * $c, 2);
    }
}
