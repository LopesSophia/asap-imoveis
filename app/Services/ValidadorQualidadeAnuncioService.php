<?php

namespace App\Services;

use App\Http\Controllers\ImovelStagingController;
use App\Models\ImovelStaging;

/**
 * Motor central de validação de qualidade editorial/comercial de um
 * cadastro (Fase 1 — motor + campos; tela final e geração de conteúdo
 * extra vêm em fases separadas). Nunca espalhado pelas telas: qualquer
 * regra de "isto impede/atrapalha publicar" vive exclusivamente aqui.
 *
 * Vocabulário: `bloqueio` impede finalizar o cadastro (regra dura, nunca
 * dispensável pelo corretor); `alerta` desconta pontuação mas não
 * impede; `sugestao` é só recomendação, não desconta nada. Só
 * `alertas`/`sugestoes` podem ser reconhecidos e ignorados
 * (`pendencias_confirmadas`) — um `bloqueio` nunca é dispensável.
 */
class ValidadorQualidadeAnuncioService
{
    private const PONTOS_POR_ALERTA = 5;

    private const PONTOS_POR_BLOQUEIO = 15;

    private const DESCRICAO_MINIMO_TECNICO = 300;

    private const DESCRICAO_META_QUALIDADE = 3000;

    /**
     * @return array{pontuacao: int, aprovado: bool, bloqueios: array<int, array{categoria: string, mensagem: string, campo: string}>, alertas: array<int, array{categoria: string, mensagem: string, campo: string}>, sugestoes: array<int, array{categoria: string, mensagem: string, campo: string}>}
     */
    public function validar(ImovelStaging $imovel): array
    {
        $bloqueios = [
            ...$this->validarIdentificacao($imovel),
            ...$this->validarValoresBloqueios($imovel),
            ...$this->validarLocalizacaoBloqueios($imovel),
            ...$this->validarDescricaoBloqueios($imovel),
            ...$this->validarFotografiasBloqueios($imovel),
        ];

        $confirmadas = $imovel->pendencias_confirmadas ?? [];

        $alertas = $this->filtrarConfirmadas([
            ...$this->validarDisponibilidade($imovel),
            ...$this->validarValoresAlertas($imovel),
            ...$this->validarCaracteristicas($imovel),
            ...$this->validarLocalizacaoAlertas($imovel),
            ...$this->validarDescricaoAlertas($imovel),
            ...$this->validarFotografiasAlertas($imovel),
            ...$this->validarConsistenciaAlertas($imovel),
        ], $confirmadas);

        $sugestoes = $this->filtrarConfirmadas([
            ...$this->validarLocalizacaoSugestoes($imovel),
            ...$this->validarFotografiasSugestoes($imovel),
            ...$this->validarConsistenciaSugestoes($imovel),
        ], $confirmadas);

        return [
            'pontuacao' => $this->calcularPontuacao($bloqueios, $alertas),
            'aprovado' => $bloqueios === [],
            'bloqueios' => $bloqueios,
            'alertas' => $alertas,
            'sugestoes' => $sugestoes,
        ];
    }

    private function calcularPontuacao(array $bloqueios, array $alertas): int
    {
        $pontuacao = 100 - (count($bloqueios) * self::PONTOS_POR_BLOQUEIO) - (count($alertas) * self::PONTOS_POR_ALERTA);

        return max(0, $pontuacao);
    }

    /**
     * @param  array<int, array{categoria: string, mensagem: string, campo: string}>  $itens
     * @param  string[]  $confirmadas
     * @return array<int, array{categoria: string, mensagem: string, campo: string}>
     */
    private function filtrarConfirmadas(array $itens, array $confirmadas): array
    {
        return array_values(array_filter($itens, fn (array $item) => ! in_array($item['mensagem'], $confirmadas, true)));
    }

    private function item(string $categoria, string $mensagem, string $campo): array
    {
        return ['categoria' => $categoria, 'mensagem' => $mensagem, 'campo' => $campo];
    }

    // ------------------------------------------------------------------
    // Identificação (bloqueio)
    // ------------------------------------------------------------------

