#!/usr/bin/env python3
"""Offline regression probes for the actual release and network-guard workflows.

Run from any cwd: python3 -I tests/workflow_probes.py. Only Python stdlib,
Bash and PHP CLI are required; no vendor install, Git or API is used. Workflow
blocks are extracted strictly, so a structural change requires an explicit
update of this harness rather than silently exercising a stale copy.
"""

from __future__ import annotations

import contextlib
import copy
import io
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import unittest
import urllib.error
import urllib.request
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
TAG = "v0.2.2"
COMMIT = "1" * 40
REPOSITORY = "QrCommunication/sdk-withallo-php"
NOTES = "Visible fixture release notes\n"
SENTINEL = "PRIVATE_FIXTURE_DO_NOT_REFLECT"
IDENTITY_STEP = "Validate tag and exact commit against fresh main"
NOTES_STEP = "Extract unique visible release notes"
API_STEP = "Check existing GitHub Release without changing it"
CREATE_STEP = "Create GitHub Release only when absent"
VERIFY_STEP = "Verify the resulting GitHub Release"
NETWORK_STEP = "Run strict tests and quality checks without external networking"


def no_network(event, arguments):
    """A missed API double must fail, including in fixture worker processes."""
    if event in {"socket.connect", "socket.getaddrinfo", "socket.sendto", "socket.sendmsg"}:
        raise RuntimeError("Real networking is forbidden in workflow probes")


def unique_step(source, name):
    marker = "      - name: " + name + "\n"
    if source.count(marker) != 1:
        raise AssertionError("Expected one workflow step: " + name)
    section = source.split(marker, 1)[1]
    boundary = re.search(r"^(?:      - |  [A-Za-z_][A-Za-z0-9_-]*:)", section, re.M)
    if boundary:
        section = section[:boundary.start()]
    if section.count("        run: |\n") != 1:
        raise AssertionError("Expected one literal run block: " + name)
    raw = section.split("        run: |\n", 1)[1]
    lines = raw.splitlines()
    if any(line.strip() and not line.startswith("          ") for line in lines):
        raise AssertionError("Unexpected run indentation: " + name)
    return "\n".join(line[10:] if line else "" for line in lines).rstrip() + "\n", section


def unique_heredoc(block, opener, delimiter):
    if block.count(opener) != 1:
        raise AssertionError("Expected one heredoc opener")
    tail = block.split(opener, 1)[1]
    matches = list(re.finditer(r"^" + re.escape(delimiter) + r"$", tail, re.M))
    if len(matches) != 1:
        raise AssertionError("Expected one heredoc terminator")
    return tail[:matches[0].start()]


def contracts(root=ROOT):
    release = (root / ".github/workflows/release.yml").read_text(encoding="utf-8")
    checks = (root / ".github/workflows/tests.yml").read_text(encoding="utf-8")
    identity, _ = unique_step(release, IDENTITY_STEP)
    notes, _ = unique_step(release, NOTES_STEP)
    api, api_section = unique_step(release, API_STEP)
    create, create_section = unique_step(release, CREATE_STEP)
    verify, _ = unique_step(release, VERIFY_STEP)
    if "        id: existing\n" not in api_section:
        raise AssertionError("Release inspection output contract changed")
    if "        if: steps.existing.outputs.exists == 'false'\n" not in create_section:
        raise AssertionError("Release creation condition changed")
    modes = []
    for block in (api, create, verify):
        calls = re.findall(r'^python3 "\$RUNNER_TEMP/withallo-release-check\.py" (optional|create|required)$', block, re.M)
        if len(calls) != 1:
            raise AssertionError("Expected one release helper invocation per step")
        modes.append(calls[0])
    if modes != ["optional", "create", "required"]:
        raise AssertionError("Release inspection/create/postcheck order changed")
    api_code = unique_heredoc(api, 'cat > "$RUNNER_TEMP/withallo-release-check.py" <<\'PY\'\n', "PY")
    notes_code = unique_heredoc(notes, "python3 - <<'PY'\n", "PY")
    network, _ = unique_step(checks, NETWORK_STEP)
    php_blocks = re.findall(r"^php <<'PHP'\n(.*?)^PHP$", network, re.M | re.S)
    if len(php_blocks) != 2 or "glob('/tmp/withallo-home/*.network')" not in php_blocks[-1]:
        raise AssertionError("Expected the unique final network scanner after the JUnit scanner")
    return {"identity": identity, "notes": notes_code, "api": api_code,
            "network": php_blocks[-1], "modes": modes}


