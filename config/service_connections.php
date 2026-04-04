<?php

return [
    // Gates city rules + structured targeting enhancements.
    'advanced_enabled' => (bool) env('SERVICE_CONNECTIONS_ADVANCED_ENABLED', true),

    // Enforce operator rationale for moderation outcomes.
    'require_notes_for_reject' => (bool) env('SERVICE_CONNECTIONS_REQUIRE_NOTES_FOR_REJECT', true),
    'require_notes_for_cancel' => (bool) env('SERVICE_CONNECTIONS_REQUIRE_NOTES_FOR_CANCEL', false),
];
