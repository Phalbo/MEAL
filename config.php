<?php
define('DB_PATH',          __DIR__ . '/data/meal_planner.db');
define('APP_NAME',         'Meal Planner');
define('SESSION_LIFETIME', 60 * 60 * 24 * 30); // 30 giorni

define('INTOLERANCE_PRESETS', [
    'lattosio', 'glutine', 'nichel', 'uova crude', 'peperoni',
    'arachidi', 'frutta secca', 'crostacei', 'soia', 'senape',
]);

define('PORTION_ADULT', 1.0);
define('PORTION_CHILD', 0.6);

define('ZONE_ORDER', [
    'ortofrutta' => 1,
    'pane'       => 2,
    'macelleria' => 3,
    'pesce'      => 4,
    'latticini'  => 5,
    'scaffali'   => 6,
    'bevande'    => 7,
    'surgelati'  => 8,
    'altro'      => 9,
]);
