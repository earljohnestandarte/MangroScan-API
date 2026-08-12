<?php

namespace App\Http\Requests\Mission;

use Illuminate\Foundation\Http\FormRequest;

class MissionStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'started_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
