<?php

namespace App\Http\Requests\Desktop;

use Illuminate\Foundation\Http\FormRequest;

class UploadScreenshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'tracking_session_id' => ['required', 'integer', 'exists:tracking_sessions,id'],
            'captured_at' => ['required', 'date'],
            'image' => ['required', 'file', 'image', 'mimes:jpeg,jpg,png,webp', 'max:8192'], // 8MB cap
            'activity_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'keyboard_count' => ['nullable', 'integer', 'min:0'],
            'mouse_count' => ['nullable', 'integer', 'min:0'],
            'active_app' => ['nullable', 'string', 'max:255'],
            'active_window_title' => ['nullable', 'string', 'max:255'],
            'url_domain' => ['nullable', 'string', 'max:255'],
        ];
    }
}
