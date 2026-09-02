<?php

namespace App\Http\Requests\Mobile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MobileSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required', 'uuid'],
            'base_cursor' => ['required', 'string', 'max:10000'],
            'changes' => ['present', 'array', 'max:100'],
            'changes.*.client_id' => ['required', 'string', 'max:150'],
            'changes.*.entity' => [
                'required',
                'string',
                Rule::in(['flight_checklist', 'flight_session', 'media', 'validation_record']),
            ],
            'changes.*.operation' => [
                'required',
                'string',
                Rule::in(['create', 'update', 'upsert']),
            ],
            'changes.*.version' => ['required', 'integer', 'min:1'],
            'changes.*.payload' => ['required', 'array'],
        ];
    }
}
