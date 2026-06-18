<?php

namespace App\Http\Requests\Desktop;

use Illuminate\Foundation\Http\FormRequest;

class StartSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'client_uuid' => ['required', 'string', 'max:64'],
            'started_at' => ['required', 'date'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'upwork_profile_id' => ['nullable', 'integer', 'exists:upwork_profiles,id'],
            'work_type' => ['nullable', 'string', 'max:50'],
            'task_note' => ['nullable', 'string', 'max:1000'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:30'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }
}
