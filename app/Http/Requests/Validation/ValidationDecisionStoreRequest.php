<?php

namespace App\Http\Requests\Validation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ValidationDecisionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $paired = fn (): bool => in_array($this->input('decision'), ['matched', 'corrected'], true);
        $falsePositive = fn (): bool => $this->input('decision') === 'false_positive';
        $falseNegative = fn (): bool => $this->input('decision') === 'false_negative';
        $hasTree = fn (): bool => $paired() || $falsePositive();
        $hasTruth = fn (): bool => $paired() || $falseNegative();
        $hasPair = fn (): bool => $paired();

        return [
            'tree_observation_id' => [Rule::requiredIf($hasTree), Rule::prohibitedIf($falseNegative), 'uuid'],
            'ground_truth_id' => [Rule::requiredIf($hasTruth), Rule::prohibitedIf($falsePositive), 'uuid'],
            'decision' => ['required', 'string', Rule::in(['matched', 'corrected', 'false_positive', 'false_negative'])],
            'accepted_species_id' => ['sometimes', 'nullable', Rule::prohibitedIf(fn (): bool => ! $hasPair()), 'uuid'],
            'accepted_height_m' => ['sometimes', 'nullable', Rule::prohibitedIf(fn (): bool => ! $hasPair()), 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'accepted_age_years' => ['sometimes', 'nullable', Rule::prohibitedIf(fn (): bool => ! $hasPair()), 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'corrected_geometry' => ['sometimes', 'nullable', Rule::prohibitedIf(fn (): bool => ! $hasPair()), 'array'],
            'corrected_geometry.type' => ['required_with:corrected_geometry', 'string', Rule::in(['Point'])],
            'corrected_geometry.coordinates' => ['required_with:corrected_geometry', 'array', 'size:2'],
            'corrected_geometry.coordinates.0' => ['required_with:corrected_geometry', 'numeric', 'between:-180,180'],
            'corrected_geometry.coordinates.1' => ['required_with:corrected_geometry', 'numeric', 'between:-90,90'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'validation_evidence' => ['sometimes', 'nullable', 'array', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('decision') === 'corrected'
                && ! collect(['accepted_species_id', 'accepted_height_m', 'accepted_age_years', 'corrected_geometry'])
                    ->contains(fn (string $key): bool => $this->filled($key))) {
                $validator->errors()->add('decision', 'A corrected decision requires at least one accepted or corrected value.');
            }

            $evidence = $this->input('validation_evidence');
            if (is_array($evidence) && array_is_list($evidence) && $evidence !== []) {
                $validator->errors()->add('validation_evidence', 'The validation evidence must be a JSON object.');
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['tree_observation_id', 'ground_truth_id', 'decision', 'accepted_species_id', 'notes'] as $key) {
            if (is_string($this->input($key))) {
                $value = trim($this->input($key));
                $normalized[$key] = $value === '' ? null : ($key === 'notes' ? $value : Str::lower($value));
            }
        }

        if (is_string($this->input('corrected_geometry.type'))) {
            $normalized['corrected_geometry'] = array_replace(
                is_array($this->input('corrected_geometry')) ? $this->input('corrected_geometry') : [],
                ['type' => trim($this->input('corrected_geometry.type'))],
            );
        }

        $this->merge($normalized);
    }
}
