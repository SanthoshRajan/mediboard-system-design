<?php

return [

    'auth' => [

        'fail_response' => [
            'result' => 'fail',
            'error'  => [
                'type'    => 'validation_error',
                'code'    => 'invalid_credentials',
                'details' => 'The credentials entered are invalid.',
            ],
        ],

        // Update this when switching hash driver or cost factor
        'dummy_hash' => 'REDACTED_DUMMY_HASH'

    ],

    'cache' => [

        /**
         * Standard TTL for reference/lookup data that changes rarely.
         * Used by: LookupService, LabService, InventoryService, StaffService,
         *          PatientService, FacilityService.
         * Default: 15 days (60 * 60 * 24 * 15 = 1,296,000 seconds)
         * Override per environment in .env: REDIS_TTL_STANDARD=1296000
         */
        'ttl_standard' => (int) env('REDIS_TTL_STANDARD', 60 * 60 * 24 * 15),

        /**
         * TTL for frequently-updated or session-scoped data.
         * Reserved for future use where a shorter window makes sense.
         * Default: 24 hours
         */
        'ttl_session' => (int) env('REDIS_TTL_SESSION', 60 * 60 * 24),

    ],

];