<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Everything this extension registers lives in Configuration/ (Services,
// RequestMiddlewares, Backend routes, TCA, JavaScriptModules, Icons); the
// file exists so PHPStan and the extension scanner have a stable entry point.
