<?php namespace ProcessWire;

/**
 * Module information file for ProcessErecht24
 */

$info = [
    'title' => 'eRecht24 Admin',
    'summary' => 'Admin interface for managing eRecht24 legal texts',
    'version' => '0.2.0',
    'author' => 'ProcessWire Module',
    'requires' => 'Erecht24',
    'icon' => 'legal',
    'page' => [
        'name' => 'erecht24',
        'parent' => 'setup',
        'title' => 'eRecht24 Legal Texts'
    ]
];