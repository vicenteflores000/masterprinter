<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePrinterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // API interna
    }

    public function rules(): array
    {
        return [
            'ip' => ['required', 'ip'],
            'community' => ['nullable', 'string'],
            'snmp_version' => ['nullable', 'in:1,2c'],
            'location' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
