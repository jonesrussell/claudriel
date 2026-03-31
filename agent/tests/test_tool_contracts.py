"""Contract tests: every tool module has a valid TOOL_DEF + execute(), no sibling imports.

CI runs this against the real ``claudriel_agent.tools`` package. Network is not used;
tools are not executed against a live API here.
"""

from __future__ import annotations

import ast
import importlib
import inspect
from pathlib import Path
from typing import Any, get_origin

from claudriel_agent.tools_discovery import discover_tools


def _tools_package_dir() -> Path:
    return Path(__file__).resolve().parent.parent / "claudriel_agent" / "tools"


def _parse_tool_modules_ast() -> list[tuple[str, ast.Module]]:
    out: list[tuple[str, ast.Module]] = []
    tools_dir = _tools_package_dir()
    for path in sorted(tools_dir.glob("*.py")):
        if path.name == "__init__.py":
            continue
        source = path.read_text(encoding="utf-8")
        out.append((path.stem, ast.parse(source, filename=str(path))))
    return out


def _collect_sibling_tool_import_violations(tree: ast.AST, filename: str) -> list[str]:
    """Tool modules must not import other ``claudriel_agent.tools.*`` modules."""
    violations: list[str] = []

    for node in ast.walk(tree):
        if isinstance(node, ast.ImportFrom):
            mod = node.module
            if mod == "claudriel_agent.tools":
                for alias in node.names:
                    if alias.name != "*":
                        violations.append(
                            f"{filename}: from claudriel_agent.tools import {alias.name}"
                        )
            elif mod is not None and mod.startswith("claudriel_agent.tools."):
                violations.append(f"{filename}: from {mod} import ...")
        elif isinstance(node, ast.Import):
            for alias in node.names:
                name = alias.name
                prefix = "claudriel_agent.tools."
                if name.startswith(prefix):
                    rest = name[len(prefix) :]
                    if rest and not rest.startswith("__"):
                        violations.append(f"{filename}: import {name}")

    return violations


def _tool_def_ok(tool_def: dict[str, Any], tool_name: str) -> None:
    assert (
        isinstance(tool_def.get("name"), str) and tool_def["name"].strip()
    ), f"{tool_name}: TOOL_DEF['name'] must be a non-empty string"
    assert isinstance(
        tool_def.get("description"), str
    ), f"{tool_name}: TOOL_DEF['description'] must be str"
    schema = tool_def.get("input_schema")
    assert isinstance(schema, dict), f"{tool_name}: TOOL_DEF['input_schema'] must be a dict"
    assert schema.get("type") == "object", f"{tool_name}: input_schema['type'] must be 'object'"


def _return_annotation_ok(annotation: Any) -> bool:
    if annotation is inspect.Signature.empty:
        return True
    if annotation is dict:
        return True
    origin = get_origin(annotation)
    if origin is dict:
        return True
    # typing.Any, object — permissive for adapter tools
    if getattr(annotation, "__name__", None) in ("Any", "object"):
        return True
    return str(annotation) in ("typing.Any", "typing.Dict[str, typing.Any]", "dict[str, Any]")


def test_tool_module_stem_matches_tool_def_name() -> None:
    """TOOL_DEF['name'] must equal the module file stem (grep-friendly)."""
    tools_dir = _tools_package_dir()
    for path in sorted(tools_dir.glob("*.py")):
        if path.name == "__init__.py":
            continue
        mod = importlib.import_module(f"claudriel_agent.tools.{path.stem}")
        tool_def = getattr(mod, "TOOL_DEF", None)
        execute = getattr(mod, "execute", None)
        if tool_def is None or execute is None:
            continue
        name = tool_def.get("name")
        assert (
            name == path.stem
        ), f"{path.name}: TOOL_DEF['name'] must equal module stem, got {name!r}"


def test_all_discovered_tools_match_contract() -> None:
    tool_defs, executors = discover_tools(
        package_name="claudriel_agent.tools",
        package_path=_tools_package_dir(),
    )
    assert tool_defs, "expected at least one tool"
    assert len(tool_defs) == len(executors)

    for tool_def in tool_defs:
        name = tool_def["name"]
        assert isinstance(name, str)
        _tool_def_ok(tool_def, name)

        execute = executors[name]
        sig = inspect.signature(execute)
        params = list(sig.parameters.values())
        assert len(params) == 2, f"{name}: execute() must take exactly (api, args)"

        assert not inspect.iscoroutinefunction(execute), f"{name}: execute() must be synchronous"

        ret = sig.return_annotation
        assert _return_annotation_ok(
            ret
        ), f"{name}: execute() return annotation must be dict-like or omitted"


def test_tool_modules_do_not_import_sibling_tools() -> None:
    all_violations: list[str] = []
    for stem, tree in _parse_tool_modules_ast():
        all_violations.extend(_collect_sibling_tool_import_violations(tree, f"{stem}.py"))
    assert not all_violations, "Sibling tool imports:\n" + "\n".join(all_violations)


def _minimal_args_from_schema(schema: dict[str, Any]) -> dict[str, Any]:
    """Build args that satisfy JSON Schema ``required`` keys for tool contract smoke calls."""
    required = schema.get("required")
    if not isinstance(required, list):
        required = []
    props = schema.get("properties")
    if not isinstance(props, dict):
        props = {}
    out: dict[str, Any] = {}
    for key in required:
        if not isinstance(key, str):
            continue
        p = props.get(key, {})
        if not isinstance(p, dict):
            p = {}
        t = p.get("type")
        if t == "integer":
            out[key] = 0
        elif t == "number":
            out[key] = 0.0
        elif t == "boolean":
            out[key] = False
        elif t == "array":
            out[key] = []
        elif t == "object":
            out[key] = {}
        else:
            out[key] = ""
    return out


def test_tool_modules_do_not_call_emit() -> None:
    """Tools must not emit JSONL; only loop/emit drive stdout protocol."""
    for stem, tree in _parse_tool_modules_ast():
        for node in ast.walk(tree):
            if isinstance(node, ast.Call) and isinstance(node.func, ast.Name):
                if node.func.id == "emit":
                    raise AssertionError(f"{stem}.py must not call emit(); use return dict only")
            if isinstance(node, ast.ImportFrom):
                mod = node.module or ""
                if mod == "claudriel_agent.emit" or mod.endswith(".emit"):
                    raise AssertionError(f"{stem}.py must not import emit module")


def test_execute_returns_dict_with_stub_api() -> None:
    from unittest.mock import MagicMock

    tools_dir = _tools_package_dir()
    tool_defs, executors = discover_tools(
        package_name="claudriel_agent.tools",
        package_path=tools_dir,
    )
    api = MagicMock()
    api.get.return_value = {}
    api.post.return_value = {}
    api.put.return_value = {}
    api.patch.return_value = {}
    api.delete.return_value = {}

    for tool_def in tool_defs:
        name = tool_def["name"]
        execute = executors[name]
        schema = tool_def.get("input_schema")
        assert isinstance(schema, dict)
        args = _minimal_args_from_schema(schema)
        try:
            result = execute(api, args)
        except Exception as e:
            raise AssertionError(f"{name}: execute(api, {args!r}) raised: {e}") from e
        assert isinstance(
            result, dict
        ), f"{name}: execute must return dict, got {type(result).__name__}"
