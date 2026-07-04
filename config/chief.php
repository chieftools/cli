<?php

return [

    'client_id' => 'clichief',

    'scopes' => implode(' ', [
        'openid',
        'profile',
        'email',
        'teams',
        'offline_access',
        'domainchief:tlds:read',
        'domainchief:domains:read',
        'domainchief:contacts:read',
        'domainchief:domains:register',
    ]),

    'required_scopes' => [
        'domain' => [
            'list'         => ['domainchief:domains:read'],
            'availability' => ['domainchief:domains:read:availability'],
            'register'     => [
                'domainchief:tlds:read',
                'domainchief:domains:read:availability',
                'domainchief:contacts:read',
                'domainchief:domains:register',
            ],
        ],
    ],

    'endpoints' => [
        'auth'   => 'https://account.chief.app',
        'openid' => 'https://account.chief.app/.well-known/openid-configuration',
        'domain' => 'https://domain.chief.app',
    ],

];
