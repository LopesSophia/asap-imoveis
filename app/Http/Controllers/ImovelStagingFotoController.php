<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreImovelStagingFotoRequest;
use App\Models\ImovelStaging;
use App\Models\ImovelStagingFoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class ImovelStagingFotoController extends Controller
{
    public function store(StoreImovelStagingFotoRequest $request, ImovelStaging $imovelStaging): JsonResponse
    {
        $proximaOrdem = ($imovelStaging->fotos()->max('ordem') ?? 0) + 1;

        $fotosSalvas = [];

        foreach ($request->file('fotos') as $arquivo) {
            $caminho = $arquivo->store("imoveis/{$imovelStaging->id}", 'public');

            $fotosSalvas[] = $imovelStaging->fotos()->create([
                'caminho' => $caminho,
                'ordem' => $proximaOrdem,
            ]);

            $proximaOrdem++;
        }

        $this->invalidarAnaliseSeExistir($imovelStaging);

        return response()->json([
            'fotos' => $fotosSalvas,
            'total_fotos' => $imovelStaging->fotos()->count(),
        ], 201);
    }

    public function destroy(ImovelStaging $imovelStaging, ImovelStagingFoto $foto): JsonResponse
    {
        abort_if($foto->imovel_staging_id !== $imovelStaging->id, 404);

        Storage::disk('public')->delete($foto->caminho);
        $foto->delete();

        // foto_capa_id (FK, nullOnDelete) cuida sozinho de se limpar quando a
        // foto removida era a capa ATIVA — nunca tocamos nele aqui, pra
        // preservar a escolha manual do corretor quando a foto removida for
        // outra qualquer.
        $this->invalidarAnaliseSeExistir($imovelStaging);

        return response()->json([
            'message' => 'Foto removida.',
            'total_fotos' => $imovelStaging->fotos()->count(),
        ], 200);
    }

    /**
     * Upload ou remoção de QUALQUER foto invalida uma análise já feita — o
     * conjunto de fotos mudou, então tudo que é resultado exclusivamente
     * fotográfico pode não refletir mais o lote real. Diferente de antes:
     * agora isso limpa de verdade (nunca acumula resultado obsoleto), não só
     * zera o timestamp. foto_capa_id (a capa ATIVA) nunca é tocado aqui —
     * só a FK cuida dele, e só se a foto removida for exatamente essa.
     */
    private function invalidarAnaliseSeExistir(ImovelStaging $imovelStaging): void
    {
        if ($imovelStaging->fotos_analisadas_em === null) {
            return;
        }

        $imovelStaging->update([
            'fotos_analisadas_em' => null,
            'diferenciais_fotos' => [],
            'diferenciais_outros_fotos' => [],
            'observacoes_visuais' => [],
            'alertas_fotos' => [],
            'foto_capa_sugerida_id' => null,
            'foto_capa_motivo' => null,
        ]);
    }
}
