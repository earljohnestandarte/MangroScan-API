<?php

namespace App\Http\Requests\Ai;

use Illuminate\Foundation\Http\FormRequest;

class AiModelVersionDeployRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['release_notes' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('release_notes'))) {
            $this->merge(['release_notes' => trim($this->input('release_notes'))]);
        }
    }
}
