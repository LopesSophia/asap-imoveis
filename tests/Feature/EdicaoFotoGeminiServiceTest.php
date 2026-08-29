<?php

namespace Tests\Feature;

use App\Exceptions\EdicaoFotoException;
use App\Services\EdicaoFotoGeminiService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EdicaoFotoGeminiServiceTest extends TestCase
{
    private function imagemValidaBase64(): string
    {
        $imagem = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($imagem);
        $bytes = ob_get_clean();
        imagedestroy($imagem);

        return base64_encode($bytes);
    }

    /**
     * @return array<string, mixed>
     */
    private function respostaComImagem(): array
    {
        return [
            'candidates' => [[
                'content' => [
                    'parts' => [
                        ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $this->imagemValidaBase64()]],
                    ],
                ],
                'finishReason' => 'STOP',
            ]],
        ];
    }

    /**
     * @return array<int, array{categoria: string, descricao: string}>
     */
    private function itensPessoa(): array
    {
        return [['categoria' => 'pessoa', 'descricao' => 'pessoa em pé perto da porta']];
    }

    private function prepararFotoOriginal(): string
    {
        Storage::fake('public');
        Storage::disk('public')->put('imoveis/1/original.jpg', 'conteudo-fake-da-foto-original');

        return 'imoveis/1/original.jpg';
    }

    public function test_ausencia_da_chave_lanca_excecao_antes_de_qualquer_chamada_http(): void
    {
        config(['services.gemini.key' => null]);
        Http::fake();

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);

        $this->expectException(EdicaoFotoException::class);
        $this->expectExceptionMessage('GOOGLE_GEMINI_API_KEY não está configurada');

        try {
            $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_sucesso_grava_arquivo_editado_no_disco_sem_tocar_no_original(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaComImagem(), 200)]);

        $caminho = $this->prepararFotoOriginal();
        $conteudoOriginalAntes = Storage::disk('public')->get($caminho);

        $service = app(EdicaoFotoGeminiService::class);
        $resultado = $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');

        $this->assertSame('imoveis/1/edicoes/1/1.jpg', $resultado['caminho']);
        $this->assertSame('gemini', $resultado['provider']);
        Storage::disk('public')->assertExists('imoveis/1/edicoes/1/1.jpg');
        $this->assertSame($conteudoOriginalAntes, Storage::disk('public')->get($caminho));
    }

    public function test_timeout_configurado_na_chamada_http(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaComImagem(), 200)]);

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);
        $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'generativelanguage.googleapis.com');
        });
    }

    public function test_modelo_configurado_do_gemini_e_a_versao_estavel(): void
    {
        $this->assertSame('gemini-2.5-flash-image', config('services.gemini.model'));
    }

    public function test_modelo_configurado_e_usado_na_url_da_chamada(): void
    {
        config(['services.gemini.key' => 'fake-key', 'services.gemini.model' => 'gemini-2.5-flash-image']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaComImagem(), 200)]);

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);
        $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'gemini-2.5-flash-image:generateContent'));
    }

    // ---- Retry automático de falha transitória (503/UNAVAILABLE) ----

    /**
     * Substitui EdicaoFotoGeminiService::aguardar() (o sleep() do backoff)
     * por um no-op no container — sem isso, os testes de retry realmente
     * dormiriam alguns segundos a cada execução.
     */
    private function servicoSemEspera(): EdicaoFotoGeminiService
    {
        return $this->partialMock(EdicaoFotoGeminiService::class, function ($mock) {
            $mock->shouldAllowMockingProtectedMethods()->shouldReceive('aguardar')->andReturnNull();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function respostaIndisponivel(): array
    {
        return ['error' => ['message' => 'This model is currently experiencing high demand.', 'status' => 'UNAVAILABLE']];
    }

    public function test_503_503_sucesso_recupera_na_terceira_chamada_interna(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->respostaIndisponivel(), 503)
            ->push($this->respostaIndisponivel(), 503)
            ->push($this->respostaComImagem(), 200)]);

        $caminho = $this->prepararFotoOriginal();
        $service = $this->servicoSemEspera();

        $resultado = $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');

        $this->assertSame('imoveis/1/edicoes/1/1.jpg', $resultado['caminho']);
        Http::assertSentCount(3);
        Storage::disk('public')->assertExists('imoveis/1/edicoes/1/1.jpg');
    }

    public function test_503_definitivo_apos_tres_tentativas_internas_marca_erro_sanitizado(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push($this->respostaIndisponivel(), 503)
            ->push($this->respostaIndisponivel(), 503)
            ->push($this->respostaIndisponivel(), 503)]);

        $caminho = $this->prepararFotoOriginal();
        $service = $this->servicoSemEspera();

        try {
            $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
            $this->fail('Deveria ter lançado EdicaoFotoException após esgotar as 3 tentativas internas.');
        } catch (EdicaoFotoException $e) {
            $this->assertStringNotContainsString('high demand', $e->getMessage());
            $this->assertStringNotContainsString('UNAVAILABLE', $e->getMessage());
        }

        Http::assertSentCount(3);
        Storage::disk('public')->assertMissing('imoveis/1/edicoes/1/1.jpg');
    }

    public function test_403_nao_repete_chamada(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'Method doesn\'t allow unregistered callers', 'status' => 'PERMISSION_DENIED'],
        ], 403)]);

        $caminho = $this->prepararFotoOriginal();
        $service = $this->servicoSemEspera();

        try {
            $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
            $this->fail('Deveria ter lançado EdicaoFotoException.');
        } catch (EdicaoFotoException) {
            // Chegou aqui sem retry nenhum — só a asserção de contagem abaixo já prova isso.
        }

        Http::assertSentCount(1);
    }

    public function test_erro_de_conexao_tambem_repete_ate_o_maximo_e_depois_marca_erro_sanitizado(): void
    {
        config(['services.gemini.key' => 'fake-key']);

        // Http::assertSentCount() não serve aqui: quando o callback do fake
        // LANÇA a ConnectionException (em vez de devolver uma resposta), o
        // Laravel nunca chega a registrar essa tentativa como "enviada" —
        // a contagem ficaria zerada mesmo com as 3 tentativas reais
        // acontecendo. Um contador local incrementado dentro do próprio
        // callback prova a quantidade de tentativas de verdade.
        $tentativas = 0;
        Http::fake(function () use (&$tentativas) {
            $tentativas++;
            throw new ConnectionException('Connection timed out');
        });

        $caminho = $this->prepararFotoOriginal();
        $service = $this->servicoSemEspera();

        try {
            $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
            $this->fail('Deveria ter lançado EdicaoFotoException após esgotar as 3 tentativas internas.');
        } catch (EdicaoFotoException $e) {
            $this->assertStringNotContainsString('Connection timed out', $e->getMessage());
        }

        $this->assertSame(3, $tentativas);
    }

    // ---- Nunca expor o erro técnico bruto do Google ----

    public function test_falta_de_cota_ou_faturamento_gera_mensagem_curta_em_portugues_sem_texto_bruto(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => [
                'message' => 'Quota exceeded for quota metric project 987654321, please enable billing',
                'status' => 'RESOURCE_EXHAUSTED',
            ],
        ], 429)]);

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);

        try {
            $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
            $this->fail('Deveria ter lançado EdicaoFotoException.');
        } catch (EdicaoFotoException $e) {
            $this->assertStringNotContainsString('Quota exceeded', $e->getMessage());
            $this->assertStringNotContainsString('987654321', $e->getMessage());
            $this->assertStringNotContainsString('RESOURCE_EXHAUSTED', $e->getMessage());
            $this->assertStringNotContainsString('billing', $e->getMessage());
            $this->assertSame('O serviço de edição de fotos atingiu o limite de uso no momento. Tente novamente mais tarde.', $e->getMessage());
        }
    }

    public function test_acesso_negado_gera_mensagem_curta_em_portugues_sem_texto_bruto(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'Method doesn\'t allow unregistered callers (callers without established identity)', 'status' => 'PERMISSION_DENIED'],
        ], 403)]);

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);

        try {
            $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
            $this->fail('Deveria ter lançado EdicaoFotoException.');
        } catch (EdicaoFotoException $e) {
            $this->assertStringNotContainsString('unregistered callers', $e->getMessage());
            $this->assertStringNotContainsString('PERMISSION_DENIED', $e->getMessage());
        }
    }

    public function test_falha_http_generica_tambem_nao_expoe_texto_bruto(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'error' => ['message' => 'internal error trace: NullPointerException at line 42 of GenerateContentHandler.java', 'status' => 'INTERNAL'],
        ], 500)]);

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);

        try {
            $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
            $this->fail('Deveria ter lançado EdicaoFotoException.');
        } catch (EdicaoFotoException $e) {
            $this->assertStringNotContainsString('NullPointerException', $e->getMessage());
            $this->assertStringNotContainsString('GenerateContentHandler', $e->getMessage());
            $this->assertSame('Não foi possível gerar a edição da foto no momento. Tente novamente.', $e->getMessage());
        }
    }

    public function test_resposta_sem_imagem_lanca_excecao(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'não posso editar isso']]], 'finishReason' => 'SAFETY']],
            ], 200),
        ]);

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);

        $this->expectException(EdicaoFotoException::class);
        $this->expectExceptionMessage('não trouxe a imagem editada');

        $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
    }

    public function test_imagem_retornada_invalida_lanca_excecao_e_nao_grava_arquivo(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [
                    ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => base64_encode('isto-nao-e-uma-imagem')]],
                ]]]],
            ], 200),
        ]);

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);

        try {
            $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
            $this->fail('Deveria ter lançado EdicaoFotoException.');
        } catch (EdicaoFotoException $e) {
            $this->assertStringContainsString('não é uma imagem válida', $e->getMessage());
        }

        Storage::disk('public')->assertMissing('imoveis/1/edicoes/1/1.jpg');
    }

    public function test_falha_ao_gravar_arquivo_no_disco_lanca_excecao(): void
    {
        config(['services.gemini.key' => 'fake-key']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaComImagem(), 200)]);

        $discoMock = \Mockery::mock();
        $discoMock->shouldReceive('get')->andReturn('conteudo-fake-da-foto-original');
        $discoMock->shouldReceive('put')->andReturn(false);
        Storage::shouldReceive('disk')->with('public')->andReturn($discoMock);

        $service = app(EdicaoFotoGeminiService::class);

        $this->expectException(EdicaoFotoException::class);
        $this->expectExceptionMessage('Não foi possível gravar');

        $service->editar('imoveis/1/original.jpg', $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');
    }

    public function test_prompt_enviado_inclui_categoria_e_descricao_dos_itens_solicitados(): void
    {
        $service = app(EdicaoFotoGeminiService::class);

        $prompt = $service->montarPrompt([
            ['categoria' => 'pessoa', 'descricao' => 'pessoa em pé perto da porta'],
            ['categoria' => 'animal', 'descricao' => 'cachorro no quintal'],
        ]);

        $this->assertStringContainsString('- pessoa: pessoa em pé perto da porta', $prompt);
        $this->assertStringContainsString('- animal: cachorro no quintal', $prompt);
        $this->assertStringNotContainsString('{{ITENS}}', $prompt);
    }

    public function test_nenhuma_chave_nem_conteudo_sensivel_vai_para_o_log(): void
    {
        config(['services.gemini.key' => 'segredo-super-secreto']);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($this->respostaComImagem(), 200)]);

        $caminho = $this->prepararFotoOriginal();
        $service = app(EdicaoFotoGeminiService::class);
        $service->editar($caminho, $service->montarPrompt($this->itensPessoa()), 'imoveis/1/edicoes/1/1.jpg');

        Http::assertSent(function ($request) {
            // A chave vai só no header, nunca no corpo/URL (onde vazaria mais facilmente em logs de request).
            return $request->hasHeader('x-goog-api-key', 'segredo-super-secreto')
                && ! str_contains($request->url(), 'segredo-super-secreto')
                && ! str_contains(json_encode($request->data()), 'segredo-super-secreto');
        });
    }
}
