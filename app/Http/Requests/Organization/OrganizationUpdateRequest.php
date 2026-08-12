<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class OrganizationUpdateRequest extends FormRequest
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
            'organization_name' => ['sometimes', 'required', 'string', 'max:150'],
            'organization_type' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['school', 'lgu', 'denr', 'ngo', 'research_group']),
            ],
            'contact_email' => ['sometimes', 'nullable', 'email:rfc', 'max:150'],
            'contact_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string'],
            'status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['active', 'inactive']),
            ],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->rules()), array_keys($this->all())) === []) {
                $validator->errors()->add(
                    'request',
                    'At least one organization field is required.',
                );
            }
        }];
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
            'status',
        ] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        foreach (['organization_type', 'contact_email', 'status'] as $key) {
            if (isset($normalized[$key])) {
                $normalized[$key] = Str::lower($normalized[$key]);
            }
        }

        $this->merge($normalized);
    }
}
