<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;

class FlightStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'started_at' => ['required', 'date'],
            'takeoff_location' => ['sometimes', 'nullable', 'array'],
            'takeoff_location.type' => ['required_with:takeoff_location', 'string', 'in:Point'],
            'takeoff_location.coordinates' => ['required_with:takeoff_location', 'array', 'size:2'],
            'takeoff_location.coordinates.0' => ['required_with:takeoff_location', 'numeric', 'between:-180,180'],
            'takeoff_location.coordinates.1' => ['required_with:takeoff_location', 'numeric', 'between:-90,90'],
        ];
    }
}
