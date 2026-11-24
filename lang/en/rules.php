<?php

// Rule messages shown to field users and DPCs. Keys match rule codes (docs/05-rules-engine.md).
return [
    'R01' => 'Spending (₹ :spent paise) cannot be more than the amount released (₹ :released paise).',
    'R02' => 'Progress cannot go down. Last approved progress was :previous%.',
    'R03' => 'Some photos were taken more than :radius_m m from the work site.',
    'R04' => 'Please add at least :min photos when progress changes.',
    'R05' => 'Some photos are too old or have a wrong date. Take new photos at the site.',
    'R06' => 'Some photos were already used in an earlier update.',
    'R07' => 'Spending (:financial_pct%) is far ahead of physical progress (:physical_pct%).',
    'R08' => 'The progress % (:pct) does not match the selected stage (:min–:max%).',
    'R09' => 'Spending has gone up without any physical progress for :updates updates.',
    'R10' => 'Photo location is not reliable (GPS accuracy or mock location).',
    'R11' => 'Spending (₹ :spent paise) cannot be more than the sanctioned amount (₹ :sanctioned paise).',
    'R12' => 'Photos were uploaded as files, not taken in the app. The DPC must check them.',
];
