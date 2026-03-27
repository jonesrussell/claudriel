#!/usr/bin/env python3
"""Schema contract validator: checks SKILL.md GraphQL field references against PHP fieldDefinitions.

Usage:
    python agent/eval_contracts.py
    python agent/eval_contracts.py --skill commitment
    python agent/eval_contracts.py --json
"""

import argparse
import re
import sys
from dataclasses import dataclass, field
from pathlib import Path


@dataclass
class ContractViolation:
    skill: str
    entity_type: str
    field_name: str
    message: str


@dataclass
class ContractReport:
    skills_checked: int = 0
    violations: list[ContractViolation] = field(default_factory=list)
    schema_fields: dict[str, set[str]] = field(default_factory=dict)
    skill_fields: dict[str, set[str]] = field(default_factory=dict)


def extract_field_definitions(src_dir: Path) -> dict[str, set[str]]:
    """Parse fieldDefinitions from PHP service providers. Returns {entity_type: {field_names}}."""
    fields_by_type: dict[str, set[str]] = {}

    for php_file in sorted(src_dir.glob("Provider/*ServiceProvider.php")):
        content = php_file.read_text()

        # Find entityType registrations with fieldDefinitions
        # Pattern: id: 'entity_type_id' ... fieldDefinitions: [...]
        for match in re.finditer(
            r"EntityType\(\s*id:\s*'(\w+)'.*?fieldDefinitions:\s*\[(.*?)\]\s*,?\s*\)",
            content,
            re.DOTALL,
        ):
            entity_id = match.group(1)
            defs_block = match.group(2)

            field_names = set()
            for field_match in re.finditer(r"'(\w+)'\s*=>\s*\[", defs_block):
                field_names.add(field_match.group(1))

            if field_names:
                fields_by_type[entity_id] = field_names

    return fields_by_type


# Map skill directory names to entity type IDs
SKILL_TO_ENTITY = {
    "commitment": "commitment",
    "new-person": "person",
    "new-workspace": "workspace",
    "schedule-entry": "schedule_entry",
    "triage-entry": "triage_entry",
    "judgment-rule": "judgment_rule",
    "project": "project",
}


def extract_skill_fields(skills_dir: Path) -> dict[str, set[str]]:
    """Parse GraphQL Fields sections from SKILL.md files. Returns {skill_name: {field_names}}."""
    fields_by_skill: dict[str, set[str]] = {}

    for skill_name in SKILL_TO_ENTITY:
        skill_md = skills_dir / skill_name / "SKILL.md"
        if not skill_md.exists():
            continue

        content = skill_md.read_text()
        match = re.search(r"## GraphQL Fields\s*```\s*\n(.+?)\n```", content, re.DOTALL)
        if not match:
            continue

        field_line = match.group(1).strip()
        field_names = set(field_line.split())
        if field_names:
            fields_by_skill[skill_name] = field_names

    return fields_by_skill


def validate_contracts(
    src_dir: Path,
    skills_dir: Path,
    skill_filter: str | None = None,
) -> ContractReport:
    """Validate that skill field references match PHP schema definitions."""
    report = ContractReport()
    report.schema_fields = extract_field_definitions(src_dir)
    report.skill_fields = extract_skill_fields(skills_dir)

    for skill_name, skill_fields in sorted(report.skill_fields.items()):
        if skill_filter and skill_name != skill_filter:
            continue

        entity_type = SKILL_TO_ENTITY.get(skill_name)
        if not entity_type:
            continue

        report.skills_checked += 1
        schema_fields = report.schema_fields.get(entity_type, set())

        if not schema_fields:
            report.violations.append(
                ContractViolation(
                    skill=skill_name,
                    entity_type=entity_type,
                    field_name="*",
                    message=f"No fieldDefinitions found for entity type '{entity_type}'",
                )
            )
            continue

        # Fields in skill but not in schema
        for field_name in sorted(skill_fields - schema_fields):
            report.violations.append(
                ContractViolation(
                    skill=skill_name,
                    entity_type=entity_type,
                    field_name=field_name,
                    message=f"Field '{field_name}' referenced in skill but not in schema",
                )
            )

        # Fields in schema but not in skill (warning, not error)
        # Omitted: skills don't need to reference every field

    return report


def format_markdown(report: ContractReport) -> str:
    """Format contract validation report as markdown."""
    lines = [
        "## Schema Contract Validation",
        "",
        f"**Skills checked:** {report.skills_checked}",
        f"**Violations:** {len(report.violations)}",
        "",
    ]

    if not report.violations:
        lines.append("All skill field references match the schema.")
    else:
        lines.extend(
            [
                "| Skill | Entity | Field | Issue |",
                "|-------|--------|-------|-------|",
            ]
        )
        for v in report.violations:
            lines.append(
                f"| {v.skill} | {v.entity_type} | {v.field_name} | {v.message} |"
            )

    return "\n".join(lines)


def format_json(report: ContractReport) -> dict:
    """Format as JSON-serializable dict."""
    return {
        "skills_checked": report.skills_checked,
        "violations": [
            {
                "skill": v.skill,
                "entity_type": v.entity_type,
                "field": v.field_name,
                "message": v.message,
            }
            for v in report.violations
        ],
        "schema_fields": {k: sorted(v) for k, v in report.schema_fields.items()},
        "skill_fields": {k: sorted(v) for k, v in report.skill_fields.items()},
    }


def main() -> None:
    parser = argparse.ArgumentParser(
        description="Validate skill GraphQL field contracts"
    )
    parser.add_argument("--skill", type=str, help="Check specific skill only")
    parser.add_argument(
        "--src-dir", type=str, default="src", help="PHP source directory"
    )
    parser.add_argument(
        "--skills-dir", type=str, default=".claude/skills", help="Skills directory"
    )
    parser.add_argument("--json", action="store_true", help="Output as JSON")
    args = parser.parse_args()

    report = validate_contracts(
        src_dir=Path(args.src_dir),
        skills_dir=Path(args.skills_dir),
        skill_filter=args.skill,
    )

    if args.json:
        import json

        print(json.dumps(format_json(report), indent=2))
    else:
        print(format_markdown(report))

    if report.violations:
        sys.exit(1)


if __name__ == "__main__":
    main()
