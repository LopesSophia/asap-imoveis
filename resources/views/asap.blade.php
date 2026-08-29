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
            align-self: center;
        }

        .entrega-caixa {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 12px;
            overflow-y: auto;
            padding-bottom: 24px;
        }
        .entrega-caixa .botoes { justify-content: center; }
        .secao-entrega {
            width: 100%;
            text-align: left;
            border: 1px solid var(--cinza-borda);
            border-radius: 8px;
            padding: 10px 12px;
        }
        .secao-entrega-cabecalho {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }
        .secao-entrega-cabecalho h3 { margin: 0; font-size: 14px; }
        .secao-entrega-cabecalho button { font-size: 12px; padding: 4px 10px; }
        .secao-entrega-texto {
            white-space: pre-wrap;
            word-break: break-word;
            font-family: inherit;
            font-size: 13px;
            color: var(--texto-suave);
            margin: 8px 0 0;
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
        .grade-fotos .thumb .numero-foto {
            position: absolute;
            bottom: 4px;
            right: 4px;
            background: rgba(0,0,0,.6);
            color: #fff;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 999px;
            line-height: 1.4;
        }
        .grade-fotos .thumb.destaque-temporario {
            outline: 3px solid var(--azul);
            outline-offset: 2px;
        }
        .lista-alertas-fotos {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-top: 8px;
        }
        .alerta-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 6px 8px;
            border: 1px solid var(--erro);
            border-radius: 6px;
            background: #fff;
        }
        .alerta-item.clicavel {
            cursor: pointer;
        }
        .alerta-item img {
            width: 40px;
            height: 40px;
            object-fit: cover;
            border-radius: 4px;
            flex-shrink: 0;
        }
        .alerta-item .alerta-texto {
            font-size: 13px;
            line-height: 1.4;
        }
        .alerta-item .alerta-foto-numero {
            font-weight: 700;
            margin-right: 4px;
        }

        .status-descricao {
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }
        .status-descricao-texto {
            color: var(--texto-suave, #666);
        }
        .status-descricao-erro {
            color: var(--erro);
        }
        .btn-tentar-novamente {
            border: 1px solid var(--erro);
            color: var(--erro);
            background: #fff;
            border-radius: 6px;
            padding: 4px 10px;
            font-size: 13px;
            cursor: pointer;
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
        .grade-fotos .thumb .acoes-thumb {
            position: absolute;
            bottom: 4px;
            left: 4px;
            right: 4px;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .grade-fotos .thumb .acoes-thumb button {
            background: rgba(0,0,0,.6);
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 10px;
            padding: 3px 4px;
            cursor: pointer;
        }
        .grade-fotos .thumb .selo-editada {
            display: none;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            padding: 2px 6px;
            line-height: 1.4;
            background: #2e7d32;
            color: #fff;
        }
        .grade-fotos .thumb.editada-ativa .selo-editada { display: inline-block; }
        .grade-fotos .thumb.editada-ativa { border: 2px solid #2e7d32; }

        .overlay-edicao {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 16px;
        }
        .overlay-edicao.active { display: flex; }
        .painel-edicao-foto {
            background: #fff;
            border-radius: 8px;
            padding: 16px;
            max-width: 480px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
        }
        .painel-edicao-foto .fechar-painel {
            position: absolute;
            top: 8px;
            right: 8px;
            background: none;
            border: none;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
        }
        .comparacao-edicao {
            display: flex;
            gap: 8px;
            margin: 12px 0;
        }
        .comparacao-edicao > div { flex: 1; min-width: 0; }
        .comparacao-edicao p {
            font-size: 11px;
            color: var(--texto-suave);
            margin: 0 0 4px;
        }
        .comparacao-edicao img {
            width: 100%;
            border-radius: 6px;
            display: block;
        }
        .selecao-itens-edicao label {
            display: block;
            font-size: 13px;
            margin: 4px 0;
        }
        .status-edicao-foto {
            font-size: 12px;
            color: var(--texto-suave);
            min-height: 14px;
            margin: 8px 0;
        }
        .botoes-edicao {
            display: flex;
            gap: 8px;
            margin-top: 8px;
            flex-wrap: wrap;
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

            {{-- Endereço completo é SEPARADO do relato falado/digitado —
                 extraído por um serviço dedicado (ExtracaoEnderecoService),
                 sempre revisável na próxima tela, nunca inventado se vier
                 incompleto. --}}
            <label for="endereco-completo">Endereço completo do imóvel *</label>
            <textarea id="endereco-completo" class="captura-textarea" placeholder="Ex.: Rua Vergueiro, 1000, apto 52, Vila Mariana, São Paulo - SP, CEP 04101-000"></textarea>
            <div class="erro-msg" id="erro-endereco-completo"></div>

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

            {{-- Endereço estruturado: preenchido automaticamente a partir do
                 campo "Endereço completo do imóvel" da tela inicial
                 (ExtracaoEnderecoService), mas sempre revisável aqui à mão —
                 nunca inventamos o que não veio confirmado. --}}
            <div class="campo">
                <label for="logradouro">Logradouro</label>
                <input type="text" id="logradouro" data-field>
                <div class="erro-msg" id="erro-logradouro"></div>
            </div>

            <div class="linha-3">
                <div class="campo">
                    <label for="numero">Número</label>
                    <input type="text" id="numero" data-field>
                </div>
                <div class="campo">
                    <label class="checkbox-linha">
                        <input type="checkbox" id="sem_numero"> Sem número
                    </label>
                </div>
                <div class="campo">
                    <label for="complemento">Complemento</label>
                    <input type="text" id="complemento" data-field>
                </div>
            </div>

            <div class="campo">
                <label for="bairro">Bairro</label>
                <input type="text" id="bairro" data-field>
                <div class="erro-msg" id="erro-bairro"></div>
            </div>

            <div class="linha-3">
                <div class="campo">
                    <label for="cidade">Cidade</label>
                    <input type="text" id="cidade" data-field>
                    <div class="erro-msg" id="erro-cidade"></div>
                </div>
                <div class="campo">
                    <label for="estado">Estado (UF)</label>
                    <input type="text" id="estado" data-field maxlength="2" placeholder="SP">
                    <div class="erro-msg" id="erro-estado"></div>
                </div>
                <div class="campo">
                    <label for="cep">CEP</label>
                    <input type="text" id="cep" data-field>
                    <div class="erro-msg" id="erro-cep"></div>
                </div>
            </div>

            <div class="campo">
                <label for="nome_edificio">Nome do edifício/condomínio</label>
                <input type="text" id="nome_edificio" data-field placeholder="Se mencionado — não aparece no título nem na descrição">
                <div class="erro-msg" id="erro-nome_edificio"></div>
            </div>

            <div class="linha-3">
                <div class="campo">
                    <label for="metragem">Área útil (m²)</label>
                    <input type="number" step="0.01" min="0" id="metragem" data-field>
                    <div class="erro-msg" id="erro-metragem"></div>
                </div>
                <div class="campo">
                    <label for="area_total">Área total (m²)</label>
                    <input type="number" step="0.01" min="0" id="area_total" data-field>
                </div>
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
                    <div class="rotulo">🛋️ Salas</div>
                    <input type="number" min="0" id="salas" data-field>
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
            <div class="lista-alertas-fotos" id="lista-alertas-fotos"></div>

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
                <div class="status-descricao" id="status-descricao"></div>
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

        {{-- Edição opcional de foto (remoção de itens temporários via Gemini):
             sugestão -> seleção explícita -> geração assíncrona (polling) ->
             comparação original/editada -> aprovar/rejeitar/voltar ao
             original. Um único painel reaproveitado para qualquer foto. --}}
        <div class="overlay-edicao" id="overlay-edicao-foto">
            <div class="painel-edicao-foto">
                <button type="button" class="fechar-painel" id="btn-fechar-edicao" title="Fechar">×</button>
                <h3>Editar foto</h3>

                <div class="comparacao-edicao">
                    <div>
                        <p>Original</p>
                        <img id="img-edicao-original" alt="Foto original">
                    </div>
                    <div>
                        <p>Editada</p>
                        <img id="img-edicao-editada" alt="Foto editada" style="display:none">
                    </div>
                </div>

                <div class="campo">
                    <label>Itens a remover (sugeridos pela análise)</label>
                    <div class="selecao-itens-edicao" id="selecao-itens-edicao"></div>
                </div>

                <div class="status-edicao-foto" id="status-edicao-foto"></div>

                <div class="botoes-edicao">
                    <button type="button" class="primario" id="btn-gerar-edicao">Gerar edição</button>
                    <button type="button" class="secundario" id="btn-aprovar-edicao" style="display:none">Aprovar</button>
                    <button type="button" class="secundario" id="btn-rejeitar-edicao" style="display:none">Rejeitar</button>
                    <button type="button" class="secundario" id="btn-voltar-original-edicao" style="display:none">Voltar ao original</button>
                </div>
            </div>
        </div>

        {{-- SUCESSO --}}
        {{-- Não existe integração com o Prontos nesta versão — esta tela
             organiza o cadastro pronto para o corretor copiar/baixar e
             lançar manualmente. Nunca cadastra proprietário nem cria
             qualquer campo relacionado (fica para a integração futura com o
             Portal do Corretor). --}}
        <section id="screen-sucesso" class="screen">
            <div class="entrega-caixa">
                <div class="sucesso-icone">✓</div>
                <h2>Cadastro preparado para o Prontos</h2>
                <p style="color: var(--texto-suave)">Copie os dados abaixo ou baixe o pacote completo para lançar manualmente no Prontos.</p>

                <div class="botoes">
                    <button type="button" class="primario" id="btn-copiar-tudo">Copiar todos os dados</button>
                    <button type="button" class="secundario" id="btn-baixar-zip">Baixar pacote (ZIP)</button>
                </div>
                <div class="status-fotos" id="status-pacote-prontos"></div>

                <div class="secao-entrega" data-secao="identificacao">
                    <div class="secao-entrega-cabecalho">
                        <h3>1. Identificação e localização</h3>
                        <button type="button" class="secundario btn-copiar-secao">Copiar</button>
                    </div>
                    <pre class="secao-entrega-texto" id="texto-secao-identificacao"></pre>
                </div>

                <div class="secao-entrega" data-secao="medidas">
                    <div class="secao-entrega-cabecalho">
                        <h3>2. Medidas e características</h3>
                        <button type="button" class="secundario btn-copiar-secao">Copiar</button>
                    </div>
                    <pre class="secao-entrega-texto" id="texto-secao-medidas"></pre>
                </div>

                <div class="secao-entrega" data-secao="negocio">
                    <div class="secao-entrega-cabecalho">
                        <h3>3. Tipo de negócio</h3>
                        <button type="button" class="secundario btn-copiar-secao">Copiar</button>
                    </div>
                    <pre class="secao-entrega-texto" id="texto-secao-negocio"></pre>
                </div>

                <div class="secao-entrega" data-secao="adicionais">
                    <div class="secao-entrega-cabecalho">
                        <h3>4. Informações adicionais</h3>
                        <button type="button" class="secundario btn-copiar-secao">Copiar</button>
                    </div>
                    <pre class="secao-entrega-texto" id="texto-secao-adicionais"></pre>
                </div>

                <div class="secao-entrega" data-secao="anuncio">
                    <div class="secao-entrega-cabecalho">
                        <h3>5. Conteúdo do anúncio</h3>
                        <button type="button" class="secundario btn-copiar-secao">Copiar</button>
                    </div>
                    <pre class="secao-entrega-texto" id="texto-secao-anuncio"></pre>
                </div>

                <div class="secao-entrega" data-secao="fotos">
                    <div class="secao-entrega-cabecalho">
                        <h3>6. Fotos</h3>
                        <button type="button" class="secundario btn-copiar-secao">Copiar</button>
                    </div>
                    <pre class="secao-entrega-texto" id="texto-secao-fotos"></pre>
                </div>

                <button type="button" class="primario" id="btn-novo-cadastro">Novo cadastro</button>
            </div>
        </section>
    </div>

    <script>
        // URLs absolutas geradas pelo Laravel: funcionam tanto acessando pelo
        // domínio raiz quanto por subpasta (ex.: http://localhost/asap-imoveis/public/asap).
        const URL_EXTRAIR_IMOVEL = @json(url('/api/extrair-imovel'));
        const URL_EXTRAIR_ENDERECO = @json(url('/api/extrair-endereco'));
        const URL_IMOVEIS_STAGING = @json(url('/api/imoveis-staging'));

        const MINIMO_FOTOS = 25;

        // O PHP do servidor tem max_file_uploads=20 (não alteramos php.ini) —
        // 10 fica bem abaixo disso, com folga, mesmo que outros campos de
        // arquivo entrem no formulário no futuro.
        const MAX_FOTOS_POR_LOTE_UPLOAD = 10;

        // ---- Estado do rascunho salvo no servidor (id do registro + contagem de fotos) ----
        let imovelStagingId = null;
        let totalFotos = 0;
        // Foto de capa ATIVA (o que realmente vale) vs. sugestão da IA — dois
        // conceitos separados, podem ser fotos diferentes.
        let fotoCapaId = null;
        let fotoCapaSugeridaId = null;
        let fotoCapaSugeridaMotivo = null;

        // ---- Campos do schema (nesta ordem visual) ----
        const CAMPOS_TEXTO = ['tipo_imovel', 'negociacao', 'utilizacao', 'logradouro', 'numero', 'complemento', 'bairro', 'cidade', 'estado', 'cep', 'nome_edificio', 'vagas_cobertura', 'estado_conservacao'];
        const CAMPOS_NUMERO = ['metragem', 'area_total', 'quartos', 'suites', 'banheiros', 'salas', 'vagas', 'valor', 'condominio', 'iptu'];
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

        // Mescla dois objetos de extração SEM deixar um campo null/undefined
        // de "sobrepor" apagar um valor já preenchido em "base" — usado ao
        // combinar a extração do relato geral com a extração dedicada de
        // endereço: a dedicada tem prioridade QUANDO tem um valor de
        // verdade, mas nunca "vence" só por ter vindo depois se ela mesma
        // não conseguiu extrair aquele campo (retornou null).
        function mesclarPreferindoNaoNulo(base, sobrepor) {
            const resultado = { ...base };
            for (const chave in sobrepor) {
                const valor = sobrepor[chave];
                if (valor !== null && valor !== undefined) {
                    resultado[chave] = valor;
                }
            }
            return resultado;
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
            definirValor('logradouro', dados.logradouro);
            definirValor('numero', dados.numero);
            document.getElementById('sem_numero').checked = !!dados.sem_numero;
            definirValor('complemento', dados.complemento);
            definirValor('bairro', dados.bairro);
            definirValor('cidade', dados.cidade);
            definirValor('estado', dados.estado);
            definirValor('cep', dados.cep);
            definirValor('nome_edificio', dados.nome_edificio);
            definirValor('metragem', dados.metragem);
            definirValor('area_total', dados.area_total);
            definirValor('quartos', dados.quartos);
            definirValor('suites', dados.suites);
            definirValor('banheiros', dados.banheiros);
            definirValor('salas', dados.salas);
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
        // Endereço completo é SEPARADO do relato falado/digitado e sempre
        // obrigatório: extraído pelo serviço dedicado (ExtracaoEnderecoService)
        // e tem prioridade sobre qualquer bairro/cidade que a extração geral
        // do relato possa ter capturado. Nunca inventa: se vier incompleto
        // ou de baixa confiança, o banner de aviso pede revisão manual — os
        // campos continuam editáveis na tela seguinte.
        document.getElementById('btn-processar').addEventListener('click', async () => {
            const btnProcessar = document.getElementById('btn-processar');
            const aviso = document.getElementById('aviso-extracao-falhou');
            const erroEndereco = document.getElementById('erro-endereco-completo');
            const texto = textoLivre.value.trim();
            const enderecoCompleto = document.getElementById('endereco-completo').value.trim();

            aviso.classList.remove('active');
            erroEndereco.textContent = '';

            if (!enderecoCompleto) {
                erroEndereco.textContent = 'Informe o endereço completo do imóvel antes de continuar.';
                return;
            }

            btnProcessar.disabled = true;
            btnProcessar.textContent = 'Processando...';

            let dadosCombinados = {};
            let houveFalha = false;

            try {
                const respostaEndereco = await fetch(URL_EXTRAIR_ENDERECO, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ texto: enderecoCompleto }),
                });

                if (!respostaEndereco.ok) throw new Error('extracao_endereco_falhou');

                const dadosEndereco = await respostaEndereco.json();
                dadosCombinados = { ...dadosCombinados, ...dadosEndereco };

                // "completo: false" = a IA não conseguiu extrair um endereço
                // confiável — nunca finge que está tudo certo, pede revisão.
                if (dadosEndereco.completo === false) houveFalha = true;
            } catch (e) {
                houveFalha = true;
            }

            if (texto) {
                try {
                    const respostaImovel = await fetch(URL_EXTRAIR_IMOVEL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                        body: JSON.stringify({ texto }),
                    });

                    if (!respostaImovel.ok) throw new Error('extracao_imovel_falhou');

                    const dadosImovel = await respostaImovel.json();
                    // Endereço dedicado tem prioridade QUANDO tem valor de
                    // verdade — mas um campo que a extração dedicada não
                    // conseguiu identificar (null) nunca apaga um valor bom
                    // que o relato geral tenha capturado (ex.: cidade).
                    dadosCombinados = mesclarPreferindoNaoNulo(dadosImovel, dadosCombinados);
                } catch (e) {
                    houveFalha = true;
                    if (!document.getElementById('observacoes_corretor').value.trim()) {
                        document.getElementById('observacoes_corretor').value = texto;
                    }
                }
            }

            preencherRevisaoComDados(dadosCombinados);

            btnProcessar.disabled = false;
            btnProcessar.textContent = 'Processar';
            if (houveFalha) {
                aviso.classList.add('active');
            }
            mostrarTela('screen-revisao');
            atualizarDestaqueVazios();
        });

        // ---- Destaque de campos vazios ----
        function atualizarDestaqueVazios() {
            [...CAMPOS_TEXTO, ...CAMPOS_NUMERO, ...CAMPOS_TEXTAREA].forEach(id => {
                const el = document.getElementById(id);
                if (!el) return;
                // IPTU vazio por causa da isenção, ou número vazio por causa
                // de "sem número", são ausências intencionais — não campos
                // esquecidos — não faz sentido destacar como vazio.
                if (id === 'iptu' && document.getElementById('iptu_isento').checked) {
                    el.classList.remove('vazio');
                    return;
                }
                if (id === 'numero' && document.getElementById('sem_numero').checked) {
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

        document.getElementById('sem_numero').addEventListener('change', () => {
            if (document.getElementById('sem_numero').checked) {
                document.getElementById('numero').value = '';
            }
            atualizarDestaqueVazios();
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
                logradouro: valorTexto('logradouro'),
                numero: valorTexto('numero'),
                sem_numero: document.getElementById('sem_numero').checked,
                complemento: valorTexto('complemento'),
                bairro: valorTexto('bairro'),
                cidade: valorTexto('cidade'),
                estado: valorTexto('estado'),
                cep: valorTexto('cep'),
                nome_edificio: valorTexto('nome_edificio'),
                metragem: valorNumero('metragem'),
                area_total: valorNumero('area_total'),

                quartos: valorNumero('quartos'),
                suites: valorNumero('suites'),
                banheiros: valorNumero('banheiros'),
                salas: valorNumero('salas'),
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
        // "Foto N" é a numeração VISUAL (posição na grade, 1-based) — nunca
        // o id interno do banco. O id interno (foto.id) continua existindo
        // só no backend/atributos internos (data-foto-id, chamadas de API);
        // nada voltado ao corretor mostra esse número.
        function criarThumbnailFinal(foto, indice) {
            const div = document.createElement('div');
            div.className = 'thumb';
            div.id = `foto-thumb-${foto.id}`;
            div.dataset.fotoId = foto.id;
            div.dataset.urlOriginal = foto.url_original;
            if (foto.edicao_ativa_id) div.classList.add('editada-ativa');

            // "Editar foto" só existe quando a foto tem ao menos uma
            // sugestão válida (o backend já filtrou por categoria permitida
            // — qualquer item aqui é, por construção, elegível) — nunca
            // oferecemos edição "no escuro", sem sugestão nenhuma.
            const temSugestaoValida = Array.isArray(foto.itens_removiveis_sugeridos) && foto.itens_removiveis_sugeridos.length > 0;

            div.innerHTML = `
                <img src="${foto.url}" alt="Foto ${indice} do imóvel">
                <span class="numero-foto">Foto ${indice}</span>
                <div class="selos-capa">
                    <span class="selo-capa">Capa selecionada</span>
                    <span class="selo-sugestao">Sugestão da IA</span>
                    <span class="selo-editada">Versão editada ativa</span>
                </div>
                <div class="acoes-thumb">
                    <button type="button" class="btn-capa" title="Definir como foto de capa">Definir capa</button>
                    ${temSugestaoValida ? '<button type="button" class="btn-editar" title="Remover itens temporários">Editar foto</button>' : ''}
                </div>
            `;
            div.querySelector('.btn-capa').addEventListener('click', () => selecionarFotoCapa(foto.id));
            div.querySelector('.btn-editar')?.addEventListener('click', () => abrirPainelEdicao(foto));
            return div;
        }

        // Mapas fotoId (interno) -> número visual ("Foto N") / dados da foto
        // — usados pela lista de alertas pra mostrar miniatura + "Foto N" e
        // rolar até a foto certa, sem nunca expor o id interno ao corretor.
        let numeroVisualPorFotoId = {};
        let fotosPorId = {};

        function popularGradeFinal(fotos) {
            const grade = document.getElementById('grade-fotos');
            grade.innerHTML = '';
            numeroVisualPorFotoId = {};
            fotosPorId = {};
            (fotos || []).forEach((foto, i) => {
                numeroVisualPorFotoId[foto.id] = i + 1;
                fotosPorId[foto.id] = foto;
                grade.appendChild(criarThumbnailFinal(foto, i + 1));
            });
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

        // ---- Edição de fotos com Gemini (remoção de itens temporários) ----
        // Fluxo: sugestão (por foto, vinda da análise) -> seleção EXPLÍCITA
        // do corretor -> geração assíncrona (job + polling) -> comparação
        // original/editada -> aprovar ou rejeitar. Nunca sobrescreve o
        // arquivo original — "aprovar" só troca qual versão fica ATIVA;
        // "voltar ao original" desliga a versão ativa sem apagar histórico.
        let fotoEmEdicaoId = null;
        let edicaoEmAndamentoId = null;
        let pollingEdicaoTimer = null;

        function pararPollingEdicao() {
            if (pollingEdicaoTimer) {
                clearInterval(pollingEdicaoTimer);
                pollingEdicaoTimer = null;
            }
        }

        function abrirPainelEdicao(foto) {
            fotoEmEdicaoId = foto.id;
            edicaoEmAndamentoId = null;
            pararPollingEdicao();

            document.getElementById('img-edicao-original').src = foto.url_original;
            const imgEditada = document.getElementById('img-edicao-editada');
            imgEditada.style.display = 'none';
            imgEditada.src = '';

            // Cada checkbox carrega a sugestão INTEIRA (categoria + descrição)
            // exatamente como veio do backend — o corretor só pode escolher
            // entre elas, nunca digitar algo novo (o backend rejeitaria).
            const selecao = document.getElementById('selecao-itens-edicao');
            const sugestoes = foto.itens_removiveis_sugeridos || [];
            selecao.innerHTML = sugestoes.map(item => `
                <label>
                    <input type="checkbox" class="item-sugerido"
                        data-categoria="${item.categoria.replace(/"/g, '&quot;')}"
                        data-descricao="${item.descricao.replace(/"/g, '&quot;')}"
                        checked>
                    ${item.descricao}
                </label>
            `).join('');

            document.getElementById('status-edicao-foto').textContent = foto.edicao_ativa_id
                ? 'Esta foto já tem uma versão editada ativa. Gerar uma nova tentativa não altera a versão ativa até você aprovar.'
                : '';

            document.getElementById('btn-gerar-edicao').style.display = 'inline-block';
            document.getElementById('btn-gerar-edicao').disabled = false;
            document.getElementById('btn-gerar-edicao').textContent = 'Gerar edição';
            document.getElementById('btn-aprovar-edicao').style.display = 'none';
            document.getElementById('btn-rejeitar-edicao').style.display = 'none';
            document.getElementById('btn-voltar-original-edicao').style.display = foto.edicao_ativa_id ? 'inline-block' : 'none';

            document.getElementById('overlay-edicao-foto').classList.add('active');
        }

        function fecharPainelEdicao() {
            pararPollingEdicao();
            document.getElementById('overlay-edicao-foto').classList.remove('active');
        }

        document.getElementById('btn-fechar-edicao').addEventListener('click', fecharPainelEdicao);

        function itensSelecionadosNoPainel() {
            return Array.from(document.querySelectorAll('.item-sugerido:checked')).map(el => ({
                categoria: el.dataset.categoria,
                descricao: el.dataset.descricao,
            }));
        }

        function iniciarPollingEdicao() {
            pararPollingEdicao();
            pollingEdicaoTimer = setInterval(async () => {
                try {
                    const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/fotos/${fotoEmEdicaoId}/edicoes/${edicaoEmAndamentoId}`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    const dados = await resposta.json().catch(() => ({}));
                    if (!resposta.ok) return;
                    if (dados.status === 'processando' || dados.status === 'pendente') return;

                    pararPollingEdicao();
                    const status = document.getElementById('status-edicao-foto');
                    const btnGerar = document.getElementById('btn-gerar-edicao');

                    if (dados.status === 'gerada') {
                        status.textContent = 'Edição gerada. Compare e aprove ou rejeite.';
                        const imgEditada = document.getElementById('img-edicao-editada');
                        imgEditada.src = dados.url;
                        imgEditada.style.display = 'block';
                        btnGerar.style.display = 'none';
                        document.getElementById('btn-aprovar-edicao').style.display = 'inline-block';
                        document.getElementById('btn-rejeitar-edicao').style.display = 'inline-block';
                    } else if (dados.status === 'erro') {
                        status.textContent = dados.mensagem_erro || 'Falha ao gerar a edição. Tente novamente.';
                        btnGerar.disabled = false;
                        btnGerar.textContent = 'Gerar edição';
                    }
                } catch (e) {
                    // Falha transitória de rede — tenta de novo no próximo tick.
                }
            }, 3000);
        }

        document.getElementById('btn-gerar-edicao').addEventListener('click', async () => {
            const itens = itensSelecionadosNoPainel();
            const status = document.getElementById('status-edicao-foto');

            if (itens.length === 0) {
                status.textContent = 'Selecione ao menos um item para remover.';
                return;
            }

            const btn = document.getElementById('btn-gerar-edicao');
            btn.disabled = true;
            btn.textContent = 'Processando...';
            status.textContent = 'Gerando edição — isso pode levar até um minuto.';

            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/fotos/${fotoEmEdicaoId}/edicoes`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                    body: JSON.stringify({ itens }),
                });

                const dados = await resposta.json().catch(() => ({}));

                if (!resposta.ok) {
                    throw new Error(dados.message || 'Não foi possível iniciar a edição.');
                }

                edicaoEmAndamentoId = dados.id;
                iniciarPollingEdicao();
            } catch (e) {
                status.textContent = e.message || 'Não foi possível iniciar a edição. Tente novamente.';
                btn.disabled = false;
                btn.textContent = 'Gerar edição';
            }
        });

        // Reflete no grid qual é a versão ativa da foto sem precisar
        // reanalisar tudo de novo — urlEditada null = voltou pro original.
        function aplicarEdicaoAtivaNoThumb(fotoId, urlEditada) {
            const thumb = document.querySelector(`#grade-fotos .thumb[data-foto-id="${fotoId}"]`);
            if (!thumb) return;

            const img = thumb.querySelector('img');
            if (urlEditada) {
                img.src = urlEditada;
                thumb.classList.add('editada-ativa');
            } else {
                img.src = thumb.dataset.urlOriginal || img.src;
                thumb.classList.remove('editada-ativa');
            }
        }

        document.getElementById('btn-aprovar-edicao').addEventListener('click', async () => {
            const status = document.getElementById('status-edicao-foto');
            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/fotos/${fotoEmEdicaoId}/edicoes/${edicaoEmAndamentoId}/aprovar`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                });
                const dados = await resposta.json().catch(() => ({}));
                if (!resposta.ok) throw new Error(dados.message || 'Não foi possível aprovar a edição.');

                aplicarEdicaoAtivaNoThumb(fotoEmEdicaoId, dados.url);
                status.textContent = 'Edição aprovada — agora é a versão ativa desta foto.';
                document.getElementById('btn-aprovar-edicao').style.display = 'none';
                document.getElementById('btn-rejeitar-edicao').style.display = 'none';
                document.getElementById('btn-voltar-original-edicao').style.display = 'inline-block';

                // Conteúdo visual ativo mudou — análise e sugestão automática
                // de capa ficaram desatualizadas (o backend já invalidou
                // fotos_analisadas_em); o corretor precisa reanalisar antes
                // de concluir o cadastro.
                const bannerAlertas = document.getElementById('banner-alerta-fotos');
                bannerAlertas.textContent = 'Uma foto foi editada. Volte à etapa de fotos e reanalise antes de concluir o cadastro.';
                bannerAlertas.classList.add('active');
            } catch (e) {
                status.textContent = e.message || 'Não foi possível aprovar a edição. Tente novamente.';
            }
        });

        document.getElementById('btn-rejeitar-edicao').addEventListener('click', async () => {
            const status = document.getElementById('status-edicao-foto');
            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/fotos/${fotoEmEdicaoId}/edicoes/${edicaoEmAndamentoId}/rejeitar`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                });
                if (!resposta.ok) {
                    const dados = await resposta.json().catch(() => ({}));
                    throw new Error(dados.message || 'Não foi possível rejeitar a edição.');
                }

                status.textContent = 'Edição rejeitada. A versão ativa da foto não mudou.';
                document.getElementById('btn-aprovar-edicao').style.display = 'none';
                document.getElementById('btn-rejeitar-edicao').style.display = 'none';
                document.getElementById('btn-gerar-edicao').style.display = 'inline-block';
                document.getElementById('btn-gerar-edicao').disabled = false;
                document.getElementById('btn-gerar-edicao').textContent = 'Gerar edição';
                document.getElementById('img-edicao-editada').style.display = 'none';
            } catch (e) {
                status.textContent = e.message || 'Não foi possível rejeitar a edição. Tente novamente.';
            }
        });

        document.getElementById('btn-voltar-original-edicao').addEventListener('click', async () => {
            const status = document.getElementById('status-edicao-foto');
            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/fotos/${fotoEmEdicaoId}/edicao-ativa`, {
                    method: 'DELETE',
                    headers: { 'Accept': 'application/json' },
                });
                if (!resposta.ok && resposta.status !== 204) throw new Error('Não foi possível voltar ao original.');

                aplicarEdicaoAtivaNoThumb(fotoEmEdicaoId, null);
                status.textContent = 'Foto voltou a exibir a versão original.';
                document.getElementById('btn-voltar-original-edicao').style.display = 'none';
                document.getElementById('img-edicao-editada').style.display = 'none';
            } catch (e) {
                status.textContent = e.message || 'Não foi possível voltar ao original. Tente novamente.';
            }
        });

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
        // Corta um array em lotes de tamanho fixo (o último lote pode ser menor).
        function dividirEmLotes(itens, tamanho) {
            const lotes = [];
            for (let i = 0; i < itens.length; i += tamanho) {
                lotes.push(itens.slice(i, i + tamanho));
            }
            return lotes;
        }

        // O texto nativo do <input type="file"> (ex.: "5 arquivos selecionados")
        // volta para "Escolher arquivos" assim que resetamos evento.target.value
        // após o upload — por isso NUNCA usamos esse texto como feedback. A fonte
        // única de verdade é totalFotos/#contador-fotos (vindo do servidor); o
        // #status-fotos abaixo é só uma mensagem transitória sobre o progresso.
        document.getElementById('input-fotos').addEventListener('change', async (evento) => {
            const arquivos = [...evento.target.files];
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

            // O PHP do servidor tem max_file_uploads=20 (não mexemos no
            // php.ini) — selecionar 25 fotos de uma vez faz o PHP descartar a
            // requisição e devolver HTML em vez de JSON. Dividimos em lotes de
            // no máximo MAX_FOTOS_POR_LOTE_UPLOAD e enviamos um de cada vez,
            // em SEQUÊNCIA (nunca em paralelo) pro MESMO endpoint de sempre —
            // isso preserva a ordem das fotos, já que o backend numera "ordem"
            // com base na maior ordem já salva no momento de cada request.
            const lotes = dividirEmLotes(arquivos, MAX_FOTOS_POR_LOTE_UPLOAD);

            evento.target.disabled = true;

            let enviadas = 0;

            for (let i = 0; i < lotes.length; i++) {
                const lote = lotes[i];
                const rotuloLote = lotes.length > 1 ? ` (lote ${i + 1}/${lotes.length})` : '';
                statusFotos.textContent = `Enviando ${enviadas + lote.length}/${quantidadeSelecionada} foto(s)...${rotuloLote}`;

                const formData = new FormData();
                lote.forEach(arquivo => formData.append('fotos[]', arquivo));

                // Camada de rede: só cobre falha de conexão/timeout ou corpo
                // que não é JSON — nunca deixa uma exceção crua chegar até o
                // usuário. Em qualquer falha, interrompe os PRÓXIMOS lotes,
                // mas preserva grade/contador do que já foi enviado com sucesso.
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
                    avisoFotos.textContent = enviadas > 0
                        ? `Não foi possível conectar ao servidor${rotuloLote}. ${enviadas} de ${quantidadeSelecionada} foto(s) já enviada(s) foram preservadas — selecione o restante e tente de novo.`
                        : 'Não foi possível conectar ao servidor. Verifique sua conexão e tente novamente.';
                    evento.target.value = '';
                    evento.target.disabled = false;
                    return;
                }

                // Erro de validação/servidor (4xx/5xx): a mensagem do Laravel
                // já é segura para exibir (nunca é um stack trace de JS).
                if (!resposta.ok) {
                    statusFotos.textContent = '';
                    const mensagem = dados.message || 'Falha ao enviar fotos. Tente novamente.';
                    avisoFotos.textContent = enviadas > 0
                        ? `${mensagem}${rotuloLote} ${enviadas} de ${quantidadeSelecionada} foto(s) já enviada(s) foram preservadas — selecione o restante e tente de novo.`
                        : mensagem;
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
                    avisoFotos.textContent = enviadas > 0
                        ? `O servidor respondeu em um formato inesperado${rotuloLote}. ${enviadas} de ${quantidadeSelecionada} foto(s) já enviada(s) foram preservadas — tente enviar o restante novamente.`
                        : 'O servidor respondeu em um formato inesperado. Tente novamente; se persistir, avise o suporte.';
                    evento.target.value = '';
                    evento.target.disabled = false;
                    return;
                }

                dados.fotos.forEach(adicionarThumbnail);
                totalFotos = dados.total_fotos;
                enviadas += lote.length;
                atualizarContadorFotos();
            }

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

                // Endereço confirmado alimenta a busca de entorno e ano de
                // construção (mesmos serviços já existentes, reutilizados no
                // backend) — nunca trava a continuação do cadastro se falhar,
                // é um enriquecimento em segundo plano.
                try {
                    await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/enriquecer-localizacao`, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                    });
                } catch (erroEnriquecimento) {
                    console.error('Falha ao enriquecer localização/ano de construção:', erroEnriquecimento);
                }

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
        // Rola até a miniatura da foto e a destaca temporariamente — nunca
        // expõe o id interno, só usa "foto-thumb-{id}" como âncora de DOM.
        function destacarFotoTemporariamente(fotoId) {
            const thumb = document.getElementById(`foto-thumb-${fotoId}`);
            if (!thumb) return;

            thumb.scrollIntoView({ behavior: 'smooth', block: 'center' });
            thumb.classList.add('destaque-temporario');
            setTimeout(() => thumb.classList.remove('destaque-temporario'), 2000);
        }

        // Cada alerta mostra: miniatura (quando vinculado a uma foto),
        // "Foto N" (numeração VISUAL, nunca o id interno) e a mensagem sem
        // nenhum identificador embutido — o backend já garante isso, mas o
        // texto nunca é montado aqui concatenando id nenhum. Alertas gerais
        // (sem foto_id) continuam permitidos, só sem miniatura/número.
        function renderizarAlertasFotos(alertas) {
            const lista = document.getElementById('lista-alertas-fotos');
            lista.innerHTML = '';

            alertas.forEach(alerta => {
                const fotoId = alerta.foto_id ?? null;
                const numero = fotoId !== null ? numeroVisualPorFotoId[fotoId] : null;
                const foto = fotoId !== null ? fotosPorId[fotoId] : null;

                const item = document.createElement('div');
                item.className = 'alerta-item' + (foto ? ' clicavel' : '');

                item.innerHTML = `
                    ${foto ? `<img src="${foto.url}" alt="Miniatura da foto ${numero}">` : ''}
                    <div class="alerta-texto">
                        ${numero !== null ? `<span class="alerta-foto-numero">Foto ${numero}:</span>` : ''}
                        ${alerta.mensagem}
                    </div>
                `;

                if (foto) {
                    item.addEventListener('click', () => destacarFotoTemporariamente(fotoId));
                }

                lista.appendChild(item);
            });
        }

        function aplicarResultadoAnalise(dados) {
            popularGradeFinal(dados.fotos || []);

            fotoCapaId = dados.foto_capa_id ?? null;
            fotoCapaSugeridaId = dados.foto_capa_sugerida_id ?? null;
            fotoCapaSugeridaMotivo = dados.foto_capa_motivo ?? null;
            atualizarBadgesCapa();

            renderizarAlertasFotos(dados.alertas_fotos_normalizados || []);

            // diferenciais_uniao é calculado no backend a cada resposta —
            // união (sem duplicatas) entre o que veio da fala/digitação
            // (diferenciais) e o que a análise de fotos detectou agora mesmo
            // (diferenciais_fotos, sempre a mais recente, nunca acumulada de
            // análises antigas). É isso, não "diferenciais" puro, que a
            // revisão final precisa exibir.
            definirDiferenciais(dados.diferenciais_uniao);
            atualizarDestaqueVazios();
        }

        // ---- Geração assíncrona da descrição (título é síncrono/imediato) ----
        let pollingDescricaoTimeoutId = null;

        function pararPollingDescricao() {
            if (pollingDescricaoTimeoutId) {
                clearTimeout(pollingDescricaoTimeoutId);
                pollingDescricaoTimeoutId = null;
            }
        }

        function renderizarStatusDescricao(status, erro) {
            const statusEl = document.getElementById('status-descricao');
            if (!statusEl) return;

            if (status === 'pendente' || status === 'processando') {
                statusEl.innerHTML = '<span class="status-descricao-texto">Gerando descrição...</span>';
                return;
            }

            if (status === 'erro') {
                statusEl.innerHTML = `
                    <span class="status-descricao-texto status-descricao-erro">${erro || 'Não foi possível gerar a descrição.'}</span>
                    <button type="button" class="btn-tentar-novamente" id="btn-tentar-novamente-descricao">Tentar novamente</button>
                `;
                document.getElementById('btn-tentar-novamente-descricao').addEventListener('click', tentarNovamenteDescricao);
                return;
            }

            statusEl.innerHTML = '';
        }

        // Aplica o resultado (imediato, de um POST, OU de um GET de polling) —
        // nunca sobrescreve texto que o corretor já digitou no campo, e nunca
        // deixa o campo vazio sem alguma explicação visível (status ou erro).
        function aplicarStatusDescricao(dados) {
            const campoTitulo = document.getElementById('titulo_site');
            const campoDescricao = document.getElementById('descricao_gerada');

            if (campoTitulo && campoTitulo.value.trim() === '' && dados.titulo_site) {
                campoTitulo.value = dados.titulo_site;
            }

            if (campoDescricao && campoDescricao.value.trim() === '' && dados.descricao_gerada) {
                campoDescricao.value = dados.descricao_gerada;
            }

            atualizarDestaqueVazios();

            if (campoDescricao && campoDescricao.value.trim() !== '') {
                // Já tem conteúdo (da IA ou do corretor) — nada mais a mostrar/aguardar.
                renderizarStatusDescricao(null, null);
                pararPollingDescricao();
                return;
            }

            renderizarStatusDescricao(dados.descricao_geracao_status, dados.descricao_geracao_erro);

            if (dados.descricao_geracao_status === 'pendente' || dados.descricao_geracao_status === 'processando') {
                agendarPollingDescricao();
            } else {
                pararPollingDescricao();
            }
        }

        function agendarPollingDescricao() {
            pararPollingDescricao();
            pollingDescricaoTimeoutId = setTimeout(async () => {
                if (!imovelStagingId) return;
                try {
                    const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/status-descricao`, {
                        headers: { 'Accept': 'application/json' },
                    });
                    if (resposta.ok) {
                        aplicarStatusDescricao(await resposta.json());
                    } else {
                        agendarPollingDescricao();
                    }
                } catch (erroPolling) {
                    console.error('Falha ao consultar status da descrição:', erroPolling);
                    agendarPollingDescricao();
                }
            }, 3000);
        }

        async function tentarNovamenteDescricao() {
            if (!imovelStagingId) return;
            renderizarStatusDescricao('processando', null);
            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/gerar-titulo-descricao`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                });
                if (resposta.ok) {
                    aplicarStatusDescricao(await resposta.json());
                }
            } catch (erroTentativa) {
                console.error('Falha ao tentar gerar a descrição novamente:', erroTentativa);
            }
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

                // Endpoint dedicado (não roda dentro de analisar-fotos) —
                // só preenche título/descrição se estiverem vazios; nunca
                // trava o fluxo se falhar (o corretor preenche manualmente).
                try {
                    const respostaTexto = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/gerar-titulo-descricao`, {
                        method: 'POST',
                        headers: { 'Accept': 'application/json' },
                    });
                    if (respostaTexto.ok) {
                        aplicarStatusDescricao(await respostaTexto.json());
                    }
                } catch (erroTexto) {
                    console.error('Falha ao gerar título/descrição automaticamente:', erroTexto);
                }
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
        document.getElementById('btn-revisao-final-voltar').addEventListener('click', () => {
            pararPollingDescricao();
            mostrarTela('screen-fotos');
        });

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
                    pararPollingDescricao();
                    mostrarTela('screen-sucesso');
                    carregarPacoteProntos();
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

        // ---- Tela de entrega: pacote para o Prontos (sem integração real) ----
        // Busca o MESMO texto formatado usado dentro do ZIP (fonte única de
        // verdade no backend — PacoteProntosService) e preenche as 6 seções.
        const SECOES_PACOTE_PRONTOS = ['identificacao', 'medidas', 'negocio', 'adicionais', 'anuncio', 'fotos'];
        let textoCompletoPacoteProntos = '';

        async function carregarPacoteProntos() {
            const status = document.getElementById('status-pacote-prontos');
            status.textContent = 'Carregando dados do pacote...';

            try {
                const resposta = await fetch(`${URL_IMOVEIS_STAGING}/${imovelStagingId}/pacote-prontos`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!resposta.ok) throw new Error('pacote_prontos_falhou');

                const dados = await resposta.json();
                textoCompletoPacoteProntos = dados.texto_completo || '';

                SECOES_PACOTE_PRONTOS.forEach(secao => {
                    const el = document.getElementById(`texto-secao-${secao}`);
                    if (el) el.textContent = (dados.secoes && dados.secoes[secao]) || '';
                });

                status.textContent = '';
            } catch (e) {
                status.textContent = 'Não foi possível carregar os dados do pacote. Tente recarregar a página.';
            }
        }

        async function copiarTexto(texto, elementoStatus, mensagemSucesso) {
            try {
                await navigator.clipboard.writeText(texto);
                if (elementoStatus) {
                    elementoStatus.textContent = mensagemSucesso;
                    setTimeout(() => { elementoStatus.textContent = ''; }, 3000);
                }
            } catch (e) {
                if (elementoStatus) {
                    elementoStatus.textContent = 'Não foi possível copiar automaticamente — selecione e copie manualmente.';
                }
            }
        }

        document.querySelectorAll('.btn-copiar-secao').forEach(botao => {
            botao.addEventListener('click', () => {
                const secaoEl = botao.closest('.secao-entrega');
                const texto = secaoEl.querySelector('.secao-entrega-texto').textContent;
                copiarTexto(texto, document.getElementById('status-pacote-prontos'), 'Seção copiada.');
            });
        });

        document.getElementById('btn-copiar-tudo').addEventListener('click', () => {
            copiarTexto(textoCompletoPacoteProntos, document.getElementById('status-pacote-prontos'), 'Todos os dados foram copiados.');
        });

        document.getElementById('btn-baixar-zip').addEventListener('click', () => {
            // Download simples via navegação — o backend já manda
            // Content-Disposition: attachment, o navegador salva o arquivo.
            window.location.href = `${URL_IMOVEIS_STAGING}/${imovelStagingId}/pacote-prontos.zip`;
        });

        // ---- Novo cadastro ----
        document.getElementById('btn-novo-cadastro').addEventListener('click', () => {
            textoLivre.value = '';
            document.getElementById('endereco-completo').value = '';
            document.getElementById('erro-endereco-completo').textContent = '';
            document.querySelectorAll('#screen-revisao input, #screen-revisao textarea, #screen-revisao-final textarea').forEach(el => el.value = '');
            document.querySelectorAll('#screen-revisao select').forEach(el => el.value = '');
            document.querySelectorAll('.toggle-grupo').forEach(grupo => {
                grupo.dataset.value = '';
                grupo.querySelectorAll('.toggle-opcao').forEach(o => o.classList.toggle('selecionada', o.dataset.valor === ''));
            });
            document.querySelectorAll('#chips-diferenciais .chip').forEach(c => c.classList.remove('selecionado'));
            aplicarIptuIsento(false);
            document.getElementById('sem_numero').checked = false;
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
            SECOES_PACOTE_PRONTOS.forEach(secao => {
                const el = document.getElementById(`texto-secao-${secao}`);
                if (el) el.textContent = '';
            });
            textoCompletoPacoteProntos = '';
            document.getElementById('status-pacote-prontos').textContent = '';
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
