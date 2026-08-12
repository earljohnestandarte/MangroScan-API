<?php

namespace App\Http\Requests\Processing;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProcessingJobStoreRequest extends FormRequest
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
            'flight_session_id' => ['sometimes', 'nullable', 'uuid'],
            'job_type' => ['required', 'string', Rule::in(['detection', 'classification', 'full_pipeline'])],
            'media_ids' => ['required', 'array', 'min:1', 'max:1000'],
            'media_ids.*' => ['required', 'uuid', 'distinct:strict'],
            'parameters' => ['sometimes', 'nullable', 'array', 'max:100'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('job_type'))) {
            $this->merge(['job_type' => Str::lower(trim($this->input('job_type')))]);
        }
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $validator->errors()->has('parameters')
                && strlen(json_encode($this->input('parameters'), JSON_THROW_ON_ERROR)) > 65_536) {
                $validator->errors()->add('parameters', 'The parameters field must not exceed 65536 encoded bytes.');
            }
        }];
    }
}
