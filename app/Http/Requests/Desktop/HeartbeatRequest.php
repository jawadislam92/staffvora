<?php

namespace App\Http\Requests\Desktop;

use Illuminate\Foundation\Http\FormRequest;

class HeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'total_seconds' => ['required', 'integer', 'min:0'],
            'activity_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'heartbeat_at' => ['nullable', 'date'],
        ];
    }
}
