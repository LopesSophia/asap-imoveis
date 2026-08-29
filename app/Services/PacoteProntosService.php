<?php

namespace App\Services;

use App\Exceptions\PacoteProntosException;
use App\Models\ImovelStaging;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * Monta o pacote de entrega ao Prontos: o texto formatado (usado tanto
 * pelos botões "copiar" quanto dentro do ZIP) e o próprio arquivo ZIP.
 * Fonte única de verdade para os dois — nunca duplica a formatação.
 *
 * Não existe integração real com o Prontos nesta versão: isto só PREPARA
 * um pacote para o corretor copiar/baixar manualmente.
 */
class PacoteProntosService
{
    /**
     * Rótulos legíveis dos diferenciais (a lista fechada vem de
     * StoreImovelStagingRequest — mantida aqui só como rótulo de exibição,
     * não como nova fonte de validação).
     *
     * @var array<string, string>
     */
    private const ROTULOS_DIFERENCIAIS = [
        'armario_embutido' => 'Armário embutido',
        'cozinha_mobiliada' => 'Cozinha mobiliada',
        'portaria' => 'Portaria',
        'lavabo' => 'Lavabo',
        'churrasqueira' => 'Churrasqueira',
        'garagem' => 'Garagem',
        'quintal' => 'Quintal',
        'dependencia_empregados' => 'Dependência de empregados',
        'servicos' => 'Área de serviço',
        'cozinha_americana' => 'Cozinha americana',
        'piscina' => 'Piscina',
    ];

    /**
     * @return array<string, string> chave da seção => texto formatado.
     */
    public function montarSecoes(ImovelStaging $imovelStaging): array
    {
        return [
            'identificacao' => $this->secaoIdentificacao($imovelStaging),
            'medidas' => $this->secaoMedidas($imovelStaging),
            'negocio' => $this->secaoNegocio($imovelStaging),
            'adicionais' => $this->secaoAdicionais($imovelStaging),
            'anuncio' => $this->secaoAnuncio($imovelStaging),
            'fotos' => $this->secaoFotos($imovelStaging),
        ];
    }

    public function montarTextoCompleto(ImovelStaging $imovelStaging): string
    {
        return implode("\n\n", $this->montarSecoes($imovelStaging));
    }

