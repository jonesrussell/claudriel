#!/usr/bin/env python3
"""Model benchmark harness for skill routing decisions.

Runs each CRUD skill's basic eval on all three model tiers, collects
token usage, latency, and (optionally) LLM-judge scores.

Usage:
    python agent/eval_benchmark.py --skill commitment
    python agent/eval_benchmark.py --all --runs 3
    python agent/eval_benchmark.py --all --output docs/reports/model-benchmarks.json
"""

import argparse
import json
import sys
import time
from dataclasses import dataclass, field
from pathlib import Path

import yaml

TIERS = {
    "haiku": "claude-haiku-4-5-20251001",
    "sonnet": "claude-sonnet-4-6",
    "opus": "claude-opus-4-6",
}

SKILL_TO_ENTITY = {
    "commitment": "commitment",
    "new-person": "person",
    "new-workspace": "workspace",
    "schedule-entry": "schedule_entry",
    "triage-entry": "triage_entry",
    "judgment-rule": "judgment_rule",
    "project": "project",
}


@dataclass
class RunResult:
    model: str
    tier: str
    input_tokens: int
    output_tokens: int
    latency_ms: float
    response_text: str


@dataclass
class BenchmarkResult:
    skill: str
    test_name: str
    runs: list[RunResult] = field(default_factory=list)


def run_benchmark(
    skill_name: str,
    skills_dir: Path,
    tiers: dict[str, str] | None = None,
    num_runs: int = 1,
) -> list[BenchmarkResult]:
    """Run benchmarks for a skill across model tiers."""
    import anthropic

    tiers = tiers or TIERS
    client = anthropic.Anthropic()

    skill_md = skills_dir / skill_name / "SKILL.md"
    if not skill_md.exists():
        print(f"SKILL.md not found for {skill_name}", file=sys.stderr)
        return []

    skill_context = skill_md.read_text()

    eval_file = skills_dir / skill_name / "evals" / "basic.yaml"
    if not eval_file.exists():
        print(f"No basic.yaml eval for {skill_name}", file=sys.stderr)
        return []

    with open(eval_file) as f:
        data = yaml.safe_load(f)

    tests = data.get("tests", [])[:3]  # Limit to first 3 tests per skill
    results: list[BenchmarkResult] = []

    for test in tests:
        test_name = test.get("name", "unnamed")
        user_input = test.get("input", "")
        if not user_input:
            continue

        benchmark = BenchmarkResult(skill=skill_name, test_name=test_name)

        for tier_name, model_id in tiers.items():
            for _ in range(num_runs):
                start = time.monotonic()
                try:
                    response = client.messages.create(
                        model=model_id,
                        max_tokens=2048,
                        system=skill_context,
                        messages=[{"role": "user", "content": user_input}],
                    )
                    elapsed = (time.monotonic() - start) * 1000

                    benchmark.runs.append(
                        RunResult(
                            model=model_id,
                            tier=tier_name,
                            input_tokens=response.usage.input_tokens,
                            output_tokens=response.usage.output_tokens,
                            latency_ms=elapsed,
                            response_text=response.content[0].text[:500],
                        )
                    )
                except Exception as e:
                    print(f"  Error on {tier_name}/{test_name}: {e}", file=sys.stderr)

        results.append(benchmark)

    return results


def generate_report(all_results: list[BenchmarkResult]) -> dict:
    """Generate a structured benchmark report."""
    skills: dict[str, dict] = {}

    for benchmark in all_results:
        if benchmark.skill not in skills:
            skills[benchmark.skill] = {"tests": {}}

        tier_stats: dict[str, dict] = {}
        for run in benchmark.runs:
            if run.tier not in tier_stats:
                tier_stats[run.tier] = {
                    "latencies": [],
                    "input_tokens": [],
                    "output_tokens": [],
                }
            tier_stats[run.tier]["latencies"].append(run.latency_ms)
            tier_stats[run.tier]["input_tokens"].append(run.input_tokens)
            tier_stats[run.tier]["output_tokens"].append(run.output_tokens)

        test_summary = {}
        for tier, stats in tier_stats.items():
            latencies = stats["latencies"]
            test_summary[tier] = {
                "median_latency_ms": round(sorted(latencies)[len(latencies) // 2], 1),
                "avg_input_tokens": round(sum(stats["input_tokens"]) / len(stats["input_tokens"])),
                "avg_output_tokens": round(
                    sum(stats["output_tokens"]) / len(stats["output_tokens"])
                ),
            }

        skills[benchmark.skill]["tests"][benchmark.test_name] = test_summary

    return {"skills": skills, "tiers": TIERS}


def format_markdown(report: dict) -> str:
    """Format benchmark report as markdown."""
    lines = [
        "## Model Benchmark Report",
        "",
        "| Skill | Test | Tier | Latency (ms) | Input Tokens | Output Tokens |",
        "|-------|------|------|-------------|-------------|--------------|",
    ]

    for skill_name, skill_data in sorted(report["skills"].items()):
        for test_name, tiers in skill_data["tests"].items():
            for tier_name, stats in sorted(tiers.items()):
                lines.append(
                    f"| {skill_name} | {test_name} | {tier_name} "
                    f"| {stats['median_latency_ms']} "
                    f"| {stats['avg_input_tokens']} "
                    f"| {stats['avg_output_tokens']} |"
                )

    return "\n".join(lines)


def main() -> None:
    parser = argparse.ArgumentParser(description="Model benchmark harness")
    parser.add_argument("--skill", type=str, help="Benchmark specific skill")
    parser.add_argument("--all", action="store_true", help="Benchmark all CRUD skills")
    parser.add_argument("--runs", type=int, default=1, help="Runs per model per test")
    parser.add_argument("--skills-dir", type=str, default=".claude/skills")
    parser.add_argument("--output", type=str, help="Write JSON report to file")
    parser.add_argument("--markdown", action="store_true", help="Print markdown")
    args = parser.parse_args()

    skills_dir = Path(args.skills_dir)
    skill_list = [args.skill] if args.skill else list(SKILL_TO_ENTITY.keys()) if args.all else []

    if not skill_list:
        parser.error("Specify --skill NAME or --all")

    all_results: list[BenchmarkResult] = []
    for skill in skill_list:
        print(f"Benchmarking {skill}...", file=sys.stderr)
        all_results.extend(run_benchmark(skill, skills_dir, num_runs=args.runs))

    report = generate_report(all_results)

    if args.output:
        Path(args.output).parent.mkdir(parents=True, exist_ok=True)
        Path(args.output).write_text(json.dumps(report, indent=2))
        print(f"Report written to {args.output}", file=sys.stderr)

    if args.markdown:
        print(format_markdown(report))
    elif not args.output:
        print(json.dumps(report, indent=2))


if __name__ == "__main__":
    main()
