<?php

namespace App\Http\Requests\Rbac;

use Illuminate\Foundation\Http\FormRequest;

class RolePermissionReplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'permission_ids' => ['present', 'array', 'max:100'],
            'permission_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
