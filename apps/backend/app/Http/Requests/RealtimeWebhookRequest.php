<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RealtimeWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event' => ['required', 'string'],
            'session_id' => ['nullable', 'string'],
            'response_id' => ['nullable', 'string'],
            'call_id' => ['nullable', 'string', 'required_with:tool_name'],
            'tool_name' => ['nullable', 'string'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
