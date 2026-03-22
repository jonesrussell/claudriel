# Deterministic Eval Framework Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a PHP schema validator that enforces a unified YAML eval format across all 6 entity CRUD skills, producing JSON reports for CI gating.

**Architecture:** A standalone CLI tool (`bin/eval-validate`) globs `.claude/skills/*/evals/*.yaml`, validates each file against a typed assertion registry, runs cross-file coverage rules, and outputs a JSON report. No LLM calls. No external dependencies beyond `symfony/yaml` (already available transitively).

**Tech Stack:** PHP 8.4, Symfony YAML, Symfony Console (CommandTester for tests), PHPUnit 10

**Spec:** `docs/superpowers/specs/2026-03-21-deterministic-eval-framework-design.md`

**Issue:** #444

---

## File Map

| Action | Path | Responsibility |
|--------|------|---------------|
| Create | `src/Eval/Schema/AssertionRegistry.php` | Static registry of assertion types, required/optional fields, operation compatibility |
| Create | `src/Eval/Schema/EvalFileSchema.php` | Top-level file structure validation (schema_version, skill, entity_type, tests) |
| Create | `src/Eval/Schema/TestCaseSchema.php` | Per-test field validation (name, operation, input, assertions, context) |
| Create | `src/Eval/Rules/UniqueNameRule.php` | Test names unique within file |
| Create | `src/Eval/Rules/AssertionCompatibilityRule.php` | Assertion types valid for operation |
| Create | `src/Eval/Rules/ResolveFirstRule.php` | update/delete tests should have existing_entities |
| Create | `src/Eval/Rules/CoverageRule.php` | Cross-file: operation, error-handling, and edge-case coverage per skill |
| Create | `src/Eval/Rules/TagConsistencyRule.php` | Tags use known vocabulary |
| Create | `src/Eval/Rules/EvalRule.php` | Interface for per-file validation rules |
| Create | `src/Eval/Rules/CrossFileRule.php` | Interface for cross-file validation rules |
| Create | `src/Eval/Report/ValidationResult.php` | Value object: file, test, severity, rule, message |
| Create | `src/Eval/Report/JsonReporter.php` | Renders results as JSON with summary |
| Create | `src/Eval/EvalSchemaValidator.php` | Orchestrator: glob, parse, validate, collect results |
| Create | `src/Eval/Command/EvalValidateCommand.php` | CLI command `claudriel:eval-validate` |
| Create | `bin/eval-validate` | Shebang entry point for `php bin/eval-validate` |
| Create | `tests/Unit/Eval/Schema/AssertionRegistryTest.php` | Registry lookup and validation tests |
| Create | `tests/Unit/Eval/Schema/EvalFileSchemaTest.php` | File structure validation tests |
| Create | `tests/Unit/Eval/Schema/TestCaseSchemaTest.php` | Test case field validation tests |
| Create | `tests/Unit/Eval/Rules/UniqueNameRuleTest.php` | Duplicate name detection tests |
| Create | `tests/Unit/Eval/Rules/AssertionCompatibilityRuleTest.php` | Operation compatibility tests |
| Create | `tests/Unit/Eval/Rules/ResolveFirstRuleTest.php` | Context requirement tests |
| Create | `tests/Unit/Eval/Rules/CoverageRuleTest.php` | Cross-file coverage tests (operations, error-handling, edge-cases) |
| Create | `tests/Unit/Eval/Rules/TagConsistencyRuleTest.php` | Tag vocabulary tests |
| Create | `tests/Unit/Eval/Report/ValidationResultTest.php` | Value object field and serialization tests |
| Create | `tests/Unit/Eval/Report/JsonReporterTest.php` | JSON output format tests |
| Create | `tests/Unit/Eval/EvalSchemaValidatorTest.php` | End-to-end orchestrator tests |
| Create | `tests/Unit/Eval/Command/EvalValidateCommandTest.php` | CLI integration test |
| Modify | `.claude/skills/judgment-rule/evals/basic.yaml` | Migrate to unified schema |
| Modify | `.claude/skills/commitment/evals/basic.yaml` | Migrate to unified schema |
| Modify | `.claude/skills/new-workspace/evals/basic.yaml` | Migrate to unified schema |
| Modify | `.claude/skills/new-person/evals/basic.yaml` | Migrate to unified schema |
| Modify | `.claude/skills/schedule-entry/evals/basic.yaml` | Migrate to unified schema |
| Modify | `.claude/skills/triage-entry/evals/basic.yaml` | Migrate to unified schema |
| Create | `.github/workflows/eval-validate.yml` | CI workflow for PR gating |

---

## Task 1: ValidationResult value object

**Files:**
- Create: `src/Eval/Report/ValidationResult.php`
- Test: `tests/Unit/Eval/Report/ValidationResultTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Report;

use Claudriel\Eval\Report\ValidationResult;
use PHPUnit\Framework\TestCase;

final class ValidationResultTest extends TestCase
{
    public function test_error_result_exposes_all_fields(): void
    {
        $result = ValidationResult::error(
            file: 'skills/workspace/evals/basic.yaml',
            rule: 'TestCaseSchema',
            message: 'Missing required field: input',
            test: 'create-basic',
        );

        self::assertSame('skills/workspace/evals/basic.yaml', $result->file);
        self::assertSame('create-basic', $result->test);
        self::assertSame('error', $result->severity);
        self::assertSame('TestCaseSchema', $result->rule);
        self::assertSame('Missing required field: input', $result->message);
    }

    public function test_warning_result_with_null_test(): void
    {
        $result = ValidationResult::warning(
            file: 'skills/workspace/evals/basic.yaml',
            rule: 'TagConsistencyRule',
            message: 'Unknown tag: experimental',
        );

        self::assertSame('warning', $result->severity);
        self::assertNull($result->test);
    }

    public function test_is_error_returns_true_for_errors(): void
    {
        $error = ValidationResult::error('f', 'r', 'm');
        $warning = ValidationResult::warning('f', 'r', 'm');

        self::assertTrue($error->isError());
        self::assertFalse($warning->isError());
    }

    public function test_to_array_produces_expected_shape(): void
    {
        $result = ValidationResult::error('f.yaml', 'Rule', 'msg', 'test-1');

        $array = $result->toArray();

        self::assertSame([
            'file' => 'f.yaml',
            'severity' => 'error',
            'rule' => 'Rule',
            'test' => 'test-1',
            'message' => 'msg',
        ], $array);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Eval/Report/ValidationResultTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Report;

final readonly class ValidationResult
{
    private function __construct(
        public string $file,
        public string $severity,
        public string $rule,
        public string $message,
        public ?string $test = null,
    ) {}

    public static function error(string $file, string $rule, string $message, ?string $test = null): self
    {
        return new self($file, 'error', $rule, $message, $test);
    }

    public static function warning(string $file, string $rule, string $message, ?string $test = null): self
    {
        return new self($file, 'warning', $rule, $message, $test);
    }

    public function isError(): bool
    {
        return $this->severity === 'error';
    }

    /** @return array{file: string, severity: string, rule: string, test: ?string, message: string} */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'severity' => $this->severity,
            'rule' => $this->rule,
            'test' => $this->test,
            'message' => $this->message,
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Eval/Report/ValidationResultTest.php`
Expected: 4 tests, 4 assertions, PASS

