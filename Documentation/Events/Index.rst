..  include:: /Includes.rst.txt

..  _events:

======
Events
======

Three PSR-14 events are the extension points of the registry. They are the
TYPO3 counterpart of the WordPress `wp_before_execute_ability` /
`wp_after_execute_ability` actions.

..  _events-before:

BeforeAbilityExecutionEvent
===========================

Dispatched before the pipeline starts — before the policy gate. Listeners
may **rewrite the input** or **veto** the run; a vetoed run fails with
`ability_policy_denied` and nothing executes.

..  code-block:: php
    :caption: EXT:my_extension/Classes/EventListener/DraftGuard.php

    <?php

    declare(strict_types=1);

    namespace MyVendor\MyExtension\EventListener;

    use TYPO3\CMS\Core\Attribute\AsEventListener;
    use Webconsulting\Abilities\Event\BeforeAbilityExecutionEvent;

    final class DraftGuard
    {
        #[AsEventListener(identifier: 'my-extension/draft-guard')]
        public function __invoke(BeforeAbilityExecutionEvent $event): void
        {
            if ($event->definition->name !== 'content/create-page-draft') {
                return;
            }

            // Veto: this site does not create pages on the root level.
            if (($event->getInput()['parent'] ?? null) === 0) {
                $event->deny('Pages on the root level are created by hand on this site.');

                return;
            }

            // Rewrite: every agent-created draft is marked as such.
            $input = $event->getInput();
            $input['title'] = '[Draft] ' . ($input['title'] ?? '');
            $event->setInput($input);
        }
    }

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   -   Member
        -   Purpose

    *   -   :php:`$event->definition`
        -   The :php:`AbilityDefinition` about to run.

    *   -   :php:`$event->context`
        -   The :php:`ExecutionContext` — surface, scopes, approval, user.

    *   -   :php:`$event->getInput()` / :php:`setInput()`
        -   The caller's input, before schema defaults are applied.

    *   -   :php:`$event->deny($reason)`
        -   Veto the run; the reason reaches the caller.

..  _events-after:

AfterAbilityExecutionEvent
==========================

Dispatched after **every** attempt — successes, failures and denials.
Governance wants the denials most of all. This is the hook for audit logs,
metering, alerting or eval sets.

..  code-block:: php

    use TYPO3\CMS\Core\Attribute\AsEventListener;
    use Webconsulting\Abilities\Event\AfterAbilityExecutionEvent;

    final class AlertOnDestructiveRuns
    {
        #[AsEventListener(identifier: 'my-extension/alert-destructive')]
        public function __invoke(AfterAbilityExecutionEvent $event): void
        {
            if ($event->definition->destructive && $event->result->ok) {
                $this->notifyTeam(sprintf(
                    '%s ran %s from %s in %.0f ms',
                    (string)$event->context->backendUserUid,
                    $event->definition->name,
                    $event->context->surface,
                    $event->durationMs,
                ));
            }
        }
    }

:php:`$event->input` is the caller's **raw** input, before defaults — the
honest record of what was requested. :php:`$event->result` is the envelope,
:php:`$event->durationMs` the measured duration.

The built-in :php:`Trace\TraceRecorder` is exactly such a listener.

..  _events-modify-definition:

ModifyAbilityDefinitionEvent
============================

Dispatched by the registry for every definition it builds, so an
installation can change governance facts without touching the ability class:

..  code-block:: php

    use TYPO3\CMS\Core\Attribute\AsEventListener;
    use Webconsulting\Abilities\Domain\RiskTier;
    use Webconsulting\Abilities\Event\ModifyAbilityDefinitionEvent;

    final class TightenNewsAbilities
    {
        #[AsEventListener(identifier: 'my-extension/tighten-news')]
        public function __invoke(ModifyAbilityDefinitionEvent $event): void
        {
            if (!str_starts_with($event->getDefinition()->name, 'news/')) {
                return;
            }

            // Not for agents on this installation …
            $event->setExpose(['cli', 'rest']);
            // … and treated as riskier than the shipping default.
            $event->setRiskTier(RiskTier::High);
        }
    }

Available mutators: :php:`setExpose()`, :php:`setRiskTier()`,
:php:`setReadOnly()` and :php:`setDefinition()` for a wholesale replacement
(which may not change the ability's name or class).

..  warning::
    Listeners must not depend on the :php:`AbilitiesRegistry` — it is being
    constructed while this event is dispatched. Inject a service closure if
    you really need it later.

..  _events-deprecated:

Deprecated: AbilityExecutedEvent
================================

..  deprecated:: 1.0.0
    :php:`Event\AbilityExecutedEvent` is deprecated in favour of
    :php:`Event\AfterAbilityExecutionEvent`. It remains a subclass and is
    still dispatched for one release, so listeners registered on either
    class name keep working. It will be removed in 1.1.0 — move listeners
    to :php:`AfterAbilityExecutionEvent`, which needs no other change.
