<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class AiServiceCredentialRotateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['api_key' => ['required', 'string', 'max:4096']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('api_key'))) {
            $this->merge(['api_key' => trim($this->input('api_key'))]);
        }
    }
}
