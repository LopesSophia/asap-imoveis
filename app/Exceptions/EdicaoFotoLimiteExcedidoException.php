<?php

namespace App\Exceptions;

/**
 * Lançada quando um dos três limitadores de custo da edição de fotos
 * (por foto, por imóvel, ou mensal/global) foi atingido — a reserva de
 * cota (EdicaoFotoCotaService) nunca chega a criar a tentativa nem a
 * despachar o job nesse caso.
 */
class EdicaoFotoLimiteExcedidoException extends EdicaoFotoException
{
    public const FOTO = 'foto';

    public const IMOVEL = 'imovel';

    public const MENSAL = 'mensal';

    private const MENSAGENS = [
        self::FOTO => 'Esta foto já atingiu o limite de tentativas de edição.',
        self::IMOVEL => 'Este imóvel já atingiu o limite de tentativas de edição de fotos.',
        self::MENSAL => 'O limite mensal de edições de fotos foi atingido. Tente novamente no próximo mês.',
    ];

    public readonly string $tipo;

    public function __construct(string $tipo)
    {
        $this->tipo = $tipo;

        parent::__construct(self::MENSAGENS[$tipo] ?? 'Limite de edições de fotos atingido.');
    }
}
