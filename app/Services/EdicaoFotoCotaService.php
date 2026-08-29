<?php

namespace App\Services;

use App\Exceptions\EdicaoFotoLimiteExcedidoException;
use App\Jobs\GerarEdicaoFotoJob;
use App\Models\GeminiUsoMensal;
use App\Models\ImovelStagingFoto;
use App\Models\ImovelStagingFotoEdicao;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Reserva atômica de cota + criação da tentativa + despacho do job. Os
 * três limitadores (por foto, por imóvel, mensal) são checados e a cota
 * mensal é incrementada dentro de UMA transação — nunca depois. O job só é
 * despachado DEPOIS que a transação commita, nunca de dentro dela.
 *
 * "Tentativa" aqui = uma linha em imovel_staging_foto_edicoes, contada
 * independentemente do status final (pendente/processando/gerada/aprovada/
 * rejeitada/erro) — só a CRIAÇÃO da linha consome cota; uma requisição
 * rejeitada na validação (FormRequest) nunca chega até aqui.
 */
class EdicaoFotoCotaService
{
    /**
     * @param  array<int, array{categoria: string, descricao: string}>  $itens
     *
     * @throws EdicaoFotoLimiteExcedidoException
     */
    public function reservarEDespachar(ImovelStagingFoto $foto, array $itens, string $promptEnviado): ImovelStagingFotoEdicao
    {
        $edicao = DB::transaction(function () use ($foto, $itens, $promptEnviado) {
            // Trava (ou cria) o contador do mês corrente PRIMEIRO — isso
            // serializa TODA reserva de cota do sistema durante esta seção
            // crítica. Como qualquer outra tentativa concorrente (de
            // QUALQUER foto/imóvel) precisa esperar esta mesma linha
            // liberar, as contagens por-foto/por-imóvel abaixo (baseadas em
            // contar linhas existentes) também ficam livres de corrida, sem
            // precisar de um lock dedicado por foto/imóvel.
            $anoMes = now()->format('Y-m');

            try {
                GeminiUsoMensal::firstOrCreate(['ano_mes' => $anoMes]);
            } catch (QueryException $e) {
                // Corrida rara no PRIMEIRO pedido de edição do mês: duas
                // transações tentaram criar a linha do contador ao mesmo
                // tempo e uma esbarrou na constraint unique(ano_mes) — a
                // linha já existe, é só seguir para travá-la abaixo.
            }

            $cota = GeminiUsoMensal::where('ano_mes', $anoMes)->lockForUpdate()->first();

            if ($cota->quantidade >= (int) config('services.gemini.limite_chamadas_mensal')) {
                throw new EdicaoFotoLimiteExcedidoException(EdicaoFotoLimiteExcedidoException::MENSAL);
            }

            $tentativasFoto = $foto->edicoes()->count();
            if ($tentativasFoto >= (int) config('services.gemini.limite_tentativas_por_foto')) {
                throw new EdicaoFotoLimiteExcedidoException(EdicaoFotoLimiteExcedidoException::FOTO);
            }

            $tentativasImovel = $foto->imovelStaging->edicoesFotos()->count();
            if ($tentativasImovel >= (int) config('services.gemini.limite_tentativas_por_imovel')) {
                throw new EdicaoFotoLimiteExcedidoException(EdicaoFotoLimiteExcedidoException::IMOVEL);
            }

            $cota->increment('quantidade');

            return $foto->edicoes()->create([
                'solicitado_por_user_id' => Auth::id(),
                'itens_solicitados' => $itens,
                'prompt_enviado' => $promptEnviado,
                'provider' => 'gemini',
                'modelo' => config('services.gemini.model'),
                'status' => ImovelStagingFotoEdicao::PENDENTE,
            ]);
        });

        GerarEdicaoFotoJob::dispatch($edicao->id);

        return $edicao;
    }
}
