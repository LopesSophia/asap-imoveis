<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'imovel_staging_foto_id',
    'solicitado_por_user_id',
    'itens_solicitados',
    'prompt_enviado',
    'provider',
    'modelo',
    'status',
    'caminho_arquivo_editado',
    'mensagem_erro',
    'iniciada_em',
    'concluida_em',
    'decidido_por_user_id',
    'decidida_em',
])]
class ImovelStagingFotoEdicao extends Model
{
    protected $table = 'imovel_staging_foto_edicoes';

    protected $appends = ['url'];

    public const PENDENTE = 'pendente';

    public const PROCESSANDO = 'processando';

    public const GERADA = 'gerada';

    public const APROVADA = 'aprovada';

    public const REJEITADA = 'rejeitada';

    public const ERRO = 'erro';

    /**
     * Estados que ainda não têm um arquivo gerado para revisar — usados para
     * decidir se uma tentativa em curso já existe para uma foto (idempotência
     * contra duplo clique em "gerar edição").
     */
    public const EM_ANDAMENTO = [self::PENDENTE, self::PROCESSANDO];

    /**
     * Transições válidas da máquina de estados. pendente/processando são
     * geridas pelo job (nunca por uma ação do corretor); aprovar/rejeitar só
     * partem de "gerada". erro/rejeitada/aprovada são terminais para a
     * PRÓPRIA linha — uma nova tentativa é sempre uma linha nova.
     *
     * @var array<string, string[]>
     */
    private const TRANSICOES_VALIDAS = [
        self::PENDENTE => [self::PROCESSANDO],
        self::PROCESSANDO => [self::GERADA, self::ERRO],
        self::GERADA => [self::APROVADA, self::REJEITADA],
        self::APROVADA => [],
        self::REJEITADA => [],
        self::ERRO => [],
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'itens_solicitados' => 'array',
            'iniciada_em' => 'datetime',
            'concluida_em' => 'datetime',
            'decidida_em' => 'datetime',
        ];
    }

    public function imovelStagingFoto(): BelongsTo
    {
        return $this->belongsTo(ImovelStagingFoto::class);
    }

    public function solicitadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por_user_id');
    }

    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por_user_id');
    }

    public function podeTransicionarPara(string $novoStatus): bool
    {
        return in_array($novoStatus, self::TRANSICOES_VALIDAS[$this->status] ?? [], true);
    }

    /**
     * URL do arquivo editado desta tentativa — null enquanto não houver
     * "caminho_arquivo_editado" (pendente/processando/erro sem geração).
     */
    public function getUrlAttribute(): ?string
    {
        return $this->caminho_arquivo_editado !== null
            ? url('/storage/'.$this->caminho_arquivo_editado)
            : null;
    }
}
