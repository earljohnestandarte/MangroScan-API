<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ReportUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'report_title' => ['sometimes', 'required', 'string', 'max:200'],
            'report_type' => ['sometimes', 'required', 'string', Rule::in(['monitoring_summary', 'validation_report', 'species_report'])],
            'report_status' => ['sometimes', 'required', 'string', Rule::in(['draft', 'archived'])],
            'audience' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'interpretation' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'limitations' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'recommendations' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'formats' => ['sometimes', 'nullable', 'array', 'min:1', 'max:5'],
            'formats.*' => ['required', 'string', 'distinct:strict', Rule::in(['pdf', 'csv', 'xlsx', 'geojson', 'kml'])],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->rules()), array_keys($this->all())) === []) {
                $validator->errors()->add('request', 'At least one editable report field is required.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['report_type', 'report_status'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = Str::lower(trim($this->input($field)));
            }
        }
        foreach (['report_title', 'audience', 'summary', 'interpretation', 'limitations', 'recommendations'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $normalized[$field] = $value === '' && $field !== 'report_title' ? null : $value;
            }
        }
        if (is_array($this->input('formats'))) {
            $normalized['formats'] = array_map(
                fn (mixed $format): mixed => is_string($format) ? Str::lower(trim($format)) : $format,
                $this->input('formats'),
            );
        }
        $this->merge($normalized);
    }
}
