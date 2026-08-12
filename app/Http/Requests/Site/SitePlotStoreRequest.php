<?php

namespace App\Http\Requests\Site;

use App\Rules\ValidPolygonGeoJson;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SitePlotStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'plot_code' => ['required', 'string', 'max:50'],
            'plot_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'plot_geom' => ['required', 'array', new ValidPolygonGeoJson],
            'area_square_meters' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['plot_code', 'plot_name', 'description'] as $key) {
            if (is_string($this->input($key))) {
                $values[$key] = trim($this->input($key));
            }
        }
        if (isset($values['plot_code'])) {
            $values['plot_code'] = Str::upper($values['plot_code']);
        }

        $geometry = $this->input('plot_geom');
        if (is_array($geometry) && is_array($geometry['coordinates'] ?? null)) {
            $geometry['coordinates'] = array_map(
                static fn (mixed $ring): mixed => is_array($ring)
                    ? array_map(
                        static fn (mixed $position): mixed => is_array($position) && count($position) === 2 && is_numeric($position[0]) && is_numeric($position[1])
                            ? [(float) $position[0], (float) $position[1]] : $position,
                        $ring,
                    ) : $ring,
                $geometry['coordinates'],
            );
            $values['plot_geom'] = $geometry;
        }

        $this->merge($values);
    }
}
