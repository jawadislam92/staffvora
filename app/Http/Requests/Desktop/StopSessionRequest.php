<?php

namespace App\Http\Requests\Desktop;

use App\Services\TrackingSessionService;
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
            // Floor-level guard only. The real control is the server-elapsed
            // clamp applied in SessionController::stop() — this just makes an
            // absurd payload fail fast. See TrackingSessionService::clampTotalSeconds.
            'total_seconds' => ['required', 'integer', 'min:0', 'max:'.TrackingSessionService::MAX_SESSION_SECONDS],
            'activity_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'task_note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
