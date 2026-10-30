<?php
// This file is part of Moodle - http://moodle.org/

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname'   => '\core\event\course_module_created',
        'callback'    => '\local_edubot\event_observers::course_module_created',
        'internal'    => false,
    ],
    [
        'eventname'   => '\core\event\course_module_updated',
        'callback'    => '\local_edubot\event_observers::course_module_updated',
        'internal'    => false,
    ],
    [
        'eventname'   => '\core\event\course_module_deleted',
        'callback'    => '\local_edubot\event_observers::course_module_deleted',
        'internal'    => false,
    ],
];
