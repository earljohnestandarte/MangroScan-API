<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

class UserUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'max:100'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'last_name' => ['sometimes', 'required', 'string', 'max:100'],
            'position_title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'email' => ['sometimes', 'required', 'email:rfc', 'max:150'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->rules()), array_keys($this->all())) === []) {
                $validator->errors()->add('request', 'At least one profile field is required.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['first_name', 'middle_name', 'last_name', 'position_title', 'email'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = trim($this->input($key));
            }
        }

        if (isset($normalized['email'])) {
            $normalized['email'] = Str::lower($normalized['email']);
        }

        $this->merge($normalized);
    }
}
