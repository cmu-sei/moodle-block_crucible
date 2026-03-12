<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\block_crucible\task\sync_keycloak_users',
        'blocking'  => 0,
        'minute'    => '*/30',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => '\block_crucible\task\sync_org_roles',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];
