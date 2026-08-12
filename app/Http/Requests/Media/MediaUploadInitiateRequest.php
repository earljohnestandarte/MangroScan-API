<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MediaUploadInitiateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $imageMimes = ['image/jpeg', 'image/png', 'image/tiff', 'image/webp'];
        $videoMimes = ['video/mp4', 'video/quicktime'];
        $fileType = $this->input('file_type');

        return [
            'file_name' => ['required', 'string', 'max:255', function ($attribute, $value, $fail): void {
                if (is_string($value) && (str_contains($value, '/') || str_contains($value, '\\') || $value === '.' || $value === '..')) {
                    $fail('The '.$attribute.' must be a base file name.');
                }
            }],
            'file_type' => ['required', 'string', Rule::in(['image', 'video'])],
            'mime_type' => [
                'required', 'string', 'max:150',
                Rule::in($fileType === 'video' ? $videoMimes : $imageMimes),
            ],
            'file_size_bytes' => [
                'required', 'integer', 'min:1',
                'max:'.max(1, (int) config('mangroscan.media.max_upload_bytes')),
            ],
            'checksum_sha256' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'capture_location' => ['sometimes', 'nullable', 'array'],
            'capture_location.type' => ['required_with:capture_location', 'string', 'in:Point'],
            'capture_location.coordinates' => ['required_with:capture_location', 'array', 'size:2'],
            'capture_location.coordinates.0' => ['required_with:capture_location', 'numeric', 'between:-180,180'],
            'capture_location.coordinates.1' => ['required_with:capture_location', 'numeric', 'between:-90,90'],
            'captured_at' => ['sometimes', 'nullable', 'date'],
            'metadata' => ['sometimes', 'nullable', 'array'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];
        foreach (['file_name', 'file_type', 'mime_type', 'checksum_sha256'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }
        foreach (['file_type', 'mime_type', 'checksum_sha256'] as $field) {
            if (isset($normalized[$field])) {
                $normalized[$field] = Str::lower($normalized[$field]);
            }
        }
        $this->merge($normalized);
    }
}