    /**
     * Gera o ZIP em um arquivo temporário e devolve o caminho — nunca toca
     * nos arquivos originais, só LÊ e copia. Chamador é responsável por
     * apagar o arquivo temporário depois de servir o download.
     */
    public function gerarZip(ImovelStaging $imovelStaging): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new PacoteProntosException('A extensão PHP "zip" não está disponível neste servidor. Peça para o suporte habilitar a extensão "zip" no php.ini.');
        }

        $caminhoZip = tempnam(sys_get_temp_dir(), 'prontos_').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($caminhoZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new PacoteProntosException('Não foi possível criar o arquivo ZIP.');
        }

        $zip->addFromString('cadastro-prontos.txt', $this->montarTextoCompleto($imovelStaging));

        foreach ($this->montarListaDeArquivos($imovelStaging) as $arquivo) {
            $zip->addFile($this->caminhoAbsolutoSeguro($arquivo['origem']), $arquivo['destino']);
        }

        $zip->close();

        return $caminhoZip;
    }

    /**
     * Decide QUAIS fotos entram no pacote e com que nome — sem tocar em
     * ZipArchive, pra manter a regra de negócio (capa primeiro e nunca
     * duplicada, edição APROVADA ativa substituindo o original nas fotos
     * "para o Prontos", pasta "originais" sempre com o arquivo original de
     * TODAS as fotos) testável independente da extensão "zip" estar
     * disponível.
     *
     * @return array<int, array{origem: string, destino: string}>
     */
    public function montarListaDeArquivos(ImovelStaging $imovelStaging): array
    {
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();
        $fotoCapa = $fotos->firstWhere('id', $imovelStaging->foto_capa_id) ?? $fotos->first();

        $arquivos = [];

        // Fotos para o Prontos: capa primeiro (nunca duplicada), depois as
        // demais na ordem — usando a edição APROVADA ativa quando houver
        // (caminhoAtivo() nunca resolve para uma edição rejeitada ou apenas
        // "gerada"/pendente, só para uma com status "aprovada" — a mesma
        // regra de edicao_ativa_id usada em toda a tela de fotos).
        if ($fotoCapa) {
            $arquivos[] = [
                'origem' => $fotoCapa->caminhoAtivo(),
                'destino' => 'fotos-para-prontos/01-CAPA'.$this->extensao($fotoCapa->caminhoAtivo()),
            ];
        }

        $indice = 1;
        foreach ($fotos as $foto) {
            if ($fotoCapa && $foto->id === $fotoCapa->id) {
                continue;
            }

            $indice++;
            $arquivos[] = [
                'origem' => $foto->caminhoAtivo(),
                'destino' => sprintf('fotos-para-prontos/%02d-foto%s', $indice, $this->extensao($foto->caminhoAtivo())),
            ];
        }

        // Pasta "originais": TODAS as fotos originais, sempre — nunca a
        // versão editada — para rastreabilidade completa independente do
        // que foi aprovado para uso.
        foreach ($fotos as $i => $foto) {
            $arquivos[] = [
                'origem' => $foto->caminho,
                'destino' => sprintf('originais/%02d-original%s', $i + 1, $this->extensao($foto->caminho)),
            ];
        }

        return $arquivos;
    }

    private function extensao(string $caminho): string
    {
        $extensao = pathinfo($caminho, PATHINFO_EXTENSION);

        return $extensao !== '' ? ".{$extensao}" : '';
    }

    /**
     * Resolve um caminho relativo do disco "public" para um caminho
     * absoluto real, recusando qualquer resultado que escape da raiz do
     * disco (proteção contra path traversal) ou que não exista.
     */
    private function caminhoAbsolutoSeguro(string $caminhoRelativo): string
    {
        if (! Storage::disk('public')->exists($caminhoRelativo)) {
            throw new PacoteProntosException("Arquivo de foto não encontrado: \"{$caminhoRelativo}\".");
        }

        $raiz = realpath(Storage::disk('public')->path(''));
        $absoluto = realpath(Storage::disk('public')->path($caminhoRelativo));

        if ($raiz === false || $absoluto === false || ! str_starts_with($absoluto, $raiz)) {
            throw new PacoteProntosException('Caminho de arquivo inválido.');
        }

        return $absoluto;
    }

    private function secaoIdentificacao(ImovelStaging $imovelStaging): string
    {
        $linhas = [
            'IDENTIFICAÇÃO E LOCALIZAÇÃO',
            'CEP: '.$this->texto($imovelStaging->cep),
            'Tipo de imóvel: '.$this->texto($imovelStaging->tipo_imovel),
            'Utilização: '.$this->texto($imovelStaging->utilizacao),
            'Em condomínio: '.$this->booleano($imovelStaging->em_condominio),
            'Nome do condomínio/edifício: '.$this->texto($imovelStaging->nome_edificio),
            'Estado: '.$this->texto($imovelStaging->estado),
            'Cidade: '.$this->texto($imovelStaging->cidade),
            'Bairro: '.$this->texto($imovelStaging->bairro),
            'Logradouro: '.$this->texto($imovelStaging->logradouro),
            'Número: '.($imovelStaging->sem_numero ? 'Sem número' : $this->texto($imovelStaging->numero)),
            'Complemento: '.$this->texto($imovelStaging->complemento),
        ];

        return implode("\n", $linhas);
    }

    private function secaoMedidas(ImovelStaging $imovelStaging): string
    {
        $linhas = [
            'MEDIDAS E CARACTERÍSTICAS',
            'Área útil (m²): '.$this->texto($imovelStaging->metragem),
            'Área total (m²): '.$this->texto($imovelStaging->area_total),
            'Dormitórios: '.$this->texto($imovelStaging->quartos),
            'Suítes: '.$this->texto($imovelStaging->suites),
            'Banheiros: '.$this->texto($imovelStaging->banheiros),
            'Salas: '.$this->texto($imovelStaging->salas),
            'Vagas: '.$this->texto($imovelStaging->vagas),
        ];

        return implode("\n", $linhas);
    }

    private function secaoNegocio(ImovelStaging $imovelStaging): string
    {
        $rotulos = [
            'venda' => 'Venda',
            'locacao' => 'Locação',
            'venda_e_locacao' => 'Venda e locação',
        ];

        $linhas = [
            'TIPO DE NEGÓCIO',
            'Negociação: '.($rotulos[$imovelStaging->negociacao] ?? $this->texto($imovelStaging->negociacao)),
            'Valor: '.$this->moeda($imovelStaging->valor),
        ];

        return implode("\n", $linhas);
    }

    private function secaoAdicionais(ImovelStaging $imovelStaging): string
    {
        $linhas = [
            'INFORMAÇÕES ADICIONAIS',
            'Condomínio (R$): '.$this->moeda($imovelStaging->condominio),
            'IPTU (R$): '.($imovelStaging->iptu_isento ? 'Isento' : $this->moeda($imovelStaging->iptu)),
            'Chaves: '.$this->texto($imovelStaging->chaves),
            'Ano de construção: '.$this->texto($imovelStaging->ano_construcao),
        ];

        return implode("\n", $linhas);
    }

    private function secaoAnuncio(ImovelStaging $imovelStaging): string
    {
        $diferenciais = collect($imovelStaging->diferenciais_uniao ?? [])
            ->map(fn ($slug) => self::ROTULOS_DIFERENCIAIS[$slug] ?? $slug)
            ->implode(', ');

        $observacoesVisuais = implode(' ', $imovelStaging->observacoes_visuais ?? []);

        $linhas = [
            'CONTEÚDO DO ANÚNCIO',
            'Título: '.$this->texto($imovelStaging->titulo_site),
            '',
            'Descrição:',
            $this->texto($imovelStaging->descricao_gerada),
            '',
            'Diferenciais: '.($diferenciais !== '' ? $diferenciais : '—'),
            'Observações úteis: '.($observacoesVisuais !== '' ? $observacoesVisuais : $this->texto($imovelStaging->observacoes_corretor)),
        ];

        return implode("\n", $linhas);
    }

    private function secaoFotos(ImovelStaging $imovelStaging): string
    {
        $total = $imovelStaging->fotos()->count();

        return implode("\n", [
            'FOTOS',
            "{$total} foto(s) — ver pasta \"fotos-para-prontos\" no pacote ZIP (01-CAPA é a foto de capa).",
        ]);
    }

    private function texto($valor): string
    {
        return $valor === null || $valor === '' ? '—' : (string) $valor;
    }

    private function moeda($valor): string
    {
        return $valor === null ? '—' : 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    private function booleano($valor): string
    {
        return $valor === null ? '—' : ($valor ? 'Sim' : 'Não');
    }
}
