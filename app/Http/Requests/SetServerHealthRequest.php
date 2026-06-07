<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetServerHealthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'server' => ['required', 'string', 'in:server-1,server-2,server-3'],
            'healthy' => ['required', 'boolean'],
        ];
    }
}
