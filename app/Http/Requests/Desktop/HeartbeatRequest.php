<?php

namespace App\Http\Requests\Desktop;

use App\Services\TrackingSessionService;
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
            // Floor-level guard only; SessionController::heartbeat() clamps the
            // value to server-measured elapsed time before storing it.
            'total_seconds' => ['required', 'integer', 'min:0', 'max:'.TrackingSessionService::MAX_SESSION_SECONDS],
            'activity_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'heartbeat_at' => ['nullable', 'date'],
        ];
    }
}
