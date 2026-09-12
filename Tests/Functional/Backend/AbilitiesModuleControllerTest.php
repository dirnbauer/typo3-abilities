<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Backend\Controller\AbilitiesModuleController;

/**
 * The module actually renders: this is the guard against a Fluid template
 * that only breaks when a human opens the module. It asserts the four tab
 * panels, the registry rows with their filter data attributes, and that the
 * AJAX URLs the JavaScript reads are present.
 */
final class AbilitiesModuleControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    #[Test]
    public function rendersFourTabsWithTheRegistry(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $backendUser = $this->setUpBackendUser(1);
        $languageServiceFactory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $languageServiceFactory);
        $GLOBALS['LANG'] = $languageServiceFactory->createFromUserPreferences($backendUser);

        $controller = $this->get(AbilitiesModuleController::class);
        self::assertInstanceOf(AbilitiesModuleController::class, $controller);

        $request = (new ServerRequest('https://localhost/typo3/module/system/abilities'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            // packageName is how BackendViewFactory finds this extension's
            // Resources/Private/Templates — the real backend route carries it.
            ->withAttribute('route', new Route('/module/system/abilities', ['packageName' => 'webconsulting/typo3-abilities']))
            ->withAttribute('normalizedParams', \TYPO3\CMS\Core\Http\NormalizedParams::createFromServerParams([
                'HTTP_HOST' => 'localhost',
                'SCRIPT_NAME' => '/typo3/index.php',
            ]));

        $response = $controller->handleRequest($request);
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();

        foreach (['registry', 'run', 'traces', 'tokens'] as $tab) {
            self::assertStringContainsString('id="abilities-tab-' . $tab . '"', $html, $tab);
            self::assertStringContainsString('data-typo3-tab="#abilities-tab-' . $tab . '"', $html, $tab);
        }

        // Registry rows carry exactly the metadata the client-side filters use.
        self::assertStringContainsString('data-ability="content/delete-page"', $html);
        self::assertStringContainsString('data-risk="high"', $html);
        self::assertStringContainsString('data-category="workspace"', $html);
        self::assertStringContainsString('data-surfaces="mcp cli rest"', $html);
        self::assertStringContainsString('ability_content_search', $html);

        // The JavaScript reads its endpoints from this JSON island.
        self::assertStringContainsString('id="abilities-ajax-urls"', $html);
        foreach (['abilities/run', 'abilities/traces', 'abilities/tokens/create', 'abilities/tokens/revoke'] as $route) {
            self::assertStringContainsString($route, $html, $route);
        }

        self::assertStringNotContainsString('LLL:EXT:abilities', $html, 'every label is resolved');
    }
}
