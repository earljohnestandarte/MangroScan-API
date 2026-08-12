<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrganizationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:150'],
            'organization_type' => [
                'required',
                'string',
                Rule::in(['school', 'lgu', 'denr', 'ngo', 'research_group']),
            ],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ([
            'organization_name',
            'organization_type',
            'contact_email',
            'contact_number',
            'address',
        ] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        foreach (['organization_type', 'contact_email'] as $key) {
            if (isset($normalized[$key])) {
                $normalized[$key] = Str::lower($normalized[$key]);
            }
        }

        $this->merge($normalized);
    }
}
