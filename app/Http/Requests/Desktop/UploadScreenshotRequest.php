<?php

namespace App\Http\Requests\Desktop;

use App\Support\ImageGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UploadScreenshotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Keep the `screenshot_too_large` code on a rejected image.
     *
     * The pixel budget used to be enforced in the controller, which answered
     * with a machine-readable `code` the desktop agent branches on to decide
     * whether to DROP a buffered capture or retry it later. Moving the budget
     * into the shared ImageGuard rule moved the rejection one layer earlier, to
     * the validator — which would have answered with a bare field error and left
     * the agent retrying a capture that can never be accepted, forever.
     *
     * The guard moved; the contract did not.
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('image') && $this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'message' => $validator->errors()->first('image'),
                'code' => 'screenshot_too_large',
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
    }

    public function rules(): array
    {
        return [
            'tracking_session_id' => ['required', 'integer', 'exists:tracking_sessions,id'],
            'captured_at' => ['required', 'date'],
            // ONE guard for every image upload in the app — see App\Support\ImageGuard.
            //
            // What used to be here was `dimensions:max_width=8000,max_height=8000`,
            // and it was wrong in BOTH directions. It let 8000x4999 through
            // (39.99 Mpx, ~274 MB of GD — 8000 is not > 8000), and it REFUSED
            // honest ultrawide captures: three 4K monitors side by side is
            // 11520x2160 = 24.9 Mpx, 62% of the pixel budget, and the desktop
            // agent got a 422 and dropped the screenshot on the floor. For a
            // monitoring product, a workstation that quietly stops producing
            // evidence is a worse outcome than the attack.
            //
            // The replacement budgets on TOTAL pixels — the only shape that
            // expresses "how much will this cost to handle" — and logs and
            // explains every rejection rather than failing mutely. 8 MB cap.
            'image' => ImageGuard::rules(['jpg', 'png', 'webp'], 8192),
            'activity_percent' => ['nullable', 'integer', 'min:0', 'max:100'],
            'keyboard_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'mouse_count' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'active_app' => ['nullable', 'string', 'max:255'],
            'active_window_title' => ['nullable', 'string', 'max:255'],
            'url_domain' => ['nullable', 'string', 'max:255'],
        ];
    }
}
