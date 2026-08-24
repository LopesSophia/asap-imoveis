<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelecionarFotoCapaRequest extends FormRequest
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
            'foto_id' => [
                'required',
                'integer',
                // Pertencimento ao mesmo staging é garantido aqui, não só no
                // controller: o id só é válido se existir DENTRO deste imóvel.
                Rule::exists('imovel_staging_fotos', 'id')
                    ->where('imovel_staging_id', $this->route('imovelStaging')?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto_id.exists' => 'A foto selecionada não pertence a este cadastro.',
        ];
    }
}
