<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Abilities Registry',
    'description' => 'One typed, permissioned registry of what the installation can do; MCP tools, CLI commands, REST routes and the backend module become projections of it.',
    'category' => 'services',
    'author' => 'Kurt Dirnbauer',
    'author_email' => 'dirnbauer@webconsulting.at',
    'author_company' => 'webconsulting business services gmbh',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'php' => '8.4.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'mcp_server' => '',
            'skillflow' => '',
            'sg_apicore' => '',
        ],
    ],
];
