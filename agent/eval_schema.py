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
}

REQUIRED_TOP_LEVEL = {"schema_version", "skill", "tests"}
REQUIRED_TEST_FIELDS = {"name", "operation", "input"}


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

    tests = data.get("tests", [])
    if not isinstance(tests, list):
        errors.append(ValidationError(filename, "tests must be a list"))
        return errors

    seen_names: set[str] = set()
    for i, test in enumerate(tests):
        if not isinstance(test, dict):
            errors.append(ValidationError(filename, f"Test {i} must be a mapping"))
            continue

        for field in REQUIRED_TEST_FIELDS:
            if field not in test:
                errors.append(ValidationError(filename, f"Test {i}: missing required field: {field}"))

        name = test.get("name", "")
        if name in seen_names:
            errors.append(ValidationError(filename, f"Duplicate test name: {name}"))
        seen_names.add(name)

        for assertion in test.get("assertions", []):
            if not isinstance(assertion, dict):
                continue
            atype = assertion.get("type", "")
            if atype not in VALID_ASSERTION_TYPES:
                errors.append(ValidationError(filename, f"Test '{name}': unknown assertion type: {atype}"))

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
