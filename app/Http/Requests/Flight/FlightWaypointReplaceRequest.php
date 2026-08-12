<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FlightWaypointReplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'waypoints' => ['present', 'array', 'max:1000'],
            'waypoints.*.sequence_no' => ['required', 'integer', 'min:0'],
            'waypoints.*.location' => ['required', 'array'],
            'waypoints.*.location.type' => ['required', 'string', 'in:Point'],
            'waypoints.*.location.coordinates' => ['required', 'array', 'size:2'],
            'waypoints.*.location.coordinates.0' => ['required', 'numeric', 'between:-180,180'],
            'waypoints.*.location.coordinates.1' => ['required', 'numeric', 'between:-90,90'],
            'waypoints.*.altitude_meters' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'waypoints.*.speed_mps' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'between:0,999999.99'],
            'waypoints.*.action' => ['sometimes', 'nullable', 'string', Rule::in(['capture', 'turn', 'hover', 'return_home'])],
        ];
    }

    /** @return list<callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $sequences = collect($this->input('waypoints', []))->pluck('sequence_no');
            if ($sequences->count() !== $sequences->uniqueStrict()->count()) {
                $validator->errors()->add('waypoints', 'Waypoint sequence numbers must be distinct.');
            }
        }];
    }

    protected function prepareForValidation(): void
    {
        if (! is_array($this->input('waypoints'))) {
            return;
        }
        $waypoints = array_map(static function (mixed $waypoint): mixed {
            if (! is_array($waypoint)) {
                return $waypoint;
            }
            if (is_string($waypoint['action'] ?? null)) {
                $waypoint['action'] = Str::lower(trim($waypoint['action']));
            }
            $coordinates = $waypoint['location']['coordinates'] ?? null;
            if (is_array($coordinates) && count($coordinates) === 2 && is_numeric($coordinates[0]) && is_numeric($coordinates[1])) {
                $waypoint['location']['coordinates'] = [(float) $coordinates[0], (float) $coordinates[1]];
            }

            return $waypoint;
        }, $this->input('waypoints'));
        $this->merge(['waypoints' => $waypoints]);
    }
}
