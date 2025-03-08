<?php

return [

    'client_id' => 'clichief',

    'scopes' => 'profile email teams offline_access domainchief',

    'endpoints' => [
        'auth'   => 'https://account.chief.app',
        'openid' => 'https://account.chief.app/.well-known/openid-configuration',
        'domain' => 'https://domain.chief.app',
    ],

];
