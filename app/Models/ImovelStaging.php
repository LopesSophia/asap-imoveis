<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable([
    'corretor_id',
    'tipo_imovel',
    'negociacao',
    'utilizacao',
    'bairro',
    'logradouro',
    'numero',
    'sem_numero',
    'cidade',
    'cep',
    'estado',
    'complemento',
    'metragem',
    'area_total',
    'quartos',
    'suites',
    'banheiros',
    'salas',
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
    'data_confirmacao_proprietario',
    'condominio_situacao',
    'iptu_situacao',
    'iptu_periodicidade',
    'outros_encargos',
    'disponibilidade_visita',
    'previsao_entrega',
    'pontuacao_qualidade',
    'data_ultima_validacao',
    'pendencias_confirmadas',
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
    'descricao_geracao_status',
    'descricao_geracao_erro',
    'descricao_geracao_iniciada_em',
    'descricao_gerada_em',
    'observacoes_corretor',
    'localizacao',
    'status_propagacao',
    'criado_em',
])]
class ImovelStaging extends Model
{
    use HasFactory;

    /**
     * Estados de geração da descrição (assíncrona — ver
     * GerarDescricaoImovelJob). O título nunca usa isto: é síncrono e
     * determinístico, sem IA.
     */
    public const DESCRICAO_PENDENTE = 'pendente';

    public const DESCRICAO_PROCESSANDO = 'processando';

    public const DESCRICAO_CONCLUIDA = 'concluida';

    public const DESCRICAO_ERRO = 'erro';

    /**
     * Status que significam "já existe (ou já existiu) um job cuidando
     * disso" — usado pra decidir se um NOVO job precisa ser despachado
     * (idempotência contra clique duplo/chamadas concorrentes).
     */
    public const DESCRICAO_EM_ANDAMENTO = [self::DESCRICAO_PENDENTE, self::DESCRICAO_PROCESSANDO];

    // Calculados a cada serialização, nunca persistidos — a revisão final
    // exibe a união sem duplicidades entre o que veio da fala/digitação
    // (diferenciais) e o que a análise de fotos detectou (diferenciais_fotos),
    // sem que isso signifique mesclar os dados de origem de verdade.
    protected $appends = ['diferenciais_uniao', 'diferenciais_outros_uniao', 'alertas_fotos_normalizados'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metragem' => 'decimal:2',
            'area_total' => 'decimal:2',
            'valor' => 'decimal:2',
            'condominio' => 'decimal:2',
            'iptu' => 'decimal:2',
            'sem_numero' => 'boolean',
            'em_condominio' => 'boolean',
            'reformado' => 'boolean',
            'mobiliado' => 'boolean',
            'iptu_isento' => 'boolean',
            'data_confirmacao_proprietario' => 'date',
            'pontuacao_qualidade' => 'integer',
            'data_ultima_validacao' => 'datetime',
            'pendencias_confirmadas' => 'array',
            'descricao_geracao_iniciada_em' => 'datetime',
            'descricao_gerada_em' => 'datetime',
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

    /**
     * "alertas_fotos" (a coluna crua) sempre foi um array — ANTES desta
     * versão, de strings soltas ("3 fotos parecem..."); agora, de objetos
     * {foto_id, mensagem}. Este accessor normaliza SEMPRE para o formato
     * novo — nunca quebra a exibição de um cadastro analisado antes desta
     * mudança. A coluna crua "alertas_fotos" nunca é reescrita por causa
     * disto — só esta representação, calculada a cada serialização.
     *
     * Análises antigas em string às vezes ecoavam literalmente o rótulo
     * interno "Foto id=NNN" (usado só pra identificar a foto pra IA) dentro
     * do texto — reconhecemos esse padrão e, se o id ainda pertencer a uma
     * foto real deste imóvel, o alerta vira vinculado (foto_id preenchido,
     * rótulo removido do texto); se o id não existir mais (foto removida)
     * ou não houver padrão nenhum, vira alerta geral — o rótulo bruto NUNCA
     * aparece na mensagem apresentada, em nenhum dos dois casos.
     *
     * @return array<int, array{foto_id: ?int, mensagem: string}>
     */
    public function getAlertasFotosNormalizadosAttribute(): array
    {
        $idsFotos = null; // calculado sob demanda (só se houver alerta em string) pra não gastar query à toa no caso comum (formato novo).

        return collect($this->alertas_fotos ?? [])
            ->map(function ($alerta) use (&$idsFotos) {
                if (is_string($alerta)) {
                    $idsFotos ??= $this->fotos()->pluck('id')->all();

                    return $this->normalizarAlertaLegadoEmString($alerta, $idsFotos);
                }

                if (! is_array($alerta)) {
                    return null;
                }

                $fotoId = $alerta['foto_id'] ?? null;

                return [
                    'foto_id' => is_numeric($fotoId) ? (int) $fotoId : null,
                    'mensagem' => (string) ($alerta['mensagem'] ?? ''),
                ];
            })
            ->filter(fn ($alerta) => $alerta !== null && $alerta['mensagem'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  int[]  $idsFotosValidos
     * @return array{foto_id: ?int, mensagem: string}
     */
    private function normalizarAlertaLegadoEmString(string $alerta, array $idsFotosValidos): array
    {
        $fotoId = null;

        if (preg_match('/foto\s*id\s*=\s*(\d+)/i', $alerta, $match)) {
            $idEncontrado = (int) $match[1];

            if (in_array($idEncontrado, $idsFotosValidos, true)) {
                $fotoId = $idEncontrado;
            }

            // Remove o rótulo bruto do texto de qualquer forma — vinculado
            // ou não, o id interno nunca pode sobrar na mensagem exibida.
            $alerta = trim((string) preg_replace('/foto\s*id\s*=\s*\d+\s*[:,]?\s*/i', '', $alerta));
        }

        return ['foto_id' => $fotoId, 'mensagem' => $alerta];
    }

    public function fotos(): HasMany
    {
        return $this->hasMany(ImovelStagingFoto::class)->orderBy('ordem');
    }

    /**
     * Todas as tentativas de edição de foto (de QUALQUER foto) deste
     * imóvel — usado por EdicaoFotoCotaService para o limite "por imóvel".
     */
    public function edicoesFotos(): HasManyThrough
    {
        return $this->hasManyThrough(ImovelStagingFotoEdicao::class, ImovelStagingFoto::class);
    }

    /**
     * Invalida o resultado EXCLUSIVAMENTE fotográfico de uma análise já
     * feita — usado tanto quando o conjunto de fotos muda (upload/remoção,
     * via ImovelStagingFotoController) quanto quando uma edição de foto é
     * aprovada (o conteúdo visual ativo mudou, via
     * ImovelStagingFotoEdicaoController): em ambos os casos o resultado
     * anterior pode não refletir mais o que está nas fotos. Nunca toca
     * foto_capa_id — a escolha manual do corretor sobrevive, só a sugestão
     * automática é invalidada.
     */
    public function invalidarAnaliseFotografica(): void
    {
        if ($this->fotos_analisadas_em === null) {
            return;
        }

        $this->update([
            'fotos_analisadas_em' => null,
            'diferenciais_fotos' => [],
            'diferenciais_outros_fotos' => [],
            'observacoes_visuais' => [],
            'alertas_fotos' => [],
            'foto_capa_sugerida_id' => null,
            'foto_capa_motivo' => null,
        ]);
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
