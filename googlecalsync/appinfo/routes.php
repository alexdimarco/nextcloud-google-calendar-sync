<?php

return [
    'routes' => [
        // Settings page
        ['name' => 'page#index',         'url' => '/',                 'verb' => 'GET'],

        // OAuth flow
        ['name' => 'oauth#start',        'url' => '/oauth/start',      'verb' => 'POST'],
        ['name' => 'oauth#callback',     'url' => '/oauth/callback',   'verb' => 'GET'],
        ['name' => 'oauth#disconnect',   'url' => '/oauth/disconnect', 'verb' => 'POST'],
        ['name' => 'oauth#status',       'url' => '/oauth/status',     'verb' => 'GET'],

        // Calendar mapping management
        ['name' => 'sync#listGoogleCalendars',  'url' => '/calendars/google',       'verb' => 'GET'],
        ['name' => 'sync#listNextcloudCalendars','url' => '/calendars/nextcloud',    'verb' => 'GET'],
        ['name' => 'sync#getMappings',          'url' => '/mappings',               'verb' => 'GET'],
        ['name' => 'sync#saveMapping',          'url' => '/mappings',               'verb' => 'POST'],
        ['name' => 'sync#deleteMapping',        'url' => '/mappings/{id}',          'verb' => 'DELETE'],

        // Manual sync trigger
        ['name' => 'sync#runNow',        'url' => '/sync/run',         'verb' => 'POST'],

        // Settings
        ['name' => 'settings#get',       'url' => '/settings',         'verb' => 'GET'],
        ['name' => 'settings#save',      'url' => '/settings',         'verb' => 'POST'],
    ],
];
