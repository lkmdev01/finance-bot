<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTransactionFromAIRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // A validação será feita no Job/Service
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'description' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:income,expense'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'date' => ['required', 'date', 'before_or_equal:today'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'O valor da transação é obrigatório.',
            'amount.numeric' => 'O valor deve ser um número válido.',
            'amount.min' => 'O valor mínimo é R$ 0,01.',
            'amount.max' => 'O valor máximo é R$ 999.999,99.',
            'description.required' => 'A descrição é obrigatória.',
            'description.max' => 'A descrição não pode ter mais de 255 caracteres.',
            'type.required' => 'O tipo da transação é obrigatório.',
            'type.in' => 'O tipo deve ser "income" ou "expense".',
            'category_id.exists' => 'A categoria selecionada não existe.',
            'date.required' => 'A data é obrigatória.',
            'date.date' => 'A data deve ser uma data válida.',
            'date.before_or_equal' => 'A data não pode ser futura.',
        ];
    }
}
