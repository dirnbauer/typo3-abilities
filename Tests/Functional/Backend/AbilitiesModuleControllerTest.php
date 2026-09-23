<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Tests\Functional\Backend;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use Webconsulting\Abilities\Backend\Controller\AbilitiesModuleController;

/**
 * The module actually renders: this is the guard against a Fluid template
 * that only breaks when a human opens the module. It asserts the five tab
 * panels, the registry and catalogue rows with their filter data attributes
 * and that every label resolves.
 */
final class AbilitiesModuleControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['webconsulting/typo3-abilities'];

    #[Test]
    public function rendersFiveTabsWithTheRegistryAndTheCatalogue(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $backendUser = $this->setUpBackendUser(1);
        $languageServiceFactory = $this->get(LanguageServiceFactory::class);
        self::assertInstanceOf(LanguageServiceFactory::class, $languageServiceFactory);
        $GLOBALS['LANG'] = $languageServiceFactory->createFromUserPreferences($backendUser);

        $controller = $this->get(AbilitiesModuleController::class);
        self::assertInstanceOf(AbilitiesModuleController::class, $controller);

        $request = new ServerRequest('https://localhost/typo3/module/system/abilities')
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            // packageName is how BackendViewFactory finds this extension's
            // Resources/Private/Templates — the real backend route carries it.
            ->withAttribute('route', new Route('/module/system/abilities', ['packageName' => 'webconsulting/typo3-abilities']))
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([
                'HTTP_HOST' => 'localhost',
                'SCRIPT_NAME' => '/typo3/index.php',
            ]));

        $response = $controller->handleRequest($request);
        self::assertSame(200, $response->getStatusCode());
        $html = (string)$response->getBody();

        foreach (['registry', 'catalog', 'run', 'traces', 'tokens'] as $tab) {
            self::assertStringContainsString('id="abilities-tab-' . $tab . '"', $html, $tab);
            self::assertStringContainsString('data-typo3-tab="#abilities-tab-' . $tab . '"', $html, $tab);
        }

        // Registry rows carry exactly the metadata the client-side filters use.
        self::assertStringContainsString('data-ability="content/delete-page"', $html);
        self::assertStringContainsString('data-risk="high"', $html);
        self::assertStringContainsString('data-category="workspace"', $html);
        self::assertStringContainsString('data-surfaces="mcp cli rest"', $html);
        self::assertStringContainsString('ability_content_search', $html);

        // Catalogue rows: every source the container knows, with invocations.
        self::assertStringContainsString('data-source="cli"', $html);
        self::assertStringContainsString('data-source="rest"', $html);
        self::assertStringContainsString('vendor/bin/typo3 abilities:catalog', $html);
        self::assertStringContainsString('GET /abilities/v1/catalog', $html);
        self::assertStringContainsString('data-filter-rows="#abilities-catalog-table .abilities-row"', $html);

        self::assertStringNotContainsString('LLL:EXT:abilities', $html, 'every label is resolved');
    }
}
