#!/usr/bin/env python3
"""Create a metadata-normalized gzip tarball for the Nextcloud app release."""

from __future__ import annotations

import gzip
import os
import sys
import tarfile
from pathlib import Path


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: create-release-archive.py STAGING_PARENT OUTPUT_ARCHIVE")

    stage = Path(sys.argv[1]).resolve()
    output = Path(sys.argv[2]).resolve()
    app = stage / "apple_photos_connector"
    if not app.is_dir():
        raise SystemExit("staging parent must contain apple_photos_connector/")

    source_date_epoch = int(os.environ.get("SOURCE_DATE_EPOCH", "0"))
    if source_date_epoch < 0:
        raise SystemExit("SOURCE_DATE_EPOCH must be non-negative")

    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open("wb") as raw, gzip.GzipFile(filename="", mode="wb", fileobj=raw, mtime=0, compresslevel=9) as zipped:
        with tarfile.open(fileobj=zipped, mode="w", format=tarfile.USTAR_FORMAT) as archive:
            paths = [app, *sorted(app.rglob("*"), key=lambda path: path.relative_to(stage).as_posix())]
            for path in paths:
                if path.is_symlink():
                    raise SystemExit(f"release package contains a symlink: {path.relative_to(stage)}")
                relative = path.relative_to(stage).as_posix()
                info = archive.gettarinfo(str(path), arcname=relative)
                info.uid = 0
                info.gid = 0
                info.uname = ""
                info.gname = ""
                info.mtime = source_date_epoch
                info.pax_headers = {}
                if info.isfile():
                    with path.open("rb") as content:
                        archive.addfile(info, content)
                else:
                    archive.addfile(info)


if __name__ == "__main__":
    main()
