<?php

namespace App\Http\Requests\Site;

use App\Rules\ValidPolygonGeoJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SiteBoundaryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'boundary_name' => ['sometimes', 'required', 'string', 'max:150'],
            'boundary_type' => ['sometimes', 'required', 'string', Rule::in(['survey_area', 'no_fly_zone', 'restoration_area'])],
            'boundary_geom' => ['sometimes', 'required', 'array', new ValidPolygonGeoJson],
            'source' => ['sometimes', 'nullable', 'string', Rule::in(['manual', 'drone_map', 'imported_geojson'])],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_intersect(array_keys($this->rules()), array_keys($this->all())) === []) {
                $validator->errors()->add('request', 'At least one boundary field is required.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['boundary_name', 'boundary_type', 'source'] as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }
        foreach (['boundary_type', 'source'] as $key) {
            if (isset($values[$key])) {
                $values[$key] = Str::lower($values[$key]);
            }
        }
        $geometry = $this->input('boundary_geom');
        if (is_array($geometry) && is_array($geometry['coordinates'] ?? null)) {
            $geometry['coordinates'] = array_map(static fn ($ring) => is_array($ring) ? array_map(static fn ($position) => is_array($position) && count($position) === 2 && is_numeric($position[0]) && is_numeric($position[1]) ? [(float) $position[0], (float) $position[1]] : $position, $ring) : $ring, $geometry['coordinates']);
            $values['boundary_geom'] = $geometry;
        }
        $this->merge($values);
    }
}
