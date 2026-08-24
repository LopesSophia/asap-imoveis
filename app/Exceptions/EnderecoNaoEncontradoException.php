<?php

namespace App\Exceptions;

/**
 * Geocoding retornou ZERO_RESULTS: diferente de uma falha de infraestrutura,
 * este caso precisa da atenção do corretor (endereço errado/incompleto),
 * então é tratado separadamente para virar um 422 acionável, não um 500 logado.
 */
class EnderecoNaoEncontradoException extends EnriquecimentoLocalizacaoException
{
    //
}
