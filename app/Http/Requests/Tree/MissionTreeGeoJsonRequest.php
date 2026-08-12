<?php

namespace App\Http\Requests\Tree;

use Illuminate\Foundation\Http\FormRequest;

class MissionTreeGeoJsonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'species_id' => ['sometimes', 'nullable', 'uuid'],
            'validated_only' => ['sometimes', 'boolean'],
        ];
    }
}
