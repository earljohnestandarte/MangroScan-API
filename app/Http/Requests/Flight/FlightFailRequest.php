<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class FlightFailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['aborted', 'failed'])],
            'reason' => ['required', 'string', 'max:5000'],
            'ended_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        if (is_string($this->input('status'))) {
            $values['status'] = Str::lower(trim($this->input('status')));
        }
        if (is_string($this->input('reason'))) {
            $values['reason'] = trim($this->input('reason'));
        }
        $this->merge($values);
    }
}
