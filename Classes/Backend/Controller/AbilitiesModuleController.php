<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * Backend module: the abilities registry, rendered natively in the TYPO3
 * backend. This is the backend projection — no separate login, no API
 * token: the module lists the registry server-side from AbilitiesRegistry
 * and runs abilities through the same governed executor via a backend AJAX
 * route (see AbilitiesAjaxController).
 */
final class AbilitiesModuleController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly UriBuilder $uriBuilder,
        private readonly IconFactory $iconFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly AbilitiesRegistry $registry,
    ) {
    }

    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        $this->pageRenderer->addCssFile('EXT:abilities/Resources/Public/Css/module.css');
        $this->pageRenderer->loadJavaScriptModule('@webconsulting/abilities/registry.js');

        $moduleTemplate = $this->moduleTemplateFactory->create($request);
        $moduleTemplate->setTitle('Abilities');
        $moduleTemplate->getDocHeaderComponent()->setMetaInformation([]);

        $this->registerDocHeaderButtons($moduleTemplate);

        // The backend module is an admin-only inspector: it deliberately lists
        // the whole registry regardless of each ability's `expose` surfaces (an
        // admin managing the site should see every capability). Execution stays
        // governed — admin access, site policy and each ability's
        // checkPermission() still gate every run.
        $definitions = $this->registry->getDefinitions();
        $categories = [];
        foreach ($definitions as $definition) {
            $categories[$definition->category][] = $this->present($definition);
        }
        ksort($categories);

        $moduleTemplate->assignMultiple([
            'categories' => $categories,
            'total' => count($definitions),
            'ajaxUrls' => (string)json_encode([
                'describe' => (string)$this->uriBuilder->buildUriFromRoute('ajax_abilities_describe'),
                'run' => (string)$this->uriBuilder->buildUriFromRoute('ajax_abilities_run'),
            ], JSON_UNESCAPED_SLASHES),
        ]);

        return $moduleTemplate->renderResponse('AbilitiesModule/Index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(AbilityDefinition $definition): array
    {
        return [
            'name' => $definition->name,
            'title' => $definition->title,
            'description' => $definition->description,
            'riskTier' => $definition->riskTier->value,
            'scopes' => $definition->scopes,
            'sideEffects' => $definition->sideEffects,
            'readOnly' => $definition->isReadOnly(),
            'destructive' => $definition->destructive,
            'idempotent' => $definition->idempotent,
            'surfaces' => $definition->expose,
            'mcpToolName' => $definition->mcpToolName(),
        ];
    }

    private function registerDocHeaderButtons(ModuleTemplate $moduleTemplate): void
    {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $reload = $buttonBar->makeLinkButton()
            ->setHref((string)$this->uriBuilder->buildUriFromRoute('system_abilities'))
            ->setTitle('Reload')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-refresh', IconSize::SMALL));
        $buttonBar->addButton($reload, ButtonBar::BUTTON_POSITION_RIGHT);

        $shortcut = $buttonBar->makeShortcutButton()
            ->setRouteIdentifier('system_abilities')
            ->setDisplayName('Abilities');
        $buttonBar->addButton($shortcut, ButtonBar::BUTTON_POSITION_RIGHT);
    }
}
