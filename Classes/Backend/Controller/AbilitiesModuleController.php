<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\Abilities\Category\CategoryRegistry;
use Webconsulting\Abilities\Domain\AbilityCategory;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * Backend module: the abilities registry, rendered natively in the TYPO3
 * backend. This is the backend projection — no separate login, no API
 * token: the module lists the registry server-side from AbilitiesRegistry
 * and runs abilities through the same governed executor via backend AJAX
 * routes (see AbilitiesAjaxController).
 *
 * Four tabs: Registry (browse and filter), Run (schema-driven form),
 * Traces (what ran, from which surface, with which outcome) and Tokens
 * (REST bearer tokens of the acting user).
 */
final class AbilitiesModuleController
{
    private const LL = 'LLL:EXT:abilities/Resources/Private/Language/locallang_mod.xlf:';

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly PageRenderer $pageRenderer,
        private readonly AbilitiesRegistry $registry,
        private readonly CategoryRegistry $categories,
    ) {}

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->addCssFile('EXT:abilities/Resources/Public/Css/module.css');
        $this->pageRenderer->loadJavaScriptModule('@typo3/backend/tab.js');
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/abilities/registry.js');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle($this->translate('mlang_tabs_tab'));

        // No breadcrumb: the registry is installation-wide, not bound to a page.
        // v14 adds the reload button on its own; the shortcut is declared,
        // not built (ButtonBar::makeShortcutButton() is deprecated).
        $moduleTemplate->getDocHeaderComponent()->setShortcutContext(
            'system_abilities',
            $this->translate('mlang_tabs_tab'),
        );

        // The backend module is an admin-only inspector: it deliberately lists
        // the whole registry regardless of each ability's `expose` surfaces (an
        // admin managing the site should see every capability). Execution stays
        // governed — admin access, site policy and each ability's
        // checkPermission() still gate every run.
        $definitions = $this->registry->getDefinitions();

        $moduleTemplate->assignMultiple([
            'abilities' => array_values(array_map($this->present(...), $definitions)),
            'total' => count($definitions),
            'categories' => $this->categoriesInUse(),
            'surfaces' => [
                ExecutionContext::SURFACE_MCP,
                ExecutionContext::SURFACE_CLI,
                ExecutionContext::SURFACE_REST,
            ],
            'riskTiers' => array_map(static fn(RiskTier $tier): string => $tier->value, RiskTier::cases()),
            'ajaxUrls' => (string)json_encode($this->ajaxUrls(), JSON_UNESCAPED_SLASHES),
        ]);

        return $moduleTemplate->renderResponse('AbilitiesModule/Index');
    }

    /**
     * @return array<string, string>
     */
    private function ajaxUrls(): array
    {
        $routes = [
            'list' => 'ajax_abilities_list',
            'describe' => 'ajax_abilities_describe',
            'run' => 'ajax_abilities_run',
            'categories' => 'ajax_abilities_categories',
            'tokens' => 'ajax_abilities_tokens',
            'tokenCreate' => 'ajax_abilities_token_create',
            'tokenRevoke' => 'ajax_abilities_token_revoke',
            'traces' => 'ajax_abilities_traces',
        ];

        return array_map(
            fn(string $route): string => (string)$this->uriBuilder->buildUriFromRoute($route),
            $routes,
        );
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
        $category = $this->categories->has($definition->category)
            ? $this->categories->get($definition->category)
            : new AbilityCategory($definition->category, $definition->category);

        return [
            'name' => $definition->name,
            'title' => $definition->title,
            'description' => $definition->description,
            'instructions' => $definition->instructions,
            'category' => $definition->category,
            'categoryLabel' => $category->label,
            'riskTier' => $definition->riskTier->value,
            'scopes' => $definition->scopes,
            'sideEffects' => $definition->sideEffects,
            'readOnly' => $definition->isReadOnly(),
            'destructive' => $definition->destructive,
            'idempotent' => $definition->idempotent,
            'surfaces' => $definition->expose,
            'mcpToolName' => $definition->mcpToolName(),
            'restMethod' => $definition->restMethod(),
        ];
    }

    private function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;

        return $languageService instanceof LanguageService ? $languageService->sL(self::LL . $key) : $key;
    }
}
