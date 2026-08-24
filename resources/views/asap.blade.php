<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ASAP — Cadastro de Imóvel</title>
    <style>
        :root {
            --azul: #2563eb;
            --azul-escuro: #1d4ed8;
            --cinza-fundo: #f3f4f6;
            --cinza-borda: #d1d5db;
            --texto: #1f2937;
            --texto-suave: #6b7280;
            --vazio-borda: #f59e0b;
            --vazio-fundo: #fffbeb;
            --erro: #dc2626;
            --erro-fundo: #fef2f2;
            --sucesso: #16a34a;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            background: var(--cinza-fundo);
            color: var(--texto);
        }
        .app {
            max-width: 480px;
            margin: 0 auto;
            min-height: 100vh;
            background: #fff;
            box-shadow: 0 0 24px rgba(0,0,0,.06);
            display: flex;
            flex-direction: column;
        }
        header.topo {
            padding: 16px 20px;
            border-bottom: 1px solid var(--cinza-borda);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        header.topo h1 {
            font-size: 16px;
            margin: 0;
            color: var(--azul-escuro);
        }
        .passos {
            display: flex;
            gap: 6px;
        }
        .passos span {
            width: 22px;
            height: 4px;
            border-radius: 2px;
            background: var(--cinza-borda);
        }
        .passos span.ativo { background: var(--azul); }

        .topo-status {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 6px;
        }
        .contador-fotos {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .contador-fotos.contador-baixo { color: var(--vazio-borda); background: var(--vazio-fundo); }
        .contador-fotos.contador-ok { color: var(--sucesso); background: #f0fdf4; }

        .screen {
            display: none;
            flex-direction: column;
            padding: 20px;
            gap: 16px;
            flex: 1;
        }
        .screen.active { display: flex; }

        label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--texto-suave);
            margin-bottom: 4px;
        }
        .campo { margin-bottom: 4px; }
        input[type=text], input[type=number], select, textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--cinza-borda);
            border-radius: 8px;
            font-size: 15px;
            background: #fff;
            color: var(--texto);
        }
        textarea { resize: vertical; font-family: inherit; }
        input.vazio, select.vazio, textarea.vazio {
            border-color: var(--vazio-borda);
            background: var(--vazio-fundo);
        }
        .erro-msg {
            color: var(--erro);
            font-size: 12px;
            margin-top: 2px;
            min-height: 14px;
        }
        .checkbox-linha {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--texto-suave);
            cursor: pointer;
        }
        .checkbox-linha input[type=checkbox] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }

        .linha-icones {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
        }
        .linha-icones .item { text-align: center; }
        .linha-icones .item .rotulo { font-size: 18px; }
        .linha-icones .item input {
            text-align: center;
            padding: 8px 4px;
        }

        .linha-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .toggle-grupo {
            display: flex;
            border: 1px solid var(--cinza-borda);
            border-radius: 8px;
            overflow: hidden;
        }
        .toggle-grupo.vazio { border-color: var(--vazio-borda); }
        .toggle-opcao {
            flex: 1;
            padding: 8px 4px;
            text-align: center;
            font-size: 13px;
            font-family: inherit;
            cursor: pointer;
            background: #fff;
            color: var(--texto-suave);
            border: none;
            user-select: none;
        }
        .toggle-opcao + .toggle-opcao { border-left: 1px solid var(--cinza-borda); }

        /* Não selecionada, grupo "vazio": fundo âmbar claro, texto escuro legível. */
        .toggle-grupo.vazio .toggle-opcao { background: var(--vazio-fundo); color: var(--texto-suave); }

        /* Selecionada: sempre fundo azul + texto branco — a regra abaixo tem
           especificidade maior de propósito que ".toggle-grupo.vazio .toggle-opcao"
           (4 classes vs. 3), pra nunca repetir o bug de texto branco sobre fundo
           claro no botão "Não informado" quando o grupo está vazio. */
        .toggle-opcao.selecionada { background: var(--azul); color: #fff; font-weight: 600; }
        .toggle-grupo.vazio .toggle-opcao.selecionada { background: var(--azul); color: #fff; }

        .toggle-opcao:focus-visible {
            outline: 2px solid var(--azul-escuro);
            outline-offset: -2px;
            position: relative;
            z-index: 1;
        }
        .toggle-opcao:disabled {
            cursor: not-allowed;
            opacity: .55;
            color: var(--texto-suave);
        }

        .chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding: 8px;
            border: 1px solid var(--cinza-borda);
            border-radius: 8px;
        }
        .chip {
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--cinza-borda);
            font-size: 13px;
            cursor: pointer;
            background: #fff;
            color: var(--texto-suave);
            user-select: none;
        }
        .chip.selecionado { background: var(--azul); color: #fff; border-color: var(--azul); }

        .botoes {
            margin-top: auto;
            display: flex;
            gap: 10px;
            padding-top: 12px;
        }
        button {
            font-size: 15px;
            font-weight: 600;
            border-radius: 8px;
            border: none;
            padding: 12px 16px;
            cursor: pointer;
        }
        button.primario { background: var(--azul); color: #fff; flex: 1; }
        button.primario:active { background: var(--azul-escuro); }
        button.secundario { background: #fff; color: var(--texto-suave); border: 1px solid var(--cinza-borda); }
        button.secundario:active { background: var(--cinza-fundo); }
        button:disabled { opacity: .5; cursor: not-allowed; }

        .captura-textarea { flex: 1; min-height: 200px; }
        .mic-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: #fff;
            border: 1px solid var(--cinza-borda);
            color: var(--texto);
        }
        .mic-btn.gravando { background: var(--erro-fundo); border-color: var(--erro); color: var(--erro); }

        .banner-erro {
            background: var(--erro-fundo);
            color: var(--erro);
            border: 1px solid var(--erro);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            display: none;
        }
        .banner-erro.active { display: block; }

        .banner-alerta {
            background: var(--vazio-fundo);
            color: #92400e;
            border: 1px solid var(--vazio-borda);
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 13px;
            display: none;
            text-align: left;
        }
        .banner-alerta.active { display: block; }

        .sucesso-caixa {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            gap: 12px;
        }
        .sucesso-icone {
            width: 64px; height: 64px;
            border-radius: 50%;
            background: var(--sucesso);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 32px;
        }

        .grade-fotos {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-top: 8px;
        }
        .grade-fotos .thumb {
            position: relative;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--cinza-borda);
            background: var(--cinza-fundo);
        }
        .grade-fotos .thumb img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .grade-fotos .thumb .remover-foto {
            position: absolute;
            top: 4px;
            right: 4px;
            background: rgba(0,0,0,.6);
            color: #fff;
            border: none;
            border-radius: 50%;
            width: 22px;
            height: 22px;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
            padding: 0;
        }
        .aviso-fotos {
            font-size: 12px;
            color: var(--erro);
            min-height: 14px;
        }
        .status-fotos {
            font-size: 12px;
            color: var(--texto-suave);
            min-height: 14px;
        }
        .legenda-capa {
            font-size: 12px;
            color: var(--texto-suave);
            min-height: 14px;
        }
        .grade-fotos .thumb.capa-ativa {
            border: 2px solid var(--azul);
        }
        .grade-fotos .thumb.capa-sugerida:not(.capa-ativa) {
            border: 2px solid var(--vazio-borda);
        }
        .selos-capa {
            position: absolute;
            top: 4px;
            left: 4px;
            display: flex;
            flex-direction: column;
            gap: 2px;
            align-items: flex-start;
        }
        .grade-fotos .thumb .selo-capa,
        .grade-fotos .thumb .selo-sugestao {
            display: none;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            line-height: 1.4;
        }
        .grade-fotos .thumb .selo-capa { background: var(--azul); color: #fff; }
        .grade-fotos .thumb .selo-sugestao { background: var(--vazio-borda); color: #fff; }
        .grade-fotos .thumb.capa-ativa .selo-capa { display: inline-block; }
        .grade-fotos .thumb.capa-sugerida .selo-sugestao { display: inline-block; }
        .grade-fotos .thumb.capa-ativa .btn-capa { display: none; }
        .grade-fotos .thumb .btn-capa {
            position: absolute;
            bottom: 4px;
            left: 4px;
            right: 4px;
            background: rgba(0,0,0,.6);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 10px;
            padding: 3px 4px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="app">
        <header class="topo">
            <h1>ASAP — Cadastro de Imóvel</h1>
            <div class="topo-status">
                <div class="passos">
                    <span id="passo-1" class="ativo"></span>
                    <span id="passo-2"></span>
                    <span id="passo-3"></span>
                </div>
                <div class="contador-fotos contador-baixo" id="contador-fotos">0 / 25 fotos</div>
            </div>
        </header>

        {{-- 1. CAPTURA --}}
        <section id="screen-captura" class="screen active">
            <label for="texto-livre">Descreva o imóvel (fale ou digite)</label>
            <textarea id="texto-livre" class="captura-textarea" placeholder="Ex.: apartamento 2 quartos em Moema, 75m², com vaga, R$ 650 mil..."></textarea>
            <button type="button" id="btn-mic" class="mic-btn">🎙️ Falar</button>
            <div class="botoes">
                <button type="button" class="primario" id="btn-processar">Processar</button>
            </div>
        </section>

        {{-- 2. REVISÃO --}}
        <section id="screen-revisao" class="screen">
            <div class="banner-erro" id="aviso-extracao-falhou">Não foi possível extrair os dados automaticamente. Confira e preencha os campos manualmente antes de continuar.</div>

            <div class="campo">
                <label for="tipo_imovel">Tipo de imóvel *</label>
                <select id="tipo_imovel" data-field>
                    <option value="">— selecione —</option>
                    <option value="apartamento">Apartamento</option>
                    <option value="casa">Casa</option>
                    <option value="terreno">Terreno</option>
                    <option value="comercial">Comercial</option>
                    <option value="cobertura">Cobertura</option>
                </select>
                <div class="erro-msg" id="erro-tipo_imovel"></div>
            </div>

            <div class="campo">
                <label for="negociacao">Negociação</label>
                <select id="negociacao" data-field>
                    <option value="">— selecione —</option>
                    <option value="venda">Venda</option>
                    <option value="locacao">Locação</option>
                    <option value="venda_e_locacao">Venda e locação</option>
                </select>
                <div class="erro-msg" id="erro-negociacao"></div>
            </div>

            <div class="campo">
                <label for="utilizacao">Utilização</label>
                <select id="utilizacao" data-field>
                    <option value="">— selecione —</option>
                    <option value="residencial">Residencial</option>
                    <option value="comercial">Comercial</option>
                </select>
                <div class="erro-msg" id="erro-utilizacao"></div>
            </div>

            <div class="campo">
                <label for="bairro">Bairro</label>
                <input type="text" id="bairro" data-field>
                <div class="erro-msg" id="erro-bairro"></div>
            </div>

            <div class="campo">
                <label for="cidade">Cidade</label>
                <input type="text" id="cidade" data-field>
                <div class="erro-msg" id="erro-cidade"></div>
            </div>

            <div class="campo">
                <label for="nome_edificio">Nome do edifício/condomínio</label>
                <input type="text" id="nome_edificio" data-field placeholder="Se mencionado — não aparece no título nem na descrição">
                <div class="erro-msg" id="erro-nome_edificio"></div>
            </div>

            <div class="campo">
                <label for="metragem">Metragem (m²)</label>
                <input type="number" step="0.01" min="0" id="metragem" data-field>
                <div class="erro-msg" id="erro-metragem"></div>
            </div>

            <div class="linha-icones">
                <div class="item">
                    <div class="rotulo">🛏️ Quartos</div>
                    <input type="number" min="0" id="quartos" data-field>
                </div>
                <div class="item">
                    <div class="rotulo">🚪 Suítes</div>
                    <input type="number" min="0" id="suites" data-field>
                </div>
                <div class="item">
                    <div class="rotulo">🚿 Banheiros</div>
                    <input type="number" min="0" id="banheiros" data-field>
                </div>
                <div class="item">
                    <div class="rotulo">🚗 Vagas</div>
                    <input type="number" min="0" id="vagas" data-field>
                </div>
            </div>

            <div class="campo">
                <label for="vagas_cobertura">Cobertura da vaga</label>
                <select id="vagas_cobertura" data-field>
                    <option value="">— selecione —</option>
                    <option value="coberta">Coberta</option>
                    <option value="descoberta">Descoberta</option>
                    <option value="mista">Mista</option>
                </select>
                <div class="erro-msg" id="erro-vagas_cobertura"></div>
            </div>

            <div class="linha-3">
                <div class="campo">
                    <label for="valor">Valor (R$)</label>
                    <input type="number" step="0.01" min="0" id="valor" data-field>
                </div>
                <div class="campo">
                    <label for="condominio">Condomínio</label>
                    <input type="number" step="0.01" min="0" id="condominio" data-field>
                </div>
                <div class="campo">
                    <label for="iptu">IPTU</label>
                    <input type="number" step="0.01" min="0" id="iptu" data-field>
                    <label class="checkbox-linha">
                        <input type="checkbox" id="iptu_isento">
                        IPTU isento
                    </label>
                    <div class="erro-msg" id="erro-iptu"></div>
                </div>
            </div>

            <div class="campo">
                <label>Em condomínio?</label>
                <div class="toggle-grupo" data-toggle="em_condominio" data-value="">
                    <button type="button" class="toggle-opcao selecionada" data-valor="">Não informado</button>
                    <button type="button" class="toggle-opcao" data-valor="1">Sim</button>
                    <button type="button" class="toggle-opcao" data-valor="0">Não</button>
                </div>
            </div>

            <div class="campo">
                <label>Reformado?</label>
                <div class="toggle-grupo" data-toggle="reformado" data-value="">
                    <button type="button" class="toggle-opcao selecionada" data-valor="">Não informado</button>
                    <button type="button" class="toggle-opcao" data-valor="1">Sim</button>
                    <button type="button" class="toggle-opcao" data-valor="0">Não</button>
                </div>
            </div>

            <div class="campo">
                <label for="estado_conservacao">Estado de conservação</label>
                <select id="estado_conservacao" data-field>
                    <option value="">— selecione —</option>
                    <option value="novo">Novo</option>
                    <option value="reformado">Reformado</option>
                    <option value="usado">Usado</option>
                    <option value="a_reformar">A reformar</option>
                </select>
                <div class="erro-msg" id="erro-estado_conservacao"></div>
            </div>

            <div class="campo">
                <label>Mobiliado?</label>
                <div class="toggle-grupo" data-toggle="mobiliado" data-value="">
                    <button type="button" class="toggle-opcao selecionada" data-valor="">Não informado</button>
                    <button type="button" class="toggle-opcao" data-valor="1">Sim</button>
                    <button type="button" class="toggle-opcao" data-valor="0">Não</button>
                </div>
            </div>

            <div class="campo">
                <label for="chaves">Chaves</label>
                <input type="text" id="chaves" data-field placeholder="Ex.: com a imobiliária, com o proprietário...">
                <div class="erro-msg" id="erro-chaves"></div>
            </div>

            <div class="banner-erro" id="banner-erro-revisao"></div>

            <div class="botoes">
                <button type="button" class="secundario" id="btn-revisao-voltar">Voltar</button>
                <button type="button" class="primario" id="btn-revisao-continuar">Continuar</button>
            </div>
        </section>

        {{-- 3. FOTOS: só upload + disparo da análise. A análise em si roda num
             endpoint separado de finalizar() (POST .../analisar-fotos) — não
             finaliza o cadastro, só mescla e persiste o resultado. --}}
        <section id="screen-fotos" class="screen">
            <div class="campo">
                <label>Fotos do imóvel (mínimo 25)</label>
                <input type="file" id="input-fotos" accept="image/jpeg,image/png" multiple>
                <div class="status-fotos" id="status-fotos"></div>
                <div class="aviso-fotos" id="aviso-fotos"></div>
                <div class="grade-fotos" id="grade-fotos-upload"></div>
            </div>

            <div class="banner-erro" id="banner-erro-analise"></div>

            <div class="botoes">
                <button type="button" class="secundario" id="btn-fotos-voltar">Voltar</button>
                <button type="button" class="primario" id="btn-analisar-fotos">Analisar fotos</button>
            </div>
        </section>

        {{-- 4. REVISÃO FINAL: só é alcançada depois que a análise (etapa 3)
             já rodou com sucesso — fotos+capa, alertas, diferenciais já
             complementados pela IA, título/descrição (ainda manuais, Etapa 4
             de geração não está conectada) e observações. --}}
        <section id="screen-revisao-final" class="screen">
            <div class="campo">
                <label>Fotos e capa</label>
                <div class="grade-fotos" id="grade-fotos"></div>
                <div class="legenda-capa" id="legenda-capa"></div>
            </div>

            <div class="banner-alerta" id="banner-alerta-fotos"></div>

            <div class="campo">
                <label>Diferenciais (preenchidos/complementados pela análise das fotos)</label>
                <div class="chips" id="chips-diferenciais" data-field>
                    <div class="chip" data-valor="armario_embutido">Armário embutido</div>
                    <div class="chip" data-valor="cozinha_mobiliada">Cozinha mobiliada</div>
                    <div class="chip" data-valor="portaria">Portaria</div>
                    <div class="chip" data-valor="lavabo">Lavabo</div>
                    <div class="chip" data-valor="churrasqueira">Churrasqueira</div>
                    <div class="chip" data-valor="garagem">Garagem</div>
                    <div class="chip" data-valor="quintal">Quintal</div>
                    <div class="chip" data-valor="dependencia_empregados">Dependência de empregados</div>
                    <div class="chip" data-valor="servicos">Serviços</div>
                    <div class="chip" data-valor="cozinha_americana">Cozinha americana</div>
                    <div class="chip" data-valor="piscina">Piscina</div>
                </div>
            </div>

            <div class="campo">
                <label for="titulo_site">Título para o site</label>
                <textarea id="titulo_site" data-field rows="2"></textarea>
                <div class="erro-msg" id="erro-titulo_site"></div>
            </div>

            <div class="campo">
                <label for="descricao_gerada">Descrição</label>
                <textarea id="descricao_gerada" data-field rows="4"></textarea>
                <div class="erro-msg" id="erro-descricao_gerada"></div>
            </div>

            <div class="campo">
                <label for="observacoes_corretor">Observações do corretor</label>
                <textarea id="observacoes_corretor" data-field rows="3"></textarea>
                <div class="erro-msg" id="erro-observacoes_corretor"></div>
            </div>

            <div class="banner-erro" id="banner-erro-final"></div>

            <div class="botoes">
                <button type="button" class="secundario" id="btn-revisao-final-voltar">Voltar</button>
                <button type="button" class="primario" id="btn-concluir">Concluir cadastro</button>
            </div>
        </section>

        {{-- SUCESSO --}}
        <section id="screen-sucesso" class="screen">
            <div class="sucesso-caixa">
                <div class="sucesso-icone">✓</div>
                <h2>Cadastro enviado!</h2>
                <p style="color: var(--texto-suave)">O imóvel foi registrado com sucesso.</p>
                <button type="button" class="primario" id="btn-novo-cadastro">Novo cadastro</button>
            </div>
        </section>
    </div>

    <script>
        // URLs absolutas geradas pelo Laravel: funcionam tanto acessando pelo
        // domínio raiz quanto por subpasta (ex.: http://localhost/asap-imoveis/public/asap).
        const URL_EXTRAIR_IMOVEL = @json(url('/api/extrair-imovel'));
        const URL_IMOVEIS_STAGING = @json(url('/api/imoveis-staging'));

        const MINIMO_FOTOS = 25;

        // ---- Estado do rascunho salvo no servidor (id do registro + contagem de fotos) ----
        let imovelStagingId = null;
        let totalFotos = 0;
        // Foto de capa ATIVA (o que realmente vale) vs. sugestão da IA — dois
        // conceitos separados, podem ser fotos diferentes.
        let fotoCapaId = null;
        let fotoCapaSugeridaId = null;
        let fotoCapaSugeridaMotivo = null;

        // ---- Campos do schema (nesta ordem visual) ----
        const CAMPOS_TEXTO = ['tipo_imovel', 'negociacao', 'utilizacao', 'bairro', 'cidade', 'nome_edificio', 'vagas_cobertura', 'estado_conservacao'];
        const CAMPOS_NUMERO = ['metragem', 'quartos', 'suites', 'banheiros', 'vagas', 'valor', 'condominio', 'iptu'];
        const CAMPOS_TEXTAREA = ['chaves', 'titulo_site', 'descricao_gerada', 'observacoes_corretor'];
        const TOGGLES = ['em_condominio', 'reformado', 'mobiliado'];

        function mostrarTela(id) {
            document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
            document.getElementById(id).classList.add('active');

            const passos = {
                'screen-captura': 1,
                'screen-revisao': 2,
                'screen-fotos': 3,
                'screen-revisao-final': 3,
                'screen-sucesso': 3,
            };
            const ativo = passos[id] || 1;
            [1, 2, 3].forEach(n => {
                document.getElementById('passo-' + n).classList.toggle('ativo', n <= ativo);
            });
        }

        // ---- Captura por voz (Web Speech API) ----
        const textoLivre = document.getElementById('texto-livre');
        const btnMic = document.getElementById('btn-mic');
        const SpeechRecognitionAPI = window.SpeechRecognition || window.webkitSpeechRecognition;
        let recognition = null;
        let gravando = false;

        let transcricaoFinal = '';

        if (SpeechRecognitionAPI) {
            recognition = new SpeechRecognitionAPI();
            recognition.lang = 'pt-BR';
            recognition.continuous = true;
            recognition.interimResults = true;

            recognition.onresult = function (evento) {
                let transcricaoParcial = '';
                for (let i = evento.resultIndex; i < evento.results.length; i++) {
                    const transcript = evento.results[i][0].transcript;
                    if (evento.results[i].isFinal) {
                        transcricaoFinal += transcript + ' ';
                    } else {
                        transcricaoParcial += transcript;
                    }
                }
                textoLivre.value = transcricaoFinal + transcricaoParcial;
            };
            recognition.onerror = () => pararGravacao();
            recognition.onend = () => pararGravacao();
        } else {
            btnMic.disabled = true;
            btnMic.textContent = '🎙️ Ditado por voz indisponível neste navegador';
        }

        function pararGravacao() {
            gravando = false;
            btnMic.classList.remove('gravando');
            btnMic.textContent = '🎙️ Falar';
        }

        btnMic.addEventListener('click', () => {
            if (!recognition) return;
            if (gravando) {
                recognition.stop();
                pararGravacao();
                return;
            }
            gravando = true;
            transcricaoFinal = textoLivre.value.trim() ? textoLivre.value.trim() + ' ' : '';
            btnMic.classList.add('gravando');
            btnMic.textContent = '⏹️ Gravando... toque para parar';
            recognition.start();
        });

        // ---- Preenche a tela de Revisão a partir dos dados extraídos pela IA ----
        function definirValor(id, valor) {
            const el = document.getElementById(id);
            if (!el) return;
            el.value = (valor === null || valor === undefined) ? '' : valor;
        }

        function definirToggleValor(nome, valor) {
            const grupo = document.querySelector(`[data-toggle="${nome}"]`);
            if (!grupo) return;
            const alvo = (valor === null || valor === undefined) ? '' : (valor ? '1' : '0');
            grupo.dataset.value = alvo;
            grupo.querySelectorAll('.toggle-opcao').forEach(o => {
                o.classList.toggle('selecionada', o.dataset.valor === alvo);
            });
        }

        function definirDiferenciais(lista) {
            const selecionados = Array.isArray(lista) ? lista : [];
            document.querySelectorAll('#chips-diferenciais .chip').forEach(chip => {
                chip.classList.toggle('selecionado', selecionados.includes(chip.dataset.valor));
            });
        }

        // IPTU isento e o valor de IPTU são mutuamente exclusivos: marcar a
        // isenção sempre limpa e desabilita o campo de valor; desmarcar sempre
        // reabilita (o corretor digita o valor de novo se quiser).
        function aplicarIptuIsento(isento) {
            const checkbox = document.getElementById('iptu_isento');
            const campoIptu = document.getElementById('iptu');
            checkbox.checked = !!isento;
            campoIptu.disabled = !!isento;
            if (isento) {
                campoIptu.value = '';
            }
            atualizarDestaqueVazios();
        }

        function preencherRevisaoComDados(dados) {
            definirValor('tipo_imovel', dados.tipo_imovel);
            definirValor('negociacao', dados.negociacao);
            definirValor('utilizacao', dados.utilizacao);
            definirValor('bairro', dados.bairro);
            definirValor('cidade', dados.cidade);
            definirValor('nome_edificio', dados.nome_edificio);
            definirValor('metragem', dados.metragem);
            definirValor('quartos', dados.quartos);
            definirValor('suites', dados.suites);
            definirValor('banheiros', dados.banheiros);
            definirValor('vagas', dados.vagas);
            definirValor('vagas_cobertura', dados.vagas_cobertura);
            definirValor('valor', dados.valor);
            definirValor('condominio', dados.condominio);
            if (dados.iptu_isento) {
                aplicarIptuIsento(true);
            } else {
                aplicarIptuIsento(false);
                definirValor('iptu', dados.iptu);
            }
            definirToggleValor('em_condominio', dados.em_condominio);
            definirToggleValor('reformado', dados.reformado);
            definirValor('estado_conservacao', dados.estado_conservacao);
            definirToggleValor('mobiliado', dados.mobiliado);
            definirValor('chaves', dados.chaves);
            definirDiferenciais(dados.diferenciais);
            definirValor('observacoes_corretor', dados.observacoes_corretor);
        }

        // ---- 1 -> 2: Processar (extração via IA, com fallback manual) ----
        document.getElementById('btn-processar').addEventListener('click', async () => {
            const btnProcessar = document.getElementById('btn-processar');
            const aviso = document.getElementById('aviso-extracao-falhou');
            const texto = textoLivre.value.trim();

            aviso.classList.remove('active');

            if (!texto) {
                mostrarTela('screen-revisao');
                atualizarDestaqueVazios();
                return;
            }

            btnProcessar.disabled = true;
            btnProcessar.textContent = 'Processando...';

            try {
                const resposta = await fetch(URL_EXTRAIR_IMOVEL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ texto }),
                });

                if (!resposta.ok) {
                    throw new Error('extracao_falhou');
                }

                preencherRevisaoComDados(await resposta.json());
            } catch (e) {
                if (!document.getElementById('observacoes_corretor').value.trim()) {
                    document.getElementById('observacoes_corretor').value = texto;
                }
                aviso.classList.add('active');
            } finally {
                btnProcessar.disabled = false;
                btnProcessar.textContent = 'Processar';
                mostrarTela('screen-revisao');
                atualizarDestaqueVazios();
            }
        });

        // ---- Destaque de campos vazios ----
        function atualizarDestaqueVazios() {
            [...CAMPOS_TEXTO, ...CAMPOS_NUMERO, ...CAMPOS_TEXTAREA].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                // IPTU vazio por causa da isenção é uma ausência intencional,
                // não um campo esquecido — não faz sentido destacar como vazio.
                if (id === 'iptu' && document.getElementById('iptu_isento').checked) {
                    el.classList.remove('vazio');
                    return;
                }
                el.classList.toggle('vazio', el.value.trim() === '');
            });

            TOGGLES.forEach(nome => {
                const grupo = document.querySelector(`[data-toggle="${nome}"]`);
                grupo.classList.toggle('vazio', grupo.dataset.value === '');
            });

            const chipsBox = document.getElementById('chips-diferenciais');
            chipsBox.classList.toggle('vazio', chipsBox.querySelectorAll('.chip.selecionado').length === 0);
        }

        document.querySelectorAll('[data-field]').forEach(el => {
            el.addEventListener('input', atualizarDestaqueVazios);
            el.addEventListener('change', atualizarDestaqueVazios);
        });

        document.getElementById('iptu_isento').addEventListener('change', (evento) => {
            aplicarIptuIsento(evento.target.checked);
        });

        // ---- Toggles de 3 estados (não informado / sim / não) ----
        document.querySelectorAll('.toggle-grupo').forEach(grupo => {
            grupo.querySelectorAll('.toggle-opcao').forEach(opcao => {
                opcao.addEventListener('click', () => {
                    grupo.querySelectorAll('.toggle-opcao').forEach(o => o.classList.remove('selecionada'));
                    opcao.classList.add('selecionada');
                    grupo.dataset.value = opcao.dataset.valor;
                    atualizarDestaqueVazios();
                });
            });
        });

        // ---- Chips de diferenciais ----
        document.querySelectorAll('#chips-diferenciais .chip').forEach(chip => {
            chip.addEventListener('click', () => {
                chip.classList.toggle('selecionado');
                atualizarDestaqueVazios();
            });
        });

        // ---- Coleta dos dados da tela de revisão ----
        function valorTexto(id) {
            const v = document.getElementById(id).value.trim();
            return v === '' ? null : v;
        }
        function valorNumero(id) {
            const v = document.getElementById(id).value.trim();
            return v === '' ? null : Number(v);
        }
        function valorToggle(nome) {
            const v = document.querySelector(`[data-toggle="${nome}"]`).dataset.value;
            return v === '' ? null : v === '1';
        }

        function montarPayload() {
            return {
                // TODO: substituir por auth real quando disponível (item de auth por corretor depende da TI).
                corretor_id: 1,

                tipo_imovel: valorTexto('tipo_imovel'),
                negociacao: valorTexto('negociacao'),
                utilizacao: valorTexto('utilizacao'),
                bairro: valorTexto('bairro'),
                cidade: valorTexto('cidade'),
                nome_edificio: valorTexto('nome_edificio'),
                metragem: valorNumero('metragem'),

                quartos: valorNumero('quartos'),
                suites: valorNumero('suites'),
                banheiros: valorNumero('banheiros'),
                vagas: valorNumero('vagas'),
                vagas_cobertura: valorTexto('vagas_cobertura'),

                valor: valorNumero('valor'),
                condominio: valorNumero('condominio'),
                iptu: valorNumero('iptu'),
                iptu_isento: document.getElementById('iptu_isento').checked,

                em_condominio: valorToggle('em_condominio'),
                reformado: valorToggle('reformado'),
                estado_conservacao: valorTexto('estado_conservacao'),
                mobiliado: valorToggle('mobiliado'),

                chaves: valorTexto('chaves'),
                diferenciais: [...document.querySelectorAll('#chips-diferenciais .chip.selecionado')].map(c => c.dataset.valor),

                titulo_site: valorTexto('titulo_site'),
                descricao_gerada: valorTexto('descricao_gerada'),
                observacoes_corretor: valorTexto('observacoes_corretor'),
            };
        }

        function limparErros() {
            document.querySelectorAll('.erro-msg').forEach(e => e.textContent = '');
        }

        // ---- Contador de fotos (visível em toda a navegação, no cabeçalho) ----
        // "Analisar fotos" fica de fato desabilitado (cinza) enquanto
        // totalFotos < MINIMO_FOTOS. A validação do servidor continua ativa
        // como defesa em profundidade, mas o estado visual do botão é a
        // fonte de verdade que o corretor vê.
        function atualizarContadorFotos() {
            const contador = document.getElementById('contador-fotos');
            contador.textContent = `${totalFotos} / ${MINIMO_FOTOS} fotos`;
            contador.classList.toggle('contador-ok', totalFotos >= MINIMO_FOTOS);
            contador.classList.toggle('contador-baixo', totalFotos < MINIMO_FOTOS);

            document.getElementById('btn-analisar-fotos').disabled = totalFotos < MINIMO_FOTOS;
        }

        // ---- Salva o registro em modo rascunho (cria na primeira vez, atualiza depois) ----
        async function salvarRascunho() {
            const payload = montarPayload();

            const resposta = imovelStagingId
                ? await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                })
                : await fetch(URL_IMOVEIS_STAGING, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                });

            if (!resposta.ok) {
                const erro = new Error('salvar_rascunho_falhou');
                erro.resposta = resposta;
                throw erro;
            }

            const dados = await resposta.json();
            imovelStagingId = dados.id;
            return dados;
        }

        // Garante que exista um rascunho salvo antes do primeiro upload de fotos.
        async function garantirRascunhoCriado() {
            if (imovelStagingId) return imovelStagingId;
            await salvarRascunho();
            return imovelStagingId;
        }

        // ---- Thumbnails de fotos já enviadas ----
        // Fase de upload (tela de fotos): miniatura simples, só com remover —
        // a capa ainda não existe (só passa a existir depois da análise).
        function adicionarThumbnail(foto) {
            const div = document.createElement('div');
            div.className = 'thumb';
            div.dataset.fotoId = foto.id;
            div.innerHTML = `
                <img src="${foto.url}" alt="Foto do imóvel">
                <button type="button" class="remover-foto" title="Remover foto">×</button>
            `;
            div.querySelector('.remover-foto').addEventListener('click', () => removerFoto(foto.id, div));
            document.getElementById('grade-fotos-upload').appendChild(div);
        }

        // Fase de revisão final (pós-análise): miniatura com seleção de capa,
        // sem remover (pra remover, o corretor volta pra tela de fotos, o que
        // invalida a análise e exige reanalisar).
        function criarThumbnailFinal(foto) {
            const div = document.createElement('div');
            div.className = 'thumb';
            div.dataset.fotoId = foto.id;
            div.innerHTML = `
                <img src="${foto.url}" alt="Foto do imóvel">
                <div class="selos-capa">
                    <span class="selo-capa">Capa selecionada</span>
                    <span class="selo-sugestao">Sugestão da IA</span>
                </div>
                <button type="button" class="btn-capa" title="Definir como foto de capa">Definir capa</button>
            `;
            div.querySelector('.btn-capa').addEventListener('click', () => selecionarFotoCapa(foto.id));
            return div;
        }

        function popularGradeFinal(fotos) {
            const grade = document.getElementById('grade-fotos');
            grade.innerHTML = '';
            (fotos || []).forEach(foto => grade.appendChild(criarThumbnailFinal(foto)));
            atualizarBadgesCapa();
        }

        // ---- Foto de capa: "Capa selecionada" (ativa) e "Sugestão da IA" são
        // conceitos separados — podem ser a mesma foto (os dois selos aparecem
        // juntos) ou fotos diferentes, se o corretor escolheu outra manualmente.
        function atualizarBadgesCapa() {
            document.querySelectorAll('#grade-fotos .thumb').forEach(thumb => {
                const id = Number(thumb.dataset.fotoId);
                thumb.classList.toggle('capa-ativa', fotoCapaId !== null && id === Number(fotoCapaId));
                thumb.classList.toggle('capa-sugerida', fotoCapaSugeridaId !== null && id === Number(fotoCapaSugeridaId));
            });

            const partesLegenda = [];
            if (fotoCapaSugeridaId !== null) {
                partesLegenda.push(`Sugestão da IA: ${fotoCapaSugeridaMotivo || 'foto ' + fotoCapaSugeridaId}.`);
            }
            if (fotoCapaId !== null) {
                partesLegenda.push(fotoCapaId === fotoCapaSugeridaId
                    ? 'A sugestão da IA está definida como capa selecionada.'
                    : 'Capa selecionada manualmente pelo corretor.');
            }

            document.getElementById('legenda-capa').textContent = partesLegenda.join(' ');
        }

        // Escolha manual da capa ATIVA — nunca altera a sugestão da IA, que
        // continua registrada e visível separadamente. Chamada a partir das
        // miniaturas em screen-revisao-final, então o erro aparece no banner
        // dessa tela (banner-erro-final), não em aviso-fotos (outra tela).
        async function selecionarFotoCapa(fotoId) {
            const bannerFinal = document.getElementById('banner-erro-final');
            bannerFinal.classList.remove('active');

            if (!imovelStagingId) return;

            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/foto-capa`, {
                    method: 'PUT',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ foto_id: fotoId }),
                });

                const dados = await resposta.json().catch(() => ({}));

                if (!resposta.ok) {
                    throw new Error(dados.message || 'Não foi possível definir a foto de capa.');
                }

                fotoCapaId = dados.foto_capa_id;
                fotoCapaSugeridaId = dados.foto_capa_sugerida_id;
                fotoCapaSugeridaMotivo = dados.foto_capa_motivo;
                atualizarBadgesCapa();
            } catch (e) {
                bannerFinal.textContent = e.message || 'Não foi possível definir a foto de capa. Tente novamente.';
                bannerFinal.classList.add('active');
            }
        }

        async function removerFoto(fotoId, elementoThumb) {
            const avisoFotos = document.getElementById('aviso-fotos');
            avisoFotos.textContent = '';

            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/fotos/${fotoId}`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json' },
                });

                if (!resposta.ok) throw new Error('remover_foto_falhou');

                const dados = await resposta.json();
                totalFotos = dados.total_fotos;
                elementoThumb.remove();

                // O backend já limpa foto_capa_id e/ou foto_capa_sugerida_id
                // (FKs nullOnDelete) se a foto removida era a ativa e/ou a
                // sugerida — espelha isso no estado do front, cada uma por si.
                if (fotoCapaId !== null && Number(fotoId) === Number(fotoCapaId)) {
                    fotoCapaId = null;
                }
                if (fotoCapaSugeridaId !== null && Number(fotoId) === Number(fotoCapaSugeridaId)) {
                    fotoCapaSugeridaId = null;
                    fotoCapaSugeridaMotivo = null;
                }

                atualizarContadorFotos();
                atualizarBadgesCapa();
                document.getElementById('status-fotos').textContent = `Foto removida. Total: ${totalFotos}/${MINIMO_FOTOS}.`;
            } catch (e) {
                avisoFotos.textContent = 'Não foi possível remover a foto. Tente novamente.';
            }
        }

        // ---- Upload de fotos ----
        // O texto nativo do <input type="file"> (ex.: "5 arquivos selecionados")
        // volta para "Escolher arquivos" assim que resetamos evento.target.value
        // após o upload — por isso NUNCA usamos esse texto como feedback. A fonte
        // única de verdade é totalFotos/#contador-fotos (vindo do servidor); o
        // #status-fotos abaixo é só uma mensagem transitória sobre a última leva.
        document.getElementById('input-fotos').addEventListener('change', async (evento) => {
            const arquivos = evento.target.files;
            const avisoFotos = document.getElementById('aviso-fotos');
            const statusFotos = document.getElementById('status-fotos');
            avisoFotos.textContent = '';

            if (!arquivos.length) return;

            const quantidadeSelecionada = arquivos.length;

            if (!document.getElementById('tipo_imovel').value) {
                avisoFotos.textContent = 'Selecione o tipo de imóvel antes de enviar fotos.';
                evento.target.value = '';
                return;
            }

            try {
                await garantirRascunhoCriado();
            } catch (e) {
                avisoFotos.textContent = 'Não foi possível salvar o rascunho do cadastro. Tente novamente.';
                evento.target.value = '';
                return;
            }

            const formData = new FormData();
            [...arquivos].forEach(arquivo => formData.append('fotos[]', arquivo));

            evento.target.disabled = true;
            statusFotos.textContent = `Enviando ${quantidadeSelecionada} foto(s)...`;

            // Camada de rede: só cobre falha de conexão/timeout ou corpo que
            // não é JSON — nunca deixa uma exceção crua chegar até o usuário.
            let resposta;
            let dados;
            try {
                resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/fotos`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData,
                });
                dados = await resposta.json();
            } catch (e) {
                console.error('Falha de rede ou resposta não-JSON no upload de fotos:', e);
                statusFotos.textContent = '';
                avisoFotos.textContent = 'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.';
                evento.target.value = '';
                evento.target.disabled = false;
                return;
            }

            // Erro de validação/servidor (4xx/5xx): a mensagem do Laravel já é
            // segura para exibir (nunca é um stack trace de JS).
            if (!resposta.ok) {
                statusFotos.textContent = '';
                avisoFotos.textContent = dados.message || 'Falha ao enviar fotos. Tente novamente.';
                evento.target.value = '';
                evento.target.disabled = false;
                return;
            }

            // Contrato esperado de ImovelStagingFotoController::store():
            // { fotos: [{id, caminho, ordem, url}], total_fotos: number }.
            // Nunca assumimos isso com optional chaining/array vazio — se o
            // formato vier diferente do esperado é um erro real (ex.: resposta
            // 2xx sem corpo válido por algum proxy/limite de upload no meio do
            // caminho), não um caso de "0 fotos" a esconder silenciosamente.
            if (!Array.isArray(dados.fotos) || typeof dados.total_fotos !== 'number') {
                console.error('Resposta do upload de fotos em formato inesperado:', dados);
                statusFotos.textContent = '';
                avisoFotos.textContent = 'O servidor respondeu em um formato inesperado. Tente novamente; se persistir, avise o suporte.';
                evento.target.value = '';
                evento.target.disabled = false;
                return;
            }

            dados.fotos.forEach(adicionarThumbnail);
            totalFotos = dados.total_fotos;
            atualizarContadorFotos();
            statusFotos.textContent = `${quantidadeSelecionada} foto(s) enviada(s) com sucesso. Total: ${totalFotos}/${MINIMO_FOTOS}.`;
            evento.target.value = '';
            evento.target.disabled = false;
        });

        // ---- 1. Revisão (dados objetivos) -> 2. Fotos ----
        document.getElementById('btn-revisao-voltar').addEventListener('click', () => mostrarTela('screen-captura'));

        document.getElementById('btn-revisao-continuar').addEventListener('click', async () => {
            const btn = document.getElementById('btn-revisao-continuar');
            const bannerRevisao = document.getElementById('banner-erro-revisao');
            limparErros();
            bannerRevisao.classList.remove('active');
            btn.disabled = true;
            btn.textContent = 'Salvando...';

            try {
                await salvarRascunho();
                mostrarTela('screen-fotos');
            } catch (e) {
                if (e.resposta && e.resposta.status === 422) {
                    const dados = await e.resposta.json();
                    Object.entries(dados.errors || {}).forEach(([campo, mensagens]) => {
                        const el = document.getElementById('erro-' + campo);
                        if (el) el.textContent = mensagens.join(' ');
                    });
                    bannerRevisao.textContent = dados.message || 'Corrija os campos destacados antes de continuar.';
                } else {
                    bannerRevisao.textContent = 'Não foi possível salvar os dados. Tente novamente.';
                }
                bannerRevisao.classList.add('active');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Continuar';
            }
        });

        // ---- 3. Analisar fotos -> 4. Revisão final ----
        document.getElementById('btn-fotos-voltar').addEventListener('click', () => mostrarTela('screen-revisao'));

        // Aplica o resultado de analisar-fotos() na revisão final: fotos+capa,
        // alertas, e diferenciais complementados pela IA. Só populamos isso
        // DEPOIS da resposta chegar — nunca antes, pra não sugerir que a IA já
        // opinou sobre algo que ainda não analisou.
        function aplicarResultadoAnalise(dados) {
            popularGradeFinal(dados.fotos || []);

            fotoCapaId = dados.foto_capa_id ?? null;
            fotoCapaSugeridaId = dados.foto_capa_sugerida_id ?? null;
            fotoCapaSugeridaMotivo = dados.foto_capa_motivo ?? null;
            atualizarBadgesCapa();

            const bannerAlertas = document.getElementById('banner-alerta-fotos');
            const alertas = dados.alertas_fotos || [];
            if (alertas.length > 0) {
                bannerAlertas.textContent = `Atenção: ${alertas.length} alerta(s) sobre as fotos — ${alertas.join(' ')}`;
                bannerAlertas.classList.add('active');
            } else {
                bannerAlertas.classList.remove('active');
            }

            // diferenciais_uniao é calculado no backend a cada resposta —
            // união (sem duplicatas) entre o que veio da fala/digitação
            // (diferenciais) e o que a análise de fotos detectou agora mesmo
            // (diferenciais_fotos, sempre a mais recente, nunca acumulada de
            // análises antigas). É isso, não "diferenciais" puro, que a
            // revisão final precisa exibir.
            definirDiferenciais(dados.diferenciais_uniao);
            atualizarDestaqueVazios();
        }

        document.getElementById('btn-analisar-fotos').addEventListener('click', async () => {
            const btn = document.getElementById('btn-analisar-fotos');
            const bannerAnalise = document.getElementById('banner-erro-analise');
            bannerAnalise.classList.remove('active');

            if (!imovelStagingId) {
                bannerAnalise.textContent = 'Cadastro não foi salvo corretamente. Volte e tente novamente.';
                bannerAnalise.classList.add('active');
                return;
            }

            btn.disabled = true;
            // Indicador claro de processamento — pode levar alguns segundos
            // (a análise roda em vários lotes de fotos).
            btn.textContent = 'Analisando fotos...';

            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/analisar-fotos`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                });

                const dados = await resposta.json().catch(() => ({}));

                if (!resposta.ok) {
                    // Erro da IA mantém o corretor na etapa das fotos, com mensagem amigável.
                    bannerAnalise.textContent = dados.message || 'Não foi possível analisar as fotos. Tente novamente.';
                    bannerAnalise.classList.add('active');
                    return;
                }

                aplicarResultadoAnalise(dados);
                mostrarTela('screen-revisao-final');
            } catch (e) {
                console.error('Erro ao analisar fotos:', e);
                bannerAnalise.textContent = 'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.';
                bannerAnalise.classList.add('active');
            } finally {
                btn.disabled = totalFotos < MINIMO_FOTOS;
                btn.textContent = 'Analisar fotos';
            }
        });

        // ---- 5. Revisão final -> 6. Concluir cadastro ----
        document.getElementById('btn-revisao-final-voltar').addEventListener('click', () => mostrarTela('screen-fotos'));

        document.getElementById('btn-concluir').addEventListener('click', async () => {
            const btn = document.getElementById('btn-concluir');
            const bannerFinal = document.getElementById('banner-erro-final');
            limparErros();
            bannerFinal.classList.remove('active');
            btn.disabled = true;
            btn.textContent = 'Concluindo...';

            try {
                try {
                    await salvarRascunho();
                } catch (e) {
                    if (e.resposta && e.resposta.status === 422) {
                        const dadosErro = await e.resposta.json();
                        Object.entries(dadosErro.errors || {}).forEach(([campo, mensagens]) => {
                            const el = document.getElementById('erro-' + campo);
                            if (el) el.textContent = mensagens.join(' ');
                        });
                        throw new Error(dadosErro.message || 'Corrija os campos destacados antes de concluir.');
                    }
                    throw new Error('Não foi possível salvar os dados. Tente novamente.');
                }

                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/finalizar`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                });
                const dados = await resposta.json().catch(() => ({}));

                if (resposta.status === 200 || resposta.status === 201) {
                    mostrarTela('screen-sucesso');
                    return;
                }

                throw new Error(dados.message || ('Erro inesperado ao concluir o cadastro (status ' + resposta.status + ').'));
            } catch (e) {
                bannerFinal.textContent = e.message || 'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.';
                bannerFinal.classList.add('active');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Concluir cadastro';
            }
        });

        // ---- Novo cadastro ----
        document.getElementById('btn-novo-cadastro').addEventListener('click', () => {
            textoLivre.value = '';
            document.querySelectorAll('#screen-revisao input, #screen-revisao textarea, #screen-revisao-final textarea').forEach(el => el.value = '');
            document.querySelectorAll('#screen-revisao select').forEach(el => el.value = '');
            document.querySelectorAll('.toggle-grupo').forEach(grupo => {
                grupo.dataset.value = '';
                grupo.querySelectorAll('.toggle-opcao').forEach(o => o.classList.toggle('selecionada', o.dataset.valor === ''));
            });
            document.querySelectorAll('#chips-diferenciais .chip').forEach(c => c.classList.remove('selecionado'));
            aplicarIptuIsento(false);
            document.getElementById('aviso-extracao-falhou').classList.remove('active');
            document.getElementById('banner-erro-revisao').classList.remove('active');
            document.getElementById('banner-erro-analise').classList.remove('active');
            document.getElementById('banner-erro-final').classList.remove('active');
            document.getElementById('banner-alerta-fotos').classList.remove('active');
            document.getElementById('aviso-fotos').textContent = '';
            document.getElementById('status-fotos').textContent = '';
            document.getElementById('grade-fotos-upload').innerHTML = '';
            document.getElementById('grade-fotos').innerHTML = '';
            document.getElementById('legenda-capa').textContent = '';
            imovelStagingId = null;
            totalFotos = 0;
            fotoCapaId = null;
            fotoCapaSugeridaId = null;
            fotoCapaSugeridaMotivo = null;
            atualizarContadorFotos();
            mostrarTela('screen-captura');
        });

        // Estado inicial: sem fotos ainda, então "Analisar fotos" começa desabilitado.
        atualizarContadorFotos();
    </script>
</body>
</html>
