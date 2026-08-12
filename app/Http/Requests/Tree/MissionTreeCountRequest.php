<?php

namespace App\Http\Requests\Tree;

use Illuminate\Foundation\Http\FormRequest;

class MissionTreeCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['species_id' => ['sometimes', 'nullable', 'uuid']];
    }
}
