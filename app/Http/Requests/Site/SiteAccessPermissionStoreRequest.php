<?php

namespace App\Http\Requests\Site;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SiteAccessPermissionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'permit_title' => ['required', 'string', 'max:150'],
            'issuing_agency' => ['required', 'string', 'max:150'],
            'permit_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'valid_from' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:valid_from'],
            'document_path' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['required', 'string', 'max:30'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['permit_title', 'issuing_agency', 'permit_number', 'document_path', 'status'] as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }
        if (isset($values['status'])) {
            $values['status'] = Str::lower($values['status']);
        }
        $this->merge($values);
    }
}
