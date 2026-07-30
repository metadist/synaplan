<?php

declare(strict_types=1);

namespace App\Service\File\Presentation;

use PhpOffice\PhpPresentation\Slide\Transition;

/**
 * Slide transitions a generated presentation can apply.
 *
 * OOXML separates slide transitions (how one slide replaces the next) from
 * element animations (how a single shape enters a slide). PhpPresentation writes
 * the former and cannot express the latter, so this enum is the whole animation
 * surface a generated deck has — the officemaker prompt says so explicitly to
 * keep the assistant from promising entrance effects it cannot deliver.
 *
 * Transitions are opt-in: without the `{{PPTX:transition=...}}` directive a deck
 * gets {@see self::None} and behaves exactly like before.
 */
enum SlideTransitionKind: string
{
    case None = 'none';
    case Fade = 'fade';
    case Push = 'push';
    case Wipe = 'wipe';
    case Dissolve = 'dissolve';
    case Zoom = 'zoom';

    /**
     * Resolve a transition name from the directive. An unknown or missing name
     * means "no transition" rather than a surprise animation.
     */
    public static function fromName(?string $name): self
    {
        if (null === $name) {
            return self::None;
        }

        return self::tryFrom(strtolower(trim($name))) ?? self::None;
    }

    /**
     * Names offered to the model, for the prompt block.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    /**
     * Build the slide transition, or null when the deck should not animate.
     *
     * The transition advances on click only: a time trigger would auto-play the
     * deck, which nobody asked for.
     */
    public function toTransition(): ?Transition
    {
        $type = match ($this) {
            self::None => null,
            self::Fade => Transition::TRANSITION_FADE,
            self::Push => Transition::TRANSITION_PUSH_LEFT,
            self::Wipe => Transition::TRANSITION_WIPE_RIGHT,
            self::Dissolve => Transition::TRANSITION_DISSOLVE,
            self::Zoom => Transition::TRANSITION_ZOOM_IN,
        };

        if (null === $type) {
            return null;
        }

        return (new Transition())
            ->setTransitionType($type)
            ->setSpeed(Transition::SPEED_MEDIUM)
            ->setManualTrigger(true);
    }
}
