<?php

// Alert titles and message texts (docs/08). SMS/WhatsApp wording must match the approved DLT / WhatsApp templates before go-live.
return [
    'A01' => [
        'title' => 'No recent progress update',
        'message' => 'DCP Pragati: No progress update for :code (:name) for :days days. Please update in the app. -T&D Assam',
    ],
    'A02' => [
        'title' => 'Milestone missed',
        'message' => 'DCP Pragati: :code (:name): ":stage" was due on :due. Please update progress or add a remark. -T&D Assam',
    ],
    'A03' => [
        'title' => 'Released funds not spent',
        'message' => 'DCP Pragati: :code (:name): only :utilisation% of :released released has been spent. Please update spending. -T&D Assam',
    ],
    'A04' => [
        'title' => 'Spending ahead of progress',
        'message' => 'DCP Pragati: :code (:name): spending is :financial% of sanction but physical progress is :physical%. Please check. -T&D Assam',
    ],
    'A05' => [
        'title' => 'Approval overdue',
        'message' => 'DCP Pragati: :pending progress update(s) for :code are waiting more than :days days for approval. -T&D Assam',
    ],
];
