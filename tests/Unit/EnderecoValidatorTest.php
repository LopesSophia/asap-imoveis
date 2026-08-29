<?php

namespace Tests\Unit;

use App\Services\EnderecoValidator;
use PHPUnit\Framework\TestCase;

class EnderecoValidatorTest extends TestCase
{
    public function test_endereco_completo_com_confianca_alta_e_valido(): void
    {
        $this->assertTrue(EnderecoValidator::completo([
            'logradouro' => 'Rua Serra de Botucatu',
            'numero' => '800',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
            'confianca' => 'alta',
        ]));
    }

    public function test_endereco_sem_logradouro_e_incompleto(): void
    {
        $this->assertFalse(EnderecoValidator::completo([
            'logradouro' => null,
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
            'confianca' => 'media',
        ]));
    }

    public function test_endereco_sem_bairro_e_incompleto(): void
    {
        $this->assertFalse(EnderecoValidator::completo([
            'logradouro' => 'Rua Serra de Botucatu',
            'bairro' => null,
            'cidade' => 'São Paulo',
            'confianca' => 'alta',
        ]));
    }

    public function test_endereco_sem_cidade_e_incompleto(): void
    {
        $this->assertFalse(EnderecoValidator::completo([
            'logradouro' => 'Rua Serra de Botucatu',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => null,
            'confianca' => 'alta',
        ]));
    }

    public function test_confianca_baixa_bloqueia_mesmo_com_campos_preenchidos(): void
    {
        $this->assertFalse(EnderecoValidator::completo([
            'logradouro' => 'Rua Serra de Botucatu',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
            'confianca' => 'baixa',
        ]));
    }

    public function test_confianca_ausente_nao_bloqueia_registro_ja_persistido(): void
    {
        // Depois que o endereço já está salvo no imovel_staging, "confianca"
        // não existe mais (era um sinal transitório da extração via IA).
        $this->assertTrue(EnderecoValidator::completo([
            'logradouro' => 'Rua Serra de Botucatu',
            'bairro' => 'Vila Regente Feijó',
            'cidade' => 'São Paulo',
        ]));
    }

    // ---- camposFaltantesParaConclusao() — regressão do Bug 2 ----
    // (mensagem de erro genérica que listava TODOS os campos mesmo quando
    // só "cidade", "CEP" e "estado" estavam realmente ausentes)

    public function test_endereco_completo_para_conclusao_nao_tem_campos_faltando(): void
    {
        $this->assertSame([], EnderecoValidator::camposFaltantesParaConclusao([
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'cep' => '04101-000',
            'estado' => 'SP',
        ]));
        $this->assertTrue(EnderecoValidator::completoParaConclusao([
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'cep' => '04101-000',
            'estado' => 'SP',
        ]));
    }

    /**
     * Reproduz EXATAMENTE o cenário relatado no Bug 2: logradouro, número,
     * complemento e bairro preenchidos; cidade, CEP e estado ausentes. A
     * lista de campos faltando precisa citar SÓ esses três — nunca os que
     * já estão preenchidos.
     */
    public function test_lista_apenas_os_campos_realmente_ausentes_no_cenario_do_bug(): void
    {
        $faltando = EnderecoValidator::camposFaltantesParaConclusao([
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'complemento' => 'apartamento 52',
            'bairro' => 'Vila Mariana',
            'cidade' => null,
            'cep' => null,
            'estado' => null,
        ]);

        $this->assertSame(['cidade', 'CEP', 'estado'], $faltando);
        $this->assertNotContains('logradouro', $faltando);
        $this->assertNotContains('número (ou marque "sem número")', $faltando);
        $this->assertNotContains('bairro', $faltando);
    }

    public function test_numero_ausente_mas_sem_numero_marcado_nao_e_listado_como_faltando(): void
    {
        $faltando = EnderecoValidator::camposFaltantesParaConclusao([
            'logradouro' => 'Rua Vergueiro',
            'numero' => null,
            'sem_numero' => true,
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'cep' => '04101-000',
            'estado' => 'SP',
        ]);

        $this->assertSame([], $faltando);
    }

    public function test_numero_ausente_sem_marcar_sem_numero_e_listado_como_faltando(): void
    {
        $faltando = EnderecoValidator::camposFaltantesParaConclusao([
            'logradouro' => 'Rua Vergueiro',
            'numero' => null,
            'sem_numero' => false,
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'cep' => '04101-000',
            'estado' => 'SP',
        ]);

        $this->assertContains('número (ou marque "sem número")', $faltando);
    }

    public function test_confianca_baixa_e_listada_como_pendencia_mesmo_com_campos_preenchidos(): void
    {
        $faltando = EnderecoValidator::camposFaltantesParaConclusao([
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'cep' => '04101-000',
            'estado' => 'SP',
            'confianca' => 'baixa',
        ]);

        $this->assertNotSame([], $faltando);
        $this->assertFalse(EnderecoValidator::completoParaConclusao([
            'logradouro' => 'Rua Vergueiro',
            'numero' => '1000',
            'bairro' => 'Vila Mariana',
            'cidade' => 'São Paulo',
            'cep' => '04101-000',
            'estado' => 'SP',
            'confianca' => 'baixa',
        ]));
    }
}
