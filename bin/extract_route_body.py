#!/usr/bin/env python3
"""Extract closure body from public/index.php (1-based inclusive line range). Strip 8 leading spaces, add 4 for method body."""
import sys
from pathlib import Path

def main() -> None:
    if len(sys.argv) != 4:
        print("Usage: extract_route_body.py <index.php> <start_line> <end_line>", file=sys.stderr)
        sys.exit(1)
    path = Path(sys.argv[1])
    start = int(sys.argv[2])
    end = int(sys.argv[3])
    lines = path.read_text().splitlines(keepends=True)
    chunk = lines[start - 1 : end]
    out: list[str] = []
    for line in chunk:
        if line.strip() == "":
            out.append("\n")
            continue
        stripped = line
        if stripped.startswith("        "):
            stripped = stripped[8:]
        out.append("        " + stripped)
    sys.stdout.write("".join(out))


if __name__ == "__main__":
    main()
