<?php

namespace App\Http\Requests\Media;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class MediaUploadCompleteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'parts' => ['sometimes', 'nullable', 'array', 'max:10000'],
            'parts.*.part_number' => ['required_with:parts', 'integer', 'min:1'],
            'parts.*.etag' => ['required_with:parts', 'string', 'max:500'],
            'checksum_sha256' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('checksum_sha256'))) {
            $this->merge(['checksum_sha256' => Str::lower(trim($this->input('checksum_sha256')))]);
        }
    }
}
