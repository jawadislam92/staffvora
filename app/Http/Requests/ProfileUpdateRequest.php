<?php

namespace App\Http\Requests;

use App\Models\User;
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
            // `image` only proves it IS an image — a 2 MB single-colour PNG can
            // still declare 20,000 x 20,000 px, and ImageResizer decodes avatars
            // with GD inside the request that renders the team page (~4
            // bytes/pixel = ~1.6 GB). `dimensions` reads the header via
            // getimagesize and never decodes, so it is safe on hostile input.
            // The TOTAL-pixel budget, which per-axis bounds cannot express
            // (7999x7999 is 64 Mpx), is enforced in ProfileController before the
            // file is stored. Same two-part shape as UploadScreenshotRequest.
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }
}
