<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class SystemSettingUpdateRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'setting_value' => ['required', 'string', 'max:10000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['setting_value', 'description'] as $key) {
            if (is_string($this->input($key))) $values[$key] = trim($this->input($key));
        }
        $this->merge($values);
    }
}
