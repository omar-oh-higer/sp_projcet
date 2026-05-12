<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TallyDailySalesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sale_date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
