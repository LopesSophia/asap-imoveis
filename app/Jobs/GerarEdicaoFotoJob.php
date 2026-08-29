<?php

namespace App\Jobs;

use App\Exceptions\EdicaoFotoException;
use App\Models\ImovelStagingFotoEdicao;
use App\Services\EdicaoFotoGeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class GerarEdicaoFotoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Recebe só o id (não a model) para nunca serializar/reidratar um
     * estado de fila obsoleto — o status real é sempre lido do banco no
     * momento em que o job efetivamente roda.
     */
    public function __construct(public readonly int $edicaoId) {}

    public function handle(EdicaoFotoGeminiService $service): void
    {
        $edicao = ImovelStagingFotoEdicao::with('imovelStagingFoto')->find($this->edicaoId);

        // Já foi processada (ou a linha não existe mais) — nunca reprocessa
        // fora da transição pendente → processando.
        if ($edicao === null || $edicao->status !== ImovelStagingFotoEdicao::PENDENTE) {
            return;
        }

        $edicao->update([
            'status' => ImovelStagingFotoEdicao::PROCESSANDO,
            'iniciada_em' => now(),
        ]);

        $foto = $edicao->imovelStagingFoto;
        $caminhoDestino = "imoveis/{$foto->imovel_staging_id}/edicoes/{$foto->id}/{$edicao->id}.jpg";

        try {
            $resultado = $service->editar($foto->caminho, $edicao->prompt_enviado, $caminhoDestino);

            $edicao->update([
                'status' => ImovelStagingFotoEdicao::GERADA,
                'caminho_arquivo_editado' => $resultado['caminho'],
                'concluida_em' => now(),
            ]);
        } catch (EdicaoFotoException $e) {
            $this->marcarErro($edicao, $e->getMessage());
        } catch (Throwable $e) {
            Log::error('GerarEdicaoFotoJob: erro inesperado ao gerar edição de foto.', [
                'edicao_id' => $edicao->id,
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            $this->marcarErro($edicao, 'Erro inesperado ao gerar a edição da foto.');
        }
    }

    /**
     * Rede de segurança: se o worker cair/esgotar tentativas antes de
     * handle() concluir normalmente, a linha não fica presa em "processando"
     * para sempre — vira "erro" de forma visível pro corretor.
     */
    public function failed(?Throwable $exception): void
    {
        $edicao = ImovelStagingFotoEdicao::find($this->edicaoId);

        if ($edicao !== null && in_array($edicao->status, ImovelStagingFotoEdicao::EM_ANDAMENTO, true)) {
            $this->marcarErro($edicao, 'Falha ao processar a edição da foto (fila).');
        }
    }

    private function marcarErro(ImovelStagingFotoEdicao $edicao, string $mensagem): void
    {
        $edicao->update([
            'status' => ImovelStagingFotoEdicao::ERRO,
            'mensagem_erro' => $mensagem,
            'concluida_em' => now(),
        ]);
    }
}
