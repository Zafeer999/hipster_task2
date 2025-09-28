<?php

return [

    'stacking' => [
        // deterministic apply order: any array containing 'percentage' and/or 'fixed' (you can extend)
        'order' => ['percentage', 'fixed'],
        'max_total_percentage' => 50.0,
    ],

    'rounding' => [
        // 'round'|'ceil'|'floor'
        'mode' => 'round',
        'precision' => 2,
    ],

    'defaults' => [
        'max_uses_per_user' => 1,
    ],
];
