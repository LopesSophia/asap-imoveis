<?php

namespace App\Services;

class EnriquecimentoLocalizacaoService
{
    public function __construct(
        private readonly GeocodingService $geocodingService,
        private readonly NearbySearchService $nearbySearchService,
    ) {}

    /**
     * Orquestra as etapas 3-6: geocoding, nearby search (8 categorias),
     * cálculo de distância e montagem do schema "localizacao". A validação
     * de completude (etapa 2) é responsabilidade de quem chama, antes disso,
     * para não gastar nenhuma chamada de API num endereço incompleto.
     *
     * @param  array<string, mixed>  $endereco
     * @return array<string, mixed>
     */
    public function enriquecer(array $endereco): array
    {
        $geocode = $this->geocodingService->geocodificar($endereco);

        $localizacao = $this->nearbySearchService->buscar(
            $geocode['lat'],
            $geocode['lng'],
            $endereco['logradouro'] ?? null
        );

        $localizacao['formatted_address'] = $geocode['formatted_address'];

        return $localizacao;
    }
}
