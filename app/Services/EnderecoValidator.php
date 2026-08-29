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

    /**
     * Completude exigida para CONCLUIR o cadastro (finalizar()) e para a
     * entrega ao Prontos — mais rígida que completo(): também exige CEP e
     * estado, e número só é dispensado quando sem_numero está marcado
     * explicitamente (nunca por omissão). Nunca inventa/assume um endereço
     * incompleto ou não confirmado pelo corretor.
     *
     * @param  array<string, mixed>  $endereco
     */
    public static function completoParaConclusao(array $endereco): bool
    {
        return self::camposFaltantesParaConclusao($endereco) === [];
    }

    /**
     * Lista, em português, SOMENTE os campos que estão realmente faltando
     * para concluir o cadastro — nunca uma lista genérica fixa. Usada para
     * montar uma mensagem de erro precisa (finalizar() nunca deve dizer que
     * "cidade" está faltando se cidade já está preenchida).
     *
     * @param  array<string, mixed>  $endereco
     * @return string[]
     */
    public static function camposFaltantesParaConclusao(array $endereco): array
    {
        $faltando = [];

        if (empty($endereco['logradouro'])) {
            $faltando[] = 'logradouro';
        }

        if (empty($endereco['numero']) && empty($endereco['sem_numero'])) {
            $faltando[] = 'número (ou marque "sem número")';
        }

        if (empty($endereco['bairro'])) {
            $faltando[] = 'bairro';
        }

        if (empty($endereco['cidade'])) {
            $faltando[] = 'cidade';
        }

        if (empty($endereco['cep'])) {
            $faltando[] = 'CEP';
        }

        if (empty($endereco['estado'])) {
            $faltando[] = 'estado';
        }

        if (($endereco['confianca'] ?? null) === 'baixa') {
            $faltando[] = 'confirmação do endereço (confiança da extração ficou baixa — revise manualmente)';
        }

        return $faltando;
    }
}
