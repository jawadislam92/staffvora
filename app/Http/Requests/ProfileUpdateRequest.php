<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Support\ImageGuard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // reservedEmailRule keeps the sample-data namespace out of a
            // self-service e-mail change. Renaming YOURSELF into it would take
            // your own row off the seat count — a free seat — so User::booted()
            // refuses it outright; without the rule here that refusal surfaces
            // as a 500 instead of a readable field error. Every other
            // customer-supplied address (admin create/edit, onboarding invite)
            // already carries it.
            'email' => ['required', 'string', 'lowercase', 'not_regex:/[\r\n]/', 'email', 'max:255', Rule::unique(User::class)->ignore($this->user()->id), User::reservedEmailRule()],
            // ONE guard for every image upload in the app — see App\Support\ImageGuard.
            // The `dimensions:max_width=8000,max_height=8000` rule that used to
            // sit here passed 8000x4999 (39.99 Mpx, ~274 MB of GD from an
            // 89-byte file) because 8000 is not > 8000. Per-axis bounds cannot
            // express a cost budget; a total-pixel bound can. Same call as the
            // admin user form, the branding logo and the site builder, so the
            // four sibling avatar/logo doors can no longer disagree about what
            // they accept.
            'avatar' => ImageGuard::rules(['jpg', 'png', 'gif'], 2048, ImageGuard::MAX_DISPLAY_PIXELS, required: false),
        ];
    }
}
