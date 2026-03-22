<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;
use Claudriel\Eval\Schema\AssertionRegistry;

final class TrajectorySchemaRule implements EvalRule
{
    private const TRAJECTORY_TYPES = ['trajectory', 'multi-turn'];

    private const VALID_OPERATIONS = ['create', 'list', 'update', 'delete'];

    /** @return list<ValidationResult> */
    public function validate(array $data, string $file): array
    {
        $evalType = $data['eval_type'] ?? null;

        if ($evalType === null || ! in_array($evalType, self::TRAJECTORY_TYPES, true)) {
            return [];
        }

        $results = [];

        if (isset($data['max_turns']) && (! is_int($data['max_turns']) || $data['max_turns'] < 1)) {
            $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', 'max_turns must be a positive integer');
        }

        foreach ($data['tests'] ?? [] as $test) {
            $testName = $test['name'] ?? '(unnamed)';

            if (! isset($test['turns']) || ! is_array($test['turns']) || count($test['turns']) === 0) {
                $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Test '$testName' must have a non-empty turns array", $testName);

                continue;
            }

            if (! isset($test['rubric'])) {
                $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Test '$testName' must have a rubric field", $testName);
            }

            $turnCount = count($test['turns']);
            foreach ($test['turns'] as $i => $turn) {
                if (! isset($turn['input'])) {
                    $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Turn #$i in '$testName' must have an input field", $testName);
                }

                if (! isset($turn['operation'])) {
                    $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Turn #$i in '$testName' must have an operation field", $testName);
                } elseif (! in_array($turn['operation'], self::VALID_OPERATIONS, true)) {
                    $results[] = ValidationResult::error($file, 'TrajectorySchemaRule', "Turn #$i in '$testName' has invalid operation '{$turn['operation']}'", $testName);
                }

                if ($i < $turnCount - 1 && ! isset($turn['mock_response'])) {
                    $results[] = ValidationResult::warning($file, 'TrajectorySchemaRule', "Turn #$i in '$testName' has no mock_response (recommended for non-final turns)", $testName);
                }

                if (isset($turn['operation']) && isset($turn['assertions']) && is_array($turn['assertions'])) {
                    foreach ($turn['assertions'] as $assertion) {
                        if (isset($assertion['type']) && ! AssertionRegistry::isValidForOperation($assertion['type'], $turn['operation'])) {
                            $validOps = AssertionRegistry::get($assertion['type'])['operations'] ?? [];
                            $results[] = ValidationResult::error(
                                $file,
                                'TrajectorySchemaRule',
                                "Turn #$i assertion type '{$assertion['type']}' not valid for operation '{$turn['operation']}' (valid: ".implode(', ', $validOps).')',
                                $testName,
                            );
                        }
                    }
                }
            }
        }

        return $results;
    }
}
