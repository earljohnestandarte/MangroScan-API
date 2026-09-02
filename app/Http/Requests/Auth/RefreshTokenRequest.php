<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RefreshTokenRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['refresh_token' => ['required', 'string', 'max:255']]; }
}
