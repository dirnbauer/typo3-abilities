<?php

declare(strict_types=1);

namespace Webconsulting\Abilities\Event;

/**
 * @deprecated since 1.0.0, will be removed in 1.1.0 — listen to
 *             AfterAbilityExecutionEvent instead. The executor still dispatches
 *             this subclass for one release so listeners registered on either
 *             class name keep receiving the event.
 */
final readonly class AbilityExecutedEvent extends AfterAbilityExecutionEvent
{
}
