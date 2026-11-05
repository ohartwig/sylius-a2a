<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Skill;

/**
 * What a message asks for, reduced to the two shapes the skills read: the text
 * of its text parts, and the fields of its data parts.
 */
final readonly class SkillInput
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $text = '',
        public array $data = [],
    ) {
    }
}