- [ ] **Step 5: Commit**

```bash
git add src/Eval/Report/ValidationResult.php tests/Unit/Eval/Report/ValidationResultTest.php
git commit -m "feat(eval): add ValidationResult value object (#444)"
```

---

## Task 2: AssertionRegistry

**Files:**
- Create: `src/Eval/Schema/AssertionRegistry.php`
- Test: `tests/Unit/Eval/Schema/AssertionRegistryTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Schema;

use Claudriel\Eval\Schema\AssertionRegistry;
use PHPUnit\Framework\TestCase;

final class AssertionRegistryTest extends TestCase
{
    public function test_known_type_returns_definition(): void
    {
        $def = AssertionRegistry::get('field_extraction');

        self::assertNotNull($def);
        self::assertContains('field', $def['required']);
        self::assertContains('must_not_contain', $def['optional']);
    }

    public function test_unknown_type_returns_null(): void
    {
        self::assertNull(AssertionRegistry::get('nonexistent_type'));
    }

    public function test_is_valid_for_operation_checks_compatibility(): void
    {
        self::assertTrue(AssertionRegistry::isValidForOperation('resolve_first', 'update'));
        self::assertTrue(AssertionRegistry::isValidForOperation('resolve_first', 'delete'));
        self::assertFalse(AssertionRegistry::isValidForOperation('resolve_first', 'create'));
        self::assertFalse(AssertionRegistry::isValidForOperation('resolve_first', 'list'));
    }

    public function test_all_types_returns_complete_list(): void
    {
        $types = AssertionRegistry::allTypes();

        self::assertContains('field_extraction', $types);
        self::assertContains('graphql_operation', $types);
        self::assertContains('confirmation_shown', $types);
        self::assertContains('no_file_operations', $types);
        self::assertContains('resolve_first', $types);
        self::assertContains('error_surfaced', $types);
        self::assertContains('offers_alternative', $types);
        self::assertContains('disambiguation', $types);
        self::assertContains('echo_back_required', $types);
        self::assertContains('secondary_intent_queued', $types);
        self::assertContains('asks_for_field', $types);
        self::assertContains('direction_detected', $types);
        self::assertContains('no_conjunction_split', $types);
        self::assertContains('filter_applied', $types);
        self::assertContains('table_presented', $types);
        self::assertContains('before_after_shown', $types);
        self::assertCount(16, $types);
    }

    public function test_graphql_operation_valid_for_all_operations(): void
    {
        foreach (['create', 'list', 'update', 'delete'] as $op) {
            self::assertTrue(
                AssertionRegistry::isValidForOperation('graphql_operation', $op),
                "graphql_operation should be valid for $op",
            );
        }
    }

    public function test_validate_fields_catches_missing_required(): void
    {
        $errors = AssertionRegistry::validateFields('field_extraction', []);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('field', $errors[0]);
    }

    public function test_validate_fields_passes_with_required_present(): void
    {
        $errors = AssertionRegistry::validateFields('field_extraction', ['field' => 'name']);

        self::assertEmpty($errors);
    }

    public function test_validate_fields_ignores_optional_fields(): void
    {
        $errors = AssertionRegistry::validateFields('field_extraction', [
            'field' => 'name',
            'must_not_equal' => 'full sentence',
            'must_not_contain' => ['filler'],
        ]);

        self::assertEmpty($errors);
    }

    public function test_validate_fields_catches_unknown_fields(): void
    {
        $errors = AssertionRegistry::validateFields('field_extraction', [
            'field' => 'name',
            'bogus_field' => 'value',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('bogus_field', $errors[0]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Eval/Schema/AssertionRegistryTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Schema;

final class AssertionRegistry
{
    /** @var array<string, array{required: list<string>, optional: list<string>, operations: list<string>}> */
    private const TYPES = [
        'field_extraction' => [
            'required' => ['field'],
            'optional' => ['must_not_equal', 'should_match', 'must_not_contain'],
            'operations' => ['create', 'update'],
        ],
        'graphql_operation' => [
            'required' => ['operation'],
            'optional' => ['mutation'],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'confirmation_shown' => [
            'required' => [],
            'optional' => [],
            'operations' => ['create', 'update', 'delete'],
        ],
        'no_file_operations' => [
            'required' => [],
            'optional' => [],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'resolve_first' => [
            'required' => [],
            'optional' => [],
            'operations' => ['update', 'delete'],
        ],
        'error_surfaced' => [
            'required' => [],
            'optional' => ['contains'],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'offers_alternative' => [
            'required' => ['alternative'],
            'optional' => [],
            'operations' => ['update', 'delete'],
        ],
        'disambiguation' => [
            'required' => [],
            'optional' => [],
            'operations' => ['update', 'delete'],
        ],
        'echo_back_required' => [
            'required' => ['field'],
            'optional' => [],
            'operations' => ['delete'],
        ],
        'secondary_intent_queued' => [
            'required' => [],
            'optional' => ['intent'],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'asks_for_field' => [
            'required' => ['field'],
            'optional' => [],
            'operations' => ['create', 'update'],
        ],
        'direction_detected' => [
            'required' => ['direction'],
            'optional' => [],
            'operations' => ['create'],
        ],
        'no_conjunction_split' => [
            'required' => [],
            'optional' => [],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'filter_applied' => [
            'required' => ['field', 'value'],
            'optional' => [],
            'operations' => ['list'],
        ],
        'table_presented' => [
            'required' => ['columns'],
            'optional' => [],
            'operations' => ['list'],
        ],
        'before_after_shown' => [
            'required' => [],
            'optional' => [],
            'operations' => ['update'],
        ],
    ];

    /** @return array{required: list<string>, optional: list<string>, operations: list<string>}|null */
    public static function get(string $type): ?array
    {
        return self::TYPES[$type] ?? null;
    }

    public static function isValidForOperation(string $type, string $operation): bool
    {
        $def = self::TYPES[$type] ?? null;

        return $def !== null && in_array($operation, $def['operations'], true);
    }

    /** @return list<string> */
    public static function allTypes(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Validate that an assertion's fields match the registry definition.
     *
     * @param array<string, mixed> $fields The assertion fields (excluding 'type')
     * @return list<string> Error messages (empty = valid)
     */
    public static function validateFields(string $type, array $fields): array
    {
        $def = self::TYPES[$type] ?? null;
        if ($def === null) {
            return ["Unknown assertion type: $type"];
        }

        $errors = [];
        foreach ($def['required'] as $req) {
            if (!array_key_exists($req, $fields)) {
                $errors[] = "Missing required field '$req' for assertion type '$type'";
            }
        }

        $allowed = array_merge($def['required'], $def['optional']);
        foreach (array_keys($fields) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = "Unknown field '$key' for assertion type '$type'";
            }
        }

        return $errors;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Eval/Schema/AssertionRegistryTest.php`
