<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reference' => ['required', 'string', 'max:4096']];
    }
}
