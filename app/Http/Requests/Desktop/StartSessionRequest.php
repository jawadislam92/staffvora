<?php

namespace App\Http\Requests\Desktop;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

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
            'client_id' => ['nullable', 'integer', $this->scopedExists('clients')],
            'upwork_profile_id' => ['nullable', 'integer', $this->scopedExists('upwork_profiles')],
            'work_type' => ['nullable', 'string', 'max:50'],
            'task_note' => ['nullable', 'string', 'max:1000'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:30'],
            'app_version' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * A bare `exists:clients,id` resolves through the raw presence verifier,
     * which never sees the OrganizationScope — so an id belonging to a
     * completely different tenant passes validation, gets persisted onto the
     * session, and is mirrored into work_hours. Scope the existence check to
     * the caller's own organization (the same thing the web path does at
     * WorkHourController::store).
     *
     * Legacy pre-tenancy users (organization_id NULL) are deliberately admitted
     * by OrganizationAccess::denialFor(), so mirror OrganizationScope's own
     * semantics for them — match only rows that are themselves org-less, rather
     * than hard-failing them or letting them reach every tenant's rows.
     */
    private function scopedExists(string $table): Exists
    {
        $orgId = $this->user()?->organization_id;

        return Rule::exists($table, 'id')->where(function ($query) use ($orgId) {
            $orgId === null
                ? $query->whereNull('organization_id')
                : $query->where('organization_id', $orgId);
        });
    }
}
