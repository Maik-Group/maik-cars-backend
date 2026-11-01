<?php

namespace App\Http\Requests\API;

use Illuminate\Foundation\Http\FormRequest;

class ValuationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['currency' => ['sometimes', 'string', 'in:USD,EUR'], 'revalidate_date' => ['sometimes', 'date_format:Y-m-d']];
    }
}
