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

        $imovelStaging->invalidarAnaliseFotografica();

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
        $imovelStaging->invalidarAnaliseFotografica();

        return response()->json([
            'message' => 'Foto removida.',
            'total_fotos' => $imovelStaging->fotos()->count(),
        ], 200);
    }
}