def private_environment(directory, path=None):
    return {"PATH": path or "/usr/bin:/bin", "HOME": str(directory), "TMPDIR": str(directory),
            "LANG": "C.UTF-8", "LC_ALL": "C.UTF-8", "GITHUB_REF": "refs/tags/" + TAG,
            "GITHUB_SHA": COMMIT, "GITHUB_REPOSITORY": REPOSITORY,
            "GITHUB_OUTPUT": str(directory / "output"), "RUNNER_TEMP": str(directory),
            "RELEASE_VERSION": TAG[1:], "RELEASE_TAG": TAG, "RELEASE_COMMIT": COMMIT,
            "GH_TOKEN": "fixture-only-not-a-credential"}


def execute(command, directory, env, stdin=None):
    return subprocess.run(command, cwd=directory, env=env, input=stdin, text=True,
                          capture_output=True, timeout=15, check=False)


# This double models state and API outcomes, not the workflow's validation logic.
# Its expected payload is a fixture; the code under test comes only from YAML.
class Reply:
    def __init__(self, status, data):
        self.status = status
        self.file = io.BytesIO(json.dumps(data).encode())

    def __enter__(self):
        return self

    def __exit__(self, *arguments):
        return False

    def read(self, *arguments):
        return self.file.read(*arguments)


class ReleaseServer:
    def __init__(self, case):
        self.case = case
        self.expected = {"tag_name": TAG, "target_commitish": COMMIT,
                         "name": "Withallo SDK PHP " + TAG, "body": NOTES,
                         "draft": False, "prerelease": False}
        self.conflict = {"errors": [{"resource": "Release", "field": "tag_name", "code": "already_exists"}]}
        self.current = None
        self.original = None
        self.calls = []
        self.post_seen = False
        if case.startswith("existing-"):
            self.current = copy.deepcopy(self.expected)
            if case == "existing-divergent":
                self.current["body"] = "Preserve operator body"
            if case == "existing-draft":
                self.current["draft"] = True
            self.original = copy.deepcopy(self.current)

    def build(self, *handlers):
        self.handlers = handlers
        return self

    def error(self, url, status, detail=None):
        raise urllib.error.HTTPError(url, status, "fixture", {},
                                     io.BytesIO(json.dumps(detail or {}).encode()))

    def open(self, request, timeout):
        self.calls.append(request.method)
        endpoint = "https://api.github.com/repos/" + REPOSITORY + "/releases"
        if timeout != 30 or request.get_header("Authorization") != "Bearer fixture-only-not-a-credential":
            raise AssertionError("Unexpected API timeout/authentication contract")
        if request.full_url != (endpoint if request.method == "POST" else endpoint + "/tags/" + TAG):
            raise AssertionError("Unexpected API endpoint")
        case = self.case
        if request.method == "GET":
            if case.startswith("get-") and not self.post_seen:
                self.error(request.full_url, int(case[4:]))
            if self.post_seen and case == "postcheck-404":
                self.error(request.full_url, 404)
            if self.post_seen and case == "postcheck-network":
                raise urllib.error.URLError("fixture outcome")
            if self.post_seen and case == "postcheck-mismatch":
                return Reply(200, self.expected | {"body": "Different postcheck body"})
            if self.current is None or case == "race-draft-invisible":
                self.error(request.full_url, 404)
            return Reply(200, self.current)
        if request.method != "POST":
            raise AssertionError("A forbidden update method was attempted")
        self.post_seen = True
        if json.loads(request.data) != self.expected | {"generate_release_notes": False}:
            raise AssertionError("Create payload differs from exact source fixtures")
        if case.startswith("post-") and case[5:].isdigit():
            self.error(request.full_url, int(case[5:]))
        if case == "post-network":
            raise urllib.error.URLError("fixture outcome")
        if case == "post-timeout":
            raise TimeoutError("fixture outcome")
        if case == "post-redirect":
            # Exercise the actual NoRedirect policy passed to the opener.
            self.handlers[0].redirect_request(request, None, 307, "fixture", {}, "https://example.invalid/")
        if case in {"other-422", "mixed-422", "malformed-422"}:
            detail = {"errors": [{"resource": "Release", "field": "name", "code": "invalid"}]}
            if case == "mixed-422":
                detail["errors"] += self.conflict["errors"]
            if case == "malformed-422":
                raise urllib.error.HTTPError(request.full_url, 422, "fixture", {}, io.BytesIO(b"not JSON"))
            self.error(request.full_url, 422, detail)
        if case.startswith("race-"):
            self.current = copy.deepcopy(self.expected)
            if case == "race-divergent":
                self.current.update(body="Preserve concurrent operator body", target_commitish="2" * 40)
            if case in {"race-draft", "race-draft-invisible"}:
                self.current.update(draft=True, body="Preserve concurrent draft")
            if case == "race-prerelease":
                self.current["prerelease"] = True
            self.original = copy.deepcopy(self.current)
            self.error(request.full_url, 422, self.conflict)
        self.current = copy.deepcopy(self.expected)
        if case == "created-mismatch":
            self.current["target_commitish"] = "2" * 40
        return Reply(200 if case == "created-status200" else 201, self.current)


