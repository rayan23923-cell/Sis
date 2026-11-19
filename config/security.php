<?php

return [
    // Roles that must use two-step sign-in. Comma-separated in the environment; empty turns the requirement off.
    'two_factor_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('SIS_TWO_FACTOR_ROLES', 'super_admin,registrar,rules_admin,accountant'))))),

    // Minimum password length for every account.
    'password_min' => (int) env('SIS_PASSWORD_MIN', 10),

    // HSTS is sent only over HTTPS.
    'hsts' => (bool) env('SIS_HSTS', true),
];
