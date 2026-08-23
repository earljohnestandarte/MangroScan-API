<?php

namespace App\Http\Requests\Processing;

use Illuminate\Foundation\Http\FormRequest;

class ProcessingJobCancelRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['reason' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $this->merge(['reason' => trim($this->input('reason'))]);
        }
    }
}
