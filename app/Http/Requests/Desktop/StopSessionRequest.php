<?php

namespace App\Http\Requests\Desktop;

use Illuminate\Foundation\Http\FormRequest;

class StopSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'stopped_at' => ['required', 'date'],
            'total_seconds' => ['required', 'integer', 'min:0'],
            'activity_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'task_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
