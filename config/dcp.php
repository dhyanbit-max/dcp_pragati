<?php

/** DCP Pragati settings. Thresholds for rules and alerts live in the database, not here. */
return [
    'locales' => ['en', 'as', 'bn'],

    'approval' => [
        // docs/18 Q-2: whether clean (unflagged) updates skip DPC approval.
        'auto_accept_clean' => (bool) env('DCP_AUTO_ACCEPT_CLEAN', false),
    ],

    'otp' => [
        'length' => 6,
        'ttl_minutes' => 5,
        'max_attempts' => 5,
        // 'log' writes the code to the application log (local/dev only). Real gateways added in week 2.
        'driver' => env('DCP_OTP_DRIVER', 'log'),
        // Show the code on the form that asks for it. Only with the 'log' driver, never in production (DevOtp).
        'show_on_screen' => (bool) env('DCP_OTP_SHOW_ON_SCREEN', true),
    ],

    'field_token_days' => 30,

    // Alerts and escalation (docs/08). Trigger thresholds live in alert_policies; these are delivery rules.
    'alerts' => [
        'quiet_start_hour' => 21,      // no SMS / WhatsApp from 21:00 …
        'quiet_end_hour' => 8,         // … until 08:00 IST
        'rate_limit_hours' => 24,      // max one message per person per alert per channel in this window
        'ack_pause_days' => 7,         // acknowledging pauses escalation this long
        'resolve_snooze_days' => 7,    // a manually resolved alert does not reopen for this long
    ],

    'notifications' => [
        // 'log' writes messages to the application log (dev). Real gateways: docs/18 Q-7.
        'sms' => env('DCP_SMS_DRIVER', 'log'),
        'whatsapp' => env('DCP_WHATSAPP_DRIVER', 'log'),
    ],

    // Works map (docs/10). Base maps are data: add, remove or point them at a self-hosted tile cache
    // on the SDC without code changes. Check each provider's terms before production (docs/10 §6):
    // OSM and OpenTopoMap have fair-use policies; Esri basemaps need an ArcGIS licence for production use;
    // Bhuvan (ISRO/NRSC) is the preferred government source for imagery.
    'map' => [
        'default_base' => env('DCP_MAP_DEFAULT_BASE', 'light'),
        'default_base_dark' => 'dark',
        'state_lgd_code' => env('DCP_STATE_LGD_CODE', '18'), // Assam: outline highlighted on the map
        'center' => [92.9, 26.2], // lon, lat: Assam
        'zoom' => 7,
        'base_layers' => array_values(array_filter([
            ['id' => 'light', 'label' => 'Light', 'type' => 'xyz', 'max_zoom' => 16,
                'url' => env('DCP_MAP_LIGHT_TILES', 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Base/MapServer/tile/{z}/{y}/{x}'),
                'labels_url' => env('DCP_MAP_LIGHT_LABELS', 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Light_Gray_Reference/MapServer/tile/{z}/{y}/{x}'),
                'attribution' => 'Basemap © Esri, HERE, Garmin, © OpenStreetMap contributors'],
            ['id' => 'streets', 'label' => 'Streets', 'type' => 'xyz', 'max_zoom' => 19,
                'url' => env('DCP_MAP_TILES', 'https://tile.openstreetmap.org/{z}/{x}/{y}.png'),
                'attribution' => '© OpenStreetMap contributors'],
            ['id' => 'satellite', 'label' => 'Satellite', 'type' => 'xyz', 'max_zoom' => 19,
                'url' => env('DCP_MAP_SATELLITE_TILES', 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
                'attribution' => 'Imagery © Esri, Maxar, Earthstar Geographics'],
            ['id' => 'hybrid', 'label' => 'Satellite + labels', 'type' => 'xyz', 'max_zoom' => 19,
                'url' => env('DCP_MAP_SATELLITE_TILES', 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'),
                'labels_url' => env('DCP_MAP_LABEL_TILES', 'https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}'),
                'attribution' => 'Imagery and labels © Esri, Maxar, Earthstar Geographics'],
            ['id' => 'terrain', 'label' => 'Terrain', 'type' => 'xyz', 'max_zoom' => 17,
                'url' => env('DCP_MAP_TERRAIN_TILES', 'https://{a-c}.tile.opentopomap.org/{z}/{x}/{y}.png'),
                'attribution' => '© OpenStreetMap contributors, SRTM | © OpenTopoMap (CC-BY-SA)'],
            ['id' => 'dark', 'label' => 'Dark', 'type' => 'xyz', 'max_zoom' => 16,
                'url' => env('DCP_MAP_DARK_TILES', 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Base/MapServer/tile/{z}/{y}/{x}'),
                'labels_url' => env('DCP_MAP_DARK_LABELS', 'https://server.arcgisonline.com/ArcGIS/rest/services/Canvas/World_Dark_Gray_Reference/MapServer/tile/{z}/{y}/{x}'),
                'attribution' => 'Basemap © Esri, HERE, Garmin, © OpenStreetMap contributors'],
            env('DCP_MAP_BHUVAN_WMS') ? ['id' => 'bhuvan', 'label' => 'Bhuvan', 'type' => 'wms', 'max_zoom' => 19,
                'url' => env('DCP_MAP_BHUVAN_WMS'), 'layer' => env('DCP_MAP_BHUVAN_LAYER'),
                'attribution' => '© NRSC / ISRO Bhuvan'] : null,
        ])),
    ],

    'pdf' => [
        // Gotenberg (headless Chromium) renders report PDFs; docker compose runs it as the `pdf` service.
        'gotenberg_url' => env('DCP_GOTENBERG_URL', 'http://pdf:3000'),
    ],

    // Dashboard status definitions (docs/07 §2). [Assumption] values; confirm in the week-2 workshop.
    'monitoring' => [
        'stale_days' => 7,
        'stalled_days' => 60,
        'milestone_grace_days' => 7,
        'approval_sla_days' => 3,
    ],

    // Public pages (docs/04 §4, docs/18 Q-4 and Q-13). Off in production until the Department approves;
    // on elsewhere so the demo shows them.
    'public' => [
        // Landing page statewide figures and district shading (approved data, totals only: no list of works).
        'portal' => (bool) env('DCP_PUBLIC_PORTAL', env('APP_ENV', 'production') !== 'production'),
        // Citizen grievances: collects a mobile number (OTP-verified) and an optional name. See docs/13 §3.
        'grievances' => (bool) env('DCP_GRIEVANCES', env('APP_ENV', 'production') !== 'production'),
    ],

    // Grievance handling (docs/20). [Assumption] values; confirm with the Department.
    'grievances' => [
        'acknowledge_days' => 3,   // acknowledge within
        'resolve_days' => 15,      // resolve or reject within (from submission)
        'retention_days' => 1095,  // citizen name and mobile are cleared this long after closing
        'max_photo_kb' => 5120,
        'categories' => ['quality', 'delay', 'not_started', 'incomplete', 'safety', 'wrong_location', 'other'],
    ],
];
