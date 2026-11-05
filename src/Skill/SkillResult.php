<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Skill;

/**
 * The outcome of one skill run: a one-sentence status for the task, and the
 * result itself as structured data plus a plain-text rendering of it. A result
 * without data (nothing found) becomes a task without an artifact.
 */
final readonly class SkillResult
{
    /** @param array<string, mixed>|null $data */
    public function __construct(
        public string $summary,
        public string $artifactName,
        public ?array $data = null,
        public string $text = '',
    ) {
    }
}
