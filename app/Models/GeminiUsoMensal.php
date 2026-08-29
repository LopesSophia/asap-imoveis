<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro auditável de consumo mensal da API do Gemini — uma linha por
 * mês calendário ("YYYY-MM"). Ver EdicaoFotoCotaService para a reserva
 * atômica que incrementa "quantidade".
 */
#[Fillable(['ano_mes', 'quantidade'])]
class GeminiUsoMensal extends Model
{
    protected $table = 'gemini_uso_mensal';
}