def release_fixture(case, directory, single=None, payload=None, status=200, redirect=False):
    """Run actual helper code in this private worker, never an actual opener."""
    contract = contracts()
    directory.mkdir(parents=True, exist_ok=True)
    (directory / "withallo-release-notes.md").write_text(NOTES, encoding="utf-8")
    output = directory / "output"
    server = ReleaseServer(case)
    if single is not None:
        server.current = payload

        def one_open(request, timeout):
            server.calls.append(request.method)
            if request.method != "GET" or timeout != 30:
                raise AssertionError("Unexpected inspection request")
            if redirect:
                server.handlers[0].redirect_request(request, None, 302, "fixture", {}, "https://example.invalid/")
            if status != 200:
                server.error(request.full_url, status)
            return Reply(200, server.current)

        server.open = one_open
    modes = [single] if single else contract["modes"]
    successful = True
    runs = []
    needed = True
    with mock.patch.dict(os.environ, private_environment(directory), clear=True), \
            mock.patch.object(urllib.request, "build_opener", server.build):
        for mode in modes:
            if mode == "create" and not needed:
                continue
            output.write_text("", encoding="utf-8")
            status_code = 0
            text = io.StringIO()
            try:
                with mock.patch.object(sys, "argv", ["actual-release-helper", mode]), contextlib.redirect_stdout(text):
                    exec(compile(contract["api"], "actual-release-workflow-helper", "exec"), {"__name__": "__main__"})
            except SystemExit as error:
                status_code = 0 if error.code in (None, 0) else 1
            except Exception:
                status_code = 1
            runs.append({"mode": mode, "exit": status_code})
            if status_code:
                successful = False
                break
            if mode == "optional":
                needed = "exists=false\n" in output.read_text(encoding="utf-8")
    return {"accepted": successful, "runs": runs, "calls": server.calls,
            "posts": server.calls.count("POST"),
            "state_preserved": server.original is None or server.current == server.original}


