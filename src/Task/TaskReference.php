<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Task;

/**
 * Everything needed to rebuild a task: which skill ran with which arguments,
 * for which channel and locale, when, and in which context.
 */
final readonly class TaskReference
{
    /**
     * @param array<string, mixed> $arguments   the skill's normalised arguments
     * @param list<string>         $outputModes the media types the client accepted
     */
    public function __construct(
        public string $skillId,
        public array $arguments,
        public string $channelCode,
        public string $localeCode,
        public string $contextId,
        public int $createdAt,
        public array $outputModes,
        public string $nonce,
    ) {
    }
}
