from __future__ import annotations

import sys

from .worker import Worker


def main() -> int:
    if "--healthcheck" in sys.argv:
        Worker().healthcheck()
        return 0

    Worker().run_forever()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
