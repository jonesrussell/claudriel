"""Tests for the eval runner CLI orchestrator."""

from pathlib import Path

from claudriel_agent.eval_runner import parse_args, run_deterministic


def test_parse_args_deterministic():
    args = parse_args(["--deterministic"])
    assert args.deterministic is True
    assert args.llm_judge is False


def test_parse_args_llm_judge_with_skill():
    args = parse_args(["--llm-judge", "--skill", "commitment"])
    assert args.llm_judge is True
    assert args.skill == "commitment"


def test_parse_args_default_skills_dir():
    args = parse_args(["--deterministic"])
    assert args.skills_dir == ".claude/skills"


def test_run_deterministic_on_real_evals():
    """Run deterministic validation against the actual eval files."""
    # Resolve relative to the repo root (two levels up from agent/tests/)
    repo_root = Path(__file__).resolve().parent.parent.parent
    results = run_deterministic(repo_root / ".claude" / "skills")
    assert results["totals"]["tests_run"] > 0
    assert results["totals"]["tests_passed"] == results["totals"]["tests_run"]
