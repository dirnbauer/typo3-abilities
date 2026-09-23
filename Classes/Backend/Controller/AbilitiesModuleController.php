<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\Abilities\Catalog\AbilityCatalog;
use Webconsulting\Abilities\Catalog\CatalogEntry;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * Backend module: the abilities registry and the ability catalogue,
 * rendered natively in the TYPO3 backend. No separate login, no API token:
 * the module lists server-side and runs abilities through the same governed
 * executor via backend AJAX routes (see AbilitiesAjaxController).
 *
 * Five tabs: Registry (browse and filter the abilities), Catalogue
 * (everything the installation can do, from every source), Run
 * (schema-driven form), Traces (what ran, from which surface, with which
 * outcome) and Tokens (REST bearer tokens of the acting user).
 */
final readonly class AbilitiesModuleController
{
    private const string LL = 'LLL:EXT:abilities/Resources/Private/Language/locallang_mod.xlf:';

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private PageRenderer $pageRenderer,
        private AbilitiesRegistry $registry,
        private CategoryRegistry $categories,
        private AbilityCatalog $catalog,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->addCssFile('EXT:abilities/Resources/Public/Css/module.css');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/tab.js');
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/abilities/registry.js');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('mlang_tabs_tab'));

        // No breadcrumb: the registry is installation-wide, not bound to a page.
        // v14 adds the reload button on its own; the shortcut is declared, not built.
        $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            'system_abilities',
            $this->translate('mlang_tabs_tab'),
        );

        // The module is an admin-only inspector: it deliberately lists the
        // whole registry regardless of each ability's `expose` surfaces.
        // Execution stays governed — policy, scopes and checkPermission()
        // gate every run.
        $definitions = $this->registry->getDefinitions();
        $catalog = $this->catalog->entries();

        $moduleTemplate->assignMultiple([
            'abilities' => array_values(array_map($this->present(...), $definitions)),
            'total' => count($definitions),
            'categories' => $this->categoriesInUse(),
            'surfaces' => ExecutionContext::PROJECTION_SURFACES,
            'riskTiers' => array_map(static fn(RiskTier $tier): string => $tier->value, RiskTier::cases()),
            'catalog' => array_map(self::presentEntry(...), $catalog),
            'catalogTotal' => count($catalog),
            'catalogSources' => $this->catalog->toArray()['sources'],
            'catalogSurfaces' => self::surfacesOf($catalog),
        ]);

        return $moduleTemplate->renderResponse('AbilitiesModule/Index');
    }

    /**
     * @return list<array{slug: string, label: string}>
     */
    private function categoriesInUse(): array
    {
        $categories = [];
        foreach ($this->registry->getCategoriesInUse() as $slug) {
            $categories[] = [
                'slug' => $slug,
                'label' => $this->categories->has($slug) ? $this->categories->get($slug)->label : $slug,
            ];
        }

        return $categories;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AbilityDefinition $definition): array
    {
        return [
            ...$definition->toArray(),
            'categoryLabel' => $this->categories->has($definition->category)
                ? $this->categories->get($definition->category)->label
                : $definition->category,
        ];
    }

    /**
     * The catalogue entry for Fluid: plain arrays (toArray() emits {} for
     * empty structures, which Fluid cannot iterate).
     *
     * @return array<string, mixed>
     */
    private static function presentEntry(CatalogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'title' => $entry->title,
            'description' => $entry->description,
            'source' => $entry->source,
            'surfaces' => $entry->surfaces,
            'flags' => array_keys(array_filter($entry->annotations)),
            'invocations' => $entry->invocations,
            'hasInputSchema' => $entry->inputSchema !== [],
        ];
    }

    /**
     * @param list<CatalogEntry> $entries
     * @return list<string>
     */
    private static function surfacesOf(array $entries): array
    {
        $surfaces = [];
        foreach ($entries as $entry) {
            foreach ($entry->surfaces as $surface) {
                $surfaces[$surface] = true;
            }
        }
        ksort($surfaces);

        return array_keys($surfaces);
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService ? $languageService->sL(self::LL . $key) : $key;
    }
}