GIT_STUB = r'''import json, os, sys
from pathlib import Path
args = sys.argv[1:]
mode = os.environ.get("FIXTURE_GIT_MODE", "ok")
root = Path(os.environ["RUNNER_TEMP"])
with (root / "git-calls").open("a") as output:
    output.write(json.dumps(args) + "\n")
if args == ["rev-parse", "--verify", "refs/tags/v0.2.2^{commit}"]:
    print(("2" if mode == "tag" else "1") * 40)
elif args == ["rev-parse", "--verify", "HEAD"]:
    print(("2" if mode == "head" else "1") * 40)
elif args == ["fetch", "--no-tags", "--force", "origin", "+refs/heads/main:refs/remotes/origin/main"]:
    if mode == "fetch":
        sys.exit(23)
    (root / "fetched").write_text("yes")
elif args == ["show-ref", "--verify", "--quiet", "refs/remotes/origin/main"]:
    if not (root / "fetched").exists():
        sys.exit(24)
    sys.exit(1 if mode == "missing-main" else 0)
elif args == ["merge-base", "--is-ancestor", "1" * 40, "refs/remotes/origin/main"]:
    sys.exit(1 if mode == "ancestor" else 0)
else:
    sys.exit(25)
'''


class WorkflowProbes(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.code = contracts()
        cls.php = shutil.which("php", path="/usr/bin:/bin")
        cls.bash = shutil.which("bash", path="/usr/bin:/bin")
        if cls.php is None or cls.bash is None:
            raise RuntimeError("PHP CLI and Bash are required; probes must not silently skip")

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="withallo-workflow-probes-")
        self.addCleanup(self.temp.cleanup)
        self.directory = Path(self.temp.name)
        self.env = private_environment(self.directory)

    def worker(self, case, **options):
        config = self.directory / "fixture.json"
        config.write_text(json.dumps({"case": case, **options}), encoding="utf-8")
        result = execute([sys.executable, "-I", str(Path(__file__).resolve()),
                          "--release-fixture", str(config)], self.directory, self.env)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def release_case(self, case, accepted):
        result = self.worker(case)
        self.assertEqual(result["accepted"], accepted, result)
        self.assertLessEqual(result["posts"], 1, result)
        self.assertFalse(set(result["calls"]) & {"PATCH", "PUT", "DELETE"}, result)
        self.assertTrue(result["state_preserved"], result)
        if accepted:
            self.assertEqual(result["runs"][-1]["mode"], "required", result)
        if case == "created":
            self.assertEqual(result["calls"], ["GET", "POST", "GET"])
        if case == "existing-exact":
            self.assertEqual(result["calls"], ["GET", "GET"])
        if case == "race-exact":
            self.assertEqual(result["calls"], ["GET", "POST", "GET", "GET"])

    def identity_case(self, case):
        binary = self.directory / "bin"
        binary.mkdir()
        shim = binary / "git"
        shim.write_text("#!" + sys.executable + "\n" + GIT_STUB, encoding="utf-8")
        shim.chmod(0o700)
        (binary / "python3").symlink_to(sys.executable)
        env = private_environment(self.directory, str(binary) + ":/usr/bin:/bin")
        manifest = {"name": "qrcommunication/withallo-sdk"}
        if case == "wrong-name":
            manifest["name"] = "bad/library"
        elif case == "versioned":
            manifest["version"] = "0.2.2"
        elif case == "leading-zero":
            env["GITHUB_REF"] = "refs/tags/v00.2.2"
        elif case == "prerelease":
            env["GITHUB_REF"] = "refs/tags/v0.2.2-rc.1"
        elif case == "injection":
            env["GITHUB_REF"] = "refs/tags/v0.2.2$(touch SENTINEL)"
        else:
            env["FIXTURE_GIT_MODE"] = case
        (self.directory / "composer.json").write_text(json.dumps(manifest), encoding="utf-8")
        result = execute([self.bash], self.directory, env, self.code["identity"])
        self.assertEqual(result.returncode == 0, case == "ok", result.stderr)
        self.assertFalse((self.directory / "SENTINEL").exists())
        if case == "ok":
            self.assertEqual((self.directory / "output").read_text(),
                             f"tag={TAG}\nversion={TAG[1:]}\ncommit={COMMIT}\n")
            calls = [json.loads(line) for line in (self.directory / "git-calls").read_text().splitlines()]
            self.assertEqual(calls[2][0], "fetch")
            self.assertEqual(calls[-1], ["merge-base", "--is-ancestor", COMMIT, "refs/remotes/origin/main"])

    def notes_case(self, text, accepted):
        (self.directory / "CHANGELOG.md").write_text(text, encoding="utf-8")
        result = execute([sys.executable, "-I", "-c", self.code["notes"]], self.directory, self.env)
        self.assertEqual(result.returncode == 0, accepted, result.stderr)
        notes = self.directory / "withallo-release-notes.md"
        if accepted:
            self.assertTrue(notes.read_text(encoding="utf-8").strip())
        else:
            self.assertFalse(notes.exists())

    def inspection_case(self, status=200, mode="optional", mismatch=None, redirect=False):
        payload = ReleaseServer("created").expected
        if mismatch:
            payload[mismatch[0]] = mismatch[1]
        accepted = not mismatch and not redirect and (status == 200 or status == 404 and mode == "optional")
        result = self.worker("inspection", single=mode, payload=payload, status=status, redirect=redirect)
        self.assertEqual(result["accepted"], accepted, result)
        self.assertEqual(result["calls"], ["GET"])
        self.assertEqual(result["posts"], 0)

    def network_case(self, case, line, fields=None, count=1, label="phpunit"):
        traces = self.directory / "traces"
        traces.mkdir()
        (traces / (label + ".network")).write_text((line + "\n") * count, encoding="utf-8")
        code = self.code["network"].replace("/tmp/withallo-home/", str(traces) + "/")
        result = execute([self.php], self.directory, self.env, code)
        self.assertEqual(result.returncode == 0, case == "empty", result.stderr)
        self.assertNotIn(SENTINEL, result.stdout)
        self.assertNotIn(str(traces), result.stdout)
        if case == "empty":
            self.assertEqual(result.stdout, "No connect/sendto/sendmsg calls during isolated quality checks\n")
            return
        events = [json.loads(record) for record in result.stdout.splitlines()]
        self.assertEqual(len(events), min(count, 20) + 1)
        self.assertEqual(events[-1], {"network_diagnostic_omitted": max(0, count - 20)})
        for event in events[:-1]:
            self.assertEqual(set(event), {"tool", "syscall", "family", "return", "errno"})
            self.assertEqual(event["tool"], "unknown" if label == SENTINEL else label)
            self.assertEqual(tuple(event[key] for key in ("syscall", "family", "return", "errno")), fields)

    def ipc_case(self, traces, allowed, prohibited):
        """Exercise the full scanner, including mixed files and redacted summaries."""
        fixture_root = self.directory / "traces"
        fixture_root.mkdir()
        for label, lines in traces.items():
            (fixture_root / (label + ".network")).write_text("\n".join(lines) + "\n", encoding="utf-8")
        code = self.code["network"].replace("/tmp/withallo-home/", str(fixture_root) + "/")
        result = execute([self.php], self.directory, self.env, code)
        self.assertEqual(result.returncode, 255 if prohibited else 0, result.stderr)
        if prohibited:
            self.assertIn("Unexpected network attempt during isolated quality checks", result.stderr)
        else:
            self.assertEqual(result.stderr, "")
        self.assertNotIn(SENTINEL, result.stdout)
        self.assertNotIn(str(fixture_root), result.stdout)
        self.assertNotIn("sun_path", result.stdout)
        records = [json.loads(line) for line in result.stdout.splitlines() if line.startswith("{")]
        events = [record for record in records if "syscall" in record]
        allowed_counts = [record["allowed_missing_local_ipc_connects"] for record in records
                          if "allowed_missing_local_ipc_connects" in record]
        omissions = [record["network_diagnostic_omitted"] for record in records
                     if "network_diagnostic_omitted" in record]
        self.assertEqual(allowed_counts, [allowed] if allowed else [])
        self.assertEqual(len(events), min(20, prohibited))
        self.assertEqual(omissions, [max(0, prohibited - 20)] if prohibited else [])
        for event in events:
            self.assertEqual(set(event), {"tool", "syscall", "family", "return", "errno"})
            self.assertIn(event["tool"], {"phpunit", "phpstan", "test-phpstan", "pint", "unknown"})
            self.assertIn(event["syscall"], {"connect", "sendto", "sendmsg"})
        if not prohibited:
            self.assertTrue(result.stdout.endswith(
                "No prohibited connect/sendto/sendmsg calls during isolated quality checks\n"))

    def test_extraction_refuses_ambiguous_or_missing_blocks(self):
        source = (ROOT / ".github/workflows/release.yml").read_text(encoding="utf-8")
        with self.assertRaises(AssertionError):
            unique_step(source + "      - name: " + IDENTITY_STEP + "\n", IDENTITY_STEP)
        with self.assertRaises(AssertionError):
            unique_step(source, "A missing step")
        with self.assertRaises(AssertionError):
            unique_heredoc("no heredoc", "<<'PY'\n", "PY")


