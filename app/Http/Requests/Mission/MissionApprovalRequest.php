<?php

namespace App\Http\Requests\Mission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MissionApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['decision' => ['required', 'string', Rule::in(['approved', 'rejected'])], 'notes' => ['sometimes', 'nullable', 'string', 'max:2000']];
    }

    protected function prepareForValidation(): void
    {
        $v = [];
        foreach (['decision', 'notes'] as $k) {
            if (is_string($this->input($k))) {
                $v[$k] = trim($this->input($k));
            }
        }if (isset($v['decision'])) {
            $v['decision'] = Str::lower($v['decision']);
        }$this->merge($v);
    }
}
