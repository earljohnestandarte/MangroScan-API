<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ExportedFileIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'report_id' => ['sometimes', 'nullable', 'uuid'],
            'mission_id' => ['sometimes', 'nullable', 'uuid'],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(['csv', 'xlsx', 'geojson', 'kml'])],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['report_id', 'mission_id', 'type'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = Str::lower(trim($this->input($key)));
            }
        }
        $this->merge($normalized);
    }
}
