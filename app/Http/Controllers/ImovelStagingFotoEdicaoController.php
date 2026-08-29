<?php

namespace App\Http\Controllers;

use App\Exceptions\EdicaoFotoLimiteExcedidoException;
use App\Http\Requests\StoreImovelStagingFotoEdicaoRequest;
use App\Models\ImovelStaging;
use App\Models\ImovelStagingFoto;
use App\Models\ImovelStagingFotoEdicao;
use App\Services\EdicaoFotoCotaService;
use App\Services\EdicaoFotoGeminiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ImovelStagingFotoEdicaoController extends Controller
{
    public function itensRemoviveis(ImovelStaging $imovelStaging, ImovelStagingFoto $foto): JsonResponse
    {
        $this->garantirPertencimento($imovelStaging, $foto);

        return response()->json([
            'itens_removiveis_sugeridos' => $foto->itens_removiveis_sugeridos ?? [],
        ]);
    }

    /**
     * Histórico completo de tentativas desta foto (mais recente primeiro) —
     * nunca omite tentativas rejeitadas/com erro, é auditoria, não só a
     * versão ativa.
     */
    public function index(ImovelStaging $imovelStaging, ImovelStagingFoto $foto): JsonResponse
    {
        $this->garantirPertencimento($imovelStaging, $foto);

        return response()->json($foto->edicoes()->get());
    }

    public function show(ImovelStaging $imovelStaging, ImovelStagingFoto $foto, ImovelStagingFotoEdicao $edicao): JsonResponse
    {
        $this->garantirPertencimento($imovelStaging, $foto);
        $this->garantirPertencimentoEdicao($foto, $edicao);

        return response()->json($edicao);
    }

    /**
     * Cria uma nova tentativa de edição (seleção EXPLÍCITA do corretor
     * dentre os itens SUGERIDOS e persistidos para esta foto — o
     * FormRequest já rejeitou qualquer item manual ou de outra foto antes
     * de chegar aqui) e despacha o job de geração. Idempotente contra
     * duplo clique: se já existe uma tentativa pendente/processando para
     * esta foto, devolve ELA em vez de criar outra (e sem consumir cota de
     * novo). Se algum dos três limitadores de custo estiver esgotado, a
     * reserva de cota nunca chega a criar a linha nem a despachar o job.
     */
    public function store(StoreImovelStagingFotoEdicaoRequest $request, ImovelStaging $imovelStaging, ImovelStagingFoto $foto, EdicaoFotoGeminiService $geminiService, EdicaoFotoCotaService $cotaService): JsonResponse
    {
        $this->garantirPertencimento($imovelStaging, $foto);

        $emAndamento = $foto->edicoes()
            ->whereIn('status', ImovelStagingFotoEdicao::EM_ANDAMENTO)
            ->first();

        if ($emAndamento !== null) {
            return response()->json($emAndamento, 200);
        }

        $itens = array_values($request->validated('itens'));
        $promptEnviado = $geminiService->montarPrompt($itens);

        try {
            $edicao = $cotaService->reservarEDespachar($foto, $itens, $promptEnviado);
        } catch (EdicaoFotoLimiteExcedidoException $e) {
            return response()->json(['message' => $e->getMessage()], 429);
        }

        return response()->json($edicao, 202);
    }

    /**
     * Só válido a partir de "gerada". Em transação com lockForUpdate na
     * foto: evita que duas aprovações concorrentes (da mesma foto ou de
     * tentativas diferentes) corrompam edicao_ativa_id com uma escrita
     * perdida — a segunda aprovação espera a primeira commitar antes de
     * sobrescrever o ponteiro.
     */
    public function aprovar(ImovelStaging $imovelStaging, ImovelStagingFoto $foto, ImovelStagingFotoEdicao $edicao): JsonResponse
    {
        $this->garantirPertencimento($imovelStaging, $foto);
        $this->garantirPertencimentoEdicao($foto, $edicao);

        if (! $edicao->podeTransicionarPara(ImovelStagingFotoEdicao::APROVADA)) {
            return response()->json([
                'message' => "Não é possível aprovar uma edição com status \"{$edicao->status}\".",
            ], 422);
        }

        DB::transaction(function () use ($imovelStaging, $foto, $edicao) {
            ImovelStagingFoto::query()->whereKey($foto->id)->lockForUpdate()->first();

            $edicao->update([
                'status' => ImovelStagingFotoEdicao::APROVADA,
                'decidido_por_user_id' => Auth::id(),
                'decidida_em' => now(),
            ]);

            $foto->update(['edicao_ativa_id' => $edicao->id]);

            // Conteúdo visual ativo mudou — a análise (e a sugestão
            // automática de capa) pode não refletir mais a foto exibida.
            // foto_capa_id (capa manual) nunca é tocado aqui.
            $imovelStaging->invalidarAnaliseFotografica();
        });

        return response()->json($edicao->fresh(), 200);
    }

    public function rejeitar(ImovelStaging $imovelStaging, ImovelStagingFoto $foto, ImovelStagingFotoEdicao $edicao): JsonResponse
    {
        $this->garantirPertencimento($imovelStaging, $foto);
        $this->garantirPertencimentoEdicao($foto, $edicao);

        if (! $edicao->podeTransicionarPara(ImovelStagingFotoEdicao::REJEITADA)) {
            return response()->json([
                'message' => "Não é possível rejeitar uma edição com status \"{$edicao->status}\".",
            ], 422);
        }

        // Nunca toca em análise, capa ou edicao_ativa_id — rejeitar não
        // muda o que está ativo.
        $edicao->update([
            'status' => ImovelStagingFotoEdicao::REJEITADA,
            'decidido_por_user_id' => Auth::id(),
            'decidida_em' => now(),
        ]);

        return response()->json($edicao->fresh(), 200);
    }

    /**
     * "Voltar ao original": desliga a versão ativa sem apagar o histórico
     * (a linha aprovada continua existindo com status "aprovada"). Não
     * invalida análise/capa retroativamente — isso já aconteceu quando a
     * edição foi aprovada. Idempotente: sem edição ativa, só responde 204.
     */
    public function desativarEdicaoAtiva(ImovelStaging $imovelStaging, ImovelStagingFoto $foto): JsonResponse
    {
        $this->garantirPertencimento($imovelStaging, $foto);

        if ($foto->edicao_ativa_id !== null) {
            $foto->update(['edicao_ativa_id' => null]);
        }

        return response()->json(null, 204);
    }

    private function garantirPertencimento(ImovelStaging $imovelStaging, ImovelStagingFoto $foto): void
    {
        abort_if($foto->imovel_staging_id !== $imovelStaging->id, 404);
    }

    private function garantirPertencimentoEdicao(ImovelStagingFoto $foto, ImovelStagingFotoEdicao $edicao): void
    {
        abort_if($edicao->imovel_staging_foto_id !== $foto->id, 404);
    }
}
