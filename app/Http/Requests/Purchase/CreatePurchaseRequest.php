<?php

namespace App\Http\Requests\Purchase;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client.name' => ['required', 'string', 'max:255'],
            'client.email' => ['required', 'email', 'max:255'],
            'payment.card_number' => ['required', 'digits:16'],
            'payment.cvv' => ['required', 'digits:3'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('is_active', true),
            ],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
