<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['imovel_staging_id', 'caminho', 'ordem', 'itens_removiveis_sugeridos', 'edicao_ativa_id'])]
class ImovelStagingFoto extends Model
{
    protected $appends = ['url', 'url_original'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'itens_removiveis_sugeridos' => 'array',
        ];
    }

    public function imovelStaging(): BelongsTo
    {
        return $this->belongsTo(ImovelStaging::class);
    }

    /**
     * Histórico completo de tentativas de edição desta foto (mais recente
     * primeiro) — nunca apagado, mesmo quando rejeitada ou substituída por
     * uma aprovação mais nova.
     */
    public function edicoes(): HasMany
    {
        return $this->hasMany(ImovelStagingFotoEdicao::class)->latest('id');
    }

    /**
     * Tentativa de edição ATIVA — aponta pra uma linha com status "aprovada"
     * ou é null (foto exibe o arquivo original). Nunca aponta pra uma linha
     * "rejeitada", "pendente", "processando" ou "erro".
     */
    public function edicaoAtiva(): BelongsTo
    {
        return $this->belongsTo(ImovelStagingFotoEdicao::class, 'edicao_ativa_id');
    }

    /**
     * URL do conteúdo ATIVO desta foto: a edição aprovada em vigor, se
     * houver, senão o arquivo original. É o que a grade de fotos, a capa e a
     * revisão final devem exibir — nunca precisam saber se existe edição.
     */
    public function getUrlAttribute(): string
    {
        if ($this->edicao_ativa_id !== null && $this->edicaoAtiva?->caminho_arquivo_editado) {
            return url('/storage/'.$this->edicaoAtiva->caminho_arquivo_editado);
        }

        return $this->getUrlOriginalAttribute();
    }

    /**
     * URL do arquivo ORIGINAL (caminho), sempre — independentemente de
     * existir edição ativa. Usado pela tela de comparação lado a lado.
     */
    public function getUrlOriginalAttribute(): string
    {
        return url('/storage/'.$this->caminho);
    }

    /**
     * Caminho (no disco "public", não URL) do conteúdo ATIVO desta foto —
     * mesma resolução de getUrlAttribute(), mas devolvendo o caminho bruto
     * em vez da URL pública. Usado pelo pacote de entrega ao Prontos, que
     * precisa do arquivo de verdade, não de um link.
     */
    public function caminhoAtivo(): string
    {
        if ($this->edicao_ativa_id !== null && $this->edicaoAtiva?->caminho_arquivo_editado) {
            return $this->edicaoAtiva->caminho_arquivo_editado;
        }

        return $this->caminho;
    }
}
