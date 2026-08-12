<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserStoreRequest extends FormRequest
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
            'organization_id' => ['required', 'uuid'],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'string',
                'email',
                'max:150',
                Rule::unique('users', 'email'),
            ],
            'position_title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'roles' => ['required', 'array', 'min:1', 'max:20'],
            'roles.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['first_name', 'last_name', 'position_title'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        if (is_string($this->input('email'))) {
            $normalized['email'] = Str::lower(trim($this->input('email')));
        }

        $this->merge($normalized);
    }
}