# Separate unittest cases make the CI count reflect all scenarios, not only loops.
def install_case(name, action):
    def test(self):
        action(self)
    test.__name__ = "test_" + name
    setattr(WorkflowProbes, test.__name__, test)


for identity in ("ok", "tag", "head", "fetch", "missing-main", "ancestor", "wrong-name",
                 "versioned", "leading-zero", "prerelease", "injection"):
    install_case("identity_" + identity.replace("-", "_"), lambda self, case=identity: self.identity_case(case))

for name, text, accepted in (
    ("normal", "## [0.2.2] - 2026-10-16\n\nVisible notes\n", True),
    ("duplicate", "## [0.2.2]\nOne\n## [0.2.2]\nTwo\n", False),
    ("comment", "## [0.2.2]\n<!-- hidden -->\n", False),
    ("unterminated", "## [0.2.2]\n<!-- hidden\n", False),
    ("heading", "## [0.2.2]\n### Changed\n", False),
    ("visible_before_comment", "## [0.2.2]\nVisible\n<!-- unfinished", True),
):
    install_case("notes_" + name, lambda self, text=text, accepted=accepted: self.notes_case(text, accepted))

for api_status in (200, 404, 401, 403, 429, 500):
    install_case("inspect_status_" + str(api_status), lambda self, status=api_status: self.inspection_case(status=status))
