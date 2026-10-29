<?php

return [

    /*
    | Multiplier applied to points depending on the condition of the waste.
    | PLACEHOLDER VALUES: the group has not finalised the scoring formula yet.
    | points = round(weight_kg * waste_categories.points_per_kg * multiplier)
    */
    'condition_multipliers' => [
        'clean' => 1.0,
        'contaminated' => 0.5,
    ],

    /*
    | Optional anti-gaming cap: max points one user can earn per day.
    | Leave unset in .env to disable. Example: GREENPOINTS_DAILY_POINTS_CAP=500
    */
    'daily_points_cap' => env('GREENPOINTS_DAILY_POINTS_CAP') !== null
        ? (int) env('GREENPOINTS_DAILY_POINTS_CAP')
        : null,

    /*
    | Email verification for self-registered accounts. Until verified, a user can only reach
    | /me, logout and the verification endpoints. The resend cooldown is enforced by the server.
    */
    'email_verification' => [
        'code_ttl_minutes' => (int) env('EMAIL_VERIFICATION_CODE_TTL', 15),
        'resend_cooldown_seconds' => (int) env('EMAIL_VERIFICATION_RESEND_COOLDOWN', 60),
        'max_attempts' => (int) env('EMAIL_VERIFICATION_MAX_ATTEMPTS', 5),
    ],

];
