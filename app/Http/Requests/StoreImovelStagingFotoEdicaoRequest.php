<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreImovelStagingFotoEdicaoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Seleção EXPLÍCITA do corretor — cada item precisa ser exatamente um
     * dos objetos {categoria, descricao} presentes em
     * itens_removiveis_sugeridos da PRÓPRIA foto (checado em
     * withValidator()). Nunca aceita item digitado livremente nem item
     * sugerido para outra foto — não existe campo de texto livre neste
     * formulário.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'itens' => ['required', 'array', 'min:1'],
            'itens.*.categoria' => ['required', 'string'],
            'itens.*.descricao' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @param  Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $foto = $this->route('foto');
            $sugestoes = collect($foto?->itens_removiveis_sugeridos ?? []);

            foreach ($this->input('itens', []) as $indice => $item) {
                if (! is_array($item)) {
                    continue;
                }

                $correspondeASugestao = $sugestoes->contains(
                    fn ($sugestao) => ($sugestao['categoria'] ?? null) === ($item['categoria'] ?? null)
                        && ($sugestao['descricao'] ?? null) === ($item['descricao'] ?? null)
                );

                if (! $correspondeASugestao) {
                    $validator->errors()->add(
                        "itens.{$indice}",
                        'Item não corresponde a uma sugestão válida para esta foto.'
                    );
                }
            }
        });
    }
}
