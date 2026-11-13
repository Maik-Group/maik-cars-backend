<?php

namespace App\Http\Requests\API;

use App\Enums\PreferredLanguage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::in([(string) $this->user()?->email]),
            ],
            'preferred_language' => ['sometimes', 'string', Rule::in(PreferredLanguage::values())],
            'discount_margin_percent' => ['sometimes', 'numeric', 'min:-100', 'max:4'],
        ];
    }
}
