<?php

namespace App\Http\Requests\Sensor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SensorDatasetUploadInitiateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['file_name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail): void {
            if (is_string($value) && (str_contains($value, '/') || str_contains($value, '\\') || in_array($value, ['.', '..'], true))) {
                $fail('The '.$attribute.' must be a base file name.');
            }
        }], 'dataset_type' => ['required', Rule::in(['lidar_point_cloud', 'depth_map', 'gps_log', 'imu_log'])], 'file_format' => ['required', 'string', 'max:50'], 'sensor_id' => ['required', 'uuid'], 'file_size_bytes' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) config('mangroscan.media.max_upload_bytes'))], 'spatial_reference' => ['sometimes', 'nullable', 'string', 'max:80'], 'metadata' => ['sometimes', 'nullable', 'array']];
    }

    protected function prepareForValidation(): void
    {
        $values = [];
        foreach (['file_name', 'dataset_type', 'file_format', 'spatial_reference'] as $field) {
            if (is_string($this->input($field))) {
                $values[$field] = trim($this->input($field));
            }
        } if (isset($values['dataset_type'])) {
            $values['dataset_type'] = Str::lower($values['dataset_type']);
        } $this->merge($values);
    }
}
