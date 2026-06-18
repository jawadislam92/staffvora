<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Desktop installer storage path
    |--------------------------------------------------------------------------
    |
    | Absolute path to the directory holding the packaged desktop installers
    | (the .exe and .dmg). On Hostinger this MUST point outside the deployed
    | public_html tree, because git auto-deploy wipes untracked files there.
    | Leave null for local development to use the in-repo / storage fallbacks.
    |
    */

    'installers_path' => env('DESKTOP_INSTALLERS_PATH'),

];
