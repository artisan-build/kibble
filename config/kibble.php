<?php

return [
    'path' => base_path('packages'),
    'organization' => env('GH_ORGANIZATION', 'artisan-build'),
    'template' => 'artisan-build/skeleton',
    'homepage' => 'https://artisan.community',
    'github_token' => env('GH_TOKEN'),
    'github_username' => env('GH_USERNAME', 'edgrosvenor'),
];