install_case("inspect_required_404", lambda self: self.inspection_case(status=404, mode="required"))
for name, value in (("body", "Different"), ("target_commitish", "2" * 40), ("draft", True),
                    ("prerelease", True), ("draft", 0), ("prerelease", None)):
    install_case("inspect_mismatch_" + name + "_" + str(value),
                 lambda self, name=name, value=value: self.inspection_case(mismatch=(name, value)))
install_case("inspect_redirect", lambda self: self.inspection_case(redirect=True))

RELEASE_CASES = [("created", True), ("existing-exact", True), ("existing-divergent", False),
                 ("existing-draft", False), ("race-exact", True), ("race-divergent", False),
                 ("race-draft", False), ("race-draft-invisible", False), ("race-prerelease", False),
                 ("other-422", False), ("mixed-422", False), ("malformed-422", False),
                 ("created-status200", False), ("created-mismatch", False), ("postcheck-404", False),
                 ("postcheck-network", False), ("postcheck-mismatch", False), ("post-network", False),
                 ("post-timeout", False), ("post-redirect", False)]
RELEASE_CASES += [("post-" + str(status), False) for status in (401, 403, 404, 429, 500, 503)]
RELEASE_CASES += [("get-" + str(status), False) for status in (401, 403, 429, 500)]
for release_case, accepted in RELEASE_CASES:
    install_case("release_" + release_case.replace("-", "_"),
                 lambda self, case=release_case, accepted=accepted: self.release_case(case, accepted))

