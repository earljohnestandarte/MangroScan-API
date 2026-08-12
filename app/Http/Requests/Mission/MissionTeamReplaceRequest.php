<?php

namespace App\Http\Requests\Mission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MissionTeamReplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['members' => ['present', 'array', 'max:50'], 'members.*.user_id' => ['required', 'uuid'], 'members.*.team_role' => ['required', 'string', Rule::in(['pilot', 'observer', 'validator', 'researcher'])]];
    }

    public function after(): array
    {
        return [function (Validator $v): void {
            $pairs = [];
            foreach ($this->input('members', []) as $i => $m) {
                if (! is_array($m)) {
                    continue;
                }$pair = ($m['user_id'] ?? '').'|'.($m['team_role'] ?? '');
                if (isset($pairs[$pair])) {
                    $v->errors()->add("members.$i", 'Duplicate user and team role assignments are not allowed.');
                }$pairs[$pair] = true;
            }
        }];
    }
}
