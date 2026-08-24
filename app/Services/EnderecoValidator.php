<?php

namespace App\Services;

class EnderecoValidator
{
    /**
     * Verifica se o endereço tem o mínimo necessário para seguir para o
     * geocoding sem gastar chamada de API à toa.
     *
     * "confianca" só existe logo após a extração via IA (Etapa 3.1); quando
     * este método é chamado depois, sobre um registro já persistido, a chave
     * não estará presente — ausência de "confianca" não bloqueia, só um
     * valor "baixa" explícito bloqueia.
     *
     * @param  array<string, mixed>  $endereco
     */
    public static function completo(array $endereco): bool
    {
        return ! empty($endereco['logradouro'])
            && ! empty($endereco['bairro'])
            && ! empty($endereco['cidade'])
            && ($endereco['confianca'] ?? null) !== 'baixa';
    }
}
