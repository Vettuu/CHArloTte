<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RealtimeTokenRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mode' => ['sometimes', 'in:audio,text'],
            'metadata' => ['sometimes', 'array'],
            'session' => ['sometimes', 'array'],
            'session.type' => ['sometimes', 'in:realtime,transcription'],
            'session.model' => ['sometimes', 'string'],
            'session.instructions' => ['sometimes', 'string'],
            'session.output_modalities' => ['sometimes', 'array', 'size:1'],
            'session.output_modalities.*' => ['in:audio,text'],
            'session.audio' => ['sometimes', 'array'],
            'session.audio.output' => ['sometimes', 'array'],
            'session.audio.output.voice' => ['sometimes', 'string'],
            'session.prompt' => ['sometimes', 'array'],
        ];
    }
}
