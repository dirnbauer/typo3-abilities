<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Abilities Registry',
    'description' => 'One typed, permissioned registry of what the installation can do; MCP tools, CLI commands and REST routes become projections of it.',
    'category' => 'services',
    'author' => 'Kurt Dirnbauer',
    'author_email' => 'dirnbauer@webconsulting.at',
    'author_company' => 'webconsulting business services gmbh',
    'state' => 'alpha',
    'version' => '0.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '14.3.0-14.99.99',
            'php' => '8.3.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'mcp_server' => '',
        ],
    ],
];
