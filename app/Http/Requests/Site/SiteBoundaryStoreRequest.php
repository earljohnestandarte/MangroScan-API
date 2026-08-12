<?php

namespace App\Http\Requests\Site;

use App\Rules\ValidPolygonGeoJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SiteBoundaryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'boundary_name' => ['required', 'string', 'max:150'],
            'boundary_type' => ['required', 'string', Rule::in(['survey_area', 'no_fly_zone', 'restoration_area'])],
            'boundary_geom' => ['required', 'array', new ValidPolygonGeoJson],
            'source' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(['manual', 'drone_map', 'imported_geojson']),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];

        foreach (['boundary_name', 'boundary_type', 'source'] as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }

        if (isset($values['boundary_type'])) {
            $values['boundary_type'] = Str::lower($values['boundary_type']);
        }

        if (isset($values['source'])) {
            $values['source'] = Str::lower($values['source']);
        }

        $geometry = $this->input('boundary_geom');

        if (is_array($geometry) && is_array($geometry['coordinates'] ?? null)) {
            $geometry['coordinates'] = array_map(
                static fn (mixed $ring): mixed => is_array($ring)
                    ? array_map(
                        static fn (mixed $position): mixed => is_array($position) && count($position) === 2 && is_numeric($position[0]) && is_numeric($position[1])
                            ? [(float) $position[0], (float) $position[1]]
                            : $position,
                        $ring,
                    )
                    : $ring,
                $geometry['coordinates'],
            );
            $values['boundary_geom'] = $geometry;
        }

        $this->merge($values);
    }
}
