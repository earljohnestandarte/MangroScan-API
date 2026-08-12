<?php

namespace App\Http\Requests\Notification;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class NotificationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'unread_only' => ['sometimes', 'boolean'],
            'type' => ['sometimes', 'nullable', 'string', 'max:80'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:'.max(1, (int) config('mangroscan.limits.pagination_per_page_max')),
            ],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('unread_only'))) {
            $boolean = match (Str::lower(trim($this->input('unread_only')))) {
                'true' => true,
                'false' => false,
                default => $this->input('unread_only'),
            };
            $this->merge(['unread_only' => $boolean]);
        }

        if (is_string($this->input('type'))) {
            $this->merge(['type' => Str::lower(trim($this->input('type')))]);
        }
    }
}
