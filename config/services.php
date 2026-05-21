<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'zoho' => [
        'webhook_token' => env('ZOHO_WEBHOOK_TOKEN'),
    ],

    'event_payment_gateway' => env('EVENT_PAYMENT_GATEWAY', 'zoho'),
    'zoho_event_ticket_item_id' => env('ZOHO_EVENT_TICKET_ITEM_ID'),

    'members_with_circles' => [
        // Fixed token for GET /api/v1/members-with-circles and /api/v1/members-with-circles/{identifier}
        'fixed_token' => env('MEMBERS_WITH_CIRCLES_FIXED_TOKEN', env('MEMBERS_LIST_FIXED_TOKEN', '302|cO0VMR2dmr9j8c3JtIU9dfkuZfSfvzaCCF1GVxJAdc6fdd2d')),
    ],

];
