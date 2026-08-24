<?php

namespace App\Services;

use App\Exceptions\AnaliseFotosException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AnaliseFotosService
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    private const ANTHROPIC_VERSION = '2023-06-01';

    private const TOOL_NAME = 'analisar_fotos_imovel';

    private const FOTOS_POR_LOTE = 3;

    private const LADO_MAXIMO_PX = 1568;

    private const QUALIDADE_JPEG = 82;

    /**
     * Analisa todas as fotos do imóvel (em lotes) e consolida o resultado,
     * incluindo a escolha da melhor candidata a foto de capa entre todos os
     * lotes (comparando "pontuacao"; em empate, vence a foto processada
     * primeiro — critério determinístico, sem novo desempate por IA).
     *
     * @param  array<int, array{id: int, caminho: string}>  $fotos  Registros de imovel_staging_fotos (id + caminho no disco "public").
     * @return array{diferenciais: string[], diferenciais_outros: string[], observacoes_visuais: string[], alertas_fotos: string[], foto_capa_sugerida_id: ?int, foto_capa_motivo: ?string}
     *
     * Nota: este serviço só produz uma SUGESTÃO (foto_capa_sugerida_id) — a
     * foto de capa efetivamente ativa é uma decisão do controller/corretor,
     * não deste serviço.
     */
    public function analisar(array $fotos): array
    {
        // Fotos de celular decodificadas pelo GD (bitmap bruto, antes do resize)
        // podem passar de 50MB cada — folga acima do memory_limit padrão.
        ini_set('memory_limit', '512M');

        $apiKey = config('services.anthropic.key');

        if (empty($apiKey)) {
            throw new AnaliseFotosException('ANTHROPIC_API_KEY não está configurada no .env.');
        }

        $diferenciais = [];
        $diferenciaisOutros = [];
        $observacoesVisuais = [];
        $alertasFotos = [];
        $melhorCandidataCapa = null;

        foreach (array_chunk($fotos, self::FOTOS_POR_LOTE) as $lote) {
            $resultadoLote = $this->analisarLote($lote, $apiKey);

            $diferenciais = array_values(array_unique(array_merge($diferenciais, $resultadoLote['diferenciais'] ?? [])));
            $diferenciaisOutros = array_values(array_unique(array_merge($diferenciaisOutros, $resultadoLote['diferenciais_outros'] ?? [])));
            $observacoesVisuais = array_values(array_unique(array_merge($observacoesVisuais, $resultadoLote['observacoes_visuais'] ?? [])));
            $alertasFotos = array_values(array_unique(array_merge($alertasFotos, $resultadoLote['alertas_fotos'] ?? [])));

            $idsValidosDoLote = array_column($lote, 'id');
            $candidata = $this->validarCandidataCapa($resultadoLote['candidata_capa'] ?? null, $idsValidosDoLote);

            if ($candidata !== null) {
                // Estritamente ">" (não ">="): em empate, a candidata já
                // guardada — de um lote processado antes — permanece vencedora.
                if ($melhorCandidataCapa === null || $candidata['pontuacao'] > $melhorCandidataCapa['pontuacao']) {
                    $melhorCandidataCapa = $candidata;
                }
            }
        }

        return [
            'diferenciais' => $diferenciais,
            'diferenciais_outros' => $diferenciaisOutros,
            'observacoes_visuais' => $observacoesVisuais,
            'alertas_fotos' => $alertasFotos,
            'foto_capa_sugerida_id' => $melhorCandidataCapa['foto_id'] ?? null,
            'foto_capa_motivo' => $melhorCandidataCapa['motivo'] ?? null,
        ];
    }

    /**
     * @param  array<int, array{id: int, caminho: string}>  $fotos
     * @return array<string, mixed>
     */
    private function analisarLote(array $fotos, string $apiKey): array
    {
        $blocosImagem = [];

        foreach ($fotos as $foto) {
            $blocosImagem[] = ['type' => 'text', 'text' => "Foto id={$foto['id']}:"];
            $blocosImagem[] = [
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'media_type' => 'image/jpeg',
                    'data' => $this->prepararImagemBase64($foto['caminho']),
                ],
            ];
        }

        $resposta = Http::withHeaders([
            'x-api-key' => $apiKey,
            'anthropic-version' => self::ANTHROPIC_VERSION,
            'content-type' => 'application/json',
        ])->post(self::ENDPOINT, [
            'model' => config('services.anthropic.model'),
            'max_tokens' => 2048,
            'system' => $this->systemPrompt(),
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Analise estas fotos do imóvel.'],
                        ...$blocosImagem,
                    ],
                ],
            ],
            'tools' => [
                [
                    'name' => self::TOOL_NAME,
                    'description' => 'Analisa fotos do imóvel e retorna diferenciais, observações visuais, alertas de fotos que não parecem ser do imóvel, e a melhor candidata a foto de capa do lote.',
                    'input_schema' => $this->inputSchema(),
                ],
            ],
            'tool_choice' => ['type' => 'tool', 'name' => self::TOOL_NAME],
        ]);

        if ($resposta->failed()) {
            $mensagem = $resposta->json('error.message') ?? $resposta->body();

            throw new AnaliseFotosException(
                "Falha ao chamar a API da Anthropic para análise de fotos (HTTP {$resposta->status()}): {$mensagem}"
            );
        }

        $blocoToolUse = collect($resposta->json('content', []))
            ->firstWhere('type', 'tool_use');

        if (! $blocoToolUse || ! isset($blocoToolUse['input'])) {
            throw new AnaliseFotosException('A resposta da Anthropic não trouxe o tool_use esperado na análise de fotos.');
        }

        return $blocoToolUse['input'];
    }

    /**
     * Valida a candidata_capa de UM lote: precisa ter identificador_foto que
     * resolva para um id de foto realmente enviado neste lote (nunca confia
     * cegamente num identificador inventado/alucinado), pontuacao numérica e
     * motivo não vazio. Qualquer coisa fora disso é tratada como "sem
     * candidata válida neste lote" — nunca interrompe a finalização.
     *
     * @param  mixed  $candidata
     * @param  int[]  $idsValidosDoLote
     * @return array{foto_id: int, pontuacao: int, motivo: string}|null
     */
    private function validarCandidataCapa($candidata, array $idsValidosDoLote): ?array
    {
        if (! is_array($candidata)) {
            return null;
        }

        $identificador = $candidata['identificador_foto'] ?? null;
        $pontuacao = $candidata['pontuacao'] ?? null;
        $motivo = $candidata['motivo'] ?? null;

        if ($identificador === null || ! is_numeric($identificador) || ! is_numeric($pontuacao) || ! is_string($motivo) || trim($motivo) === '') {
            Log::info('AnaliseFotosService: candidata_capa malformada retornada pela IA, ignorada.', [
                'candidata' => $candidata,
            ]);

            return null;
        }

        $fotoId = (int) $identificador;

        if (! in_array($fotoId, $idsValidosDoLote, true)) {
            Log::info('AnaliseFotosService: candidata_capa referencia um identificador fora do lote enviado, ignorada.', [
                'identificador_foto' => $identificador,
                'ids_validos_do_lote' => $idsValidosDoLote,
            ]);

            return null;
        }

        return [
            'foto_id' => $fotoId,
            'pontuacao' => (int) $pontuacao,
            'motivo' => $motivo,
        ];
    }

    /**
     * Redimensiona (lado maior <= 1568px) e recomprime como JPEG ~82% antes do
     * base64 — fotos de celular (5-10MB) sem esse tratamento estouram o limite
     * de tamanho de request da API da Anthropic, mesmo em lotes pequenos.
     */
    private function prepararImagemBase64(string $caminho): string
    {
        $conteudoOriginal = Storage::disk('public')->get($caminho);
        $imagem = @imagecreatefromstring($conteudoOriginal);

        if ($imagem === false) {
            throw new AnaliseFotosException("Não foi possível processar a imagem \"{$caminho}\" para análise.");
        }

        $larguraOriginal = imagesx($imagem);
        $alturaOriginal = imagesy($imagem);
        $ladoMaior = max($larguraOriginal, $alturaOriginal);

        if ($ladoMaior > self::LADO_MAXIMO_PX) {
            $escala = self::LADO_MAXIMO_PX / $ladoMaior;
            $novaLargura = (int) round($larguraOriginal * $escala);
            $novaAltura = (int) round($alturaOriginal * $escala);

            $imagemRedimensionada = imagecreatetruecolor($novaLargura, $novaAltura);
            imagefill($imagemRedimensionada, 0, 0, imagecolorallocate($imagemRedimensionada, 255, 255, 255));
            imagecopyresampled(
                $imagemRedimensionada, $imagem,
                0, 0, 0, 0,
                $novaLargura, $novaAltura, $larguraOriginal, $alturaOriginal
            );

            imagedestroy($imagem);
            $imagem = $imagemRedimensionada;
        } else {
            // Ainda achata transparência (PNG) sobre fundo branco antes de virar JPEG.
            $imagemAchatada = imagecreatetruecolor($larguraOriginal, $alturaOriginal);
            imagefill($imagemAchatada, 0, 0, imagecolorallocate($imagemAchatada, 255, 255, 255));
            imagecopy($imagemAchatada, $imagem, 0, 0, 0, 0, $larguraOriginal, $alturaOriginal);
            imagedestroy($imagem);
            $imagem = $imagemAchatada;
        }

        ob_start();
        imagejpeg($imagem, null, self::QUALIDADE_JPEG);
        $conteudoComprimido = ob_get_clean();
        imagedestroy($imagem);

        return base64_encode($conteudoComprimido);
    }

    private function systemPrompt(): string
    {
        return file_get_contents(resource_path('prompts/analise_fotos_etapa2.md'));
    }

    /**
     * @return array<string, mixed>
     */
    private function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'diferenciais' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'string',
                        'enum' => [
                            'armario_embutido',
                            'cozinha_mobiliada',
                            'portaria',
                            'lavabo',
                            'churrasqueira',
                            'garagem',
                            'quintal',
                            'dependencia_empregados',
                            'servicos',
                            'cozinha_americana',
                            'piscina',
                        ],
                    ],
                ],
                'diferenciais_outros' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'observacoes_visuais' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'alertas_fotos' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'candidata_capa' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'identificador_foto' => ['type' => 'string'],
                        'pontuacao' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10],
                        'motivo' => ['type' => 'string'],
                    ],
                ],
            ],
            'required' => ['diferenciais', 'diferenciais_outros', 'observacoes_visuais', 'alertas_fotos', 'candidata_capa'],
        ];
    }
}
