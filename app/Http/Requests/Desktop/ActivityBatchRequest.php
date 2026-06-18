<?php

namespace App\Http\Requests\Desktop;

use Illuminate\Foundation\Http\FormRequest;

class ActivityBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'tracking_session_id' => ['required', 'integer', 'exists:tracking_sessions,id'],
            'samples' => ['required', 'array', 'min:1', 'max:200'],
            'samples.*.captured_at' => ['required', 'date'],
            'samples.*.keyboard_count' => ['nullable', 'integer', 'min:0'],
            'samples.*.mouse_count' => ['nullable', 'integer', 'min:0'],
            'samples.*.idle_seconds' => ['nullable', 'integer', 'min:0'],
            'samples.*.active_app' => ['nullable', 'string', 'max:255'],
            'samples.*.active_window_title' => ['nullable', 'string', 'max:255'],
            'samples.*.url_domain' => ['nullable', 'string', 'max:255'],
        ];
    }
}
