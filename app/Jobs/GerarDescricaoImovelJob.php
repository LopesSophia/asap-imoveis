<?php

namespace App\Jobs;

use App\Exceptions\GeracaoTituloDescricaoException;
use App\Models\ImovelStaging;
use App\Services\GeracaoTituloDescricaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GerarDescricaoImovelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Recebe só o id (não a model) para nunca serializar/reidratar um
     * estado de fila obsoleto — o status real é sempre lido do banco no
     * momento em que o job efetivamente roda. O título NUNCA passa por
     * aqui: é síncrono/determinístico, já persistido antes deste job ser
     * despachado.
     */
    public function __construct(public readonly int $imovelStagingId) {}

    public function handle(GeracaoTituloDescricaoService $service): void
    {
        $imovelStaging = ImovelStaging::find($this->imovelStagingId);

        // Já foi processado (ou a linha não existe mais) — nunca reprocessa
        // fora da transição pendente → processando. Cobre também chamadas
        // duplicadas: um segundo job para o mesmo staging encontra o status
        // já em "processando" (ou além) e simplesmente não faz nada.
        if ($imovelStaging === null || $imovelStaging->descricao_geracao_status !== ImovelStaging::DESCRICAO_PENDENTE) {
            return;
        }

        // O corretor pode ter preenchido/editado manualmente a descrição
        // enquanto o job esperava na fila — texto humano nunca é
        // sobrescrito pela IA, então nem chega a chamar o serviço.
        if (! empty($imovelStaging->descricao_gerada)) {
            $this->marcarConcluidaComTextoHumano($imovelStaging);

            return;
        }

        $imovelStaging->update([
            'descricao_geracao_status' => ImovelStaging::DESCRICAO_PROCESSANDO,
            'descricao_geracao_iniciada_em' => now(),
        ]);

        try {
            $descricao = $service->gerarDescricao($imovelStaging);

            // Re-checagem: as tentativas internas do serviço podem levar
            // dezenas de segundos — o corretor pode ter salvo manualmente
            // ENQUANTO a IA ainda estava gerando. Texto humano continua
            // prevalecendo, mesmo com a IA já tendo terminado com sucesso.
            $atual = $imovelStaging->fresh();

            if ($atual === null) {
                return;
            }

            if (! empty($atual->descricao_gerada)) {
                $this->marcarConcluidaComTextoHumano($atual);

                return;
            }

            $atual->update([
                'descricao_gerada' => $descricao,
                'descricao_geracao_status' => ImovelStaging::DESCRICAO_CONCLUIDA,
                'descricao_geracao_erro' => null,
                'descricao_gerada_em' => now(),
            ]);
        } catch (GeracaoTituloDescricaoException $e) {
            $this->marcarErro($imovelStaging, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('GerarDescricaoImovelJob: erro inesperado ao gerar descrição.', [
                'imovel_staging_id' => $imovelStaging->id,
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->marcarErro($imovelStaging, 'Erro inesperado ao gerar a descrição.');
        }
    }

    /**
     * Rede de segurança: se o worker cair/esgotar tentativas antes de
     * handle() concluir normalmente, a linha não fica presa em
     * "processando" para sempre — vira "erro" de forma visível pro
     * corretor (a menos que ele já tenha preenchido manualmente).
     */
    public function failed(?Throwable $exception): void
    {
        $imovelStaging = ImovelStaging::find($this->imovelStagingId);

        if ($imovelStaging !== null && in_array($imovelStaging->descricao_geracao_status, ImovelStaging::DESCRICAO_EM_ANDAMENTO, true)) {
            $this->marcarErro($imovelStaging, 'Falha ao processar a geração da descrição (fila).');
        }
    }

    /**
     * Ponto único de decisão "erro" — sempre revalida se o corretor não
     * preencheu manualmente enquanto a falha estava sendo tratada (ex.:
     * IA falhou definitivamente, mas o corretor já tinha digitado a
     * descrição à mão nesse meio-tempo).
     */
    private function marcarErro(ImovelStaging $imovelStaging, string $mensagem): void
    {
        $atual = $imovelStaging->fresh();

        if ($atual !== null && ! empty($atual->descricao_gerada)) {
            $this->marcarConcluidaComTextoHumano($atual);

            return;
        }

        $imovelStaging->update([
            'descricao_geracao_status' => ImovelStaging::DESCRICAO_ERRO,
            'descricao_geracao_erro' => $mensagem,
        ]);
    }

    private function marcarConcluidaComTextoHumano(ImovelStaging $imovelStaging): void
    {
        $imovelStaging->update([
            'descricao_geracao_status' => ImovelStaging::DESCRICAO_CONCLUIDA,
            'descricao_geracao_erro' => null,
            'descricao_gerada_em' => $imovelStaging->descricao_gerada_em ?? now(),
        ]);
    }
}
