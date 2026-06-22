<?php

return [
    'enabled' => (bool) env('FRONTEND_MAINTENANCE', false),
    'message' => env('FRONTEND_MAINTENANCE_MESSAGE', 'Aktualizujemy sklep GPSwiss. Wrócimy najszybciej jak to możliwe.'),
];
