<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['imovel_staging_id', 'caminho', 'ordem'])]
class ImovelStagingFoto extends Model
{
    protected $appends = ['url'];

    public function imovelStaging(): BelongsTo
    {
        return $this->belongsTo(ImovelStaging::class);
    }

    public function getUrlAttribute(): string
    {
        return url('/storage/'.$this->caminho);
    }
}
