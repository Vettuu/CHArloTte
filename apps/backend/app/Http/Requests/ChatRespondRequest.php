<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatRespondRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'min:1', 'max:4000'],
            'tenant' => ['sometimes', 'string', 'max:64'],
            'session_id' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
