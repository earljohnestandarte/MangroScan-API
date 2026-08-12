<?php

namespace App\Http\Requests\Rbac;

use Illuminate\Foundation\Http\FormRequest;

class UserRoleReplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'role_ids' => ['present', 'array', 'max:20'],
            'role_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
