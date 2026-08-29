<?php

namespace App\Http\Controllers;

use App\Exceptions\AnaliseFotosException;
use App\Exceptions\EnderecoNaoEncontradoException;
use App\Exceptions\EnriquecimentoLocalizacaoException;
use App\Http\Requests\SelecionarFotoCapaRequest;
use App\Http\Requests\StoreImovelStagingRequest;
use App\Jobs\GerarDescricaoImovelJob;
use App\Models\ImovelStaging;
use App\Models\ImovelStagingFoto;
use App\Services\AnaliseFotosService;
use App\Services\BuscaAnoConstrucaoService;
use App\Services\EnderecoValidator;
use App\Services\EnriquecimentoLocalizacaoService;
use App\Services\GeracaoTituloDescricaoService;
use App\Services\ValidadorQualidadeAnuncioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ImovelStagingController extends Controller
{
    // Público de propósito: ValidadorQualidadeAnuncioService reaproveita
    // exatamente esta constante para o bloqueio de "Fotografias" — nunca
    // duplica o número em outro lugar.
    public const MINIMO_FOTOS = 25;

    private const CAMPOS_ENDERECO = ['logradouro', 'numero', 'sem_numero', 'bairro', 'cidade', 'cep', 'estado'];

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
        EnriquecimentoLocalizacaoService $enriquecimentoLocalizacaoService,
        BuscaAnoConstrucaoService $buscaAnoConstrucaoService
    ): JsonResponse {
        if ($imovelStaging->localizacao && ! $request->boolean('forcar')) {
            return response()->json($imovelStaging, 200);
        }

        $endereco = [
            'logradouro' => $imovelStaging->logradouro,
            'numero' => $imovelStaging->numero,
            'bairro' => $imovelStaging->bairro,
            'cidade' => $imovelStaging->cidade,
            'estado' => $imovelStaging->estado,
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

        $dadosAtualizados = ['localizacao' => $localizacao];

        // O endereço agora CONFIRMADO alimenta também a busca de ano de
        // construção — mesmo serviço já usado na extração inicial, chamado
        // de novo aqui (idempotente: só busca de verdade se ano_construcao
        // ainda estiver null) porque o endereço estruturado confirmado
        // nesta tela costuma ser mais completo do que o que saiu só da fala
        // livre. Nunca trava o enriquecimento de localização por causa disso.
        $anoEncontrado = $buscaAnoConstrucaoService->buscar([
            'tipo_imovel' => $imovelStaging->tipo_imovel,
            'ano_construcao' => $imovelStaging->ano_construcao,
            'nome_edificio' => $imovelStaging->nome_edificio,
            'logradouro' => $imovelStaging->logradouro,
            'bairro' => $imovelStaging->bairro,
            'cidade' => $imovelStaging->cidade,
        ]);

        if ($anoEncontrado !== null) {
            $dadosAtualizados['ano_construcao'] = $anoEncontrado;
        }

        $imovelStaging->update($dadosAtualizados);

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
            return response()->json($imovelStaging->fresh()->load(['fotos.edicaoAtiva']), 200);
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
            // NUNCA array_unique() aqui: alertas_fotos é um array de arrays
            // associativos {foto_id, mensagem} — array_unique() converte
            // cada elemento pra string ("Array") pra comparar, então TODOS
            // os elementos colidiriam e só o primeiro sobreviveria. O
            // serviço já deduplica por (foto_id|mensagem) antes de devolver.
            'alertas_fotos' => array_values($analise['alertas_fotos'] ?? []),
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

        // Sugestão de itens removíveis (por foto) — substitui por inteiro a
        // cada análise, mesma regra de "nunca acumula resultado obsoleto"
        // já aplicada aos demais campos exclusivamente fotográficos.
        $itensRemoviveisPorFoto = $analise['itens_removiveis_por_foto'] ?? [];
        foreach (array_column($fotos, 'id') as $fotoId) {
            ImovelStagingFoto::whereKey($fotoId)->update([
                'itens_removiveis_sugeridos' => $itensRemoviveisPorFoto[$fotoId] ?? [],
            ]);
        }

        return response()->json($imovelStaging->fresh()->load(['fotos.edicaoAtiva']), 200);
    }

    /**
     * Endpoint DEDICADO (não roda automaticamente dentro de analisarFotos())
     * de propósito: mantém a contagem de chamadas de analisar-fotos()
     * intocada e deixa este passo explícito no fluxo — o frontend chama
     * isto logo após a análise de fotos ter sucesso.
     *
     * Título e descrição são gerados de forma INDEPENDENTE: o título é
     * determinístico (sem IA, sem risco de falhar por causa de rede/API) e
     * é gerado e persistido AQUI MESMO, de forma síncrona — o endpoint
     * sempre devolve o título imediatamente. A descrição, por poder
     * legitimamente passar de 3.000 caracteres, é gerada de forma
     * ASSÍNCRONA (GerarDescricaoImovelJob, mesmo padrão de
     * GerarEdicaoFotoJob): este endpoint só reserva o status "pendente" e
     * despacha o job, sem esperar o resultado. O frontend acompanha o
     * progresso fazendo polling em statusDescricao(). Cada campo só é
     * preenchido/despachado SE estiver vazio — nunca sobrescreve o que o
     * corretor já digitou (seja edição manual, seja geração automática
     * anterior): o único jeito de disparar uma NOVA geração para um campo
     * é limpá-lo primeiro, o que já é, em si, uma ação expressa do
     * corretor. Chamadas duplicadas (duplo clique) não despacham um
     * segundo job: um job só é despachado se a descrição não estiver já em
     * andamento (pendente/processando).
     */
    public function gerarTituloDescricao(ImovelStaging $imovelStaging, GeracaoTituloDescricaoService $service): JsonResponse
    {
        $faltaTitulo = empty($imovelStaging->titulo_site);
        $faltaDescricao = empty($imovelStaging->descricao_gerada);
        $descricaoEmAndamento = in_array($imovelStaging->descricao_geracao_status, ImovelStaging::DESCRICAO_EM_ANDAMENTO, true);

        if ($faltaTitulo) {
            $tituloGerado = $service->gerarTitulo($imovelStaging);

            if ($tituloGerado !== null) {
                $imovelStaging->update(['titulo_site' => $tituloGerado]);
            }
        }

        if ($faltaDescricao && ! $descricaoEmAndamento) {
            $imovelStaging->update([
                'descricao_geracao_status' => ImovelStaging::DESCRICAO_PENDENTE,
                'descricao_geracao_erro' => null,
            ]);

            GerarDescricaoImovelJob::dispatch($imovelStaging->id);
        }

        return response()->json($imovelStaging->fresh(), 200);
    }

    /**
     * Status enxuto da geração ASSÍNCRONA da descrição — usado pelo
     * frontend em polling enquanto "pendente"/"processando". Devolve
     * também título e descrição já persistidos: quando o job conclui (ou
     * quando o corretor edita manualmente enquanto o job roda), o
     * resultado final já está aqui, sem round-trip extra.
     */
    public function statusDescricao(ImovelStaging $imovelStaging): JsonResponse
    {
        return response()->json([
            'titulo_site' => $imovelStaging->titulo_site,
            'descricao_gerada' => $imovelStaging->descricao_gerada,
            'descricao_geracao_status' => $imovelStaging->descricao_geracao_status,
            'descricao_geracao_erro' => $imovelStaging->descricao_geracao_erro,
        ]);
    }

    /**
     * Roda o motor de validação de qualidade editorial/comercial (Fase 1)
     * sob demanda — nunca automático a cada request. Persiste só
     * `pontuacao_qualidade`/`data_ultima_validacao` (os próprios
     * bloqueios/alertas/sugestoes são sempre recalculados, nunca
     * armazenados — ficariam obsoletos silenciosamente).
     */
    public function validarQualidade(ImovelStaging $imovelStaging, ValidadorQualidadeAnuncioService $validador): JsonResponse
    {
        $resultado = $validador->validar($imovelStaging);

        $imovelStaging->update([
            'pontuacao_qualidade' => $resultado['pontuacao'],
            'data_ultima_validacao' => now(),
        ]);

        return response()->json($resultado, 200);
    }

    /**
     * Corretor reconhece um alerta/sugestão específico e decide ignorá-lo
     * — a partir daqui, esse item some das próximas validações (a menos
     * que o dado relacionado mude e o motor gere uma mensagem diferente).
     * Nunca aplicável a bloqueios (não são dispensáveis — o próprio
     * ValidadorQualidadeAnuncioService nunca filtra a lista de bloqueios
     * por pendências confirmadas).
     */
    public function confirmarPendencia(Request $request, ImovelStaging $imovelStaging): JsonResponse
    {
        $dados = $request->validate(['mensagem' => ['required', 'string']]);

        $confirmadas = $imovelStaging->pendencias_confirmadas ?? [];
        if (! in_array($dados['mensagem'], $confirmadas, true)) {
            $confirmadas[] = $dados['mensagem'];
            $imovelStaging->update(['pendencias_confirmadas' => $confirmadas]);
        }

        return response()->json($imovelStaging->fresh(), 200);
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

        // Endereço completo é obrigatório para a entrega ao Prontos — nunca
        // concluímos com endereço incompleto ou não confirmado. A mensagem
        // lista SÓ os campos realmente ausentes (nunca uma lista genérica
        // fixa) — evita confundir o corretor com campos que já preencheu.
        $camposEnderecoFaltando = EnderecoValidator::camposFaltantesParaConclusao([
            'logradouro' => $imovelStaging->logradouro,
            'numero' => $imovelStaging->numero,
            'sem_numero' => $imovelStaging->sem_numero,
            'bairro' => $imovelStaging->bairro,
            'cidade' => $imovelStaging->cidade,
            'cep' => $imovelStaging->cep,
            'estado' => $imovelStaging->estado,
        ]);

        if ($camposEnderecoFaltando !== []) {
            return response()->json([
                'message' => 'Endereço incompleto: preencha '.implode(', ', $camposEnderecoFaltando).' antes de concluir.',
            ], 422);
        }

        if (empty($imovelStaging->titulo_site) || empty($imovelStaging->descricao_gerada)) {
            return response()->json([
                'message' => 'Título e descrição do anúncio são obrigatórios para concluir o cadastro.',
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
