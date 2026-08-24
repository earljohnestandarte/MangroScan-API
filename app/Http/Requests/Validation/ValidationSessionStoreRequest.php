<?php

namespace App\Http\Requests\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ValidationSessionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mission_id' => ['required', 'uuid'],
            'site_id' => ['required', 'uuid'],
            'plot_id' => ['sometimes', 'nullable', 'uuid'],
            'validated_by' => ['required', 'uuid'],
            'validation_date' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'string', Rule::in(['ground_survey', 'expert_review', 'sample_plot'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['mission_id', 'site_id', 'plot_id', 'validated_by', 'method'] as $key) {
            if (is_string($this->input($key))) {
                $normalized[$key] = Str::lower(trim($this->input($key)));
            }
        }

        if (is_string($this->input('notes'))) {
            $notes = trim($this->input('notes'));
            $normalized['notes'] = $notes === '' ? null : $notes;
        }

        $this->merge($normalized);
    }
}
