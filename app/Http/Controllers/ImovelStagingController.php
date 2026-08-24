<?php

namespace App\Http\Controllers;

use App\Exceptions\AnaliseFotosException;
use App\Exceptions\EnderecoNaoEncontradoException;
use App\Exceptions\EnriquecimentoLocalizacaoException;
use App\Http\Requests\SelecionarFotoCapaRequest;
use App\Http\Requests\StoreImovelStagingRequest;
use App\Models\ImovelStaging;
use App\Services\AnaliseFotosService;
use App\Services\EnderecoValidator;
use App\Services\EnriquecimentoLocalizacaoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImovelStagingController extends Controller
{
    private const MINIMO_FOTOS = 25;

    private const CAMPOS_ENDERECO = ['logradouro', 'numero', 'bairro', 'cidade', 'cep'];

    // Rótulos legíveis dos campos obrigatórios do schema — hoje só tipo_imovel,
    // mas a checagem em finalizar() já escala se mais campos virarem obrigatórios.
    private const CAMPOS_OBRIGATORIOS = [
        'tipo_imovel' => 'tipo de imóvel',
    ];

    public function store(StoreImovelStagingRequest $request): JsonResponse
    {
        $imovelStaging = ImovelStaging::create([
            ...$request->validated(),
            'status_propagacao' => 'rascunho',
        ]);

        return response()->json($imovelStaging, 201);
    }

    public function update(StoreImovelStagingRequest $request, ImovelStaging $imovelStaging): JsonResponse
    {
        $dados = $request->validated();

        // Se o corretor alterou algum campo de endereço, o enriquecimento de
        // localização já salvo (se houver) fica desatualizado — invalida o
        // cache em vez de deixar "localizacao" mentir sobre o endereço novo.
        foreach (self::CAMPOS_ENDERECO as $campo) {
            if (array_key_exists($campo, $dados) && $dados[$campo] !== $imovelStaging->{$campo}) {
                $dados['localizacao'] = null;
                break;
            }
        }

        $imovelStaging->update($dados);

        return response()->json($imovelStaging->fresh(), 200);
    }

    public function enriquecerLocalizacao(
        Request $request,
        ImovelStaging $imovelStaging,
        EnriquecimentoLocalizacaoService $enriquecimentoLocalizacaoService
    ): JsonResponse {
        if ($imovelStaging->localizacao && ! $request->boolean('forcar')) {
            return response()->json($imovelStaging, 200);
        }

        $endereco = [
            'logradouro' => $imovelStaging->logradouro,
            'numero' => $imovelStaging->numero,
            'bairro' => $imovelStaging->bairro,
            'cidade' => $imovelStaging->cidade,
        ];

        if (! EnderecoValidator::completo($endereco)) {
            return response()->json([
                'message' => 'Endereço incompleto: preencha logradouro, bairro e cidade antes de enriquecer a localização.',
            ], 422);
        }

        try {
            $localizacao = $enriquecimentoLocalizacaoService->enriquecer($endereco);
        } catch (EnderecoNaoEncontradoException $e) {
            return response()->json([
                'message' => 'Não foi possível localizar esse endereço no Google Maps. Confirme os dados manualmente.',
            ], 422);
        } catch (EnriquecimentoLocalizacaoException $e) {
            Log::error('EnriquecimentoLocalizacaoService: falha ao enriquecer localização.', [
                'imovel_staging_id' => $imovelStaging->id,
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            Log::error('ImovelStagingController@enriquecerLocalizacao: erro inesperado.', [
                'imovel_staging_id' => $imovelStaging->id,
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Erro inesperado ao enriquecer a localização.'], 500);
        }

        $imovelStaging->update(['localizacao' => $localizacao]);

        return response()->json($imovelStaging->fresh(), 200);
    }

    /**
     * Roda a análise de fotos (diferenciais_fotos/observações/alertas/capa) e
     * PERSISTE o resultado, mas nunca finaliza o cadastro — status_propagacao
     * continua "rascunho". Separado de finalizar() de propósito: o corretor
     * pode analisar, revisar o resultado, e só depois concluir.
     *
     * diferenciais_fotos/diferenciais_outros_fotos/observacoes_visuais/
     * alertas_fotos são resultado EXCLUSIVAMENTE fotográfico: cada chamada
     * SUBSTITUI o que uma análise anterior tinha gravado, nunca mescla (fotos
     * removidas não podem deixar alertas/diferenciais obsoletos pra trás).
     * "diferenciais"/"diferenciais_outros" (fala/digitação/revisão humana)
     * nunca são tocados aqui — a união entre as duas origens é só de
     * apresentação, via diferenciais_uniao/diferenciais_outros_uniao.
     *
     * Idempotente por padrão: se fotos_analisadas_em já está preenchido (e
     * nenhuma foto mudou desde então — upload/remoção zeram esse campo),
     * retorna o estado atual sem gastar uma nova chamada de IA. ?forcar=1
     * força uma reanálise mesmo com fotos_analisadas_em preenchido.
     */
    public function analisarFotos(
        Request $request,
        ImovelStaging $imovelStaging,
        AnaliseFotosService $analiseFotosService
    ): JsonResponse {
        $totalFotos = $imovelStaging->fotos()->count();

        if ($totalFotos < self::MINIMO_FOTOS) {
            $faltam = self::MINIMO_FOTOS - $totalFotos;

            return response()->json([
                'message' => "Faltam {$faltam} fotos para completar o mínimo de ".self::MINIMO_FOTOS." (você tem {$totalFotos}).",
                'total_fotos' => $totalFotos,
            ], 422);
        }

        if ($imovelStaging->fotos_analisadas_em !== null && ! $request->boolean('forcar')) {
            return response()->json($imovelStaging->fresh()->load('fotos'), 200);
        }

        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get(['id', 'caminho'])
            ->map(fn ($foto) => ['id' => $foto->id, 'caminho' => $foto->caminho])
            ->all();

        try {
            $analise = $analiseFotosService->analisar($fotos);
        } catch (AnaliseFotosException $e) {
            Log::error('AnaliseFotosService: falha ao analisar fotos do imóvel.', [
                'imovel_staging_id' => $imovelStaging->id,
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => $e->getMessage()], 500);
        } catch (Throwable $e) {
            Log::error('ImovelStagingController@analisarFotos: erro inesperado na análise de fotos.', [
                'imovel_staging_id' => $imovelStaging->id,
                'mensagem' => $e->getMessage(),
                'exception' => $e,
            ]);

            return response()->json(['message' => 'Erro inesperado ao analisar as fotos do imóvel.'], 500);
        }

        // Tudo aqui é resultado EXCLUSIVAMENTE fotográfico — nunca mesclado
        // com o resultado de uma análise anterior (que pode estar obsoleto se
        // as fotos mudaram) nem com "diferenciais"/"diferenciais_outros"
        // (que são fala/digitação/revisão humana, nunca escritos pela IA de
        // fotos). Cada nova análise SUBSTITUI por inteiro estes 4 campos.
        $dadosAtualizados = [
            'diferenciais_fotos' => array_values(array_unique($analise['diferenciais'] ?? [])),
            'diferenciais_outros_fotos' => array_values(array_unique($analise['diferenciais_outros'] ?? [])),
            'observacoes_visuais' => array_values(array_unique($analise['observacoes_visuais'] ?? [])),
            'alertas_fotos' => array_values(array_unique($analise['alertas_fotos'] ?? [])),
            'fotos_analisadas_em' => now(),
        ];

        // A sugestão da IA é sempre registrada quando válida — mesmo que o
        // corretor já tenha escolhido uma capa manualmente, o corretor ainda
        // precisa ver a recomendação (são campos separados de foto_capa_id).
        if ($analise['foto_capa_sugerida_id'] !== null) {
            $dadosAtualizados['foto_capa_sugerida_id'] = $analise['foto_capa_sugerida_id'];
            $dadosAtualizados['foto_capa_motivo'] = $analise['foto_capa_motivo'];
        }

        // A capa ATIVA só é definida automaticamente a partir da sugestão na
        // primeira vez (quando ainda null) — nunca sobrescreve uma escolha
        // manual do corretor nem uma capa já ativa de uma análise anterior.
        if (! $imovelStaging->foto_capa_id && $analise['foto_capa_sugerida_id'] !== null) {
            $dadosAtualizados['foto_capa_id'] = $analise['foto_capa_sugerida_id'];
        }

        $imovelStaging->update($dadosAtualizados);

        return response()->json($imovelStaging->fresh()->load('fotos'), 200);
    }

    /**
     * Conclui o cadastro (rascunho → pendente). NUNCA chama a IA — exige que
     * analisarFotos() já tenha rodado com sucesso (fotos_analisadas_em
     * preenchido). Upload/remoção de fotos depois da análise zera esse campo
     * automaticamente, obrigando reanálise antes de conseguir concluir de novo.
     */
    public function finalizar(ImovelStaging $imovelStaging): JsonResponse
    {
        $camposFaltando = [];
        foreach (self::CAMPOS_OBRIGATORIOS as $campo => $rotulo) {
            if (empty($imovelStaging->{$campo})) {
                $camposFaltando[] = $rotulo;
            }
        }

        if ($camposFaltando !== []) {
            $prefixo = count($camposFaltando) > 1 ? 'Campos obrigatórios não preenchidos' : 'Campo obrigatório não preenchido';

            return response()->json([
                'message' => "{$prefixo}: ".implode(', ', $camposFaltando).'.',
            ], 422);
        }

        $totalFotos = $imovelStaging->fotos()->count();

        if ($totalFotos < self::MINIMO_FOTOS) {
            $faltam = self::MINIMO_FOTOS - $totalFotos;

            return response()->json([
                'message' => "Faltam {$faltam} fotos para completar o mínimo de ".self::MINIMO_FOTOS." (você tem {$totalFotos}).",
                'total_fotos' => $totalFotos,
            ], 422);
        }

        if ($imovelStaging->fotos_analisadas_em === null) {
            return response()->json([
                'message' => 'É necessário analisar as fotos antes de concluir o cadastro.',
            ], 422);
        }

        $imovelStaging->update(['status_propagacao' => 'pendente']);

        return response()->json($imovelStaging->fresh(), 200);
    }

    /**
     * Escolha manual da foto de capa ATIVA. A palavra final é sempre do
     * corretor: substitui uma escolha manual anterior ou a capa automática
     * definida a partir da sugestão da IA. Nunca toca foto_capa_sugerida_id
     * nem foto_capa_motivo — a recomendação da IA continua visível mesmo
     * depois de o corretor escolher outra foto como capa.
     * O pertencimento da foto a este staging já é garantido pela validação
     * do SelecionarFotoCapaRequest (Rule::exists com where imovel_staging_id).
     */
    public function selecionarFotoCapa(SelecionarFotoCapaRequest $request, ImovelStaging $imovelStaging): JsonResponse
    {
        $imovelStaging->update([
            'foto_capa_id' => $request->validated('foto_id'),
        ]);

        return response()->json($imovelStaging->fresh(), 200);
    }
}
