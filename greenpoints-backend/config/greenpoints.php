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

];