Expected: 8 tests, PASS

- [ ] **Step 5: Commit**

```bash
git add src/Eval/Schema/AssertionRegistry.php tests/Unit/Eval/Schema/AssertionRegistryTest.php
git commit -m "feat(eval): add AssertionRegistry with 16 typed assertions (#444)"
```

---

## Task 3: EvalFileSchema validator

**Files:**
- Create: `src/Eval/Schema/EvalFileSchema.php`
- Test: `tests/Unit/Eval/Schema/EvalFileSchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Schema;

use Claudriel\Eval\Report\ValidationResult;
use Claudriel\Eval\Schema\EvalFileSchema;
use PHPUnit\Framework\TestCase;

final class EvalFileSchemaTest extends TestCase
{
    private EvalFileSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new EvalFileSchema();
    }

    public function test_valid_file_produces_no_errors(): void
    {
        $data = [
            'schema_version' => '1.0',
            'skill' => 'commitment',
            'entity_type' => 'commitment',
            'tests' => [
                ['name' => 'test-1', 'operation' => 'create', 'input' => 'test', 'assertions' => [['type' => 'confirmation_shown']]],
            ],
        ];

        $results = $this->schema->validate($data, 'commitment/evals/basic.yaml', 'commitment');

        self::assertEmpty($results);
    }

    public function test_missing_schema_version(): void
    {
        $data = ['skill' => 'x', 'entity_type' => 'x', 'tests' => []];

        $results = $this->schema->validate($data, 'f.yaml', 'x');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'schema_version')));
    }

    public function test_wrong_schema_version(): void
    {
        $data = ['schema_version' => '2.0', 'skill' => 'x', 'entity_type' => 'x', 'tests' => [['name' => 'a', 'operation' => 'create', 'input' => 'b', 'assertions' => [['type' => 'confirmation_shown']]]]];

        $results = $this->schema->validate($data, 'f.yaml', 'x');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, '1.0')));
    }

    public function test_skill_directory_mismatch(): void
    {
        $data = ['schema_version' => '1.0', 'skill' => 'workspace', 'entity_type' => 'workspace', 'tests' => [['name' => 'a', 'operation' => 'create', 'input' => 'b', 'assertions' => [['type' => 'confirmation_shown']]]]];

        $results = $this->schema->validate($data, 'f.yaml', 'new-workspace');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'directory')));
    }

    public function test_empty_tests_array(): void
    {
        $data = ['schema_version' => '1.0', 'skill' => 'x', 'entity_type' => 'x', 'tests' => []];

        $results = $this->schema->validate($data, 'f.yaml', 'x');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'at least one test')));
    }

    public function test_missing_entity_type(): void
    {
        $data = ['schema_version' => '1.0', 'skill' => 'x', 'tests' => [['name' => 'a', 'operation' => 'create', 'input' => 'b', 'assertions' => [['type' => 'confirmation_shown']]]]];

        $results = $this->schema->validate($data, 'f.yaml', 'x');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'entity_type')));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Eval/Schema/EvalFileSchemaTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Schema;

use Claudriel\Eval\Report\ValidationResult;

final class EvalFileSchema
{
    private const REQUIRED_FIELDS = ['schema_version', 'skill', 'entity_type', 'tests'];

    /**
     * Validate top-level file structure.
     *
     * @param array<string, mixed> $data Parsed YAML data
     * @param string $file File path (for error reporting)
     * @param string $skillDir The parent directory name of the eval file
     * @return list<ValidationResult>
     */
    public function validate(array $data, string $file, string $skillDir): array
    {
        $results = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                $results[] = ValidationResult::error($file, 'EvalFileSchema', "Missing required top-level field: $field");
            }
        }

        if (isset($data['schema_version']) && $data['schema_version'] !== '1.0') {
            $results[] = ValidationResult::error($file, 'EvalFileSchema', "schema_version must be '1.0', got '{$data['schema_version']}'");
        }

        if (isset($data['skill']) && $data['skill'] !== $skillDir) {
            $results[] = ValidationResult::error($file, 'EvalFileSchema', "skill field '{$data['skill']}' does not match directory '$skillDir'");
        }

        if (isset($data['tests']) && is_array($data['tests']) && count($data['tests']) === 0) {
            $results[] = ValidationResult::error($file, 'EvalFileSchema', 'tests array must contain at least one test');
        }

        return $results;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Eval/Schema/EvalFileSchemaTest.php`
Expected: 6 tests, PASS

- [ ] **Step 5: Commit**

```bash
git add src/Eval/Schema/EvalFileSchema.php tests/Unit/Eval/Schema/EvalFileSchemaTest.php
git commit -m "feat(eval): add EvalFileSchema top-level validator (#444)"
```

---

## Task 4: TestCaseSchema validator