for name, line, fields, count, label in (
    ("empty", "", None, 1, "phpunit"),
    ("inet", f'123 connect(4, {{sa_family=AF_INET, sin_addr="{SENTINEL}"}}, 16) = -1 ECONNREFUSED (fixture)',
     ("connect", "AF_INET", -1, "ECONNREFUSED"), 1, "phpunit"),
    ("inet6", "123 connect(4, {sa_family=AF_INET6}, 28) = 0", ("connect", "AF_INET6", 0, "unknown"), 1, "phpunit"),
    ("unix", f'123 sendto(4, "{SENTINEL}", 8, 0, {{sa_family=AF_UNIX, sun_path="/{SENTINEL}"}}, 16) = -1 ENOENT (fixture)',
     ("sendto", "AF_UNIX", -1, "ENOENT"), 1, "phpunit"),
    ("netlink", f'123 sendmsg(4, {{msg_name={{sa_family=AF_NETLINK}}, msg_iov="{SENTINEL}"}}, 0) = 32',
     ("sendmsg", "AF_NETLINK", 32, "unknown"), 1, "phpunit"),
    ("unknown", f'123 connect(4, {{sa_family=AF_SECRET_{SENTINEL}}}, 16) = ? {SENTINEL}',
     ("connect", "unknown", "unknown", "unknown"), 1, "phpunit"),
    ("unfinished", f'123 sendmsg(4, "{SENTINEL}", 0 <unfinished ...>',
     ("sendmsg", "unknown", "unknown", "unknown"), 1, "phpunit"),
    ("cap20", f'123 connect(4, {{sa_family=AF_UNIX, sun_path="/{SENTINEL}"}}, 16) = -1 ENOENT (fixture)',
     ("connect", "AF_UNIX", -1, "ENOENT"), 27, SENTINEL),
):
    install_case("network_" + name, lambda self, name=name, line=line, fields=fields, count=count, label=label:
                 self.network_case(name, line, fields, count, label))



# Complete records use the actual C-locale strace grammar. The pathname is a
# fixture; none of these records opens a socket. Near misses must stay refused.
IPC_LINE = ('123 connect(4, {sa_family=AF_UNIX, sun_path="/' + SENTINEL
            + '"}, 110) = -1 ENOENT (No such file or directory)')
