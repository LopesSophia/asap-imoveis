<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreImovelStagingRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // TODO: remover quando a autenticação por corretor estiver disponível (depende da TI).
            'corretor_id' => ['required', 'integer', 'exists:users,id'],

            'tipo_imovel' => ['required', 'in:apartamento,casa,terreno,comercial,cobertura'],
            'negociacao' => ['nullable', 'in:venda,locacao,venda_e_locacao'],
            'utilizacao' => ['nullable', 'in:residencial,comercial'],

            'bairro' => ['nullable', 'string'],
            'logradouro' => ['nullable', 'string'],
            'numero' => ['nullable', 'string'],
            'sem_numero' => ['nullable', 'boolean'],
            'cidade' => ['nullable', 'string'],
            'cep' => ['nullable', 'string'],
            'estado' => ['nullable', 'string', 'size:2'],
            'complemento' => ['nullable', 'string'],
            'metragem' => ['nullable', 'numeric'],
            'area_total' => ['nullable', 'numeric'],

            'quartos' => ['nullable', 'integer', 'min:0'],
            'suites' => ['nullable', 'integer', 'min:0'],
            'banheiros' => ['nullable', 'integer', 'min:0'],
            'salas' => ['nullable', 'integer', 'min:0'],
            'vagas' => ['nullable', 'integer', 'min:0'],
            'vagas_cobertura' => ['nullable', 'in:coberta,descoberta,mista'],

            'valor' => ['nullable', 'numeric'],
            'condominio' => ['nullable', 'numeric'],
            'iptu' => ['nullable', 'numeric'],
            'iptu_isento' => ['boolean'],

            'andar' => ['nullable', 'integer'],
            'ano_construcao' => ['nullable', 'integer'],

            'em_condominio' => ['nullable', 'boolean'],
            'reformado' => ['nullable', 'boolean'],
            'estado_conservacao' => ['nullable', 'in:novo,reformado,usado,a_reformar'],
            'mobiliado' => ['nullable', 'boolean'],
            'nome_edificio' => ['nullable', 'string'],

            'data_confirmacao_proprietario' => ['nullable', 'date'],
            'condominio_situacao' => ['nullable', 'in:valor_informado,isento,sob_consulta'],
            'iptu_situacao' => ['nullable', 'in:valor_informado,isento,sob_consulta'],
            'iptu_periodicidade' => ['nullable', 'in:mensal,anual'],
            'outros_encargos' => ['nullable', 'string'],
            'disponibilidade_visita' => ['nullable', 'string'],
            'previsao_entrega' => ['nullable', 'string'],

            'chaves' => ['nullable', 'string'],

            'diferenciais' => ['nullable', 'array'],
            'diferenciais.*' => ['in:armario_embutido,cozinha_mobiliada,portaria,lavabo,churrasqueira,garagem,quintal,dependencia_empregados,servicos,cozinha_americana,piscina'],

            'titulo_site' => ['nullable', 'string'],
            'descricao_gerada' => ['nullable', 'string'],
            'observacoes_corretor' => ['nullable', 'string'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->boolean('iptu_isento') && $this->filled('iptu')) {
                $validator->errors()->add(
                    'iptu',
                    'Não é possível informar um valor de IPTU quando o imóvel está marcado como isento.'
                );
            }
        });
    }
}
