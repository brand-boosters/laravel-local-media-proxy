<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable the overlay
    |--------------------------------------------------------------------------
    |
    | When enabled, the disks listed in "disks" are re-pointed at the local
    | Local Media Proxy. Reads come from production (through the proxy's
    | read-only credentials); every write, copy and delete stays local.
    |
    */

    'enabled' => (bool) env('LOCAL_MEDIA_PROXY_ENABLED', env('S3_OVERLAY_ENABLED', false)),

    /*
    | The proxy endpoint. Must be a loopback address or a *.test host (Herd);
    | anything else is refused so production can never be targeted by mistake.
    */

    'endpoint' => env('LOCAL_MEDIA_PROXY_ENDPOINT', env('S3_OVERLAY_ENDPOINT', 'http://127.0.0.1:9100')),

    /*
    | The proxy profile's bucket name and LOCAL credentials (shown in the
    | Local Media Proxy app under Proxy > Profiles). These are not AWS credentials.
    */

    'bucket' => env('LOCAL_MEDIA_PROXY_BUCKET', env('S3_OVERLAY_BUCKET')),
    'key' => env('LOCAL_MEDIA_PROXY_KEY', env('S3_OVERLAY_KEY')),
    'secret' => env('LOCAL_MEDIA_PROXY_SECRET', env('S3_OVERLAY_SECRET')),
    'region' => env('LOCAL_MEDIA_PROXY_REGION', env('S3_OVERLAY_REGION', 'us-east-1')),

    /*
    | Public URL base used by Storage::url(). Defaults to "{endpoint}/{bucket}".
    */

    'url' => env('LOCAL_MEDIA_PROXY_URL', env('S3_OVERLAY_URL')),

    /*
    | Existing disks to re-point at the proxy (comma separated in .env).
    | Their other options (root, visibility, throw, ...) are kept.
    */

    'disks' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOCAL_MEDIA_PROXY_DISKS', env('S3_OVERLAY_DISKS', 's3')))))),

    /*
    | Strict mode: while the overlay is enabled, any other disk using the "s3"
    | driver is refused, so nothing in the app can reach real S3 by accident.
    */

    'strict' => (bool) env('LOCAL_MEDIA_PROXY_STRICT', env('S3_OVERLAY_STRICT', true)),

    /*
    | The overlay refuses to run in other environments (fail closed).
    */

    'allowed_environments' => ['local', 'testing', 'development'],

    /*
    | Extra hosts accepted for the endpoint besides loopback and *.test.
    */

    'allowed_hosts' => [],

];
