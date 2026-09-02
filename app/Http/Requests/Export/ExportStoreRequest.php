<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExportStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(['csv', 'xlsx', 'geojson', 'kml'])],
            'filters' => ['sometimes', 'nullable', 'array:species_id,validation_status'],
            'filters.species_id' => ['sometimes', 'nullable', 'uuid'],
            'filters.validation_status' => ['sometimes', 'nullable', 'string', Rule::in(['unvalidated', 'validated', 'corrected', 'rejected'])],
            'options' => ['sometimes', 'nullable', 'array', 'size:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('format'))) {
            $normalized['format'] = Str::lower(trim($this->input('format')));
        }
        $filters = $this->input('filters');
        if (is_array($filters) && is_string($filters['validation_status'] ?? null)) {
            $filters['validation_status'] = Str::lower(trim($filters['validation_status']));
            $normalized['filters'] = $filters;
        }
        $this->merge($normalized);
    }
}
