<?php

namespace App\Http\Requests\Dashboard;

use Illuminate\Foundation\Http\FormRequest;

class SavedViewUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'view_name' => ['sometimes', 'string', 'max:150'],
            'site_id' => ['sometimes', 'nullable', 'uuid'],
            'mission_id' => ['sometimes', 'nullable', 'uuid'],
            'filter_config' => ['sometimes', 'array'],
            'map_config' => ['sometimes', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('view_name'))) {
            $this->merge(['view_name' => trim($this->input('view_name'))]);
        }
    }
}
