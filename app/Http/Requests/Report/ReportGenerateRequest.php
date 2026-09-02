<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportGenerateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(['pdf'])],
            'options' => ['sometimes', 'nullable', 'array:page_size,orientation,include_source_summary'],
            'options.page_size' => ['sometimes', 'string', Rule::in(['a4', 'letter'])],
            'options.orientation' => ['sometimes', 'string', Rule::in(['portrait', 'landscape'])],
            'options.include_source_summary' => ['sometimes', 'boolean'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('options')
                && strlen(json_encode($this->input('options'), JSON_THROW_ON_ERROR)) > 4096) {
                $validator->errors()->add('options', 'The options field must not exceed 4096 encoded bytes.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('format'))) {
            $normalized['format'] = Str::lower(trim($this->input('format')));
        }
        $options = $this->input('options');
        if (is_array($options)) {
            foreach (['page_size', 'orientation'] as $field) {
                if (isset($options[$field]) && is_string($options[$field])) {
                    $options[$field] = Str::lower(trim($options[$field]));
                }
            }
            $normalized['options'] = $options;
        }
        $this->merge($normalized);
    }
}