    private function validarIdentificacao(ImovelStaging $imovel): array
    {
        $itens = [];

        if ($imovel->tipo_imovel === null) {
            $itens[] = $this->item('Identificação', 'Tipo de imóvel não informado.', 'tipo_imovel');
        }
        if ($imovel->negociacao === null) {
            $itens[] = $this->item('Identificação', 'Negociação (venda/locação) não informada.', 'negociacao');
        }

        return $itens;
    }

    // ------------------------------------------------------------------
    // Disponibilidade (alerta)
    // ------------------------------------------------------------------

    private function validarDisponibilidade(ImovelStaging $imovel): array
    {
        if ($imovel->disponibilidade_visita !== null) {
            return [];
        }

        return [$this->item('Disponibilidade', 'Disponibilidade para visita não informada.', 'disponibilidade_visita')];
    }

    // ------------------------------------------------------------------
    // Valores (bloqueio + alerta) — inclui a checagem de "Condomínio" da
    // proposta original (nunca duplicada, só referenciada aqui).
    // ------------------------------------------------------------------

    private function validarValoresBloqueios(ImovelStaging $imovel): array
    {
        $itens = [];

        if ($imovel->valor === null) {
            $itens[] = $this->item('Valores', 'Valor não informado.', 'valor');
        }

        if ($imovel->em_condominio) {
            if ($imovel->condominio_situacao === null) {
                $itens[] = $this->item(
                    'Valores',
                    "Situação do condomínio não definida — informe valor, isenção ou 'sob consulta'.",
                    'condominio_situacao'
                );
            } elseif ($imovel->condominio_situacao === 'valor_informado' && $imovel->condominio === null) {
                $itens[] = $this->item(
                    'Valores',
                    "Condomínio marcado como 'valor informado' mas o valor está vazio.",
                    'condominio'
                );
            }
        }

        return $itens;
    }

    private function validarValoresAlertas(ImovelStaging $imovel): array
    {
        $itens = [];

        if ($imovel->iptu_situacao === null) {
            $itens[] = $this->item('Valores', 'Situação do IPTU não definida.', 'iptu_situacao');

            return $itens;
        }

        if ($imovel->iptu_situacao === 'valor_informado') {
            if ($imovel->iptu === null) {
                $itens[] = $this->item(
                    'Valores',
                    "IPTU marcado como 'valor informado' mas o valor está vazio.",
                    'iptu'
                );
            }
            if ($imovel->iptu_periodicidade === null) {
                $itens[] = $this->item('Valores', 'Periodicidade do IPTU não informada.', 'iptu_periodicidade');
            }
        }

        return $itens;
    }

    // ------------------------------------------------------------------
    // Características (alerta)
    // ------------------------------------------------------------------

    private function validarCaracteristicas(ImovelStaging $imovel): array
    {
        $itens = [];

        if ($imovel->metragem === null) {
            $itens[] = $this->item('Características', 'Metragem não informada.', 'metragem');
        }
        if ($imovel->quartos === null && $imovel->utilizacao === 'residencial') {
            $itens[] = $this->item('Características', 'Número de quartos não informado.', 'quartos');
        }
        if ($imovel->vagas === null) {
            $itens[] = $this->item(
                'Características',
                'Vagas não informadas — confirme se realmente não há vaga ou se só não foi perguntado.',
                'vagas'
            );
        }

        return $itens;
    }

    // ------------------------------------------------------------------
    // Localização (bloqueio + alerta + sugestão)
    // ------------------------------------------------------------------

    private function validarLocalizacaoBloqueios(ImovelStaging $imovel): array
    {
        if ($imovel->bairro !== null) {
            return [];
        }

        return [$this->item('Localização', 'Bairro não informado — obrigatório para gerar título e descrição.', 'bairro')];
    }

    private function validarLocalizacaoAlertas(ImovelStaging $imovel): array
    {
        if ($imovel->cidade !== null) {
            return [];
        }

        return [$this->item('Localização', 'Cidade não informada.', 'cidade')];
    }

