<?php

namespace App\Http\Controllers;

use App\Exceptions\EnriquecimentoLocalizacaoException;
use App\Http\Requests\ExtrairEnderecoRequest;
use App\Services\EnderecoValidator;
use App\Services\ExtracaoEnderecoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExtrairEnderecoController extends Controller
{
    public function __invoke(ExtrairEnderecoRequest $request, ExtracaoEnderecoService $service): JsonResponse
    {
        try {
            $endereco = $service->extrair($request->validated('texto'));
        } catch (EnriquecimentoLocalizacaoException $e) {
            Log::error('ExtracaoEnderecoService: falha ao extrair endereço.', [
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            Log::error('ExtrairEnderecoController: erro inesperado ao extrair endereço.', [
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Erro inesperado ao extrair o endereço.'], 500);
        }

        $endereco['completo'] = EnderecoValidator::completo($endereco);

        return response()->json($endereco, 200);
    }
}
