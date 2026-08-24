<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'corretor_id',
    'tipo_imovel',
    'negociacao',
    'utilizacao',
    'bairro',
    'logradouro',
    'numero',
    'cidade',
    'cep',
    'complemento',
    'metragem',
    'quartos',
    'suites',
    'banheiros',
    'vagas',
    'valor',
    'condominio',
    'iptu',
    'iptu_isento',
    'andar',
    'ano_construcao',
    'em_condominio',
    'reformado',
    'estado_conservacao',
    'vagas_cobertura',
    'mobiliado',
    'nome_edificio',
    'chaves',
    'diferenciais',
    'diferenciais_outros',
    'diferenciais_fotos',
    'diferenciais_outros_fotos',
    'observacoes_visuais',
    'alertas_fotos',
    'fotos_analisadas_em',
    'foto_capa_sugerida_id',
    'foto_capa_motivo',
    'foto_capa_id',
    'titulo_site',
    'descricao_gerada',
    'observacoes_corretor',
    'localizacao',
    'status_propagacao',
    'criado_em',
])]
class ImovelStaging extends Model
{
    use HasFactory;

    // Calculados a cada serialização, nunca persistidos — a revisão final
    // exibe a união sem duplicidades entre o que veio da fala/digitação
    // (diferenciais) e o que a análise de fotos detectou (diferenciais_fotos),
    // sem que isso signifique mesclar os dados de origem de verdade.
    protected $appends = ['diferenciais_uniao', 'diferenciais_outros_uniao'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metragem' => 'decimal:2',
            'valor' => 'decimal:2',
            'condominio' => 'decimal:2',
            'iptu' => 'decimal:2',
            'em_condominio' => 'boolean',
            'reformado' => 'boolean',
            'mobiliado' => 'boolean',
            'iptu_isento' => 'boolean',
            'diferenciais' => 'array',
            'diferenciais_outros' => 'array',
            'diferenciais_fotos' => 'array',
            'diferenciais_outros_fotos' => 'array',
            'observacoes_visuais' => 'array',
            'alertas_fotos' => 'array',
            'localizacao' => 'array',
            'criado_em' => 'datetime',
            'fotos_analisadas_em' => 'datetime',
        ];
    }

    public function corretor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corretor_id');
    }

    /**
     * União (sem duplicatas) entre diferenciais informados (fala/digitação/
     * revisão humana) e diferenciais detectados pela análise de fotos.
     *
     * @return string[]
     */
    public function getDiferenciaisUniaoAttribute(): array
    {
        return array_values(array_unique(array_merge($this->diferenciais ?? [], $this->diferenciais_fotos ?? [])));
    }

    /**
     * @return string[]
     */
    public function getDiferenciaisOutrosUniaoAttribute(): array
    {
        return array_values(array_unique(array_merge($this->diferenciais_outros ?? [], $this->diferenciais_outros_fotos ?? [])));
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(ImovelStagingFoto::class)->orderBy('ordem');
    }

    /**
     * Recomendação da IA — pode divergir da foto de capa efetivamente ativa
     * (fotoCapa()) sempre que o corretor já tiver escolhido manualmente.
     */
    public function fotoCapaSugerida(): BelongsTo
    {
        return $this->belongsTo(ImovelStagingFoto::class, 'foto_capa_sugerida_id');
    }

    /**
     * Foto de capa efetivamente ativa do imóvel. Definida automaticamente a
     * partir da sugestão da IA na primeira vez (quando null); depois disso só
     * muda por escolha manual do corretor via endpoint próprio.
     */
    public function fotoCapa(): BelongsTo
    {
        return $this->belongsTo(ImovelStagingFoto::class, 'foto_capa_id');
    }
}
