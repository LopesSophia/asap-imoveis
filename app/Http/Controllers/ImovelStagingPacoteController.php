<?php

namespace App\Http\Controllers;

use App\Exceptions\PacoteProntosException;
use App\Models\ImovelStaging;
use App\Services\PacoteProntosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class ImovelStagingPacoteController extends Controller
{
    /**
     * Texto formatado (por seção + completo) usado pelos botões "copiar" da
     * tela de entrega — mesma fonte de dados usada dentro do ZIP.
     */
    public function dados(ImovelStaging $imovelStaging, PacoteProntosService $service): JsonResponse
    {
        return response()->json([
            'secoes' => $service->montarSecoes($imovelStaging),
            'texto_completo' => $service->montarTextoCompleto($imovelStaging),
        ]);
    }

    /**
     * Gera o ZIP SOB DEMANDA (nunca persistido, nunca altera os originais)
     * e devolve como download. O arquivo temporário é apagado logo após
     * ser enviado.
     */
    public function zip(ImovelStaging $imovelStaging, PacoteProntosService $service): BinaryFileResponse|JsonResponse
    {
        try {
            $caminhoZip = $service->gerarZip($imovelStaging);
        } catch (PacoteProntosException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('PacoteProntosService: erro inesperado ao gerar o ZIP.', [
                'imovel_staging_id' => $imovelStaging->id,
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Erro inesperado ao gerar o pacote para o Prontos.'], 500);
        }

        $nomeArquivo = "cadastro-prontos-imovel-{$imovelStaging->id}.zip";

        return response()->download($caminhoZip, $nomeArquivo, [
            'Content-Type' => 'application/zip',
        ])->deleteFileAfterSend(true);
    }
}
