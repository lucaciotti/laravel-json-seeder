<?php

return [
    /*
      * Directory where to find/store seeds
      */
    'directory' => env('JSON_SEEDS_DIRECTORY', 'database/json'),

    'ignore-tables' => [
        'migrations',
        'failed_jobs',
        'password_resets',
    ],

    /*
     * Json Seeding option
     */
    'json-seed' => [
      'use-upsert' => true,                         // otherwise it uses the "insert method"
      'disable-foreignKey-constraints' => true,     // disable the foreignKeyConstraints before update or insert
      'ignore-empty-values' => true,                // ignore array's empty values
    ],

    /*
     * Do not create a seed when the table is empty
     */
    'ignore-empty-tables' => false,
];
