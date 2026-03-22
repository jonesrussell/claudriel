<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\TrajectorySchemaRule;
use PHPUnit\Framework\TestCase;

final class TrajectorySchemaRuleTest extends TestCase
{
    public function test_valid_trajectory_produces_no_errors(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'max_turns' => 10,
            'tests' => [[
                'name' => 'lifecycle',
                'turns' => [
                    ['input' => 'create a thing', 'operation' => 'create', 'assertions' => [['type' => 'confirmation_shown']], 'mock_response' => ['createThing' => ['uuid' => '1']]],
                    ['input' => 'show things', 'operation' => 'list', 'assertions' => [['type' => 'graphql_operation', 'operation' => 'thingList', 'mutation' => false]]],
                ],
                'rubric' => 'test-skill',
            ]],
        ];

        $results = (new TrajectorySchemaRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_missing_turns_produces_error(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'tests' => [[
                'name' => 'bad-test',
                'rubric' => 'x',
            ]],
        ];

        $results = (new TrajectorySchemaRule)->validate($data, 'f.yaml');

        self::assertNotEmpty($results);
        self::assertStringContainsString('turns', $results[0]->message);
    }

    public function test_turn_missing_operation_produces_error(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'tests' => [[
                'name' => 'test-1',
                'turns' => [
                    ['input' => 'do something', 'assertions' => [['type' => 'confirmation_shown']]],
                ],
                'rubric' => 'x',
            ]],
        ];

        $results = (new TrajectorySchemaRule)->validate($data, 'f.yaml');

        self::assertNotEmpty($results);
        self::assertStringContainsString('operation', $results[0]->message);
    }

    public function test_missing_rubric_produces_error(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'tests' => [[
                'name' => 'test-1',
                'turns' => [
                    ['input' => 'x', 'operation' => 'create', 'assertions' => [['type' => 'confirmation_shown']]],
                ],
            ]],
        ];

        $results = (new TrajectorySchemaRule)->validate($data, 'f.yaml');

        self::assertNotEmpty($results);
        self::assertStringContainsString('rubric', $results[0]->message);
    }

    public function test_basic_eval_type_skipped(): void
    {
        $data = [
            'eval_type' => 'basic',
            'tests' => [['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]]],
        ];

        $results = (new TrajectorySchemaRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_no_eval_type_skipped(): void
    {
        $data = [
            'tests' => [['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]]],
        ];

        $results = (new TrajectorySchemaRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_invalid_max_turns_produces_error(): void
    {
        $data = [
            'eval_type' => 'trajectory',
            'max_turns' => -1,
            'tests' => [[
                'name' => 'test-1',
                'turns' => [
                    ['input' => 'x', 'operation' => 'create', 'assertions' => [['type' => 'confirmation_shown']]],
                ],
                'rubric' => 'x',
            ]],
        ];

        $results = (new TrajectorySchemaRule)->validate($data, 'f.yaml');

        self::assertNotEmpty($results);
        self::assertStringContainsString('max_turns', $results[0]->message);
    }
}
