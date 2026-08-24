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
}
