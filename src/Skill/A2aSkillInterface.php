<?php

// SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
// SPDX-License-Identifier: MIT

declare(strict_types=1);

namespace Koh\SyliusA2a\Skill;

use Koh\SyliusA2a\JsonRpc\A2aError;
use Koh\SyliusAgentContracts\Context\AgentContext;

/**
 * A skill the A2A endpoint executes — and therefore one the agent card may
 * name. The card lists exactly the services tagged `koh_a2a.skill`, so it
 * cannot advertise a skill that SendMessage would not run.
 *
 * Skills are deterministic and read-only: no model, no writes, no state kept
 * between requests. That is what lets a task be rebuilt from its id.
 */
interface A2aSkillInterface
{
    public function id(): string;

    /**
     * The A2A 1.0 AgentSkill object for the card.
     *
     * @return array{id: string, name: string, description: string, tags: list<string>, examples?: list<string>, inputModes?: list<string>, outputModes?: list<string>}
     */
    public function card(): array;

    /**
     * Validates and normalises what the message asked for. The result goes
     * into the task id, so it must be small and JSON-encodable.
     *
     * @return array<string, scalar>
     *
     * @throws A2aError invalid parameters
     */
    public function arguments(SkillInput $input): array;

    /** @param array<string, mixed> $arguments as arguments() returned them */
    public function run(array $arguments, AgentContext $context, string $localeCode): SkillResult;
}
