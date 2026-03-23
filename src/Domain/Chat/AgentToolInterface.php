<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

/**
 * Interface for agent tools that can be invoked during chat conversations.
 *
 * Each tool provides a definition (name, description, input schema) for the
 * Anthropic API and an execute method that performs the actual work.
 */
interface AgentToolInterface
{
    /**
     * Return the Anthropic tool definition.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function definition(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @param  array<string, mixed>  $args  Tool input arguments from the model
     * @return array<string, mixed> Result data
     */
    public function execute(array $args): array;
}
