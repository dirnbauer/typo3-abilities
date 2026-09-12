<?php

declare(strict_types=1);

namespace Webconsulting\AbilitiesTestListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use Webconsulting\Abilities\Event\AfterAbilityExecutionEvent;
use Webconsulting\Abilities\Event\BeforeAbilityExecutionEvent;

/**
 * Listens to the execution events of content/create-page-draft exactly like
 * a site package would: the Before listener rewrites the input (every draft
 * title gets a marker prefix), the After listener records the outcome.
 */
final class DraftTitleListener
{
    public const PREFIX = '[Draft] ';

    /** @var list<array{ability: string, ok: bool, surface: string, input: array<string, mixed>}> */
    public static array $seen = [];

    #[AsEventListener(identifier: 'abilities-test-listener/before-create-page-draft')]
    public function prefixTitle(BeforeAbilityExecutionEvent $event): void
    {
        if ($event->definition->name !== 'content/create-page-draft') {
            return;
        }
        $input = $event->getInput();
        $title = is_string($input['title'] ?? null) ? $input['title'] : '';
        if ($title === 'veto') {
            $event->deny('Drafts named "veto" are not allowed on this site.');

            return;
        }
        $input['title'] = self::PREFIX . $title;
        $event->setInput($input);
    }

    #[AsEventListener(identifier: 'abilities-test-listener/after-create-page-draft')]
    public function record(AfterAbilityExecutionEvent $event): void
    {
        self::$seen[] = [
            'ability' => $event->definition->name,
            'ok' => $event->result->ok,
            'surface' => $event->context->surface,
            'input' => $event->input,
        ];
    }
}
