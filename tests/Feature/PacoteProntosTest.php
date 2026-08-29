<?php

namespace Tests\Feature;

use App\Models\ImovelStaging;
use App\Models\ImovelStagingFoto;
use App\Models\ImovelStagingFotoEdicao;
use App\Models\User;
use App\Services\PacoteProntosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PacoteProntosTest extends TestCase
{
    use RefreshDatabase;

    private function criarRascunhoComFotos(int $quantidade, array $atributos = []): ImovelStaging
    {
        Storage::fake('public');

        $imovelStaging = ImovelStaging::create(array_merge([
            'corretor_id' => User::factory()->create()->id,
            'tipo_imovel' => 'apartamento',
            'status_propagacao' => 'rascunho',
        ], $atributos));

        for ($i = 0; $i < $quantidade; $i++) {
            $this->postJson("/api/imoveis-staging/{$imovelStaging->id}/fotos", [
                'fotos' => [UploadedFile::fake()->image("foto{$i}.jpg")],
            ]);
        }

        return $imovelStaging->fresh();
    }

    private function criarEdicao(ImovelStagingFoto $foto, string $status, ?string $caminhoEditado = null): ImovelStagingFotoEdicao
    {
        return $foto->edicoes()->create([
            'itens_solicitados' => [['categoria' => 'pessoa', 'descricao' => 'pessoa de teste']],
            'prompt_enviado' => 'prompt de teste',
            'provider' => 'gemini',
            'modelo' => 'gemini-2.5-flash-image',
            'status' => $status,
            'caminho_arquivo_editado' => $caminhoEditado,
        ]);
    }

    // ---- Ordem e capa primeiro ----

    public function test_lista_de_arquivos_coloca_a_capa_primeiro_como_01_capa(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(3);
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();
        $imovelStaging->update(['foto_capa_id' => $fotos[1]->id]);

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());

        $this->assertStringStartsWith('fotos-para-prontos/01-CAPA', $lista[0]['destino']);
        $this->assertSame($fotos[1]->caminho, $lista[0]['origem']);
    }

    public function test_lista_de_arquivos_mantem_ordem_das_demais_fotos_apos_a_capa(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(4);
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();
        $imovelStaging->update(['foto_capa_id' => $fotos[0]->id]);

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());

        // fotos-para-prontos: 01-CAPA (foto 0) + 02,03,04 (fotos 1,2,3 na ordem).
        $fotosParaProntos = array_values(array_filter($lista, fn ($a) => str_starts_with($a['destino'], 'fotos-para-prontos/')));

        $this->assertCount(4, $fotosParaProntos);
        $this->assertStringStartsWith('fotos-para-prontos/01-CAPA', $fotosParaProntos[0]['destino']);
        $this->assertStringStartsWith('fotos-para-prontos/02-foto', $fotosParaProntos[1]['destino']);
        $this->assertSame($fotos[1]->caminho, $fotosParaProntos[1]['origem']);
        $this->assertStringStartsWith('fotos-para-prontos/03-foto', $fotosParaProntos[2]['destino']);
        $this->assertSame($fotos[2]->caminho, $fotosParaProntos[2]['origem']);
        $this->assertStringStartsWith('fotos-para-prontos/04-foto', $fotosParaProntos[3]['destino']);
        $this->assertSame($fotos[3]->caminho, $fotosParaProntos[3]['origem']);
    }

    public function test_capa_nunca_e_duplicada_na_lista(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(3);
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();
        $imovelStaging->update(['foto_capa_id' => $fotos[0]->id]);

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());

        $fotosParaProntos = array_filter($lista, fn ($a) => str_starts_with($a['destino'], 'fotos-para-prontos/'));
        $destinosComOrigemDaCapa = array_filter($fotosParaProntos, fn ($a) => $a['origem'] === $fotos[0]->caminho);

        $this->assertCount(1, $destinosComOrigemDaCapa, 'A foto de capa não pode aparecer duas vezes em fotos-para-prontos.');
    }

    public function test_sem_capa_definida_usa_a_primeira_foto_como_capa(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(2);
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());

        $this->assertSame($fotos[0]->caminho, $lista[0]['origem']);
    }

    // ---- Substituição pela edição aprovada / exclusão de rejeitada e gerada ----

    public function test_fotos_para_prontos_usa_edicao_aprovada_quando_ha_edicao_ativa(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();

        $edicao = $this->criarEdicao($foto, ImovelStagingFotoEdicao::APROVADA, 'imoveis/1/edicoes/1/1.jpg');
        $foto->update(['edicao_ativa_id' => $edicao->id]);

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());

        // Única foto do imóvel = capa por padrão (nenhuma outra existe) — a
        // entrada em fotos-para-prontos precisa vir da edição aprovada.
        $this->assertSame('imoveis/1/edicoes/1/1.jpg', $lista[0]['origem']);
        $this->assertStringStartsWith('fotos-para-prontos/01-CAPA', $lista[0]['destino']);
    }

    public function test_edicao_apenas_gerada_nao_aprovada_nunca_entra_no_pacote(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $caminhoOriginal = $foto->caminho;

        // "gerada" mas NUNCA aprovada — edicao_ativa_id continua null.
        $this->criarEdicao($foto, ImovelStagingFotoEdicao::GERADA, 'imoveis/1/edicoes/1/1.jpg');

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());

        $this->assertSame($caminhoOriginal, $lista[0]['origem']);
    }

    public function test_edicao_rejeitada_nunca_entra_no_pacote(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $caminhoOriginal = $foto->caminho;

        $this->criarEdicao($foto, ImovelStagingFotoEdicao::REJEITADA, 'imoveis/1/edicoes/1/1.jpg');

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());

        $this->assertSame($caminhoOriginal, $lista[0]['origem']);
    }

    // ---- Originais preservados ----

    public function test_pasta_originais_sempre_usa_arquivo_original_mesmo_com_edicao_aprovada(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $caminhoOriginal = $foto->caminho;

        $edicao = $this->criarEdicao($foto, ImovelStagingFotoEdicao::APROVADA, 'imoveis/1/edicoes/1/1.jpg');
        $foto->update(['edicao_ativa_id' => $edicao->id]);

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());

        $originais = array_values(array_filter($lista, fn ($a) => str_starts_with($a['destino'], 'originais/')));
        $this->assertCount(1, $originais);
        $this->assertSame($caminhoOriginal, $originais[0]['origem']);
        $this->assertStringStartsWith('originais/01-original', $originais[0]['destino']);
    }

    public function test_pasta_originais_inclui_todas_as_fotos_na_ordem(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(3);
        $fotos = $imovelStaging->fotos()->orderBy('ordem')->get();

        $lista = app(PacoteProntosService::class)->montarListaDeArquivos($imovelStaging->fresh());
        $originais = array_values(array_filter($lista, fn ($a) => str_starts_with($a['destino'], 'originais/')));

        $this->assertCount(3, $originais);
        foreach ($fotos as $i => $foto) {
            $this->assertSame($foto->caminho, $originais[$i]['origem']);
        }
    }

    // ---- Validação de caminho (proteção contra path traversal / arquivo ausente) ----

    public function test_gerar_zip_falha_com_mensagem_clara_se_arquivo_nao_existir_no_disco(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        Storage::disk('public')->delete($foto->caminho);

        $this->expectExceptionMessage('Arquivo de foto não encontrado');
        app(PacoteProntosService::class)->gerarZip($imovelStaging->fresh());
    }

    // ---- Texto gerado ----

    public function test_texto_completo_contem_as_seis_secoes_na_ordem(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1, [
            'cep' => '04101-000',
            'estado' => 'SP',
            'cidade' => 'São Paulo',
            'bairro' => 'Vila Mariana',
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'metragem' => 75,
            'quartos' => 2,
            'negociacao' => 'venda',
            'valor' => 650000,
            'condominio' => 800,
            'chaves' => 'Com o corretor',
            'titulo_site' => 'Título de teste',
            'descricao_gerada' => 'Descrição de teste',
        ]);

        $texto = app(PacoteProntosService::class)->montarTextoCompleto($imovelStaging->fresh());

        $posIdentificacao = strpos($texto, 'IDENTIFICAÇÃO E LOCALIZAÇÃO');
        $posMedidas = strpos($texto, 'MEDIDAS E CARACTERÍSTICAS');
        $posNegocio = strpos($texto, 'TIPO DE NEGÓCIO');
        $posAdicionais = strpos($texto, 'INFORMAÇÕES ADICIONAIS');
        $posAnuncio = strpos($texto, 'CONTEÚDO DO ANÚNCIO');
        $posFotos = strpos($texto, 'FOTOS');

        foreach ([$posIdentificacao, $posMedidas, $posNegocio, $posAdicionais, $posAnuncio, $posFotos] as $pos) {
            $this->assertNotFalse($pos);
        }

        $this->assertLessThan($posMedidas, $posIdentificacao);
        $this->assertLessThan($posNegocio, $posMedidas);
        $this->assertLessThan($posAdicionais, $posNegocio);
        $this->assertLessThan($posAnuncio, $posAdicionais);
        $this->assertLessThan($posFotos, $posAnuncio);

        $this->assertStringContainsString('CEP: 04101-000', $texto);
        $this->assertStringContainsString('Título de teste', $texto);
        $this->assertStringContainsString('R$ 650.000,00', $texto);
    }

    public function test_texto_nunca_inclui_edicao_rejeitada_ou_apenas_gerada_na_secao_fotos(): void
    {
        // A seção "fotos" só descreve contagem/capa em texto — as fotos de
        // verdade só entram no ZIP — mas o texto não pode nunca citar um
        // arquivo de edição não aprovada.
        $imovelStaging = $this->criarRascunhoComFotos(1);
        $foto = $imovelStaging->fotos()->first();
        $this->criarEdicao($foto, ImovelStagingFotoEdicao::REJEITADA, 'imoveis/1/edicoes/1/rejeitada.jpg');

        $texto = app(PacoteProntosService::class)->montarTextoCompleto($imovelStaging->fresh());

        $this->assertStringNotContainsString('rejeitada.jpg', $texto);
    }

    // ---- Endpoints ----

    public function test_endpoint_dados_retorna_secoes_e_texto_completo(): void
    {
        $imovelStaging = $this->criarRascunhoComFotos(1);

        $response = $this->getJson("/api/imoveis-staging/{$imovelStaging->id}/pacote-prontos");

        $response->assertStatus(200)->assertJsonStructure([
            'secoes' => ['identificacao', 'medidas', 'negocio', 'adicionais', 'anuncio', 'fotos'],
            'texto_completo',
        ]);
    }

    public function test_endpoint_zip_retorna_erro_claro_quando_extensao_zip_indisponivel(): void
    {
        if (class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('Ambiente tem ext-zip disponível — este teste cobre especificamente a ausência dela.');
        }

        $imovelStaging = $this->criarRascunhoComFotos(1);

        $response = $this->getJson("/api/imoveis-staging/{$imovelStaging->id}/pacote-prontos.zip");

        $response->assertStatus(422)->assertJsonFragment([
            'message' => 'A extensão PHP "zip" não está disponível neste servidor. Peça para o suporte habilitar a extensão "zip" no php.ini.',
        ]);
    }

    // ---- Tela final ----

    public function test_tela_final_substitui_cadastro_enviado_por_cadastro_preparado(): void
    {
        $html = $this->get('/asap')->assertStatus(200)->getContent();

        $this->assertStringContainsString('Cadastro preparado para o Prontos', $html);
        $this->assertStringNotContainsString('Cadastro enviado!', $html);
        $this->assertStringNotContainsString('O imóvel foi registrado com sucesso.', $html);
    }

    public function test_tela_final_tem_as_seis_secoes_na_ordem_com_botoes_de_copiar_e_baixar_zip(): void
    {
        $html = $this->get('/asap')->assertStatus(200)->getContent();

        $posIdentificacao = strpos($html, 'data-secao="identificacao"');
        $posMedidas = strpos($html, 'data-secao="medidas"');
        $posNegocio = strpos($html, 'data-secao="negocio"');
        $posAdicionais = strpos($html, 'data-secao="adicionais"');
        $posAnuncio = strpos($html, 'data-secao="anuncio"');
        $posFotos = strpos($html, 'data-secao="fotos"');

        foreach ([$posIdentificacao, $posMedidas, $posNegocio, $posAdicionais, $posAnuncio, $posFotos] as $pos) {
            $this->assertNotFalse($pos);
        }

        $this->assertLessThan($posMedidas, $posIdentificacao);
        $this->assertLessThan($posNegocio, $posMedidas);
        $this->assertLessThan($posAdicionais, $posNegocio);
        $this->assertLessThan($posAnuncio, $posAdicionais);
        $this->assertLessThan($posFotos, $posAnuncio);

        $this->assertStringContainsString('id="btn-copiar-tudo"', $html);
        $this->assertStringContainsString('id="btn-baixar-zip"', $html);
        $this->assertStringContainsString('btn-copiar-secao', $html);
    }

    /**
     * Verificação ESTRUTURAL, não textual: a palavra "proprietário" aparece
     * legitimamente no placeholder do campo "Chaves" (ex.: "com o
     * proprietário...") e no comentário explicando que o sistema nunca
     * cadastra proprietário nesta versão — nenhum dos dois é um campo ou
     * seção de verdade, então proibir a palavra inteira no HTML gera falso
     * positivo. O que precisa ser garantido é que não existe CAMPO DE
     * FORMULÁRIO nem SEÇÃO relacionados a proprietário.
     */
    public function test_tela_final_nao_tem_campo_ou_secao_de_proprietario(): void
    {
        $html = $this->get('/asap')->assertStatus(200)->getContent();

        // 1. Nenhum input/select/textarea (id ou name) relacionado a
        // proprietário em nenhum lugar da página.
        $this->assertDoesNotMatchRegularExpression(
            '/<(input|select|textarea)\b[^>]*\b(id|name)="[^"]*propriet[^"]*"/i',
            $html,
            'Não deveria existir campo de formulário (input/select/textarea) relacionado a "proprietário".'
        );

        // 2. Nenhuma seção nem título de seção "Proprietário" na tela final
        // (screen-sucesso é a última seção do documento, antes do <script>).
        $posTelaFinal = strpos($html, 'id="screen-sucesso"');
        $this->assertNotFalse($posTelaFinal, 'Tela final (screen-sucesso) não encontrada no HTML.');
        $telaFinal = substr($html, $posTelaFinal, strpos($html, '<script>') - $posTelaFinal);

        $this->assertDoesNotMatchRegularExpression(
            '/data-secao="[^"]*propriet[^"]*"/i',
            $telaFinal,
            'Não deveria existir seção (data-secao) relacionada a "proprietário" na tela final.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<h[1-4][^>]*>[^<]*propriet[^<]*<\/h[1-4]>/i',
            $telaFinal,
            'Não deveria existir título de seção "Proprietário" na tela final.'
        );

        // Os textos legítimos (placeholder de "Chaves" e o aviso de que o
        // sistema nunca cadastra proprietário) permanecem no HTML e não são
        // verificados aqui — só a ausência de campo/seção importa.
    }
}
