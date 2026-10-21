#!/usr/bin/env bash
set -Eeuo pipefail

cd -- "$(dirname -- "${BASH_SOURCE[0]}")"
if command -v python3.12 >/dev/null 2>&1; then
    project_python=python3.12
elif command -v python3 >/dev/null 2>&1; then
    project_python=python3
else
    printf '%s\n' 'Install Python 3.12 and its venv module first. See RUN_PROJECT.md.' >&2
    exit 1
fi

# Keep subprocess arguments and .env values out of shell evaluation.
exec "$project_python" - "$@" <<'PYTHON'
import argparse
import fcntl
import json
import os
import platform
import re
import shutil
import signal
import socket
import struct
import subprocess
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(prog="run_project.sh", description="Install and run Document Lab locally on Linux.")
    parser.add_argument("--web-port", type=int, default=8001)
    parser.add_argument("--ai-port", type=int, default=8000)
    parser.add_argument("--skip-install", action="store_true", help="Skip Composer/npm/pip/build after a successful installation.")
    parser.add_argument("--no-browser", action="store_true")
    parser.add_argument("--install-system-deps", action="store_true", help="Install PHP/Composer, venv and shared OS libraries using sudo apt on Ubuntu 24.04 or derivatives based on noble (Python 3.12 and supported Node must already be available).")
    options = parser.parse_args()
    if sys.platform != "linux":
        raise RuntimeError("This launcher requires Linux. Use run_project.bat on Windows.")
    if sys.version_info[:2] != (3, 12) or struct.calcsize("P") != 8:
        raise RuntimeError("Install Python 3.12 64-bit and python3.12-venv first.")
    if platform.machine().lower() not in {"x86_64", "amd64"}:
        raise RuntimeError("This dependency profile targets Linux x86_64. Other architectures need a compatible requirements profile.")
    libc_name, libc_version = platform.libc_ver()
    if libc_name != "glibc" or tuple(map(int, libc_version.split(".")[:2])) < (2, 28):
        raise RuntimeError("The Linux CPU wheels require glibc 2.28+. Alpine/musl needs a different dependency profile.")
    if os.geteuid() == 0:
        raise RuntimeError("Run this launcher as your normal user, without sudo.")
    if not all(1024 <= port <= 65535 for port in (options.web_port, options.ai_port)):
        raise RuntimeError("Ports must be between 1024 and 65535.")
    if options.web_port == options.ai_port:
        raise RuntimeError("Web and AI ports must be different.")

    root = Path.cwd()
    env = os.environ.copy()
    children = []
    log_handles = []
    # One launcher per checkout, also when a second invocation chooses other ports.
    lock = (root / ".run-project.lock").open("a")
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError as error:
        raise RuntimeError("Another run_project.sh is already running in this checkout.") from error

    def run(arguments, capture=False):
        result = subprocess.run(
            [str(argument) for argument in arguments],
            cwd=root,
            env=env,
            stdin=subprocess.DEVNULL,
            stdout=subprocess.PIPE if capture else None,
            text=True,
            check=True,
        )
        return result.stdout.strip() if capture else None

    def stop_signal(signum, _frame):
        raise KeyboardInterrupt

    def spawn(name, arguments, directory):
        log_path = root / "storage" / "logs" / f"run-project-{name}.log"
        handle = log_path.open("ab", buffering=0)
        log_handles.append(handle)
        child = subprocess.Popen(
            [str(argument) for argument in arguments], cwd=directory, env=env,
            stdin=subprocess.DEVNULL, stdout=handle, stderr=subprocess.STDOUT,
            start_new_session=True,
        )
        children.append((name, child, log_path))
        print(f"Started {name} (PID {child.pid}); log: {log_path}", flush=True)

    def check_children():
        for name, child, log_path in children:
            if child.poll() is not None:
                raise RuntimeError(f"{name} exited ({child.returncode}). Read {log_path}")

    try:
        signal.signal(signal.SIGTERM, stop_signal)
        signal.signal(signal.SIGINT, stop_signal)
        for port in (options.web_port, options.ai_port):
            with socket.socket() as listener:
                try:
                    listener.bind(("127.0.0.1", port))
                except OSError as error:
                    raise RuntimeError(f"Port {port} is occupied. Stop the old service or select another port.") from error

        env_path = root / ".env"
        if not env_path.exists():
            shutil.copyfile(root / ".env.example", env_path)
            env_path.chmod(0o600)
        settings = {}
        for line in env_path.read_text(encoding="utf-8-sig").splitlines():
            match = re.fullmatch(r"\s*([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*?)\s*", line)
            if match:
                value = match[2]
                if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
                    value = value[1:-1]
                settings[match[1]] = value
        if env.get("APP_ENV") == "production" or settings.get("APP_ENV") == "production":
            raise RuntimeError("This launcher is for local development, not production.")
        if options.install_system_deps:
            release = platform.freedesktop_os_release()
            ubuntu_2404 = release.get("ID") == "ubuntu" and release.get("VERSION_ID") == "24.04"
            noble_derivative = release.get("UBUNTU_CODENAME") == "noble" and "ubuntu" in release.get("ID_LIKE", "").split()
            if not (ubuntu_2404 or noble_derivative):
                raise RuntimeError("--install-system-deps supports Ubuntu 24.04 and derivatives based on noble. Install equivalent OS packages manually on other distributions.")
            if shutil.which("sudo") is None or shutil.which("apt-get") is None:
                raise RuntimeError("sudo and apt-get are required for --install-system-deps.")
            print("Installing Ubuntu packages; sudo may request your Linux password...", flush=True)
            # sudo reads the password from the terminal; application setup stays unprivileged.
            subprocess.run(["sudo", "apt-get", "update"], check=True)
            subprocess.run([
                "sudo", "apt-get", "install", "-y", "php8.3-cli", "php8.3-curl",
                "php8.3-mbstring", "php8.3-xml", "php8.3-zip", "php8.3-gd",
                "php8.3-sqlite3", "php8.3-mysql", "composer", "python3.12-venv",
                "curl", "git", "unzip", "libgl1", "libglib2.0-0t64", "libgomp1",
            ], check=True)
        for tool in ("php", "composer", "node", "npm"):
            if shutil.which(tool) is None:
                raise RuntimeError(f"Install {tool} and add it to PATH. See RUN_PROJECT.md.")
        run(["php", "-r", 'if (PHP_VERSION_ID < 80300) { fwrite(STDERR, "PHP 8.3+ required.\n"); exit(1); } foreach (["curl", "dom", "fileinfo", "gd", "mbstring", "openssl", "pdo", "xml", "xmlwriter", "zip"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Enable PHP extension: ".$ext."\n"); exit(1); } }'])
        node_version = run(["node", "-p", "process.versions.node"], capture=True)
        major, minor = map(int, node_version.split(".")[:2])
        if not ((major == 20 and minor >= 19) or (major >= 22 and (major != 22 or minor >= 12))):
            raise RuntimeError("Vite requires Node 20.19+ or 22.12+; Node 21 is unsupported.")

        for key, value in settings.items():
            if re.fullmatch(r"OCR_PROVIDER|AI_OCR_PROVIDER_STATE_PATH|AI_MAX_CONCURRENT_JOBS|AI_LOCAL_OCR_WORKERS|FPT_AI_READER_.*|VNPT_EKYC_OCR_.*|EKYC_OCR_.*", key):
                env.setdefault(key, value)
        env.update({
            "APP_URL": f"http://127.0.0.1:{options.web_port}",
            "AI_ENGINE_URL": f"http://127.0.0.1:{options.ai_port}",
            "APP_FORCE_HTTPS": "false", "SESSION_SECURE_COOKIE": "false",
            "PYTHONUTF8": "1", "PYTHONIOENCODING": "utf-8",
            "PHP_CLI_SERVER_WORKERS": "1",
        })
        env.setdefault("AI_MAX_CONCURRENT_JOBS", "2")
        env.setdefault("AI_LOCAL_OCR_WORKERS", "2")
        connection = env.get("DB_CONNECTION", settings.get("DB_CONNECTION", "sqlite"))
        if connection == "sqlite":
            run(["php", "-r", 'if (!extension_loaded("pdo_sqlite")) { fwrite(STDERR, "Enable pdo_sqlite.\n"); exit(1); }'])
            database = env.get("DB_DATABASE") or settings.get("DB_DATABASE") or str(root / "database" / "database.sqlite")
            if database == ":memory:":
                raise RuntimeError("Use a persistent SQLite database, not :memory:.")
            database_path = Path(database)
            if not database_path.is_absolute():
                database_path = root / database_path
            if not database_path.exists():
                database_path.parent.mkdir(parents=True, exist_ok=True)
                database_path.touch(mode=0o600, exist_ok=False)
            env["DB_CONNECTION"] = "sqlite"
            env["DB_DATABASE"] = str(database_path)

        python = root / "ai-engine" / ".venv-linux" / "bin" / "python"
        if not options.skip_install:
            print("[1/4] Installing PHP packages from composer.lock...", flush=True)
            run(["composer", "install", "--prefer-dist", "--no-interaction"])
            run(["composer", "check-platform-reqs", "--no-interaction"])
            print("[2/4] Installing JavaScript packages and building assets...", flush=True)
            run(["npm", "ci" if (root / "package-lock.json").exists() else "install"])
            run(["npm", "run", "build"])
            print("[3/4] Installing Python CPU packages in ai-engine/.venv-linux...", flush=True)
            if not python.is_file():
                run([sys.executable, "-m", "venv", python.parent.parent])
            run([python, "-m", "pip", "install", "--upgrade", "pip", "setuptools==80.10.2", "wheel"])
            run([python, "-m", "pip", "install", "-r", "ai-engine/requirements-linux.lock.txt"])
            run([python, "-m", "pip", "check"])
        else:
            for path in (root / "vendor" / "autoload.php", root / "public" / "build" / "manifest.json", python):
                if not path.is_file():
                    raise RuntimeError(f"Missing {path}. Run without --skip-install first.")
        (root / "public" / "hot").unlink(missing_ok=True)

        print("[4/4] Preparing database and starting services...", flush=True)
        run(["php", "artisan", "config:clear", "--no-interaction"])
        if not (env.get("APP_KEY") or settings.get("APP_KEY")):
            run(["php", "artisan", "key:generate", "--no-interaction"])
        run(["php", "artisan", "migrate", "--no-interaction"])
        user_count = run(["php", "-r", 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo App\\Models\\User::query()->count();'], capture=True)
        if user_count == "0":
            run(["php", "artisan", "db:seed", "--no-interaction"])
            print("Demo accounts created. Credentials are listed in RUN_PROJECT.md.", flush=True)

        (root / "storage" / "logs").mkdir(parents=True, exist_ok=True)
        spawn("ai", [python, "-m", "uvicorn", "app:app", "--host", "127.0.0.1", "--port", options.ai_port], root / "ai-engine")
        spawn("laravel", ["php", "artisan", "serve", "--host=127.0.0.1", f"--port={options.web_port}", "--tries=1", "--no-reload", "--no-interaction"], root)
        spawn("scheduler", ["php", "artisan", "schedule:work", "--no-interaction"], root)
        # Loopback requests must not be sent to an HTTP proxy from the host environment.
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        deadline = time.monotonic() + 180
        while True:
            check_children()
            try:
                with opener.open(env["AI_ENGINE_URL"] + "/health", timeout=2) as response:
                    healthy = json.load(response).get("status") == "ok"
                with opener.open(env["APP_URL"] + "/up", timeout=2) as response:
                    healthy = healthy and response.status == 200
                if healthy:
                    break
            except (OSError, ValueError, urllib.error.URLError):
                pass
            if time.monotonic() >= deadline:
                raise RuntimeError("Startup timed out. Read storage/logs/run-project-*.log.")
            time.sleep(2)
        print(f"Ready: {env['APP_URL']}\nCtrl+C stops the services started by this launcher.", flush=True)
        print("OCR models download on first use; YuNet is optional. See RUN_PROJECT.md.", flush=True)
        if not options.no_browser and shutil.which("xdg-open") and (env.get("DISPLAY") or env.get("WAYLAND_DISPLAY")):
            subprocess.Popen(["xdg-open", env["APP_URL"]], stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        while True:
            check_children()
            time.sleep(2)
    except KeyboardInterrupt:
        print("\nStopping project services...", flush=True)
    finally:
        signal.signal(signal.SIGINT, signal.SIG_IGN)
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        for _, child, _ in children:
            try:
                os.killpg(child.pid, signal.SIGTERM)
            except ProcessLookupError:
                pass
        deadline = time.monotonic() + 5
        while time.monotonic() < deadline and any(child.poll() is None for _, child, _ in children):
            time.sleep(0.1)
        for _, child, _ in children:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.wait()
        for handle in log_handles:
            handle.close()
        lock.close()


try:
    main()
except (OSError, RuntimeError, subprocess.CalledProcessError) as error:
    print(f"ERROR: {error}", file=sys.stderr)
    sys.exit(1)
PYTHON
