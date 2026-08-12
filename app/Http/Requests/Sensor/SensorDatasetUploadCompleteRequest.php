<?php

namespace App\Http\Requests\Sensor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class SensorDatasetUploadCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['checksum_sha256' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/']];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('checksum_sha256'))) {
            $this->merge(['checksum_sha256' => Str::lower(trim($this->input('checksum_sha256')))]);
        }
    }
}
