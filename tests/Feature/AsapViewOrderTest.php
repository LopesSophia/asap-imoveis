<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AsapViewOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_secao_de_fotos_aparece_antes_de_diferenciais_titulo_descricao_e_observacoes(): void
    {
        $html = $this->get('/asap')->assertStatus(200)->getContent();

        $posicaoFotos = strpos($html, 'id="grade-fotos"');
        $posicaoDiferenciais = strpos($html, 'id="chips-diferenciais"');
        $posicaoTitulo = strpos($html, 'id="titulo_site"');
        $posicaoDescricao = strpos($html, 'id="descricao_gerada"');
        $posicaoObservacoes = strpos($html, 'id="observacoes_corretor"');

        $this->assertNotFalse($posicaoFotos);
        $this->assertNotFalse($posicaoDiferenciais);
        $this->assertNotFalse($posicaoTitulo);
        $this->assertNotFalse($posicaoDescricao);
        $this->assertNotFalse($posicaoObservacoes);

        $this->assertLessThan($posicaoDiferenciais, $posicaoFotos, 'Fotos deveriam vir antes de Diferenciais.');
        $this->assertLessThan($posicaoTitulo, $posicaoFotos, 'Fotos deveriam vir antes de Título.');
        $this->assertLessThan($posicaoDescricao, $posicaoFotos, 'Fotos deveriam vir antes de Descrição.');
        $this->assertLessThan($posicaoObservacoes, $posicaoFotos, 'Fotos deveriam vir antes de Observações.');
    }

    public function test_checkbox_iptu_isento_esta_presente(): void
    {
        $html = $this->get('/asap')->assertStatus(200)->getContent();

        $this->assertStringContainsString('id="iptu_isento"', $html);
    }

    /**
     * A revisão pós-análise precisa ser uma tela real e separada — mover
     * campos pra baixo na mesma tela (o que o teste acima já cobria antes
     * desta refatoração) não bastava, porque eles continuavam sendo
     * revisados antes de a IA analisar as fotos. Aqui confirmamos que as
     * 3 telas envolvidas (dados objetivos / fotos / revisão final) existem
     * como seções distintas, na ordem certa, e que a tela antiga de
     * confirmação/resumo não existe mais.
     */
    public function test_fluxo_tem_telas_distintas_para_fotos_e_revisao_final(): void
    {
        $html = $this->get('/asap')->assertStatus(200)->getContent();

        $posicaoRevisao = strpos($html, 'id="screen-revisao"');
        $posicaoFotos = strpos($html, 'id="screen-fotos"');
        $posicaoRevisaoFinal = strpos($html, 'id="screen-revisao-final"');
        $posicaoSucesso = strpos($html, 'id="screen-sucesso"');

        $this->assertNotFalse($posicaoRevisao);
        $this->assertNotFalse($posicaoFotos);
        $this->assertNotFalse($posicaoRevisaoFinal);
        $this->assertNotFalse($posicaoSucesso);

        $this->assertLessThan($posicaoFotos, $posicaoRevisao);
        $this->assertLessThan($posicaoRevisaoFinal, $posicaoFotos);
        $this->assertLessThan($posicaoSucesso, $posicaoRevisaoFinal);

        // screen-fotos só tem upload + botão "Analisar fotos" — nada de
        // diferenciais/título/descrição/observações antes da análise rodar.
        $screenFotos = substr($html, $posicaoFotos, strpos($html, 'id="screen-revisao-final"') - $posicaoFotos);
        $this->assertStringContainsString('id="btn-analisar-fotos"', $screenFotos);
        $this->assertStringNotContainsString('id="chips-diferenciais"', $screenFotos);
        $this->assertStringNotContainsString('id="titulo_site"', $screenFotos);

        $this->assertStringContainsString('id="btn-concluir"', $html);
        $this->assertStringNotContainsString('id="screen-confirmacao"', $html);
        $this->assertStringNotContainsString('id="btn-enviar"', $html);
    }
}