    private function validarLocalizacaoSugestoes(ImovelStaging $imovel): array
    {
        if ($imovel->localizacao !== null) {
            return [];
        }

        return [$this->item('Localização', 'Enriquecimento de localização ainda não foi executado.', 'localizacao')];
    }

    // ------------------------------------------------------------------
    // Descrição (bloqueio + alerta)
    // ------------------------------------------------------------------

    private function validarDescricaoBloqueios(ImovelStaging $imovel): array
    {
        $itens = [];

        if ($imovel->titulo_site === null) {
            $itens[] = $this->item('Descrição', 'Título ainda não gerado.', 'titulo_site');
        }

        if ($imovel->descricao_gerada === null) {
            $itens[] = $this->item('Descrição', 'Descrição ainda não gerada.', 'descricao_gerada');
        } elseif (mb_strlen($imovel->descricao_gerada) < self::DESCRICAO_MINIMO_TECNICO) {
            $itens[] = $this->item(
                'Descrição',
                'Descrição abaixo do mínimo técnico do Prontos ('.self::DESCRICAO_MINIMO_TECNICO.' caracteres).',
                'descricao_gerada'
            );
        }

        return $itens;
    }

    private function validarDescricaoAlertas(ImovelStaging $imovel): array
    {
        if ($imovel->descricao_gerada === null) {
            return [];
        }

        $tamanho = mb_strlen($imovel->descricao_gerada);
        if ($tamanho < self::DESCRICAO_MINIMO_TECNICO || $tamanho >= self::DESCRICAO_META_QUALIDADE) {
            return [];
        }

        return [$this->item(
            'Descrição',
            'Descrição abaixo da meta de qualidade de '.self::DESCRICAO_META_QUALIDADE.' caracteres.',
            'descricao_gerada'
        )];
    }

    // ------------------------------------------------------------------
    // Fotografias (bloqueio + alerta + sugestão)
    // ------------------------------------------------------------------

    private function validarFotografiasBloqueios(ImovelStaging $imovel): array
    {
        $total = $imovel->fotos()->count();
        if ($total >= ImovelStagingController::MINIMO_FOTOS) {
            return [];
        }

        $faltam = ImovelStagingController::MINIMO_FOTOS - $total;

        return [$this->item(
            'Fotografias',
            "Faltam {$faltam} fotos para completar o mínimo de ".ImovelStagingController::MINIMO_FOTOS." (você tem {$total}).",
            'fotos'
        )];
    }

    private function validarFotografiasAlertas(ImovelStaging $imovel): array
    {
        $total = count($imovel->alertas_fotos ?? []);
        if ($total === 0) {
            return [];
        }

        return [$this->item(
            'Fotografias',
            "{$total} foto(s) sinalizada(s) como possivelmente não sendo do imóvel — revise antes de publicar.",
            'alertas_fotos'
        )];
    }

    private function validarFotografiasSugestoes(ImovelStaging $imovel): array
    {
        if ($imovel->foto_capa_sugerida_id !== null) {
            return [];
        }

        return [$this->item('Fotografias', 'Nenhuma foto de capa sugerida ainda.', 'foto_capa_sugerida_id')];
    }

    // ------------------------------------------------------------------
    // Consistência geral (alerta + sugestão)
    // ------------------------------------------------------------------

    private function validarConsistenciaAlertas(ImovelStaging $imovel): array
    {
        if ($imovel->vagas_cobertura === null) {
            return [];
        }
        if ($imovel->vagas !== null && $imovel->vagas > 0) {
            return [];
        }

        return [$this->item(
            'Consistência geral',
            'Cobertura de vaga informada, mas quantidade de vagas está vazia ou zero — inconsistência.',
            'vagas'
        )];
    }

    private function validarConsistenciaSugestoes(ImovelStaging $imovel): array
    {
        if ($imovel->negociacao !== 'locacao' || $imovel->utilizacao !== 'residencial') {
            return [];
        }
        if ($imovel->iptu_situacao !== null || $imovel->condominio_situacao !== null) {
            return [];
        }

        return [$this->item(
            'Consistência geral',
            'Dados financeiros comuns em locação (IPTU/condomínio) ainda não foram informados.',
            'iptu_situacao'
        )];
    }
}
