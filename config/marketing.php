<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Photography
    |--------------------------------------------------------------------------
    |
    | Stock photographs from Pexels, free for commercial use under the Pexels
    | licence. They are placeholders until bespoke artwork exists: swap an
    | entry's id for another Pexels photo, or give it a 'src' pointing at your
    | own image under public/.
    |
    | Run `php artisan marketing:fetch-photos` once on each server. It copies
    | every photo into public/images/marketing so pages are served from our own
    | domain and no visitor's IP address is sent to a third party. Until it has
    | run, images load from the Pexels CDN instead.
    |
    */

    'photos' => [
        'hero' => [
            'id' => 7688336,
            'credit' => 'Kindel Media',
            'alt' => 'A small marketing team working through charts and laptops around an office desk',
        ],
        'webinars' => [
            'id' => 29284294,
            'credit' => null,
            'alt' => 'A speaker presenting to an audience at a conference',
        ],
        'agencies' => [
            'id' => 8145342,
            'credit' => 'olia danilevich',
            'alt' => 'Two colleagues comparing figures on their laptops at a shared table',
        ],
        'owners' => [
            'id' => 7772022,
            'credit' => 'Anna Tarazevich',
            'alt' => 'A shop owner catching up on her laptop between customers',
        ],
        'team' => [
            'id' => 7693698,
            'credit' => 'Yan Krukau',
            'alt' => 'A team gathered around one laptop, planning together',
        ],
        'founder' => [
            'id' => 3874627,
            'credit' => 'Gustavo Fring',
            'alt' => 'A businesswoman working on her laptop and phone in a café',
        ],
    ],

    // Widths generated for each photo's srcset, local or remote.
    'photo_widths' => [640, 1024, 1600],

];
