<?php

return [
    // Shown in the header and on printed documents.
    'university_name' => env('SIS_UNIVERSITY_NAME', 'الجامعة'),

    // University number: {year}{college code digits}{sequence}. Change only before go-live.
    'student_number' => [
        'sequence_digits' => 5,
    ],

    // Default assessment components for a new course offering (weights out of 100).
    // A round-2 component "X_r2" replaces the round-1 component "X".
    'default_components' => [
        ['code' => 'coursework', 'name_ar' => 'السعي', 'weight' => 40, 'max_mark' => 40, 'exam_round' => 1],
        ['code' => 'final', 'name_ar' => 'الامتحان النهائي', 'weight' => 60, 'max_mark' => 60, 'exam_round' => 1],
        ['code' => 'final_r2', 'name_ar' => 'امتحان الدور الثاني', 'weight' => 60, 'max_mark' => 60, 'exam_round' => 2],
    ],

    'admin' => [
        'email' => env('SIS_ADMIN_EMAIL', 'admin@sis.local'),
        'password' => env('SIS_ADMIN_PASSWORD'),
    ],

    'demo_data' => env('SIS_DEMO_DATA', true),
];
