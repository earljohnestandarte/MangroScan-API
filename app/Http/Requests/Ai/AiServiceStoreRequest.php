<?php

namespace App\Http\Requests\Ai;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class AiServiceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'service_name' => ['required', 'string', 'max:150'],
            'base_url' => [
                'required',
                'string',
                'max:2048',
                'url:http,https',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_string($value)) {
                        return;
                    }

                    $parts = parse_url($value);
                    if (! is_array($parts)
                        || empty($parts['host'])
                        || isset($parts['user'], $parts['pass'])
                        || isset($parts['query'])
                        || isset($parts['fragment'])) {
                        $fail('The '.$attribute.' must be a base HTTP(S) URL without credentials, query, or fragment.');
                    }
                },
            ],
            'api_key' => ['required', 'string', 'max:4096'],
            'environment' => ['required', 'string', 'max:50'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['service_name', 'base_url', 'api_key', 'environment'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        if (isset($normalized['base_url'])) {
            $normalized['base_url'] = rtrim($normalized['base_url'], '/');
        }
        if (isset($normalized['environment'])) {
            $normalized['environment'] = Str::lower($normalized['environment']);
        }

        $this->merge($normalized);
    }
}
