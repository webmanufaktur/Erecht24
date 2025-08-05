<?php namespace ProcessWire;

/**
 * Module information file for Erecht24
 */

$info = [
    'title' => 'eRecht24 Legal Texts',
    'summary' => 'Integrates with eRecht24 API to synchronize legal texts and create pages automatically',
    'version' => '0.2.0',
    'author' => 'ProcessWire Module',
    'autoload' => true,
    'singular' => true,
    'requires' => 'ProcessWire>=3.0.0',
    'icon' => 'legal',
    'installs' => ['ProcessErecht24']
];