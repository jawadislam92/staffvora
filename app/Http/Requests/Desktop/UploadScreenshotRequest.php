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
            // `image` only proves it IS an image — a single-colour 8 MB PNG can
            // still decode to 40,000 x 40,000 px (~6.4 GB under GD) and take the
            // FPM worker with it. The `dimensions` rule reads the header via
            // getimagesize and never decodes, so it is safe on hostile input.
            // The total-pixel budget (which per-axis bounds cannot express:
            // 7999x7999 is 64 Mpx) is enforced in ScreenshotController before
            // anything is stored or dispatched.
            'image' => [
                'required', 'file', 'image', 'mimes:jpeg,jpg,png,webp',
                'max:8192', // 8MB cap
                'dimensions:max_width=8000,max_height=8000',
            ],
            'activity_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'keyboard_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'mouse_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'active_app' => ['nullable', 'string', 'max:255'],
            'active_window_title' => ['nullable', 'string', 'max:255'],
            'url_domain' => ['nullable', 'string', 'max:255'],
        ];
    }
}