**Files:**
- Create: `src/Eval/Schema/TestCaseSchema.php`
- Test: `tests/Unit/Eval/Schema/TestCaseSchemaTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Schema;

use Claudriel\Eval\Report\ValidationResult;
use Claudriel\Eval\Schema\TestCaseSchema;
use PHPUnit\Framework\TestCase;

final class TestCaseSchemaTest extends TestCase
{
    private TestCaseSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new TestCaseSchema();
    }

    public function test_valid_test_case_produces_no_errors(): void
    {
        $test = [
            'name' => 'create-basic',
            'operation' => 'create',
            'input' => 'create a workspace for Acme',
            'assertions' => [['type' => 'confirmation_shown']],
        ];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_missing_name(): void
    {
        $test = ['operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'name')));
    }

    public function test_invalid_name_format(): void
    {
        $test = ['name' => 'Create_Basic', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'kebab-case')));
    }

    public function test_invalid_operation(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'upsert', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'operation')));
    }

    public function test_empty_assertions(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => []];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'assertion')));
    }

    public function test_assertion_missing_type(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['field' => 'name']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'type')));
    }

    public function test_unknown_assertion_type(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'bogus']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'bogus')));
    }

    public function test_valid_tags(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']], 'tags' => ['happy-path', 'regression']];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_valid_context_with_existing_entities(): void
    {
        $test = [
            'name' => 'update-basic',
            'operation' => 'update',
            'input' => 'rename it',
            'context' => [
                'existing_entities' => [
                    ['uuid' => 'abc-123', 'fields' => ['name' => 'Old Name']],
                ],
            ],
            'assertions' => [['type' => 'resolve_first']],
        ];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertEmpty($results);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Eval/Schema/TestCaseSchemaTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Schema;

use Claudriel\Eval\Report\ValidationResult;

final class TestCaseSchema
{
    private const VALID_OPERATIONS = ['create', 'list', 'update', 'delete'];
    private const REQUIRED_FIELDS = ['name', 'operation', 'input', 'assertions'];
    private const KEBAB_CASE_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @param array<string, mixed> $test
     * @return list<ValidationResult>
     */
    public function validate(array $test, string $file): array
    {
        $results = [];
        $testName = $test['name'] ?? '(unnamed)';

        foreach (self::REQUIRED_FIELDS as $field) {
            if (!array_key_exists($field, $test)) {
                $results[] = ValidationResult::error($file, 'TestCaseSchema', "Missing required field: $field", $testName);
            }
        }

        if (isset($test['name']) && is_string($test['name']) && !preg_match(self::KEBAB_CASE_PATTERN, $test['name'])) {
            $results[] = ValidationResult::error($file, 'TestCaseSchema', "Test name '{$test['name']}' must be kebab-case", $testName);
        }

        if (isset($test['operation']) && !in_array($test['operation'], self::VALID_OPERATIONS, true)) {
            $results[] = ValidationResult::error($file, 'TestCaseSchema', "Invalid operation '{$test['operation']}', must be one of: " . implode(', ', self::VALID_OPERATIONS), $testName);
        }

        if (isset($test['assertions'])) {
            if (!is_array($test['assertions']) || count($test['assertions']) === 0) {
                $results[] = ValidationResult::error($file, 'TestCaseSchema', 'Must have at least one assertion', $testName);
            } else {
                foreach ($test['assertions'] as $i => $assertion) {
                    if (!is_array($assertion)) {
                        $results[] = ValidationResult::error($file, 'TestCaseSchema', "Assertion #$i must be an object", $testName);
                        continue;
                    }
                    if (!isset($assertion['type'])) {
                        $results[] = ValidationResult::error($file, 'TestCaseSchema', "Assertion #$i missing required field: type", $testName);
                        continue;
                    }
                    $def = AssertionRegistry::get($assertion['type']);
                    if ($def === null) {
                        $results[] = ValidationResult::error($file, 'TestCaseSchema', "Unknown assertion type: {$assertion['type']}", $testName);
                        continue;
                    }
                    $fields = array_diff_key($assertion, ['type' => true]);
                    foreach (AssertionRegistry::validateFields($assertion['type'], $fields) as $fieldError) {
                        $results[] = ValidationResult::error($file, 'TestCaseSchema', $fieldError, $testName);
                    }
                }
            }
        }

        if (isset($test['tags']) && is_array($test['tags'])) {
            foreach ($test['tags'] as $tag) {
                if (!is_string($tag) || !preg_match(self::KEBAB_CASE_PATTERN, $tag)) {
                    $results[] = ValidationResult::warning($file, 'TestCaseSchema', "Tag '$tag' must be lowercase kebab-case", $testName);
                }
            }
        }

        return $results;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Eval/Schema/TestCaseSchemaTest.php`
Expected: 9 tests, PASS

- [ ] **Step 5: Commit**

```bash
git add src/Eval/Schema/TestCaseSchema.php tests/Unit/Eval/Schema/TestCaseSchemaTest.php
git commit -m "feat(eval): add TestCaseSchema per-test validator (#444)"
```

---

## Task 5: Validation rules

**Files:**
- Create: `src/Eval/Rules/UniqueNameRule.php`
- Create: `src/Eval/Rules/AssertionCompatibilityRule.php`
- Create: `src/Eval/Rules/ResolveFirstRule.php`
- Create: `src/Eval/Rules/CoverageRule.php`
- Create: `src/Eval/Rules/TagConsistencyRule.php`
- Test: `tests/Unit/Eval/Rules/UniqueNameRuleTest.php`
- Test: `tests/Unit/Eval/Rules/AssertionCompatibilityRuleTest.php`
- Test: `tests/Unit/Eval/Rules/ResolveFirstRuleTest.php`
- Test: `tests/Unit/Eval/Rules/CoverageRuleTest.php`

Each rule implements a simple interface:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

interface EvalRule
{
    /** @return list<ValidationResult> */
    public function validate(array $data, string $file): array;
}
```

And `CoverageRule` implements a cross-file interface:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

interface CrossFileRule
{
    /**
     * @param array<string, list<array<string, mixed>>> $allFilesBySkill skill => [parsed file data, ...]
     * @return list<ValidationResult>
     */
    public function validate(array $allFilesBySkill): array;
}
```

- [ ] **Step 1: Write failing tests for UniqueNameRule**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\UniqueNameRule;
use PHPUnit\Framework\TestCase;

final class UniqueNameRuleTest extends TestCase
{
    public function test_unique_names_pass(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
            ['name' => 'test-2', 'operation' => 'list', 'input' => 'y', 'assertions' => [['type' => 'confirmation_shown']]],
        ]];

        $results = (new UniqueNameRule())->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_duplicate_names_produce_error(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
            ['name' => 'test-1', 'operation' => 'list', 'input' => 'y', 'assertions' => [['type' => 'confirmation_shown']]],
        ]];

        $results = (new UniqueNameRule())->validate($data, 'f.yaml');

        self::assertCount(1, $results);
        self::assertStringContainsString('test-1', $results[0]->message);
    }
}
```

- [ ] **Step 2: Write failing tests for AssertionCompatibilityRule**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\AssertionCompatibilityRule;
use PHPUnit\Framework\TestCase;

final class AssertionCompatibilityRuleTest extends TestCase
{
    public function test_compatible_assertion_passes(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'update', 'input' => 'x', 'assertions' => [['type' => 'resolve_first']]],
        ]];

        $results = (new AssertionCompatibilityRule())->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_incompatible_assertion_produces_error(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'resolve_first']]],
        ]];

        $results = (new AssertionCompatibilityRule())->validate($data, 'f.yaml');

        self::assertCount(1, $results);
        self::assertStringContainsString('resolve_first', $results[0]->message);
        self::assertStringContainsString('create', $results[0]->message);
    }
}
```

