<?php

namespace App\Http\Requests\Mission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MissionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'site_id' => ['sometimes', 'required', 'uuid'],
            'mission_code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('survey_missions', 'mission_code')->ignore($this->route('mission'), 'mission_id')],
            'mission_title' => ['sometimes', 'required', 'string', 'max:150'],
            'mission_objective' => ['sometimes', 'required', 'string'],
            'planned_start_at' => ['sometimes', 'nullable', 'date'],
            'planned_end_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:planned_start_at'],
            'coverage_target_hectares' => ['sometimes', 'nullable', 'numeric', 'decimal:0,4', 'between:0,99999999.9999'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $allowed = array_keys($this->rules());
            if (array_intersect($allowed, array_keys($this->all())) === []) {
                $validator->errors()->add('request', 'At least one planning field is required.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['mission_code', 'mission_title', 'mission_objective'] as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }
        if (isset($values['mission_code'])) {
            $values['mission_code'] = Str::upper($values['mission_code']);
        }
        $this->merge($values);
    }
}