IPC_CASES = [
    ("complete_pathname", IPC_LINE, True),
    ("without_pid", IPC_LINE.removeprefix("123 "), True),
    ("fd_zero", IPC_LINE.replace("connect(4,", "connect(0,"), True),
    ("fd_max", IPC_LINE.replace("connect(4,", "connect(2147483647,"), True),
    ("pid_max", IPC_LINE.replace("123 connect", "2147483647 connect"), True),
    ("length_min", IPC_LINE.replace("}, 110)", "}, 3)"), True),
    ("length_max", IPC_LINE, True),
    ("path_max", IPC_LINE.replace("/" + SENTINEL, "/" + "x" * 106), True),
    ("unicode_bytes_max", IPC_LINE.replace("/" + SENTINEL, "/" + "é" * 53), True),
    ("whitespace", "\t" + IPC_LINE + " \t", True),
    ("inet_enoent", IPC_LINE.replace("sa_family=AF_UNIX", "sa_family=AF_INET"), False),
    ("inet6_enoent", IPC_LINE.replace("sa_family=AF_UNIX", "sa_family=AF_INET6"), False),
    ("netlink_enoent", IPC_LINE.replace("sa_family=AF_UNIX", "sa_family=AF_NETLINK"), False),
    ("unknown_family", IPC_LINE.replace("sa_family=AF_UNIX", "sa_family=AF_UNKNOWN"), False),
    ("sendto_unix", IPC_LINE.replace("connect(", "sendto("), False),
    ("sendmsg_unix", IPC_LINE.replace("connect(", "sendmsg("), False),
    ("unix_success", IPC_LINE.replace("-1 ENOENT (No such file or directory)", "0"), False),
    ("unix_eacces", IPC_LINE.replace("-1 ENOENT (No such file or directory)", "-1 EACCES (Permission denied)"), False),
    ("unix_connrefused", IPC_LINE.replace("-1 ENOENT (No such file or directory)", "-1 ECONNREFUSED (Connection refused)"), False),
    ("wrong_c_message", IPC_LINE.replace("No such file or directory", "fixture"), False),
    ("missing_c_message", IPC_LINE.replace(" (No such file or directory)", ""), False),
    ("fd_overflow", IPC_LINE.replace("connect(4,", "connect(2147483648,"), False),
    ("pid_overflow", IPC_LINE.replace("123 connect", "2147483648 connect"), False),
    ("length_underflow", IPC_LINE.replace("}, 110)", "}, 2)"), False),
    ("length_overflow", IPC_LINE.replace("}, 110)", "}, 111)"), False),
    ("empty_path", IPC_LINE.replace("/" + SENTINEL, ""), False),
    ("path_overflow", IPC_LINE.replace("/" + SENTINEL, "/" + "x" * 107), False),
    ("unicode_bytes_overflow", IPC_LINE.replace("/" + SENTINEL, "/" + "é" * 54), False),
    ("abstract_path", IPC_LINE.replace('sun_path="', 'sun_path=@"'), False),
    ("escaped_path", IPC_LINE.replace("/" + SENTINEL, "/" + SENTINEL + r"\x00"), False),
    ("control_in_path", IPC_LINE.replace("/" + SENTINEL, "/" + SENTINEL + "\t"), False),
    ("truncated_path", IPC_LINE.replace('"}, 110)', '"...}, 110)'), False),
    ("injected_family", IPC_LINE.replace("sa_family=AF_UNIX", "sa_family=AF_INET, pretend=AF_UNIX"), False),
    ("injected_result", IPC_LINE + ' 0 "-1 ENOENT (No such file or directory)"', False),
    ("injected_errno_in_path", IPC_LINE.replace("/" + SENTINEL, "/" + SENTINEL + " -1 ENOENT")
     .replace("= -1 ENOENT (No such file or directory)", "= 0"), False),
    ("extra_sockaddr_field", IPC_LINE.replace('"}, 110)', '", pretend=AF_UNIX}, 110)'), False),
    ("unfinished", IPC_LINE.replace(") = -1 ENOENT (No such file or directory)", " <unfinished ...>"), False),
    ("resumed", "123 <... connect resumed>) = -1 ENOENT (No such file or directory)", False),
    ("sendto_resumed", "123 <... sendto resumed>) = -1 ENOENT (No such file or directory)", False),
    ("sendmsg_resumed", "123 <... sendmsg resumed>) = -1 ENOENT (No such file or directory)", False),
    ("interleaved_suffix", IPC_LINE + " <... connect resumed>", False),
]
for ipc_name, ipc_line, permitted in IPC_CASES:
    install_case("ipc_" + ipc_name,
                 lambda self, line=ipc_line, permitted=permitted:
                 self.ipc_case({"phpunit": [line]}, int(permitted), int(not permitted)))

install_case("ipc_mixed_records", lambda self: self.ipc_case({
    "phpunit": [IPC_LINE, IPC_LINE.replace("AF_UNIX", "AF_INET")],
}, 1, 1))
install_case("ipc_interleaved_pair", lambda self: self.ipc_case({
    "phpunit": [IPC_LINE.replace(") = -1 ENOENT (No such file or directory)", " <unfinished ...>"),
                "123 <... connect resumed>) = -1 ENOENT (No such file or directory)"],
}, 0, 2))
install_case("ipc_mixed_files_global_cap", lambda self: self.ipc_case({
    "phpstan": [IPC_LINE] * 25,
    SENTINEL: [IPC_LINE.replace("AF_UNIX", "AF_INET")] * 27,
}, 25, 27))


def main():
    sys.addaudithook(no_network)
    if len(sys.argv) == 3 and sys.argv[1] == "--release-fixture":
        config = Path(sys.argv[2])
        options = json.loads(config.read_text(encoding="utf-8"))
        case = options.pop("case")
        print(json.dumps(release_fixture(case, config.parent, **options)))
        return
    unittest.main(verbosity=2)


if __name__ == "__main__":
    main()
