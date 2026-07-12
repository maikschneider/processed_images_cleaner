<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Processed Images Cleaner',
    'description' => 'Symfony console command to clean up processed images by several conditions',
    'category' => 'be',
    'author' => 'Torben Hansen',
    'author_email' => 'torben@derhansen.com',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.3.99',
        ],
    ],
];
