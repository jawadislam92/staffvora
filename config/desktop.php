<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Desktop installer storage path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the directory holding the packaged desktop installers
    | (the .exe and .dmg) plus their electron-updater manifests (latest.yml /
    | latest-mac.yml). On Hostinger this MUST point outside the deployed
    | public_html tree, because git auto-deploy wipes untracked files there.
    | Leave null for local development to use the fallbacks below.
    |
    */

    'installers_path' => env('DESKTOP_INSTALLERS_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Fallback installer directories
    |--------------------------------------------------------------------------
    |
    | Searched, in order, after `installers_path`. These are the local-dev
    | locations: a manually populated storage folder, and the directory the
    | desktop build writes into. Production sets `installers_path` and these
    | simply never match.
    |
    */

    'fallback_paths' => [
        storage_path('app/desktop-installers'),
        base_path('desktop/dist-app'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Minimum agent version
    |--------------------------------------------------------------------------
    |
    | Machine Compliance treats a machine whose agent is older than this as
    | "we cannot see", never as "nothing installed" — an empty inventory from an
    | old agent must not read as a clean PC. The code default is
    | Machine::MIN_AGENT_VERSION, which tracks the packaged desktop build and is
    | pinned by tests/Feature/DesktopReleaseContractTest.
    |
    | This env override exists for one situation: a fleet that has not finished
    | auto-updating after a release, where holding the threshold one version back
    | for a few days is better than reporting every PC as unknown. It must be a
    | plain x.y.z version; anything else is ignored and the code default stands.
    | Remove the override once the fleet has caught up.
    |
    */

    'min_agent_version' => env('DESKTOP_MIN_AGENT_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Installer offload prefix (nginx X-Accel-Redirect)
    |--------------------------------------------------------------------------
    |
    | DesktopDownloadController::sendPayload() has always READ this key. It was
    | never DEFINED here and was wired to no env var, so config('desktop.x_accel_prefix')
    | could only ever be null — and production runs `config:cache`, which freezes
    | that null in place. The headline mitigation in that controller's docblock
    | ("the worker is freed immediately; nginx does the transfer") therefore
    | described a branch that could not execute anywhere but in a test that set
    | the value by hand. A comment claiming a protection that does not exist is
    | worse than no comment: it stops the next person looking.
    |
    | It is now a real key. Set DESKTOP_X_ACCEL_PREFIX to the name of an nginx
    | `internal` location that maps onto the installers directory and PHP returns
    | an empty 200 carrying X-Accel-Redirect, freeing the FPM worker immediately
    | while nginx streams the ~100 MB artifact (Range handling included).
    |
    | Leave it EMPTY unless the nginx side is actually configured: with a prefix
    | set and no matching location, every installer download 404s. The fallback
    | (a BinaryFileResponse from PHP) is correct, just more expensive.
    |
    | The matching nginx server block:
    |
    |     location /protected-installers/ {
    |         internal;
    |         alias /home/USER/desktop-installers/;   # == DESKTOP_INSTALLERS_PATH, trailing slash
    |     }
    |
    | then DESKTOP_X_ACCEL_PREFIX=protected-installers
    |
    | `internal` is load-bearing: without it the directory is publicly listable
    | and every rate limit in the controller is bypassable by requesting the
    | file directly.
    |
    */

    'x_accel_prefix' => env('DESKTOP_X_ACCEL_PREFIX'),

    /*
    |--------------------------------------------------------------------------
    | Installer download rate limits (per client IP, per minute)
    |--------------------------------------------------------------------------
    |
    | Streaming a ~100 MB installer through PHP occupies an FPM worker for the
    | whole transfer, so the public update feed has to be metered. The previous
    | full-file figure of 20 was too tight for the honest case it names: an
    | office behind one NAT'd IP rolling the tracker out to thirty people in a
    | morning presents as thirty downloads from one address and started getting
    | 429s — the tracker failing to install is a worse first day than a slow one.
    |
    | 60 keeps a single address from monopolising the pool while giving a real
    | office headroom, and both numbers are env-tunable so the answer to "our
    | rollout is being throttled" is a config change, not a deploy. Set
    | DESKTOP_X_ACCEL_PREFIX above and the transfers stop costing workers at all,
    | at which point these are only an abuse ceiling.
    |
    */

    'payload_hits_per_minute' => (int) env('DESKTOP_PAYLOAD_HITS_PER_MINUTE', 60),

    'payload_range_hits_per_minute' => (int) env('DESKTOP_PAYLOAD_RANGE_HITS_PER_MINUTE', 240),

];