- [ ] **Step 3: Write failing tests for ResolveFirstRule**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\ResolveFirstRule;
use PHPUnit\Framework\TestCase;

final class ResolveFirstRuleTest extends TestCase
{
    public function test_update_with_existing_entities_passes(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'update', 'input' => 'x', 'context' => ['existing_entities' => [['uuid' => 'a', 'fields' => ['name' => 'X']]]], 'assertions' => [['type' => 'resolve_first']]],
        ]];

        $results = (new ResolveFirstRule())->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_update_without_context_produces_warning(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'update', 'input' => 'x', 'assertions' => [['type' => 'resolve_first']]],
        ]];

        $results = (new ResolveFirstRule())->validate($data, 'f.yaml');

        self::assertCount(1, $results);
        self::assertSame('warning', $results[0]->severity);
    }

    public function test_create_without_context_is_fine(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
        ]];

        $results = (new ResolveFirstRule())->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }
}
```

- [ ] **Step 4: Write failing tests for CoverageRule**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\CoverageRule;
use PHPUnit\Framework\TestCase;

final class CoverageRuleTest extends TestCase
{
    public function test_full_coverage_passes(): void
    {
        $allFiles = [
            'commitment' => [
                ['tests' => [
                    ['name' => 'c', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'l', 'operation' => 'list', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'u', 'operation' => 'update', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'd', 'operation' => 'delete', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                ]],
            ],
        ];

        $results = (new CoverageRule())->validate($allFiles);

        self::assertEmpty($results);
    }

    public function test_missing_operation_produces_error(): void
    {
        $allFiles = [
            'commitment' => [
                ['tests' => [
                    ['name' => 'c', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'l', 'operation' => 'list', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                ]],
            ],
        ];

        $results = (new CoverageRule())->validate($allFiles);

        self::assertCount(2, $results); // missing update and delete
    }
}
```

- [ ] **Step 5: Run all rule tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Eval/Rules/`
Expected: FAIL — classes not found

- [ ] **Step 6: Implement all rule classes**

Create interfaces `EvalRule` and `CrossFileRule` in `src/Eval/Rules/`.
Create `UniqueNameRule`, `AssertionCompatibilityRule`, `ResolveFirstRule`, `TagConsistencyRule` implementing `EvalRule`.
Create `CoverageRule` implementing `CrossFileRule`.

Each rule is a small class with a single `validate()` method. Refer to the spec Section 4.4 and 4.5 for the exact rules. See test code above for expected behavior.

Key implementations:

**UniqueNameRule:** Collect all `tests[].name` values, flag duplicates.

**AssertionCompatibilityRule:** For each test, check each assertion's type against `AssertionRegistry::isValidForOperation()`.

**ResolveFirstRule:** For update/delete tests, warn if `context.existing_entities` is absent or empty.

**CoverageRule:** Cross-file rule that checks three things per skill (spec 4.5):
1. Operation coverage: each skill must have at least one test for create, list, update, delete.
2. Error handling coverage: each skill must have at least one test tagged `error-handling` or with an `error_surfaced` assertion.
3. Edge case coverage: each skill must have at least one test tagged `edge-case` or `regression`.

**TagConsistencyRule:** Collect all tags, warn on non-kebab-case.

**Note:** Spec rule 4.4.13 (EntityFieldConsistencyRule) is deferred. It requires comparing field keys across tests within a skill, which adds complexity for minimal value at this stage. Can be added as a follow-up if field inconsistency proves to be a real problem.

- [ ] **Step 7: Run all rule tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Eval/Rules/`
Expected: All PASS

- [ ] **Step 8: Commit**

```bash
git add src/Eval/Rules/ tests/Unit/Eval/Rules/
git commit -m "feat(eval): add validation rules — uniqueness, compatibility, coverage (#444)"
```

---

## Task 6: JsonReporter

**Files:**
- Create: `src/Eval/Report/JsonReporter.php`
- Test: `tests/Unit/Eval/Report/JsonReporterTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Report;

use Claudriel\Eval\Report\JsonReporter;
use Claudriel\Eval\Report\ValidationResult;
use PHPUnit\Framework\TestCase;

final class JsonReporterTest extends TestCase
{
    public function test_passing_report_structure(): void
    {
        $reporter = new JsonReporter();
        $results = [
            ValidationResult::warning('f.yaml', 'TagConsistencyRule', 'Unknown tag'),
        ];
        $coverage = [
            'commitment' => ['create', 'list', 'update', 'delete'],
        ];

        $json = $reporter->render($results, filesScanned: 1, testsScanned: 10, skillsCovered: ['commitment'], operationCoverage: $coverage);
        $data = json_decode($json, true);

        self::assertSame('1.0', $data['schema_version']);
        self::assertSame('pass', $data['status']);
        self::assertSame(0, $data['summary']['errors']);
        self::assertSame(1, $data['summary']['warnings']);
        self::assertSame(1, $data['summary']['files_scanned']);
        self::assertSame(10, $data['summary']['tests_scanned']);
        self::assertCount(1, $data['results']);
    }

    public function test_failing_report_when_errors_present(): void
    {
        $reporter = new JsonReporter();
        $results = [
            ValidationResult::error('f.yaml', 'TestCaseSchema', 'Missing field'),
        ];

        $json = $reporter->render($results, filesScanned: 1, testsScanned: 5, skillsCovered: ['x'], operationCoverage: []);
        $data = json_decode($json, true);

        self::assertSame('fail', $data['status']);
        self::assertSame(1, $data['summary']['errors']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Eval/Report/JsonReporterTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Report;

final class JsonReporter
{
    /**
     * @param list<ValidationResult> $results
     * @param list<string> $skillsCovered
     * @param array<string, list<string>> $operationCoverage
     */
    public function render(
        array $results,
        int $filesScanned,
        int $testsScanned,
        array $skillsCovered,
        array $operationCoverage,
    ): string {
        $errors = count(array_filter($results, fn (ValidationResult $r) => $r->isError()));
        $warnings = count($results) - $errors;

        $report = [
            'schema_version' => '1.0',
            'timestamp' => date('c'),
            'status' => $errors > 0 ? 'fail' : 'pass',
            'summary' => [
                'files_scanned' => $filesScanned,
                'tests_scanned' => $testsScanned,
                'errors' => $errors,
                'warnings' => $warnings,
                'skills_covered' => $skillsCovered,
                'operation_coverage' => $operationCoverage,
            ],
            'results' => array_map(fn (ValidationResult $r) => $r->toArray(), $results),
        ];

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Eval/Report/JsonReporterTest.php`
Expected: 2 tests, PASS

