<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Processed Images Cleaner',
    'description' => 'Symfony console command to clean up processed images by several conditions',
    'category' => 'be',
    'author' => 'Torben Hansen',
    'author_email' => 'torben@derhansen.com',
    'state' => 'stable',
    'version' => '0.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.3.99',
        ],
    ],
];
