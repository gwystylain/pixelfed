<?php

/*
|--------------------------------------------------------------------------
| Immich library (fork feature)
|--------------------------------------------------------------------------
|
| Lets each account connect its own Immich server, pick photos out of it in
| the composer, and have the people Immich recognised in them offered as
| tags. This file is not part of upstream Pixelfed — see docs/fork/IMMICH.md.
|
*/

return [
    /*
    | Master switch. When false, no Immich routes are registered and the
    | composer and settings show nothing.
    */
    'enabled' => env('IMMICH_ENABLED', true),

    /*
    | Pre-filled in the connection form. Most installs run Immich next to
    | Pixelfed, so this is usually a LAN address such as
    | http://192.168.1.10:2283 — it is fetched by the Pixelfed server, never
    | by the browser, so it does not have to be reachable from outside.
    */
    'default_url' => env('IMMICH_DEFAULT_URL', ''),

    /*
    | Hosts an account may connect to, comma separated. Empty allows any.
    |
    | The server makes requests to whatever URL an account saves. On an
    | instance with untrusted users, set this: otherwise the connection form
    | lets anyone have Pixelfed make requests on their behalf to hosts on
    | its own network.
    */
    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('IMMICH_ALLOWED_HOSTS', ''))
    ))),

    /*
    | Seconds to wait on Immich for API calls, and for file downloads.
    */
    'timeout' => (int) env('IMMICH_TIMEOUT', 10),
    'download_timeout' => (int) env('IMMICH_DOWNLOAD_TIMEOUT', 120),

    /*
    | Assets per page in the composer's picker.
    */
    'page_size' => (int) env('IMMICH_PAGE_SIZE', 60),

    /*
    | Seconds a picker thumbnail may be cached by the browser. Thumbnails are
    | served through Pixelfed so the API key never reaches the browser, which
    | makes every scroll a round trip through this server; caching makes the
    | second look free.
    */
    'thumbnail_cache_seconds' => (int) env('IMMICH_THUMBNAIL_CACHE', 86400),
];