- [ ] **Step 5: Commit**

```bash
git add src/Eval/Report/JsonReporter.php tests/Unit/Eval/Report/JsonReporterTest.php
git commit -m "feat(eval): add JsonReporter for machine-readable output (#444)"
```

---

## Task 7: EvalSchemaValidator orchestrator

**Files:**
- Create: `src/Eval/EvalSchemaValidator.php`
- Test: `tests/Unit/Eval/EvalSchemaValidatorTest.php`

- [ ] **Step 1: Write the failing test**

This test uses a temporary directory with fixture YAML files to test the full orchestration.

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval;

use Claudriel\Eval\EvalSchemaValidator;
use PHPUnit\Framework\TestCase;

final class EvalSchemaValidatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/eval_test_' . uniqid('', true);
        mkdir($this->tempDir . '/skill-a/evals', 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $this->removeDir($this->tempDir);
    }

    public function test_valid_file_produces_pass(): void
    {
        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "skill-a"
entity_type: "skill_a"

tests:
  - name: create-basic
    operation: create
    input: "create a thing"
    assertions:
      - type: confirmation_shown
  - name: list-all
    operation: list
    input: "show things"
    assertions:
      - type: graphql_operation
        operation: skillAList
        mutation: false
  - name: update-basic
    operation: update
    input: "rename it"
    context:
      existing_entities:
        - uuid: "abc"
          fields: { name: "Old" }
    assertions:
      - type: resolve_first
  - name: delete-basic
    operation: delete
    input: "remove it"
    context:
      existing_entities:
        - uuid: "abc"
          fields: { name: "Old" }
    assertions:
      - type: echo_back_required
        field: name
YAML;
        file_put_contents($this->tempDir . '/skill-a/evals/basic.yaml', $yaml);

        $validator = new EvalSchemaValidator($this->tempDir);
        $report = $validator->validate();

        self::assertSame('pass', $report['status']);
        self::assertSame(0, $report['summary']['errors']);
    }

    public function test_invalid_file_produces_fail(): void
    {
        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "skill-a"
entity_type: "skill_a"

tests:
  - name: bad_name
    operation: create
    input: "create a thing"
    assertions:
      - type: confirmation_shown
YAML;
        file_put_contents($this->tempDir . '/skill-a/evals/basic.yaml', $yaml);

        $validator = new EvalSchemaValidator($this->tempDir);
        $report = $validator->validate();

        self::assertSame('fail', $report['status']);
        self::assertGreaterThan(0, $report['summary']['errors']);
    }

    public function test_file_without_schema_version_is_skipped(): void
    {
        $yaml = <<<'YAML'
prompts:
  - prompt: "something"
    expectations:
      - "does a thing"
YAML;
        file_put_contents($this->tempDir . '/skill-a/evals/basic.yaml', $yaml);

        $validator = new EvalSchemaValidator($this->tempDir);
        $report = $validator->validate();

        self::assertSame(0, $report['summary']['files_scanned']);
    }

    public function test_skill_filter_limits_scope(): void
    {
        mkdir($this->tempDir . '/skill-b/evals', 0777, true);

        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "skill-a"
entity_type: "skill_a"

tests:
  - name: create-basic
    operation: create
    input: "x"
    assertions:
      - type: confirmation_shown
YAML;
        file_put_contents($this->tempDir . '/skill-a/evals/basic.yaml', $yaml);
        file_put_contents($this->tempDir . '/skill-b/evals/basic.yaml', str_replace(['skill-a', 'skill_a'], ['skill-b', 'skill_b'], $yaml));

        $validator = new EvalSchemaValidator($this->tempDir);
        $report = $validator->validate(skillFilter: 'skill-a');

        self::assertSame(1, $report['summary']['files_scanned']);
        self::assertSame(['skill-a'], $report['summary']['skills_covered']);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Eval/EvalSchemaValidatorTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval;

use Claudriel\Eval\Report\JsonReporter;
use Claudriel\Eval\Report\ValidationResult;
use Claudriel\Eval\Rules\AssertionCompatibilityRule;
use Claudriel\Eval\Rules\CoverageRule;
use Claudriel\Eval\Rules\ResolveFirstRule;
use Claudriel\Eval\Rules\TagConsistencyRule;
use Claudriel\Eval\Rules\UniqueNameRule;
use Claudriel\Eval\Schema\EvalFileSchema;
use Claudriel\Eval\Schema\TestCaseSchema;
use Symfony\Component\Yaml\Yaml;

final class EvalSchemaValidator
{
    private readonly EvalFileSchema $fileSchema;
    private readonly TestCaseSchema $testCaseSchema;
    /** @var list<\Claudriel\Eval\Rules\EvalRule> */
    private readonly array $fileRules;
    /** @var list<\Claudriel\Eval\Rules\CrossFileRule> */
    private readonly array $crossFileRules;
    private readonly JsonReporter $reporter;

    public function __construct(
        private readonly string $skillsBasePath,
    ) {
        $this->fileSchema = new EvalFileSchema();
        $this->testCaseSchema = new TestCaseSchema();
        $this->fileRules = [
            new UniqueNameRule(),
            new AssertionCompatibilityRule(),
            new ResolveFirstRule(),
            new TagConsistencyRule(),
        ];
        $this->crossFileRules = [
            new CoverageRule(),
        ];
        $this->reporter = new JsonReporter();
    }

    /**
     * @return array<string, mixed> The parsed JSON report
     */
    public function validate(?string $skillFilter = null, bool $strict = false): array
    {
        $results = [];
        $filesScanned = 0;
        $testsScanned = 0;
        $skillsCovered = [];
        $operationCoverage = [];
        $allFilesBySkill = [];

        $pattern = $skillFilter !== null
            ? $this->skillsBasePath . "/$skillFilter/evals/*.yaml"
            : $this->skillsBasePath . '/*/evals/*.yaml';

        $files = glob($pattern) ?: [];

        foreach ($files as $filePath) {
            $parsed = Yaml::parseFile($filePath);
            if (!is_array($parsed) || !isset($parsed['schema_version']) || $parsed['schema_version'] !== '1.0') {
                continue;
            }

            $filesScanned++;
            $relativePath = str_replace($this->skillsBasePath . '/', '', $filePath);
            $skillDir = basename(dirname(dirname($filePath)));

            if (!in_array($skillDir, $skillsCovered, true)) {
                $skillsCovered[] = $skillDir;
            }

            $results = array_merge($results, $this->fileSchema->validate($parsed, $relativePath, $skillDir));

            if (isset($parsed['tests']) && is_array($parsed['tests'])) {
                $testsScanned += count($parsed['tests']);

                foreach ($parsed['tests'] as $test) {
                    if (is_array($test)) {
                        $results = array_merge($results, $this->testCaseSchema->validate($test, $relativePath));
                    }
                }

                foreach ($this->fileRules as $rule) {
                    $results = array_merge($results, $rule->validate($parsed, $relativePath));
                }

                $allFilesBySkill[$skillDir][] = $parsed;

                $ops = array_unique(array_column($parsed['tests'], 'operation'));
                $operationCoverage[$skillDir] = array_values(array_unique(
                    array_merge($operationCoverage[$skillDir] ?? [], $ops),
                ));
            }
        }

        foreach ($this->crossFileRules as $rule) {
            $results = array_merge($results, $rule->validate($allFilesBySkill));
        }

        if ($strict) {
            $results = array_map(
                fn (ValidationResult $r) => $r->isError() ? $r : ValidationResult::error($r->file, $r->rule, $r->message, $r->test),
                $results,
            );
        }

        sort($skillsCovered);
        ksort($operationCoverage);

        $json = $this->reporter->render($results, $filesScanned, $testsScanned, $skillsCovered, $operationCoverage);

        return json_decode($json, true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Eval/EvalSchemaValidatorTest.php`
Expected: 4 tests, PASS

- [ ] **Step 5: Commit**

```bash
git add src/Eval/EvalSchemaValidator.php tests/Unit/Eval/EvalSchemaValidatorTest.php
git commit -m "feat(eval): add EvalSchemaValidator orchestrator (#444)"
```

---

## Task 8: CLI command and bin entry point

**Files:**
- Create: `src/Eval/Command/EvalValidateCommand.php`
- Create: `bin/eval-validate`
- Test: `tests/Unit/Eval/Command/EvalValidateCommandTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Command;

use Claudriel\Eval\Command\EvalValidateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EvalValidateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/eval_cmd_test_' . uniqid('', true);
        mkdir($this->tempDir . '/test-skill/evals', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_valid_evals_exit_zero(): void
    {
        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "test-skill"
entity_type: "test"

tests:
  - name: create-basic
    operation: create
    input: "create a thing"
    assertions:
      - type: confirmation_shown
  - name: list-basic
    operation: list
    input: "show things"
    assertions:
      - type: graphql_operation
        operation: testList
        mutation: false
  - name: update-basic
    operation: update
    input: "rename it"
    context:
      existing_entities:
        - uuid: "a"
          fields: { name: "X" }
    assertions:
      - type: resolve_first
  - name: delete-basic
    operation: delete
    input: "remove it"
    context:
      existing_entities:
        - uuid: "a"
          fields: { name: "X" }
    assertions:
      - type: echo_back_required
        field: name
YAML;
        file_put_contents($this->tempDir . '/test-skill/evals/basic.yaml', $yaml);

        $command = new EvalValidateCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('pass', $tester->getDisplay());
    }

    public function test_invalid_evals_exit_one(): void
    {
        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "test-skill"
entity_type: "test"

tests:
  - name: BAD_NAME
    operation: create
    input: "x"
    assertions:
      - type: confirmation_shown
YAML;
        file_put_contents($this->tempDir . '/test-skill/evals/basic.yaml', $yaml);

        $command = new EvalValidateCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Eval/Command/EvalValidateCommandTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write EvalValidateCommand**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Eval\Command;

use Claudriel\Eval\EvalSchemaValidator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:eval-validate', description: 'Validate eval YAML files against unified schema')]
final class EvalValidateCommand extends Command
{
    public function __construct(
        private readonly string $skillsBasePath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('skill', 's', InputOption::VALUE_REQUIRED, 'Validate only this skill')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write JSON report to file')
            ->addOption('strict', null, InputOption::VALUE_NONE, 'Treat warnings as errors');
        // Note: Symfony Console provides --quiet/-q built-in (suppresses output).
        // Do NOT add a custom --quiet option — it conflicts.
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $validator = new EvalSchemaValidator($this->skillsBasePath);
            $report = $validator->validate(
                skillFilter: $input->getOption('skill'),
                strict: (bool) $input->getOption('strict'),
            );
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            $output->writeln("<error>YAML parse error: {$e->getMessage()}</error>");
            return Command::INVALID; // exit code 2
        } catch (\RuntimeException $e) {
            $output->writeln("<error>Runtime error: {$e->getMessage()}</error>");
            return Command::INVALID; // exit code 2
        }

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $outputFile = $input->getOption('output');
        if (is_string($outputFile)) {
            file_put_contents($outputFile, $json);
            $output->writeln("Report written to $outputFile");
        }

        if (!$output->isQuiet()) {
            $output->write($json);
        }

        $summary = $report['summary'];
        $output->writeln(sprintf(
            "\n%s: %d files, %d tests, %d errors, %d warnings",
            strtoupper($report['status']),
            $summary['files_scanned'],
            $summary['tests_scanned'],
            $summary['errors'],
            $summary['warnings'],
        ));

        return $report['status'] === 'pass' ? Command::SUCCESS : Command::FAILURE;
    }
}
```

- [ ] **Step 4: Create bin/eval-validate entry point**

```php
#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Claudriel\Eval\Command\EvalValidateCommand;
use Symfony\Component\Console\Application;

$skillsPath = dirname(__DIR__) . '/.claude/skills';

$app = new Application('eval-validate', '1.0.0');
$app->add(new EvalValidateCommand($skillsPath));
$app->setDefaultCommand('claudriel:eval-validate', true);
$app->run();
```

Make it executable: `chmod +x bin/eval-validate`

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Eval/Command/EvalValidateCommandTest.php`
Expected: 2 tests, PASS

- [ ] **Step 6: Verify CLI works end-to-end**

Run: `php bin/eval-validate --help`
Expected: Shows usage with --skill, --output, --strict, --quiet options

- [ ] **Step 7: Commit**

```bash
git add src/Eval/Command/EvalValidateCommand.php bin/eval-validate tests/Unit/Eval/Command/EvalValidateCommandTest.php
git commit -m "feat(eval): add CLI command and bin entry point (#444)"
```

---

## Task 9: Run full test suite

- [ ] **Step 1: Run all eval tests**

Run: `vendor/bin/phpunit tests/Unit/Eval/`
Expected: All tests PASS

- [ ] **Step 2: Run full project test suite to check for regressions**

Run: `vendor/bin/phpunit`
Expected: All tests PASS (existing tests unaffected)

- [ ] **Step 3: Run linting**

Run: `vendor/bin/pint --test`
Expected: No formatting issues (or fix them with `vendor/bin/pint src/Eval/ tests/Unit/Eval/`)

- [ ] **Step 4: Commit any lint fixes**

```bash
git add -A
git commit -m "style: format eval framework code (#444)"
```

---

## Task 10: Migrate judgment-rule evals (Schema C)

**Files:**
- Modify: `.claude/skills/judgment-rule/evals/basic.yaml`

- [ ] **Step 1: Read current judgment-rule eval file**

Read: `.claude/skills/judgment-rule/evals/basic.yaml`

- [ ] **Step 2: Rewrite to unified schema**

Convert following the Schema C migration rules in the spec (Section 8.2):
- Add `schema_version: "1.0"`, `skill: judgment-rule`, `entity_type: judgment_rule`
- Rename test names from underscores to kebab-case
- Convert `existing_rules[]` to `context.existing_entities[]`
- Convert `expect{}` to typed `assertions[]`
- Add `operation` field from `expect.operation`
- Map `rule_text_must_not_contain` to `field_extraction` with `must_not_contain`
- Map `confirms_before_api` to `confirmation_shown`
- Map `fetches_list_before_parsing` to `resolve_first`
- Map `surfaces_conflict` to `error_surfaced`

- [ ] **Step 3: Validate**

Run: `php bin/eval-validate --skill judgment-rule`
Expected: PASS with 0 errors

- [ ] **Step 4: Commit**

```bash
git add .claude/skills/judgment-rule/evals/basic.yaml
git commit -m "refactor(eval): migrate judgment-rule evals to unified schema (#444)"
```

---

## Task 11: Migrate commitment evals (Schema B)

**Files:**
- Modify: `.claude/skills/commitment/evals/basic.yaml`

- [ ] **Step 1: Read current commitment eval file**

Read: `.claude/skills/commitment/evals/basic.yaml`

- [ ] **Step 2: Rewrite to unified schema**

Convert following the Schema B migration rules:
- Add `schema_version: "1.0"`, `skill: commitment`, `entity_type: commitment`
- Add `operation` field (derive from test name prefix or section comment)
- Convert `expected[]` to typed `assertions[]`
- Add `context.existing_entities` for update/delete tests
- Keep existing `name` fields (already kebab-case-ish, normalize if needed)

- [ ] **Step 3: Validate**

Run: `php bin/eval-validate --skill commitment`
Expected: PASS with 0 errors

- [ ] **Step 4: Commit**

```bash
git add .claude/skills/commitment/evals/basic.yaml
git commit -m "refactor(eval): migrate commitment evals to unified schema (#444)"
```

---

## Task 12: Migrate Schema A skills (new-workspace, new-person, schedule-entry, triage-entry)

**Files:**
- Modify: `.claude/skills/new-workspace/evals/basic.yaml`
- Modify: `.claude/skills/new-person/evals/basic.yaml`
- Modify: `.claude/skills/schedule-entry/evals/basic.yaml`
- Modify: `.claude/skills/triage-entry/evals/basic.yaml`

- [ ] **Step 1: Migrate new-workspace (reference implementation)**

Read current file, rewrite to unified schema:
- Add `schema_version: "1.0"`, `skill: new-workspace`, `entity_type: workspace`
- `prompts[]` becomes `tests[]`
- `prompt` becomes `input`
- Add `name` (derive from content, kebab-case)
- Add `operation` (from section comment)
- Convert `expectations[]` to typed `assertions[]`
- Convert `context` string to `context.existing_entities[]`

Validate: `php bin/eval-validate --skill new-workspace`

- [ ] **Step 2: Migrate new-person**

Follow same pattern as new-workspace. `skill: new-person`, `entity_type: person`.

Validate: `php bin/eval-validate --skill new-person`

- [ ] **Step 3: Migrate schedule-entry**

Follow same pattern. `skill: schedule-entry`, `entity_type: schedule_entry`.

Validate: `php bin/eval-validate --skill schedule-entry`

- [ ] **Step 4: Migrate triage-entry**

Follow same pattern. `skill: triage-entry`, `entity_type: triage_entry`.

Validate: `php bin/eval-validate --skill triage-entry`

- [ ] **Step 5: Validate all skills together**

Run: `php bin/eval-validate --strict`
Expected: PASS with 0 errors, 0 warnings, all 6 skills covered, all operations covered

- [ ] **Step 6: Commit**

```bash
git add .claude/skills/new-workspace/evals/basic.yaml .claude/skills/new-person/evals/basic.yaml .claude/skills/schedule-entry/evals/basic.yaml .claude/skills/triage-entry/evals/basic.yaml
git commit -m "refactor(eval): migrate Schema A skills to unified eval format (#444)"
```

---

## Task 13: CI workflow

**Files:**
- Create: `.github/workflows/eval-validate.yml`

- [ ] **Step 1: Create the workflow file**

See spec Section 7.1 for the complete workflow YAML. Key points:
- Triggers on PR changes to `.claude/skills/*/evals/**`, `src/Eval/**`, `bin/eval-validate`
- Uses `shivammathur/setup-php@v2` with PHP 8.4
- Runs `php bin/eval-validate --strict --output eval-report.json`
- Uploads artifact
- Comments on PR with errors on failure

- [ ] **Step 2: Validate workflow syntax**

Run: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/eval-validate.yml'))" && echo "valid YAML"`
Expected: valid YAML

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/eval-validate.yml
git commit -m "ci: add eval schema validation workflow (#444)"
```

---

## Task 14: Final verification and full test suite

- [ ] **Step 1: Run full validator against all migrated evals**

Run: `php bin/eval-validate --strict`
Expected: PASS, 6 files, ~95 tests, 0 errors, 0 warnings, all 6 skills, all 4 operations per skill

- [ ] **Step 2: Run full project test suite**

Run: `vendor/bin/phpunit`
Expected: All tests PASS

- [ ] **Step 3: Run linting**

Run: `vendor/bin/pint --test && vendor/bin/phpstan analyse src/Eval/`
Expected: No issues

- [ ] **Step 4: Verify git status is clean**

Run: `git status`
Expected: Clean working tree (all changes committed)

- [ ] **Step 5: Final commit (if any cleanup needed)**

```bash
git add -A
git commit -m "chore: final cleanup for eval framework (#444)"
```
