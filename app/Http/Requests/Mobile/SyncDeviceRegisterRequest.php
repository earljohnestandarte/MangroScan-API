<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SyncDeviceRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'platform' => ['required', 'string', Rule::in(['android', 'ios', 'web'])],
            'app_version' => ['required', 'string', 'max:50'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('platform'))) {
            $normalized['platform'] = Str::lower(trim($this->input('platform')));
        }

        foreach (['app_version', 'device_name'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        $this->merge($normalized);
    }
}
