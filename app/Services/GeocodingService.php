<?php

namespace App\Services;

use App\Exceptions\EnderecoNaoEncontradoException;
use App\Exceptions\EnriquecimentoLocalizacaoException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeocodingService
{
    private const ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Geocodifica um endereço estruturado (logradouro/numero/bairro/cidade) em lat/lng.
     *
     * @param  array<string, mixed>  $endereco
     * @return array{lat: float, lng: float, formatted_address: string}
     */
    public function geocodificar(array $endereco): array
    {
        $apiKey = config('services.google_maps.key');

        if (empty($apiKey)) {
            throw new EnriquecimentoLocalizacaoException('GOOGLE_MAPS_API_KEY não está configurada no .env.');
        }

        $resposta = Http::get(self::ENDPOINT, [
            'address' => $this->montarEnderecoParaConsulta($endereco),
            'key' => $apiKey,
        ]);

        if ($resposta->failed()) {
            Log::error('GeocodingService: falha de infraestrutura ao chamar a Geocoding API.', [
                'status' => $resposta->status(),
                'body' => $resposta->body(),
            ]);

            throw new EnriquecimentoLocalizacaoException(
                "Falha ao chamar a Geocoding API (HTTP {$resposta->status()})."
            );
        }

        $status = $resposta->json('status');

        if ($status === 'ZERO_RESULTS') {
            throw new EnderecoNaoEncontradoException('Não foi possível localizar esse endereço no Google Maps.');
        }

        if ($status !== 'OK') {
            Log::error('GeocodingService: status inesperado da Geocoding API.', [
                'status' => $status,
                'body' => $resposta->json(),
            ]);

            throw new EnriquecimentoLocalizacaoException("Geocoding API retornou status \"{$status}\".");
        }

        $resultado = $resposta->json('results.0');

        if (! $resultado) {
            throw new EnderecoNaoEncontradoException('A Geocoding API não retornou nenhum resultado para esse endereço.');
        }

        return [
            'lat' => (float) $resultado['geometry']['location']['lat'],
            'lng' => (float) $resultado['geometry']['location']['lng'],
            'formatted_address' => $resultado['formatted_address'] ?? '',
        ];
    }

    /**
     * @param  array<string, mixed>  $endereco
     */
    private function montarEnderecoParaConsulta(array $endereco): string
    {
        $logradouroComNumero = trim($endereco['logradouro'].(! empty($endereco['numero']) ? ', '.$endereco['numero'] : ''));
        $estado = $endereco['estado'] ?? 'SP';

        return "{$logradouroComNumero} - {$endereco['bairro']}, {$endereco['cidade']} - {$estado}, Brasil";
    }
}
