"""YAML schema validation for eval files."""

from dataclasses import dataclass
from pathlib import Path
from typing import Any

import yaml

VALID_ASSERTION_TYPES = {
    "field_extraction",
    "direction_detected",
    "confirmation_shown",
    "graphql_operation",
    "table_presented",
    "filter_applied",
    "resolve_first",
    "disambiguation",
    "error_surfaced",
    "before_after_shown",
    "asks_for_field",
    "no_conjunction_split",
    "echo_back_required",
    "offers_alternative",
    "no_file_operations",
    "secondary_intent_queued",
}

# skill is recommended but not required (can be inferred from directory)
REQUIRED_TOP_LEVEL: set[str] = set()
# Files must have either "tests" or "prompts" key
# Trajectory/multi-turn evals use "turns" with nested input instead of top-level "input"
REQUIRED_TEST_FIELDS_BASIC = {"name", "operation", "input"}
REQUIRED_TEST_FIELDS_TURN = {"name"}


@dataclass
class ValidationError:
    file: str
    message: str
    line: int | None = None


def validate_eval_file(data: dict[str, Any], filename: str) -> list[ValidationError]:
    """Validate a parsed eval YAML structure. Returns list of errors (empty = valid)."""
    errors: list[ValidationError] = []

    for field in REQUIRED_TOP_LEVEL:
        if field not in data:
            errors.append(ValidationError(filename, f"Missing required field: {field}"))

    tests = data.get("tests", data.get("prompts", []))
    if not isinstance(tests, list):
        errors.append(ValidationError(filename, "tests/prompts must be a list"))
        return errors
    if "tests" not in data and "prompts" not in data:
        errors.append(ValidationError(filename, "Missing required field: tests or prompts"))
        return errors

    is_prompts_format = "prompts" in data and "tests" not in data
    if is_prompts_format:
        # prompts-format evals have different structure, skip test-level field checks
        return errors

    eval_type = data.get("eval_type", "basic")
    is_turn_based = eval_type in ("trajectory", "multi-turn") or any(
        "turns" in t for t in tests if isinstance(t, dict)
    )
    required_fields = REQUIRED_TEST_FIELDS_TURN if is_turn_based else REQUIRED_TEST_FIELDS_BASIC

    seen_names: set[str] = set()
    for i, test in enumerate(tests):
        if not isinstance(test, dict):
            errors.append(ValidationError(filename, f"Test {i} must be a mapping"))
            continue

        for field in required_fields:
            if field not in test:
                errors.append(
                    ValidationError(filename, f"Test {i}: missing required field: {field}")
                )

        name = test.get("name", "")
        if name in seen_names:
            errors.append(ValidationError(filename, f"Duplicate test name: {name}"))
        seen_names.add(name)

        for assertion in test.get("assertions", []):
            if not isinstance(assertion, dict):
                continue
            atype = assertion.get("type", "")
            if atype not in VALID_ASSERTION_TYPES:
                errors.append(
                    ValidationError(filename, f"Test '{name}': unknown assertion type: {atype}")
                )

    return errors


def load_and_validate(path: Path) -> list[ValidationError]:
    """Load a YAML file and validate it."""
    with open(path) as f:
        data = yaml.safe_load(f)
    if not isinstance(data, dict):
        return [ValidationError(str(path), "File must contain a YAML mapping")]
    return validate_eval_file(data, path.name)


def discover_eval_files(skills_dir: Path) -> list[Path]:
    """Find all eval YAML files under skill directories."""
    files: list[Path] = []
    for eval_dir in sorted(skills_dir.glob("*/evals")):
        for yaml_file in sorted(eval_dir.glob("*.yaml")):
            files.append(yaml_file)
        for yml_file in sorted(eval_dir.glob("*.yml")):
            files.append(yml_file)
    return files
