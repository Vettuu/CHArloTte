<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChatMessageLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'max:255'],
            'tenant_id' => ['required', 'string', 'max:255'],
            'message_id' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string', 'in:user,assistant'],
            'content' => ['required', 'string'],
            'source' => ['nullable', 'string', 'max:32'],
            'timestamp' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
