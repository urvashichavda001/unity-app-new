<?php

return [
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH', env('FIREBASE_CREDENTIALS')),
    'credentials' => env('FIREBASE_CREDENTIALS_PATH', env('FIREBASE_CREDENTIALS')),
    'default_android_channel_id' => env('FIREBASE_DEFAULT_ANDROID_CHANNEL_ID', 'default'),
];
