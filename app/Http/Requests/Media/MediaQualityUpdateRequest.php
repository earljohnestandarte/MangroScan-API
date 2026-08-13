<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MediaQualityUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'quality_score' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,100'],
            'quality_status' => [
                'required',
                'string',
                Rule::in(['pending', 'acceptable', 'rejected', 'needs_recapture']),
            ],
            'notes' => ['sometimes', 'nullable', 'string'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('quality_status'))) {
            $normalized['quality_status'] = strtolower(trim($this->input('quality_status')));
        }

        if (is_string($this->input('notes'))) {
            $normalized['notes'] = trim($this->input('notes'));
        }

        $this->merge($normalized);
    }
}
