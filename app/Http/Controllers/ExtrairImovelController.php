<?php

namespace App\Http\Controllers;

use App\Exceptions\ExtracaoImovelException;
use App\Http\Requests\ExtrairImovelRequest;
use App\Services\BuscaAnoConstrucaoService;
use App\Services\ExtracaoImovelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtrairImovelController extends Controller
{
    public function __invoke(
        ExtrairImovelRequest $request,
        ExtracaoImovelService $service,
        BuscaAnoConstrucaoService $buscaAnoConstrucaoService
    ): JsonResponse {
        try {
            $dados = $service->extrair($request->validated('texto'));
        } catch (ExtracaoImovelException $e) {
            Log::error('ExtracaoImovelService: falha ao extrair dados do imóvel.', [
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            Log::error('ExtrairImovelController: erro inesperado ao extrair dados do imóvel.', [
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Erro inesperado ao extrair os dados do imóvel.'], 500);
        }

        // Fallback só entra em ação para apartamento/cobertura com ano_construcao
        // ainda null — o serviço já faz essa checagem, nunca sobrescreve um ano
        // que o corretor já tenha falado (nunca chamado se já não for null).
        $anoEncontrado = $buscaAnoConstrucaoService->buscar($dados);

        if ($anoEncontrado !== null) {
            $dados['ano_construcao'] = $anoEncontrado;
        }

        return response()->json($dados, 200);
    }
}
