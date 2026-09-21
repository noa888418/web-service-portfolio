"""Exercise real Git hooks in a disposable repository, never committing dummy secrets."""

import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile


ROOT = Path(__file__).resolve().parents[1]


def run(repo, *command):
    result = subprocess.run(command, cwd=repo, capture_output=True, text=True,
                            encoding="utf-8", errors="replace")
    return result.returncode, result.stdout + result.stderr


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def main():
    original_index = run(ROOT, "git", "ls-files", "--stage")
    original_head = run(ROOT, "git", "rev-parse", "--verify", "HEAD")
    files = subprocess.check_output(
        ["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd=ROOT,
    )
    with tempfile.TemporaryDirectory(prefix="portfolio-hook-test-") as folder:
        repo = Path(folder).resolve()
        for raw in set(files.split(b"\0")) - {b""}:
            relative = Path(os.fsdecode(raw))
            source = ROOT / relative
            if source.is_file() and not source.is_symlink():
                destination = repo / relative
                destination.parent.mkdir(parents=True, exist_ok=True)
                shutil.copyfile(source, destination)
        shutil.copytree(ROOT / ".tools/gitleaks", repo / ".tools/gitleaks")
        require(run(repo, "git", "init", "--quiet")[0] == 0, "Temporary git init failed")
        for key, value in (("user.name", "Local hook verification"),
                           ("user.email", "hook-test@example.invalid"),
                           ("commit.gpgsign", "false"), ("core.autocrlf", "false")):
            require(run(repo, "git", "config", key, value)[0] == 0, "Temporary Git config failed")
        command = (sys.executable, "-I", "scripts/secrets.py")
        require(run(repo, *command, "install-hook")[0] == 0, "Hook installation failed")
        hook = repo / ".git/hooks/pre-commit"
        registered_hook = hook.read_bytes()
        require(run(repo, *command, "install-hook")[0] == 0, "Idempotent registration failed")
        require(run(repo, "git", "add", "--all")[0] == 0, "Clean file staging failed")
        code, _ = run(repo, "git", "commit", "--quiet", "-m", "Clean temporary fixture")
        require(code == 0, "Clean temporary commit was blocked")
        clean_head = run(repo, "git", "rev-parse", "HEAD")
        require(run(repo, *command, "history")[0] == 0, "Clean history scan failed")
        print("PASS: copied current files, clean commit through registered hook, history scan")

        ignored = [
            ".env", ".env.local", "nested/.env.production", "terraform.tfstate",
            "nested/terraform.tfstate.backup", "prod.tfvars", "prod.tfvars.json",
            "prod.tfvars.bak", "prod.tfvars.json.bak", "nested/.terraform/providers/cache",
            "saved.tfplan", "saved.tfplan.json", "prod.plan", "prod.plan.json",
            "tfplan", "tfplan.json", "plan.out", "private.pem", "credentials.json",
        ]
        allowed = [
            ".env.example", "nested/.env.example", ".env.production.example",
            "prod.tfvars.example", "prod.tfvars.json.example", ".terraform.lock.hcl",
            "nested/.terraform.lock.hcl", "main.tf",
        ]
        for path in ignored + allowed:
            code, _ = run(repo, "git", "check-ignore", "--no-index", "--quiet", path)
            require(code == (0 if path in ignored else 1), f"Unexpected ignore result: {path}")
        print(f"PASS: {len(ignored)} ignored paths and {len(allowed)} trackable examples/lock/source paths")

        # A structurally matching but never-issued token; never contact any service with it.
        dummy = "ghp_" + "aB3dE6gH9jK2mN5pQ8sT1vW4yZ7cF0iL3oR6"
        suspect = repo / "temporary-fixture.txt"
        suspect.write_text("access_token=" + dummy + "\n", encoding="utf-8")
        require(run(repo, "git", "add", "temporary-fixture.txt")[0] == 0, "Dummy staging failed")
        suspect.write_text("No secret in the working file.\n", encoding="utf-8")
        code, output = run(repo, "git", "commit", "--quiet", "-m", "Must be blocked")
        require(code != 0, "Staged dummy secret was committed")
        require("github-pat" in output and "REDACTED" in output, "Expected redacted rule finding missing")
        require(dummy not in output, "Dummy secret leaked into scan output")
        require(run(repo, "git", "rev-parse", "HEAD") == clean_head, "HEAD changed after rejected commit")
        print("PASS: staged-only dummy detected as github-pat; commit blocked; HEAD unchanged; values redacted")

        require(run(repo, "git", "add", "temporary-fixture.txt")[0] == 0, "Clean restaging failed")
        require(run(repo, *command, "staged")[0] == 0, "Corrected staged contents did not pass")
        # Missing scanner must stop even a clean commit.
        config = json.loads((repo / "config/gitleaks.json").read_text(encoding="utf-8"))
        name = "gitleaks.exe" if os.name == "nt" else "gitleaks"
        binary = next((repo / ".tools/gitleaks" / config["version"]).rglob(name))
        saved = binary.with_suffix(".saved")
        binary.rename(saved)
        code, output = run(repo, "git", "commit", "--quiet", "-m", "Missing tool must block")
        saved.rename(binary)
        require(code != 0 and "Gitleaks is missing" in output, "Missing tool did not block commit")
        require(run(repo, "git", "rev-parse", "HEAD") == clean_head, "Missing-tool commit changed HEAD")
        print("PASS: clean restaging passes; missing scanner blocks commit")

        existing = b"#!/bin/sh\nexit 17\n"
        hook.write_bytes(existing)
        require(run(repo, *command, "install-hook")[0] != 0, "Existing hook was not rejected")
        require(hook.read_bytes() == existing, "Existing hook was overwritten")
        hook.write_bytes(registered_hook)
        require(run(repo, "git", "config", "core.hooksPath", "custom-hooks")[0] == 0, "hooksPath setup failed")
        require(run(repo, *command, "install-hook")[0] != 0, "Custom hooksPath was not preserved")
        require(hook.read_bytes() == registered_hook, "Hook changed with custom hooksPath")
        print("PASS: existing hook and core.hooksPath preserved")
    require(run(ROOT, "git", "ls-files", "--stage") == original_index, "Main repository index changed")
    require(run(ROOT, "git", "rev-parse", "--verify", "HEAD") == original_head, "Main repository HEAD changed")
    print("PASS: temporary repository removed; main repository index and HEAD unchanged")


if __name__ == "__main__":
    main()
