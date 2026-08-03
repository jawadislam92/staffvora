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

];
