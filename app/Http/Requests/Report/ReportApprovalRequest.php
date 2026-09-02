<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ReportApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['approved', 'rejected'])],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        if (is_string($this->input('decision'))) {
            $normalized['decision'] = Str::lower(trim($this->input('decision')));
        }
        if (is_string($this->input('notes'))) {
            $notes = trim($this->input('notes'));
            $normalized['notes'] = $notes === '' ? null : $notes;
        }
        $this->merge($normalized);
    }
}
