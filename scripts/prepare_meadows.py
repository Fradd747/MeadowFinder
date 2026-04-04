from __future__ import annotations

import argparse
from collections import deque
from contextlib import contextmanager
from concurrent.futures import FIRST_COMPLETED, ThreadPoolExecutor, as_completed, wait
import csv
from datetime import datetime, timezone
import importlib
import io
import json
import os
import pickle
import re
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse, urlunparse
from urllib.request import Request, urlopen
import threading
from dataclasses import dataclass
from pathlib import Path
from time import monotonic, perf_counter, sleep
from typing import Any, Iterable, Sequence, TextIO
import warnings
import xml.etree.ElementTree as ET
import zipfile

import geopandas as gpd
import numpy as np
import osmium
import pandas as pd
import shapely
from osmium.filter import KeyFilter
from pyproj import CRS
from shapely import STRtree, from_wkb
from shapely.geometry import box, shape
from shapely.geometry.base import BaseGeometry
from shapely.ops import substring


WGS84 = "EPSG:4326"
ANALYSIS_CRS = CRS.from_epsg(5514)
# Axis-aligned WGS84 envelope of Czech Republic (small margin). Keep in sync with
# public/assets/app.js (czechBounds) and public/api/meadows.php (CZECH_REPUBLIC_*).
CZECH_REPUBLIC_CLIP_BOUNDS_WGS84 = (12.07, 48.53, 18.88, 51.07)  # west, south, east, north
ELEVATION_CRS = CRS.from_epsg(3045)
MEADOW_LANDUSE = "meadow"
OSM_CACHE_VERSION = 3
ROAD_VALUES = {
    "motorway",
    "trunk",
    "primary",
    "secondary",
    "tertiary",
    "unclassified",
    "residential",
    "service",
    "living_street",
}
PATH_VALUES = {
    "path",
    "footway",
    "cycleway",
    "bridleway",
    "steps",
    "track",
    "pedestrian",
}
MAJOR_WATERWAY_VALUES = {"river", "canal"}
MINOR_WATERWAY_VALUES = {"stream"}
WATERWAY_VALUES = MAJOR_WATERWAY_VALUES | MINOR_WATERWAY_VALUES
SETTLEMENT_VALUES = {"city", "town", "village", "hamlet"}
DISTANCE_CATEGORIES = ("road", "path", "water", "river", "settlement", "building")
SEGMENTED_INDEX_CATEGORIES = {"water", "river"}
INDEX_SEGMENT_LENGTH_METERS = 1_000.0
ELEVATION_TILE_SIZE_METERS = 2_000.0
LOCAL_RELIEF_WINDOW_PIXELS = 3
FLAT_RELIEF_THRESHOLD_M = 1.5
TERRAIN_ROUGHNESS_PERCENTILE = 80.0
DEFAULT_OSD_URL = "https://atom.cuzk.cz/DMR4G-ETRS89-TIFF/OSD-DMR4G-ETRS89-TIFF.xml"
# Catalog entries often use atom.cuzk.gov.cz; the public mirror atom.cuzk.cz is the same data
# and is reachable on more networks (gov host can fail with WinError 10051, etc.).
CUZK_ATOM_GOVERNMENT_HOST = "atom.cuzk.gov.cz"
CUZK_ATOM_PUBLIC_HOST = "atom.cuzk.cz"
DEFAULT_TIMEOUT_SECONDS = 60.0
DEFAULT_MAX_WORKERS = 8
DEFAULT_MAX_HTTP_REQUESTS_PER_MINUTE = 1_500
RETRY_ATTEMPTS = 3
# Seconds to wait for the next finished catalog-metadata fetch before logging a heartbeat.
CATALOG_RESOLVE_WAIT_SEC = 30.0
REQUEST_HEADERS = {
    "User-Agent": "MeadowFinder/1.0 (+https://github.com/)",
}
ZIP_SUFFIXES = (".tif", ".tiff")
SQL_INSERT_BATCH_SIZE = 500
MEADOWS_EXPORT_TABLE = "meadows"
MEADOWS_GEOMETRY_EXPORT_TABLE = "meadow_geometries"


class SlidingWindowRateLimiter:
    """At most max_events acquisitions per rolling window_seconds (thread-safe)."""

    def __init__(self, max_events: int, window_seconds: float) -> None:
        self.max_events = max_events
        self.window_seconds = window_seconds
        self._times: deque[float] = deque()
        self._lock = threading.Lock()

    def acquire(self) -> None:
        while True:
            with self._lock:
                now = monotonic()
                cutoff = now - self.window_seconds
                while self._times and self._times[0] < cutoff:
                    self._times.popleft()
                if len(self._times) < self.max_events:
                    self._times.append(now)
                    return
                wake_at = self._times[0] + self.window_seconds
                sleep_for = max(0.0, wake_at - now)
            if sleep_for > 0:
                sleep(sleep_for)


_http_request_rate_limiter: SlidingWindowRateLimiter | None = None


def set_http_request_rate_limit(max_per_minute: int | None) -> None:
    """Cap urllib traffic; None or <=0 disables. Used by elevation HTTP (metadata + ZIP)."""
    global _http_request_rate_limiter
    if max_per_minute is None or max_per_minute <= 0:
        _http_request_rate_limiter = None
    else:
        _http_request_rate_limiter = SlidingWindowRateLimiter(max_per_minute, 60.0)


@dataclass
class CategoryGeometry:
    name: str
    geometries: np.ndarray
    tree: STRtree | None


@dataclass
class MeadowGeometries:
    all_geometries: np.ndarray
    valid_mask: np.ndarray
    valid_indices: np.ndarray
    valid_geometries: np.ndarray


@dataclass
class DatabaseConfig:
    host: str
    port: int
    name: str
    user: str
    password: str
    table: str


@dataclass
class ElevationTile:
    tile_id: str
    tif_path: Path
    bounds: tuple[float, float, float, float]
    crs: CRS


@dataclass
class TerrainMetrics:
    average_elevation_deviation_m: np.ndarray
    largest_flat_patch_m2: np.ndarray
    largest_flat_patch_share: np.ndarray
    flat_area_share: np.ndarray
    terrain_roughness_p80_m: np.ndarray


@dataclass(frozen=True)
class CatalogTile:
    tile_id: str
    dataset_feed_url: str
    title: str
    crs: str | None


@dataclass(frozen=True)
class ResolvedCatalogTile:
    tile: CatalogTile
    download_url: str
    download_size: int | None
    bounds_wgs84: tuple[float, float, float, float] | None
    updated: str | None


class ProgressTracker:
    def __init__(self, total_tiles: int) -> None:
        self.total_tiles = total_tiles
        self.archive_ready_count = 0
        self.processed_count = 0
        self.lock = threading.Lock()

    def report_archive_ready(self, tile_id: str, downloaded_now: bool) -> None:
        with self.lock:
            self.archive_ready_count += 1
            count = self.archive_ready_count
        if should_report_progress(count, self.total_tiles):
            status = "downloaded" if downloaded_now else "cached"
            print(
                f"Archive ready {count:,}/{self.total_tiles:,} "
                f"for tile {tile_id} ({status})."
            )

    def report_processed(self, tile_id: str) -> None:
        with self.lock:
            self.processed_count += 1
            count = self.processed_count
        if should_report_progress(count, self.total_tiles):
            print(
                f"Processed {count:,}/{self.total_tiles:,} elevation tiles "
                f"(latest: {tile_id})."
            )


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Prepare meadow polygons and precomputed proximity metrics."
    )
    parser.add_argument(
        "--osm",
        type=Path,
        default=Path("czech-republic-260324.osm.pbf"),
        help="Path to the OSM PBF extract for Czech Republic.",
    )
    parser.add_argument(
        "--elevation-manifest",
        type=Path,
        default=Path("data/elevation/manifest.json"),
        help="Path where the run-specific elevation manifest JSON will be written.",
    )
    parser.add_argument(
        "--elevation-osd-url",
        default=DEFAULT_OSD_URL,
        help="OpenSearch description URL that enumerates the CUZK DMR4G tile feeds.",
    )
    parser.add_argument(
        "--elevation-download-dir",
        type=Path,
        default=Path("data/elevation/downloads"),
        help="Directory where elevation ZIP archives will be cached.",
    )
    parser.add_argument(
        "--elevation-tile-dir",
        type=Path,
        default=Path("data/elevation/tiles"),
        help="Directory where elevation TIFF tiles will be extracted.",
    )
    parser.add_argument(
        "--elevation-max-workers",
        type=int,
        default=DEFAULT_MAX_WORKERS,
        help="Maximum number of concurrent workers for elevation metadata and downloads.",
    )
    parser.add_argument(
        "--elevation-timeout",
        type=float,
        default=DEFAULT_TIMEOUT_SECONDS,
        help="HTTP timeout in seconds for elevation metadata and archive downloads.",
    )
    parser.add_argument(
        "--elevation-max-http-requests-per-minute",
        type=int,
        default=DEFAULT_MAX_HTTP_REQUESTS_PER_MINUTE,
        help=(
            "Rolling cap per 60s on elevation HTTP GETs: OSD/dataset-feed XML and each ZIP download "
            "(each attempt counts); 0 disables."
        ),
    )
    parser.add_argument(
        "--elevation-catalog-limit",
        type=int,
        default=None,
        help="Optional number of catalog tiles to inspect while debugging elevation prep.",
    )
    parser.add_argument(
        "--output-dir",
        type=Path,
        default=Path("data/processed"),
        help="Directory where the processed files will be written.",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Optional number of meadows to process for a pilot run.",
    )
    parser.add_argument(
        "--simplify-tolerance",
        type=float,
        default=2.0,
        help="Simplification tolerance in meters for display geometry.",
    )
    parser.add_argument(
        "--max-area-hectares",
        type=float,
        default=None,
        help="Optional upper bound to exclude unusually large parcels.",
    )
    parser.add_argument(
        "--import",
        dest="import_to_db",
        action="store_true",
        help="Truncate the MySQL meadows table and load data directly from this script.",
    )
    parser.add_argument(
        "--output-type",
        choices=["csv", "sql"],
        default=None,
        help="File format for meadow export (required unless --import).",
    )
    parser.add_argument(
        "--output-max-size-mb",
        type=float,
        default=None,
        help="Split CSV or SQL output across multiple files when a shard would exceed this size (MiB).",
    )
    parser.add_argument(
        "--osm-cache",
        type=Path,
        default=None,
        help="Path to cache extracted OSM features. Defaults to {osm}.features.pkl.",
    )
    parser.add_argument(
        "--skip-preview",
        action="store_true",
        help="Skip writing the preview GeoJSON to speed up full rebuilds.",
    )
    parser.add_argument(
        "--detailed-timings",
        action="store_true",
        help="Print extra sub-step timings for hotspot analysis.",
    )
    args = parser.parse_args()
    if not args.import_to_db and args.output_type is None:
        parser.error("--output-type is required unless --import is used")
    return args


def validate_inputs(args: argparse.Namespace) -> None:
    missing = [str(path) for path in [args.osm] if not path.exists()]
    if missing:
        raise FileNotFoundError(f"Missing input files: {', '.join(missing)}")

    args.output_dir.mkdir(parents=True, exist_ok=True)


def format_elapsed(seconds: float) -> str:
    return f"{seconds:.2f}s"


def project_root() -> Path:
    return Path(__file__).resolve().parents[1]


def local_db_config_path() -> Path:
    return project_root() / "public" / "config" / "config.local.php"


def timed_print(label: str, start_time: float) -> None:
    print(f"{label} took {format_elapsed(perf_counter() - start_time)}.")


def timed_substep(enabled: bool, label: str, start_time: float) -> None:
    if enabled:
        timed_print(label, start_time)


def load_local_db_config(config_path: Path) -> DatabaseConfig:
    if not config_path.exists():
        raise FileNotFoundError(f"Missing database config file: {config_path}")

    text = config_path.read_text(encoding="utf-8")
    values: dict[str, str] = {}
    for match in re.finditer(r"'([^']+)'\s*=>\s*'([^']*)'", text):
        values[match.group(1)] = match.group(2)

    required_keys = ["db_host", "db_port", "db_name", "db_user", "db_password"]
    missing_keys = [key for key in required_keys if key not in values]
    if missing_keys:
        raise ValueError(
            f"Missing database settings in {config_path}: {', '.join(missing_keys)}"
        )

    return DatabaseConfig(
        host=values["db_host"],
        port=int(values["db_port"]),
        name=values["db_name"],
        user=values["db_user"],
        password=values["db_password"],
        table=values.get("db_table", "meadows"),
    )


def resolve_manifest_path(manifest_path: Path, value: str) -> Path:
    path = Path(value)
    if path.is_absolute():
        return path
    return (manifest_path.parent / path).resolve()


def should_report_progress(count: int, total: int) -> bool:
    return count <= 10 or count % 100 == 0 or count == total


def catalog_resolve_max_idle_sec(http_timeout: float, extra_slack_sec: float = 0.0) -> float:
    """Fail the elevation catalog phase if no request completes for this long."""
    backoff = sum(2**i for i in range(max(RETRY_ATTEMPTS - 1, 0)))
    return max(240.0, http_timeout * RETRY_ATTEMPTS + float(backoff) + 90.0) + extra_slack_sec


def local_name(tag: str) -> str:
    return tag.split("}", 1)[-1]


def get_attribute(attributes: dict[str, str], name: str) -> str | None:
    for key, value in attributes.items():
        if local_name(key) == name:
            return value
    return None


def fetch_bytes(url: str, timeout: float) -> bytes:
    """HTTP GET body; used for catalog/dataset XML and ZIP archives (shared rate limit)."""
    last_error: Exception | None = None
    for attempt in range(1, RETRY_ATTEMPTS + 1):
        limiter = _http_request_rate_limiter
        if limiter is not None:
            limiter.acquire()
        request = Request(url, headers=REQUEST_HEADERS)
        try:
            with urlopen(request, timeout=timeout) as response:
                return response.read()
        except (HTTPError, URLError, TimeoutError, OSError) as exc:
            last_error = exc
            if attempt == RETRY_ATTEMPTS:
                break
            sleep(2 ** (attempt - 1))
    assert last_error is not None
    raise RuntimeError(
        f"Failed to fetch {url} after {RETRY_ATTEMPTS} attempts "
        f"({timeout}s timeout each): {last_error}"
    ) from last_error


def fetch_xml_root(url: str, timeout: float) -> ET.Element:
    return ET.fromstring(fetch_bytes(url, timeout=timeout))


def normalize_cuzk_service_url(url: str) -> str:
    """Use atom.cuzk.cz instead of atom.cuzk.gov.cz when the catalog points at the gov host."""
    parsed = urlparse(url)
    if parsed.netloc.lower() == CUZK_ATOM_GOVERNMENT_HOST:
        return urlunparse(parsed._replace(netloc=CUZK_ATOM_PUBLIC_HOST))
    return url


def tile_id_from_feed_url(dataset_feed_url: str) -> str:
    match = re.search(r"_(\d+_\d+)\.xml$", dataset_feed_url)
    if not match:
        raise ValueError(f"Could not derive tile ID from dataset feed URL: {dataset_feed_url}")
    return match.group(1)


def parse_catalog(osd_url: str, timeout: float) -> list[CatalogTile]:
    root = fetch_xml_root(normalize_cuzk_service_url(osd_url), timeout=timeout)
    tiles: list[CatalogTile] = []
    for element in root.iter():
        if local_name(element.tag) != "Query":
            continue

        raw_feed_url = get_attribute(element.attrib, "spatial_dataset_identifier_code")
        if not raw_feed_url:
            continue

        dataset_feed_url = normalize_cuzk_service_url(raw_feed_url)
        tiles.append(
            CatalogTile(
                tile_id=tile_id_from_feed_url(dataset_feed_url),
                dataset_feed_url=dataset_feed_url,
                title=get_attribute(element.attrib, "title") or "",
                crs=get_attribute(element.attrib, "crs"),
            )
        )

    if not tiles:
        raise RuntimeError(f"No DMR4G tiles were found in catalog {osd_url}")
    return tiles


def parse_polygon_bounds(
    polygon_text: str | None,
) -> tuple[float, float, float, float] | None:
    if not polygon_text:
        return None

    values = [float(value) for value in polygon_text.split()]
    if len(values) < 4 or len(values) % 2 != 0:
        return None

    latitudes = values[0::2]
    longitudes = values[1::2]
    return (
        min(longitudes),
        min(latitudes),
        max(longitudes),
        max(latitudes),
    )


def dataset_entry(root: ET.Element) -> ET.Element:
    for element in root.iter():
        if local_name(element.tag) == "entry":
            return element
    raise RuntimeError("Dataset feed does not contain an <entry> element")


def entry_text(entry: ET.Element, tag_name: str) -> str | None:
    for child in entry:
        if local_name(child.tag) == tag_name:
            return (child.text or "").strip() or None
    return None


def resolve_download_metadata(tile: CatalogTile, timeout: float) -> ResolvedCatalogTile:
    try:
        root = fetch_xml_root(tile.dataset_feed_url, timeout=timeout)
    except Exception as exc:
        raise RuntimeError(
            f"Tile {tile.tile_id}: failed to fetch dataset feed {tile.dataset_feed_url!r}: {exc}"
        ) from exc
    try:
        entry = dataset_entry(root)
    except Exception as exc:
        raise RuntimeError(f"Tile {tile.tile_id}: invalid dataset feed XML: {exc}") from exc

    download_url: str | None = None
    download_size: int | None = None
    for child in entry:
        if local_name(child.tag) != "link":
            continue
        href = child.attrib.get("href")
        if href and href.lower().endswith(".zip"):
            download_url = href
            length = child.attrib.get("length")
            download_size = int(length) if length and length.isdigit() else None
            break

    if download_url is None:
        entry_id = entry_text(entry, "id")
        if entry_id and entry_id.lower().endswith(".zip"):
            download_url = entry_id

    if download_url is None:
        raise RuntimeError(f"Dataset feed did not expose a ZIP URL for tile {tile.tile_id}")

    return ResolvedCatalogTile(
        tile=tile,
        download_url=download_url,
        download_size=download_size,
        bounds_wgs84=parse_polygon_bounds(entry_text(entry, "polygon")),
        updated=entry_text(entry, "updated"),
    )


def load_prior_manifest_tile_rows(manifest_path: Path) -> dict[str, dict[str, Any]]:
    if not manifest_path.is_file():
        return {}
    try:
        payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}
    tiles = payload.get("tiles")
    if not isinstance(tiles, list):
        return {}
    out: dict[str, dict[str, Any]] = {}
    for item in tiles:
        if isinstance(item, dict) and isinstance(item.get("tile_id"), str):
            out[item["tile_id"]] = item
    return out


def resolved_tile_without_dataset_feed(
    tile: CatalogTile,
    zip_path: Path,
    prior: dict[str, Any] | None,
) -> ResolvedCatalogTile:
    """Build ResolvedCatalogTile without fetching dataset feed XML (local ZIP already present)."""
    download_url = ""
    download_size: int | None = None
    bounds_wgs84: tuple[float, float, float, float] | None = None
    updated: str | None = None

    if prior:
        du = prior.get("download_url")
        if isinstance(du, str) and du.strip():
            download_url = du.strip()
        ds = prior.get("download_size")
        if isinstance(ds, int) and not isinstance(ds, bool):
            download_size = ds
        bw = prior.get("bounds_wgs84")
        if isinstance(bw, list) and len(bw) == 4:
            try:
                bounds_wgs84 = tuple(float(x) for x in bw)
            except (TypeError, ValueError):
                bounds_wgs84 = None
        fu = prior.get("feed_updated")
        if isinstance(fu, str) and fu.strip():
            updated = fu.strip()

    if download_size is None:
        try:
            download_size = int(zip_path.stat().st_size)
        except OSError:
            download_size = None

    if not download_url and tile.dataset_feed_url.lower().endswith(".xml"):
        download_url = normalize_cuzk_service_url(tile.dataset_feed_url[:-4] + ".zip")

    if bounds_wgs84 is None:
        bounds_wgs84 = tile_bounds_wgs84_from_id(tile.tile_id)

    return ResolvedCatalogTile(
        tile=tile,
        download_url=download_url,
        download_size=download_size,
        bounds_wgs84=bounds_wgs84,
        updated=updated,
    )


def resolve_catalog_metadata(
    catalog_tiles: list[CatalogTile],
    timeout: float,
    max_workers: int,
    *,
    download_dir: Path,
    manifest_path: Path,
) -> list[ResolvedCatalogTile]:
    if not catalog_tiles:
        return []

    download_dir = download_dir.resolve()
    prior_rows = load_prior_manifest_tile_rows(manifest_path.resolve())
    skipped: list[ResolvedCatalogTile] = []
    to_fetch: list[CatalogTile] = []

    for tile in catalog_tiles:
        zip_path = download_dir / f"{tile.tile_id}.zip"
        if zip_path.is_file():
            skipped.append(
                resolved_tile_without_dataset_feed(
                    tile, zip_path, prior_rows.get(tile.tile_id)
                )
            )
        else:
            to_fetch.append(tile)

    if skipped:
        print(
            f"Skipped dataset-feed metadata for {len(skipped):,} tiles "
            f"(ZIP already in {download_dir}).",
            flush=True,
        )

    if not to_fetch:
        skipped.sort(key=lambda t: t.tile.tile_id)
        return skipped

    max_idle = catalog_resolve_max_idle_sec(
        timeout,
        extra_slack_sec=65.0 if _http_request_rate_limiter is not None else 0.0,
    )
    resolved_tiles: list[ResolvedCatalogTile] = []
    with ThreadPoolExecutor(max_workers=max(max_workers, 1)) as executor:
        future_map = {
            executor.submit(resolve_download_metadata, tile, timeout): tile.tile_id
            for tile in to_fetch
        }
        pending = set(future_map.keys())
        count = 0
        last_progress = perf_counter()
        total = len(to_fetch)

        while pending:
            done, pending = wait(
                pending,
                timeout=CATALOG_RESOLVE_WAIT_SEC,
                return_when=FIRST_COMPLETED,
            )
            now = perf_counter()
            if not done:
                idle = now - last_progress
                print(
                    f"Elevation catalog: no completions in {CATALOG_RESOLVE_WAIT_SEC:.0f}s "
                    f"({len(pending)} in flight, {count:,}/{total:,} done, "
                    f"{idle:.0f}s since last completion).",
                    flush=True,
                )
                if idle >= max_idle:
                    sample = [future_map[f] for f in list(pending)[:5]]
                    raise RuntimeError(
                        "Elevation catalog metadata stalled: no request finished in "
                        f"{max_idle:.0f}s (HTTP timeout {timeout}s per attempt, {RETRY_ATTEMPTS} attempts). "
                        f"{len(pending)} requests still pending; examples: {', '.join(sample)}"
                    )
                continue

            last_progress = now
            for future in done:
                tile_id = future_map[future]
                try:
                    resolved_tiles.append(future.result())
                except Exception as exc:
                    print(
                        f"Elevation catalog metadata error (tile {tile_id}): {exc}",
                        flush=True,
                    )
                    raise
                count += 1
                if should_report_progress(count, total):
                    print(
                        f"Resolved {count:,}/{total:,} elevation catalog entries "
                        f"(latest: {tile_id}).",
                        flush=True,
                    )

    resolved_tiles.extend(skipped)
    resolved_tiles.sort(key=lambda tile: tile.tile.tile_id)
    return resolved_tiles


def tile_id_lower_left_meters(tile_id: str) -> tuple[float, float]:
    x_str, y_str = tile_id.split("_", maxsplit=1)
    return float(int(x_str) * 1_000), float(int(y_str) * 1_000)


def tile_bounds_from_id(tile_id: str) -> tuple[float, float, float, float]:
    min_x, min_y = tile_id_lower_left_meters(tile_id)
    return (
        min_x,
        min_y,
        min_x + ELEVATION_TILE_SIZE_METERS,
        min_y + ELEVATION_TILE_SIZE_METERS,
    )


def tile_bounds_wgs84_from_id(tile_id: str) -> tuple[float, float, float, float]:
    min_x, min_y, max_x, max_y = tile_bounds_from_id(tile_id)
    projected = gpd.GeoSeries([box(min_x, min_y, max_x, max_y)], crs=ELEVATION_CRS)
    wgs_bounds = projected.to_crs(WGS84).iloc[0].bounds
    return (wgs_bounds[0], wgs_bounds[1], wgs_bounds[2], wgs_bounds[3])


def tile_id_from_lower_left_meters(min_x: float, min_y: float) -> str:
    return f"{int(round(min_x / 1_000)):03d}_{int(round(min_y / 1_000)):04d}"


def has_identity_raster_bounds(
    bounds: tuple[float, float, float, float], width: int, height: int
) -> bool:
    left, bottom, right, top = bounds
    return (
        np.isclose(left, 0.0)
        and np.isclose(top, 0.0)
        and np.isclose(right, float(width))
        and np.isclose(bottom, float(height))
    )


def resolved_raster_bounds_and_crs(
    tile_id: str,
    bounds: tuple[float, float, float, float],
    *,
    crs_value: str | None,
    width: int,
    height: int,
) -> tuple[str, list[float]]:
    if crs_value and not has_identity_raster_bounds(bounds, width, height):
        return crs_value, [bounds[0], bounds[1], bounds[2], bounds[3]]

    synthetic_bounds = tile_bounds_from_id(tile_id)
    return ELEVATION_CRS.to_string(), list(synthetic_bounds)


def aligned_tile_origin(value: float) -> float:
    return np.floor(value / ELEVATION_TILE_SIZE_METERS) * ELEVATION_TILE_SIZE_METERS


def iter_tile_origins_for_bounds(
    bounds: tuple[float, float, float, float]
) -> Iterable[tuple[float, float]]:
    min_x, min_y, max_x, max_y = bounds
    epsilon = 1e-6
    start_x = aligned_tile_origin(min_x)
    start_y = aligned_tile_origin(min_y)
    end_x = aligned_tile_origin(max(max_x - epsilon, min_x))
    end_y = aligned_tile_origin(max(max_y - epsilon, min_y))

    x = start_x
    while x <= end_x:
        y = start_y
        while y <= end_y:
            yield x, y
            y += ELEVATION_TILE_SIZE_METERS
        x += ELEVATION_TILE_SIZE_METERS


def select_catalog_tiles_for_meadows(
    meadows: gpd.GeoDataFrame, catalog_tiles: list[CatalogTile]
) -> list[CatalogTile]:
    if meadows.empty or not catalog_tiles:
        return []

    meadows_elevation = meadows.to_crs(ELEVATION_CRS)
    meadow_geometries = prepare_meadow_geometries(meadows_elevation)
    if not len(meadow_geometries.valid_geometries):
        return []

    meadow_tree = STRtree(meadow_geometries.valid_geometries)
    candidate_tile_ids: set[str] = set()
    for geometry in meadow_geometries.valid_geometries:
        for min_x, min_y in iter_tile_origins_for_bounds(geometry.bounds):
            candidate_tile_ids.add(tile_id_from_lower_left_meters(min_x, min_y))

    selected_tile_ids = [
        tile_id
        for tile_id in sorted(candidate_tile_ids)
        if len(
            meadow_tree.query(
                shapely.box(*tile_bounds_from_id(tile_id)),
                predicate="intersects",
            )
        )
    ]
    selected_tile_id_set = set(selected_tile_ids)
    selected_tiles = [
        tile for tile in catalog_tiles if tile.tile_id in selected_tile_id_set
    ]

    print(
        f"Shortlisted {len(selected_tile_ids):,} elevation tiles "
        f"from meadow geometry bounds before metadata fetch."
    )
    print(
        f"Selected {len(selected_tiles):,} of {len(catalog_tiles):,} elevation catalog tiles "
        f"for the current meadow footprint."
    )
    return selected_tiles


def download_zip(url: str, destination: Path, timeout: float) -> bool:
    """Fetch ZIP via fetch_bytes (each download counts toward --elevation-max-http-requests-per-minute)."""
    if destination.exists():
        return False

    destination.parent.mkdir(parents=True, exist_ok=True)
    temp_path = destination.with_suffix(destination.suffix + ".tmp")
    temp_path.unlink(missing_ok=True)
    temp_path.write_bytes(fetch_bytes(url, timeout=timeout))
    temp_path.replace(destination)
    return True


def extract_tiff(zip_path: Path, tile_dir: Path, tile_id: str) -> Path:
    tile_dir.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(zip_path) as archive:
        tif_members = [
            member
            for member in archive.namelist()
            if member.lower().endswith(ZIP_SUFFIXES)
        ]
        if not tif_members:
            raise RuntimeError(f"No TIFF file found in archive {zip_path}")
        if len(tif_members) > 1:
            raise RuntimeError(f"Multiple TIFF files found in archive {zip_path}")

        member = tif_members[0]
        suffix = Path(member).suffix.lower() or ".tif"
        output_path = tile_dir / f"{tile_id}{suffix}"
        if output_path.exists():
            return output_path

        with archive.open(member) as source, output_path.open("wb") as target:
            target.write(source.read())
        return output_path


@contextmanager
def open_rasterio_dem(rasterio_module: Any, path: Path):
    """Open a DEM GeoTIFF; CUZK tiles may lack embedded georeferencing (bounds come from manifest)."""
    with warnings.catch_warnings():
        warnings.simplefilter(
            "ignore",
            category=rasterio_module.errors.NotGeoreferencedWarning,
        )
        with rasterio_module.open(path) as dataset:
            yield dataset


def read_raster_metadata(tif_path: Path, tile_id: str) -> tuple[str, list[float]]:
    try:
        rasterio = importlib.import_module("rasterio")
    except ImportError as exc:
        raise RuntimeError(
            "Preparing elevation data requires rasterio. Install it with "
            "'python -m pip install rasterio' or add it from requirements.txt."
        ) from exc

    with open_rasterio_dem(rasterio, tif_path) as dataset:
        bounds = (dataset.bounds.left, dataset.bounds.bottom, dataset.bounds.right, dataset.bounds.top)
        crs = dataset.crs.to_string() if dataset.crs is not None else None
        return resolved_raster_bounds_and_crs(
            tile_id,
            bounds,
            crs_value=crs,
            width=dataset.width,
            height=dataset.height,
        )


def relpath(path: Path, base_dir: Path) -> str:
    return os.path.relpath(path.resolve(), start=base_dir.resolve())


def process_resolved_tile(
    tile: ResolvedCatalogTile,
    download_dir: Path,
    tile_dir: Path,
    manifest_dir: Path,
    timeout: float,
    progress: ProgressTracker,
) -> dict[str, Any]:
    zip_path = download_dir / f"{tile.tile.tile_id}.zip"
    downloaded_now = download_zip(tile.download_url, zip_path, timeout=timeout)
    progress.report_archive_ready(tile.tile.tile_id, downloaded_now)
    tif_path = extract_tiff(zip_path, tile_dir, tile.tile.tile_id)
    raster_crs, raster_bounds = read_raster_metadata(tif_path, tile.tile.tile_id)
    progress.report_processed(tile.tile.tile_id)

    return {
        "tile_id": tile.tile.tile_id,
        "title": tile.tile.title,
        "dataset_feed_url": tile.tile.dataset_feed_url,
        "download_url": tile.download_url,
        "download_size": tile.download_size,
        "zip_path": relpath(zip_path, manifest_dir),
        "tif_path": relpath(tif_path, manifest_dir),
        "feed_updated": tile.updated,
        "catalog_crs": tile.tile.crs,
        "raster_crs": raster_crs,
        "bounds": raster_bounds,
        "bounds_wgs84": list(tile.bounds_wgs84) if tile.bounds_wgs84 is not None else None,
    }


def process_resolved_tiles(
    tiles: list[ResolvedCatalogTile],
    download_dir: Path,
    tile_dir: Path,
    manifest_path: Path,
    timeout: float,
    max_workers: int,
) -> list[dict[str, Any]]:
    if not tiles:
        return []

    manifest_dir = manifest_path.resolve().parent
    download_dir.mkdir(parents=True, exist_ok=True)
    tile_dir.mkdir(parents=True, exist_ok=True)
    manifest_dir.mkdir(parents=True, exist_ok=True)

    results: list[dict[str, Any]] = []
    progress = ProgressTracker(len(tiles))
    with ThreadPoolExecutor(max_workers=max(max_workers, 1)) as executor:
        future_map = {
            executor.submit(
                process_resolved_tile,
                tile,
                download_dir,
                tile_dir,
                manifest_dir,
                timeout,
                progress,
            ): tile.tile.tile_id
            for tile in tiles
        }
        for future in as_completed(future_map):
            results.append(future.result())

    results.sort(key=lambda item: item["tile_id"])
    return results


def write_elevation_manifest(
    manifest_path: Path,
    osd_url: str,
    tiles: list[dict[str, Any]],
) -> None:
    manifest_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "source_osd_url": osd_url,
        "tile_count": len(tiles),
        "tiles": tiles,
    }
    manifest_path.write_text(json.dumps(payload, indent=2), encoding="utf-8")


def prepare_elevation_manifest_for_meadows(
    meadows: gpd.GeoDataFrame,
    *,
    osd_url: str,
    download_dir: Path,
    tile_dir: Path,
    manifest_path: Path,
    timeout: float,
    max_workers: int,
    catalog_limit: int | None = None,
) -> Path:
    print(f"Loading CUZK OSD catalog from {osd_url}...")
    catalog_tiles = parse_catalog(osd_url, timeout=timeout)
    if catalog_limit is not None:
        catalog_tiles = catalog_tiles[:catalog_limit]
        print(f"Inspecting first {len(catalog_tiles):,} elevation catalog tiles.")
    else:
        print(f"Discovered {len(catalog_tiles):,} elevation catalog tiles.")

    selected_catalog_tiles = select_catalog_tiles_for_meadows(meadows, catalog_tiles)
    if not selected_catalog_tiles:
        raise RuntimeError(
            "No elevation catalog tiles intersect the selected meadows. "
            "Check the meadow filters or elevation catalog coverage."
        )

    resolved_tiles = resolve_catalog_metadata(
        selected_catalog_tiles,
        timeout=timeout,
        max_workers=max_workers,
        download_dir=download_dir,
        manifest_path=manifest_path,
    )

    results = process_resolved_tiles(
        resolved_tiles,
        download_dir=download_dir,
        tile_dir=tile_dir,
        manifest_path=manifest_path,
        timeout=timeout,
        max_workers=max_workers,
    )
    write_elevation_manifest(manifest_path, osd_url, results)
    print(f"Wrote elevation manifest to {manifest_path}.")
    return manifest_path


def load_elevation_manifest(manifest_path: Path) -> list[ElevationTile]:
    try:
        payload = json.loads(manifest_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise ValueError(f"Invalid elevation manifest JSON: {manifest_path}") from exc

    tiles_payload = payload.get("tiles")
    if not isinstance(tiles_payload, list):
        raise ValueError(f"Elevation manifest is missing a 'tiles' list: {manifest_path}")

    tiles: list[ElevationTile] = []
    for tile_payload in tiles_payload:
        if not isinstance(tile_payload, dict):
            continue

        tile_id = tile_payload.get("tile_id")
        tif_value = tile_payload.get("tif_path")
        bounds_value = tile_payload.get("bounds")
        crs_value = tile_payload.get("raster_crs") or tile_payload.get("catalog_crs")
        if not isinstance(tile_id, str) or not isinstance(tif_value, str):
            continue
        if not isinstance(bounds_value, list) or len(bounds_value) != 4:
            continue

        raw_bounds = tuple(float(value) for value in bounds_value)
        raw_crs = crs_value if isinstance(crs_value, str) and crs_value else None
        resolved_crs, resolved_bounds = resolved_raster_bounds_and_crs(
            tile_id,
            raw_bounds,
            crs_value=raw_crs,
            width=max(int(round(raw_bounds[2] - raw_bounds[0])), 1),
            height=max(int(round(raw_bounds[1] - raw_bounds[3])), 1),
        )
        tif_path = resolve_manifest_path(manifest_path, tif_value)
        if not tif_path.exists():
            raise FileNotFoundError(
                f"Elevation TIFF listed in manifest is missing: {tif_path}"
            )

        tiles.append(
            ElevationTile(
                tile_id=tile_id,
                tif_path=tif_path,
                bounds=tuple(resolved_bounds),
                crs=CRS.from_user_input(resolved_crs),
            )
        )

    if not tiles:
        raise ValueError(f"No usable elevation tiles were found in {manifest_path}")
    return tiles


def empty_meadow_frame() -> gpd.GeoDataFrame:
    return gpd.GeoDataFrame(
        {
            "source_id": pd.Series(dtype="object"),
            "source_type": pd.Series(dtype="object"),
            "land_cover_code": pd.Series(dtype="object"),
            "area_ha": pd.Series(dtype="float64"),
            "area_m2": pd.Series(dtype="float64"),
        },
        geometry=gpd.GeoSeries([], crs=ANALYSIS_CRS),
        crs=ANALYSIS_CRS,
    )


def build_meadow_frame(
    meadow_records: list[tuple[str, str, str]],
    detailed_timings: bool = False,
) -> gpd.GeoDataFrame:
    if not meadow_records:
        return empty_meadow_frame()

    decode_start = perf_counter()
    source_ids = [record[0] for record in meadow_records]
    land_cover_codes = [record[1] for record in meadow_records]
    geometries = from_wkb([record[2] for record in meadow_records])
    timed_substep(detailed_timings, "OSM meadow WKB decode", decode_start)

    frame_start = perf_counter()
    meadows = gpd.GeoDataFrame(
        {
            "source_id": source_ids,
            "source_type": "osm",
            "land_cover_code": land_cover_codes,
        },
        geometry=gpd.GeoSeries(geometries, crs=WGS84),
        crs=WGS84,
    ).to_crs(ANALYSIS_CRS)
    meadows["area_m2"] = meadows.geometry.area
    meadows["area_ha"] = meadows["area_m2"] / 10_000.0
    timed_substep(detailed_timings, "OSM meadow reprojection", frame_start)
    return meadows


def clip_meadows_to_czech_republic(meadows: gpd.GeoDataFrame) -> gpd.GeoDataFrame:
    """Remove geometry outside the Czech Republic bounding box (OSM extracts can spill across borders)."""
    if meadows.empty:
        return meadows

    west, south, east, north = CZECH_REPUBLIC_CLIP_BOUNDS_WGS84
    wgs = meadows.to_crs(WGS84)
    clip_frame = gpd.GeoDataFrame(geometry=[box(west, south, east, north)], crs=WGS84)
    clipped = gpd.clip(wgs, clip_frame)
    clipped = clipped[~clipped.geometry.is_empty & clipped.geometry.notna()].copy()
    if clipped.empty:
        return meadows.iloc[0:0].copy()

    projected = clipped.to_crs(ANALYSIS_CRS)
    projected["area_m2"] = projected.geometry.area
    projected["area_ha"] = projected["area_m2"] / 10_000.0
    projected = projected.loc[projected["area_m2"] >= 1.0].reset_index(drop=True)
    return projected


def default_osm_cache_path(osm_path: Path) -> Path:
    return osm_path.with_suffix(".features.pkl")


def osm_source_signature(osm_path: Path) -> dict[str, int | str]:
    stat = osm_path.stat()
    return {
        "path": str(osm_path.resolve()),
        "size": stat.st_size,
        "mtime_ns": stat.st_mtime_ns,
    }


def empty_feature_frame() -> gpd.GeoDataFrame:
    return gpd.GeoDataFrame(geometry=gpd.GeoSeries([], crs=ANALYSIS_CRS))


def build_feature_frame(
    name: str, wkbs: list[str], detailed_timings: bool = False
) -> gpd.GeoDataFrame:
    if not wkbs:
        return empty_feature_frame()

    decode_start = perf_counter()
    geometries = from_wkb(wkbs)
    timed_substep(detailed_timings, f"OSM {name} WKB decode", decode_start)

    frame_start = perf_counter()
    frame = gpd.GeoDataFrame(geometry=gpd.GeoSeries(geometries, crs=WGS84))
    projected = frame.to_crs(ANALYSIS_CRS)
    timed_substep(detailed_timings, f"OSM {name} reprojection", frame_start)
    return projected


def load_osm_cache(
    cache_path: Path, osm_path: Path
) -> tuple[gpd.GeoDataFrame, dict[str, gpd.GeoDataFrame]] | None:
    if not cache_path.exists():
        return None

    try:
        with cache_path.open("rb") as handle:
            payload = pickle.load(handle)
    except (OSError, pickle.PickleError):
        return None

    if not isinstance(payload, dict):
        return None

    if payload.get("schema_version") != OSM_CACHE_VERSION:
        return None

    if payload.get("source") != osm_source_signature(osm_path):
        return None

    meadows = payload.get("meadows")
    features = payload.get("features")
    if not isinstance(meadows, gpd.GeoDataFrame):
        return None
    if not isinstance(features, dict):
        return None

    required_categories = set(DISTANCE_CATEGORIES)
    if not required_categories.issubset(features):
        return None
    return meadows, features


def save_osm_cache(
    cache_path: Path,
    osm_path: Path,
    meadows: gpd.GeoDataFrame,
    features: dict[str, gpd.GeoDataFrame],
) -> None:
    cache_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "schema_version": OSM_CACHE_VERSION,
        "source": osm_source_signature(osm_path),
        "meadows": meadows,
        "features": features,
    }
    with cache_path.open("wb") as handle:
        pickle.dump(payload, handle, protocol=pickle.HIGHEST_PROTOCOL)


def area_source_id(area: object) -> str:
    source_prefix = "way" if area.from_way() else "relation"
    return f"{source_prefix}:{area.orig_id()}"


def extract_osm_data(
    osm_path: Path, detailed_timings: bool = False
) -> tuple[gpd.GeoDataFrame, dict[str, gpd.GeoDataFrame]]:
    factory = osmium.geom.WKBFactory()
    processor = (
        osmium.FileProcessor(str(osm_path))
        .with_locations()
        .with_areas()
        .with_filter(KeyFilter("highway", "waterway", "place", "landuse", "building"))
    )

    meadow_records: list[tuple[str, str, str]] = []
    road_wkbs: list[str] = []
    path_wkbs: list[str] = []
    water_wkbs: list[str] = []
    river_wkbs: list[str] = []
    settlement_wkbs: list[str] = []
    building_wkbs: list[str] = []

    scan_start = perf_counter()
    for obj in processor:
        tags = obj.tags
        try:
            if obj.is_area():
                landuse = tags.get("landuse")
                building = tags.get("building")
                if landuse == MEADOW_LANDUSE or (building and building != "no"):
                    geometry_wkb = factory.create_multipolygon(obj)
                    if landuse == MEADOW_LANDUSE:
                        meadow_records.append(
                            (
                                area_source_id(obj),
                                landuse,
                                geometry_wkb,
                            )
                        )
                    if building and building != "no":
                        building_wkbs.append(geometry_wkb)
            elif obj.is_node():
                if "place" in tags and tags["place"] in SETTLEMENT_VALUES:
                    settlement_wkbs.append(factory.create_point(obj))
                building = tags.get("building")
                if building and building != "no":
                    building_wkbs.append(factory.create_point(obj))
            elif obj.is_way():
                if "highway" in tags:
                    highway = tags["highway"]
                    if highway in ROAD_VALUES:
                        road_wkbs.append(factory.create_linestring(obj))
                    elif highway in PATH_VALUES:
                        path_wkbs.append(factory.create_linestring(obj))
                elif "waterway" in tags and tags["waterway"] in WATERWAY_VALUES:
                    waterway = tags["waterway"]
                    water_wkbs.append(factory.create_linestring(obj))
                    if waterway in MAJOR_WATERWAY_VALUES:
                        river_wkbs.append(factory.create_linestring(obj))
        except RuntimeError:
            # Broken OSM geometries are rare, but skipping them keeps the export moving.
            continue

    timed_substep(detailed_timings, "OSM source scan", scan_start)

    return build_meadow_frame(meadow_records, detailed_timings=detailed_timings), {
        "road": build_feature_frame("road", road_wkbs, detailed_timings=detailed_timings),
        "path": build_feature_frame("path", path_wkbs, detailed_timings=detailed_timings),
        "water": build_feature_frame("water", water_wkbs, detailed_timings=detailed_timings),
        "river": build_feature_frame("river", river_wkbs, detailed_timings=detailed_timings),
        "settlement": build_feature_frame(
            "settlement", settlement_wkbs, detailed_timings=detailed_timings
        ),
        "building": build_feature_frame("building", building_wkbs, detailed_timings=detailed_timings),
    }


def segment_geometry_for_index(
    geometry: BaseGeometry, max_segment_length: float
) -> list[BaseGeometry]:
    parts = getattr(geometry, "geoms", (geometry,))
    segmented_parts: list[BaseGeometry] = []
    for part in parts:
        if part.is_empty:
            continue
        if part.geom_type != "LineString":
            segmented_parts.append(part)
            continue

        length = part.length
        if length <= 0 or length <= max_segment_length:
            segmented_parts.append(part)
            continue

        start = 0.0
        while start < length:
            end = min(start + max_segment_length, length)
            segment = substring(part, start, end)
            if not segment.is_empty:
                segmented_parts.append(segment)
            start = end

    return segmented_parts


def build_category_index(
    name: str, frame: gpd.GeoDataFrame, max_segment_length: float = INDEX_SEGMENT_LENGTH_METERS
) -> CategoryGeometry:
    index_geometries: list[BaseGeometry] = []
    for geometry in frame.geometry:
        if geometry is None or geometry.is_empty:
            continue
        if name in SEGMENTED_INDEX_CATEGORIES:
            index_geometries.extend(segment_geometry_for_index(geometry, max_segment_length))
        else:
            index_geometries.append(geometry)

    geometries = np.asarray(index_geometries, dtype=object)
    tree = STRtree(geometries) if len(geometries) else None
    return CategoryGeometry(name=name, geometries=geometries, tree=tree)


def prepare_meadow_geometries(meadows: gpd.GeoDataFrame) -> MeadowGeometries:
    geometry_series = meadows.geometry
    all_geometries = geometry_series.to_numpy()
    valid_mask = (geometry_series.notna() & ~geometry_series.is_empty).to_numpy()
    valid_indices = np.flatnonzero(valid_mask)
    valid_geometries = all_geometries[valid_mask]
    return MeadowGeometries(
        all_geometries=all_geometries,
        valid_mask=valid_mask,
        valid_indices=valid_indices,
        valid_geometries=valid_geometries,
    )


def compute_nearest_distances(
    meadows: MeadowGeometries,
    category: CategoryGeometry,
    detailed_timings: bool = False,
) -> np.ndarray:
    if category.tree is None:
        return np.full(len(meadows.all_geometries), np.nan)

    distances = np.full(len(meadows.all_geometries), np.nan)
    if not len(meadows.valid_geometries):
        return distances

    query_start = perf_counter()
    index_pairs, nearest_distances = category.tree.query_nearest(
        meadows.valid_geometries,
        all_matches=False,
        return_distance=True,
    )
    timed_substep(
        detailed_timings,
        f"{category.name} nearest query",
        query_start,
    )

    ordered_distances = np.empty(len(meadows.valid_geometries), dtype=float)
    ordered_distances[index_pairs[0]] = nearest_distances
    distances[meadows.valid_mask] = ordered_distances
    return distances


def compute_local_relief_grid(
    elevation_grid: np.ndarray,
    valid_mask: np.ndarray,
    window_size: int,
) -> np.ndarray:
    pad = window_size // 2
    max_source = np.pad(
        np.where(valid_mask, elevation_grid, -np.inf),
        pad,
        mode="constant",
        constant_values=-np.inf,
    )
    min_source = np.pad(
        np.where(valid_mask, elevation_grid, np.inf),
        pad,
        mode="constant",
        constant_values=np.inf,
    )
    valid_source = np.pad(
        valid_mask.astype(np.int8),
        pad,
        mode="constant",
        constant_values=0,
    )

    max_windows = []
    min_windows = []
    valid_windows = []
    for row_offset in range(window_size):
        row_slice = slice(row_offset, row_offset + elevation_grid.shape[0])
        for col_offset in range(window_size):
            col_slice = slice(col_offset, col_offset + elevation_grid.shape[1])
            max_windows.append(max_source[row_slice, col_slice])
            min_windows.append(min_source[row_slice, col_slice])
            valid_windows.append(valid_source[row_slice, col_slice])

    neighborhood_max = np.maximum.reduce(max_windows)
    neighborhood_min = np.minimum.reduce(min_windows)
    neighborhood_valid_counts = np.add.reduce(valid_windows)

    local_relief = neighborhood_max - neighborhood_min
    local_relief[neighborhood_valid_counts == 0] = np.nan
    local_relief[~valid_mask] = np.nan
    return local_relief


def largest_component_area(geometry: BaseGeometry) -> float:
    if geometry.is_empty:
        return 0.0
    if hasattr(geometry, "geoms"):
        return max((largest_component_area(part) for part in geometry.geoms), default=0.0)
    return float(geometry.area)


def compute_terrain_metrics(
    meadows: gpd.GeoDataFrame,
    manifest_path: Path,
    detailed_timings: bool = False,
) -> TerrainMetrics:
    try:
        rasterio = importlib.import_module("rasterio")
        rasterio_features = importlib.import_module("rasterio.features")
        rasterio_transform = importlib.import_module("rasterio.transform")
    except ImportError as exc:
        raise RuntimeError(
            "Terrain metrics require rasterio. Install it with "
            "'python -m pip install rasterio' or add it from requirements.txt."
        ) from exc

    manifest_start = perf_counter()
    elevation_tiles = load_elevation_manifest(manifest_path)
    timed_substep(detailed_timings, "Elevation manifest load", manifest_start)

    tile_crs = elevation_tiles[0].crs
    inconsistent_tiles = [
        tile.tile_id for tile in elevation_tiles if tile.crs != tile_crs
    ]
    if inconsistent_tiles:
        raise ValueError(
            "Elevation manifest mixes raster CRSs, which is unsupported: "
            + ", ".join(inconsistent_tiles[:5])
        )

    reproject_start = perf_counter()
    meadows_elevation = meadows.to_crs(tile_crs)
    meadow_geometries = prepare_meadow_geometries(meadows_elevation)
    timed_substep(detailed_timings, "Elevation geometry reprojection", reproject_start)
    if not len(meadow_geometries.valid_geometries):
        empty_values = np.full(len(meadows), np.nan)
        return TerrainMetrics(
            average_elevation_deviation_m=empty_values.copy(),
            largest_flat_patch_m2=empty_values.copy(),
            largest_flat_patch_share=empty_values.copy(),
            flat_area_share=empty_values.copy(),
            terrain_roughness_p80_m=empty_values.copy(),
        )

    tree_start = perf_counter()
    meadow_tree = STRtree(meadow_geometries.valid_geometries)
    timed_substep(detailed_timings, "Elevation geometry index", tree_start)

    meadow_areas = np.asarray(meadows["area_m2"], dtype=float)
    flat_area_m2 = np.zeros(len(meadows), dtype=float)
    elevation_chunks: list[list[np.ndarray]] = [[] for _ in range(len(meadows))]
    relief_chunks: list[list[np.ndarray]] = [[] for _ in range(len(meadows))]
    flat_component_geometries: list[list[BaseGeometry]] = [[] for _ in range(len(meadows))]

    def sample_tile_pixels(
        tile: ElevationTile,
    ) -> tuple[
        np.ndarray,
        np.ndarray,
        np.ndarray,
        np.ndarray,
        np.ndarray,
        float,
        list[tuple[int, BaseGeometry]],
    ] | None:
        tile_bounds = shapely.box(*tile.bounds)
        candidate_positions = np.asarray(
            meadow_tree.query(tile_bounds, predicate="intersects"),
            dtype=int,
        )
        if not len(candidate_positions):
            return None

        meadow_indices = meadow_geometries.valid_indices[candidate_positions]
        shapes = [
            (meadow_geometries.all_geometries[meadow_index], local_id)
            for local_id, meadow_index in enumerate(meadow_indices, start=1)
        ]
        if not shapes:
            return None

        with open_rasterio_dem(rasterio, tile.tif_path) as dataset:
            band = dataset.read(1, masked=True)
            elevation_grid = np.asarray(band.data, dtype=float)
            dataset_bounds = (
                dataset.bounds.left,
                dataset.bounds.bottom,
                dataset.bounds.right,
                dataset.bounds.top,
            )
            transform = dataset.transform
            if has_identity_raster_bounds(dataset_bounds, dataset.width, dataset.height):
                transform = rasterio_transform.from_bounds(
                    *tile.bounds,
                    dataset.width,
                    dataset.height,
                )
            labels = rasterio_features.rasterize(
                shapes=shapes,
                out_shape=dataset.shape,
                transform=transform,
                fill=0,
                dtype="int32",
            )

        band_valid_mask = ~np.ma.getmaskarray(band)
        valid_mask = (labels > 0) & band_valid_mask
        if not np.any(valid_mask):
            return None

        local_relief = compute_local_relief_grid(
            elevation_grid,
            band_valid_mask,
            LOCAL_RELIEF_WINDOW_PIXELS,
        )
        label_values = np.asarray(labels[valid_mask], dtype=np.int64)
        elevation_values = np.asarray(elevation_grid[valid_mask], dtype=float)
        relief_values = np.asarray(local_relief[valid_mask], dtype=float)
        flat_mask = valid_mask & (local_relief <= FLAT_RELIEF_THRESHOLD_M)
        flat_label_values = np.asarray(labels[flat_mask], dtype=np.int64)
        pixel_area = abs(transform.a * transform.e)

        flat_component_entries: list[tuple[int, BaseGeometry]] = []
        if np.any(flat_mask):
            flat_labels = np.where(flat_mask, labels, 0).astype("int32", copy=False)
            for geometry_mapping, local_id_value in rasterio_features.shapes(
                flat_labels,
                mask=flat_labels > 0,
                transform=transform,
                connectivity=8,
            ):
                local_id = int(local_id_value)
                if local_id <= 0 or local_id > len(meadow_indices):
                    continue
                flat_component_entries.append(
                    (meadow_indices[local_id - 1], shape(geometry_mapping))
                )

        return (
            meadow_indices,
            label_values,
            elevation_values,
            relief_values,
            flat_label_values,
            pixel_area,
            flat_component_entries,
        )

    terrain_start = perf_counter()
    contributing_tiles = 0
    for tile in elevation_tiles:
        tile_sample = sample_tile_pixels(tile)
        if tile_sample is None:
            continue

        (
            meadow_indices,
            label_values,
            elevation_values,
            relief_values,
            flat_label_values,
            pixel_area,
            flat_component_entries,
        ) = tile_sample
        contributing_tiles += 1
        local_flat_counts = np.bincount(
            flat_label_values,
            minlength=len(meadow_indices) + 1,
        )
        flat_area_m2[meadow_indices] += local_flat_counts[1:] * pixel_area

        for local_id, meadow_index in enumerate(meadow_indices, start=1):
            local_mask = label_values == local_id
            if not np.any(local_mask):
                continue
            elevation_chunks[meadow_index].append(elevation_values[local_mask])
            relief_chunks[meadow_index].append(relief_values[local_mask])

        for meadow_index, component_geometry in flat_component_entries:
            flat_component_geometries[meadow_index].append(component_geometry)

    timed_substep(detailed_timings, "Terrain metric sampling", terrain_start)
    if detailed_timings:
        print(
            f"Terrain metric sampling used {contributing_tiles:,} tiles "
            f"for {len(meadows):,} meadows."
        )

    deviations = np.full(len(meadows), np.nan)
    largest_flat_patch_m2 = np.full(len(meadows), np.nan)
    largest_flat_patch_share = np.full(len(meadows), np.nan)
    flat_area_share = np.full(len(meadows), np.nan)
    terrain_roughness_p80_m = np.full(len(meadows), np.nan)

    finalize_start = perf_counter()
    for meadow_index in range(len(meadows)):
        if not elevation_chunks[meadow_index]:
            continue

        meadow_elevations = np.concatenate(elevation_chunks[meadow_index])
        meadow_mean = meadow_elevations.mean()
        deviations[meadow_index] = np.abs(meadow_elevations - meadow_mean).mean()

        meadow_relief = np.concatenate(relief_chunks[meadow_index])
        terrain_roughness_p80_m[meadow_index] = float(
            np.percentile(meadow_relief, TERRAIN_ROUGHNESS_PERCENTILE)
        )

        meadow_area = meadow_areas[meadow_index]
        if meadow_area > 0:
            flat_area_share[meadow_index] = min(flat_area_m2[meadow_index] / meadow_area, 1.0)

        largest_flat_patch_m2[meadow_index] = 0.0
        if meadow_area > 0:
            largest_flat_patch_share[meadow_index] = 0.0

        component_geometries = flat_component_geometries[meadow_index]
        if not component_geometries:
            continue

        merged_components = shapely.union_all(np.asarray(component_geometries, dtype=object))
        largest_area = largest_component_area(merged_components)
        if meadow_area > 0:
            capped = min(largest_area, meadow_area)
            largest_flat_patch_m2[meadow_index] = capped
            largest_flat_patch_share[meadow_index] = capped / meadow_area
        else:
            largest_flat_patch_m2[meadow_index] = 0.0

    timed_substep(detailed_timings, "Terrain metric finalization", finalize_start)
    return TerrainMetrics(
        average_elevation_deviation_m=deviations,
        largest_flat_patch_m2=largest_flat_patch_m2,
        largest_flat_patch_share=largest_flat_patch_share,
        flat_area_share=flat_area_share,
        terrain_roughness_p80_m=terrain_roughness_p80_m,
    )


def to_geojson_text(geometries: Iterable[BaseGeometry]) -> list[str]:
    geometry_array = np.asarray(geometries, dtype=object)
    return shapely.to_geojson(geometry_array).tolist()


def bbox_polygon_wkt(min_lng: float, min_lat: float, max_lng: float, max_lat: float) -> str:
    eps = 0.000001
    if min_lat == max_lat:
        max_lat += eps
    if min_lng == max_lng:
        max_lng += eps
    c = f"{min_lng:.10f} {min_lat:.10f}"
    return (
        f"POLYGON(({c},"
        f"{min_lng:.10f} {max_lat:.10f},"
        f"{max_lng:.10f} {max_lat:.10f},"
        f"{max_lng:.10f} {min_lat:.10f},"
        f"{c}))"
    )


def compute_display_geometries(
    meadow_geometries: MeadowGeometries,
    simplify_tolerance: float,
    detailed_timings: bool = False,
) -> gpd.GeoSeries:
    simplify_start = perf_counter()
    display_source = shapely.simplify(
        meadow_geometries.all_geometries,
        simplify_tolerance,
        preserve_topology=True,
    )
    display_source = np.where(
        shapely.is_empty(display_source),
        meadow_geometries.all_geometries,
        display_source,
    )
    timed_substep(detailed_timings, "Display geometry simplify", simplify_start)

    display_transform_start = perf_counter()
    display = gpd.GeoSeries(display_source, crs=ANALYSIS_CRS).to_crs(WGS84)
    timed_substep(detailed_timings, "Display geometry reprojection", display_transform_start)
    return display


def compute_centroid_series(
    meadow_geometries: MeadowGeometries, detailed_timings: bool = False
) -> gpd.GeoSeries:
    centroid_start = perf_counter()
    centroids = gpd.GeoSeries(
        shapely.centroid(meadow_geometries.all_geometries),
        crs=ANALYSIS_CRS,
    ).to_crs(WGS84)
    timed_substep(detailed_timings, "Centroid reprojection", centroid_start)
    return centroids


def build_export_frame(
    meadows: gpd.GeoDataFrame,
    meadow_geometries: MeadowGeometries,
    simplify_tolerance: float,
    detailed_timings: bool = False,
) -> tuple[pd.DataFrame, gpd.GeoSeries]:
    with ThreadPoolExecutor(max_workers=2) as executor:
        display_future = executor.submit(
            compute_display_geometries,
            meadow_geometries,
            simplify_tolerance,
            detailed_timings,
        )
        centroid_future = executor.submit(
            compute_centroid_series,
            meadow_geometries,
            detailed_timings,
        )
        display = display_future.result()
        centroids = centroid_future.result()

    bounds_start = perf_counter()
    bounds = shapely.bounds(display.to_numpy())
    timed_substep(detailed_timings, "Display bounds extraction", bounds_start)

    geojson_start = perf_counter()
    geom_geojson = to_geojson_text(display)
    timed_substep(detailed_timings, "GeoJSON serialization", geojson_start)

    export_start = perf_counter()
    min_lng_arr = bounds[:, 0]
    min_lat_arr = bounds[:, 1]
    max_lng_arr = bounds[:, 2]
    max_lat_arr = bounds[:, 3]
    bbox_wkt = np.array([
        bbox_polygon_wkt(min_lng_arr[i], min_lat_arr[i], max_lng_arr[i], max_lat_arr[i])
        for i in range(len(min_lat_arr))
    ])
    export = pd.DataFrame(
        {
            "source_id": meadows["source_id"],
            "source_type": meadows["source_type"],
            "land_cover_code": meadows["land_cover_code"],
            "area_ha": meadows["area_ha"],
            "area_m2": meadows["area_m2"],
            "average_elevation_deviation_m": meadows["average_elevation_deviation_m"],
            "largest_flat_patch_m2": meadows["largest_flat_patch_m2"],
            "largest_flat_patch_share": meadows["largest_flat_patch_share"],
            "flat_area_share": meadows["flat_area_share"],
            "terrain_roughness_p80_m": meadows["terrain_roughness_p80_m"],
            "nearest_road_m": meadows["nearest_road_m"],
            "nearest_path_m": meadows["nearest_path_m"],
            "nearest_water_m": meadows["nearest_water_m"],
            "nearest_river_m": meadows["nearest_river_m"],
            "nearest_settlement_m": meadows["nearest_settlement_m"],
            "nearest_building_m": meadows["nearest_building_m"],
            "centroid_lat": centroids.y,
            "centroid_lng": centroids.x,
            "min_lat": min_lat_arr,
            "min_lng": min_lng_arr,
            "max_lat": max_lat_arr,
            "max_lng": max_lng_arr,
            "geom_geojson": geom_geojson,
            "bbox_wkt": bbox_wkt,
        }
    )
    timed_substep(detailed_timings, "Export DataFrame assembly", export_start)

    return export, display


def write_preview(output_dir: Path, export: pd.DataFrame, display: gpd.GeoSeries) -> Path:
    preview_frame = gpd.GeoDataFrame(
        export.drop(columns=["geom_geojson"]),
        geometry=display,
        crs=WGS84,
    )
    preview_path = output_dir / "meadows_preview.geojson"
    preview_frame.to_file(preview_path, driver="GeoJSON")
    return preview_path


def write_metadata(
    output_dir: Path,
    export: pd.DataFrame,
    preview_path: Path | None,
    csv_paths: list[Path] | None,
    sql_paths: list[Path] | None,
    imported_to_db: bool,
    config: DatabaseConfig | None,
    osm_path: Path,
    elevation_manifest_path: Path,
) -> None:
    outputs: dict[str, Any] = {}
    if preview_path is not None:
        outputs["preview_geojson"] = preview_path.name
    if csv_paths:
        names = [p.name for p in csv_paths]
        outputs["csv"] = names[0] if len(names) == 1 else names
    if sql_paths:
        names = [p.name for p in sql_paths]
        outputs["sql"] = names[0] if len(names) == 1 else names
    if imported_to_db:
        outputs["database_table"] = config.table

    metadata = {
        "record_count": int(len(export)),
        "source_files": {
            "osm": osm_path.name,
            "elevation_manifest": elevation_manifest_path.name,
        },
        "crs_for_distance_calculations": ANALYSIS_CRS.to_string(),
        "crs_for_elevation_calculations": ELEVATION_CRS.to_string(),
        "terrain_flatness": {
            "flat_metric": "local_relief",
            "local_relief_window_pixels": LOCAL_RELIEF_WINDOW_PIXELS,
            "flat_relief_threshold_m": FLAT_RELIEF_THRESHOLD_M,
            "roughness_percentile": TERRAIN_ROUGHNESS_PERCENTILE,
        },
        "outputs": outputs,
    }
    (output_dir / "build_metadata.json").write_text(
        json.dumps(metadata, indent=2),
        encoding="utf-8",
    )


def dataframe_rows(export: pd.DataFrame) -> list[tuple[object, ...]]:
    prepared = export.astype(object).where(export.notna(), None)
    return list(prepared.itertuples(index=False, name=None))


def max_output_size_bytes(megabytes: float | None) -> int | None:
    if megabytes is None or megabytes <= 0:
        return None
    return int(megabytes * 1024 * 1024)


def sharded_import_path(output_dir: Path, base: str, suffix: str, shard_index: int) -> Path:
    if shard_index <= 1:
        return output_dir / f"{base}{suffix}"
    return output_dir / f"{base}_{shard_index}{suffix}"


def utf8_byte_length(text: str) -> int:
    return len(text.encode("utf-8"))


def format_csv_line_row(row: Sequence[object]) -> str:
    buf = io.StringIO()
    writer = csv.writer(buf, quoting=csv.QUOTE_MINIMAL, lineterminator="\n")
    writer.writerow(row)
    return buf.getvalue()


def write_csv_files(
    output_dir: Path,
    export: pd.DataFrame,
    *,
    max_size_bytes: int | None = None,
) -> list[Path]:
    output_dir.mkdir(parents=True, exist_ok=True)
    base = "meadows_import"
    suffix = ".csv"
    columns = list(export.columns)

    if max_size_bytes is None:
        path = sharded_import_path(output_dir, base, suffix, 1)
        export.to_csv(path, index=False, quoting=csv.QUOTE_MINIMAL, lineterminator="\n")
        return [path]

    rows = dataframe_rows(export)
    paths: list[Path] = []
    shard_idx = 1
    path = sharded_import_path(output_dir, base, suffix, shard_idx)
    fh: TextIO = path.open("w", encoding="utf-8", newline="")
    paths.append(path)
    writer = csv.writer(fh, quoting=csv.QUOTE_MINIMAL, lineterminator="\n")
    writer.writerow(columns)
    header_size = utf8_byte_length(format_csv_line_row(columns))
    current_size = header_size

    def rotate() -> None:
        nonlocal fh, shard_idx, path, current_size
        fh.close()
        shard_idx += 1
        path = sharded_import_path(output_dir, base, suffix, shard_idx)
        fh = path.open("w", encoding="utf-8", newline="")
        paths.append(path)
        w = csv.writer(fh, quoting=csv.QUOTE_MINIMAL, lineterminator="\n")
        w.writerow(columns)
        current_size = header_size

    for row in rows:
        line = format_csv_line_row(list(row))
        line_b = utf8_byte_length(line)
        if max_size_bytes is not None and line_b > max_size_bytes:
            print(
                "Warning: single CSV row exceeds --output-max-size-mb; writing it anyway.",
                flush=True,
            )
        while (
            max_size_bytes is not None
            and current_size + line_b > max_size_bytes
            and current_size > header_size
        ):
            rotate()
        fh.write(line)
        current_size += line_b

    fh.close()
    return paths


def sql_literal(value: object) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, (float, np.floating)):
        v = float(value)
        if np.isnan(v) or not np.isfinite(v):
            return "NULL"
        return str(v)
    if isinstance(value, (bool, np.bool_)):
        return "1" if value else "0"
    if isinstance(value, (int, np.integer)):
        return str(int(value))
    text = str(value).replace("\\", "\\\\").replace("\0", "\\0")
    return "'" + text.replace("'", "''") + "'"


def quoted_identifier(name: str) -> str:
    return "`" + name.replace("`", "``") + "`"


def geometry_table_name(base_table: str) -> str:
    if base_table == MEADOWS_EXPORT_TABLE:
        return MEADOWS_GEOMETRY_EXPORT_TABLE
    return f"{base_table}_geometries"


def stage_table_name(base_table: str) -> str:
    return f"{base_table}_import_stage"


def meadow_core_columns(columns: Sequence[str]) -> list[str]:
    return [column for column in columns if column not in ("geom_geojson", "bbox_wkt")]


def meadow_stage_table_sql(stage_table: str) -> str:
    stage_ident = quoted_identifier(stage_table)
    return f"""DROP TABLE IF EXISTS {stage_ident};
CREATE TABLE {stage_ident} (
    source_id VARCHAR(64) NOT NULL,
    source_type VARCHAR(16) NOT NULL,
    land_cover_code VARCHAR(64) NOT NULL,
    area_ha DOUBLE NOT NULL,
    area_m2 DOUBLE NOT NULL,
    average_elevation_deviation_m DOUBLE DEFAULT NULL,
    largest_flat_patch_m2 DOUBLE DEFAULT NULL,
    largest_flat_patch_share DOUBLE DEFAULT NULL,
    flat_area_share DOUBLE DEFAULT NULL,
    terrain_roughness_p80_m DOUBLE DEFAULT NULL,
    nearest_road_m DOUBLE DEFAULT NULL,
    nearest_path_m DOUBLE DEFAULT NULL,
    nearest_water_m DOUBLE DEFAULT NULL,
    nearest_river_m DOUBLE DEFAULT NULL,
    nearest_settlement_m DOUBLE DEFAULT NULL,
    nearest_building_m DOUBLE DEFAULT NULL,
    centroid_lat DOUBLE NOT NULL,
    centroid_lng DOUBLE NOT NULL,
    min_lat DOUBLE NOT NULL,
    min_lng DOUBLE NOT NULL,
    max_lat DOUBLE NOT NULL,
    max_lng DOUBLE NOT NULL,
    geom_geojson MEDIUMTEXT NOT NULL,
    bbox_wkt TEXT NOT NULL,
    PRIMARY KEY (source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
"""


def meadow_bbox_polygon_sql(source_alias: str) -> str:
    return f"ST_GeomFromText({source_alias}.bbox_wkt)"


def meadow_stage_merge_sql(base_table: str, columns: Sequence[str]) -> str:
    stage_table = stage_table_name(base_table)
    geometry_table = geometry_table_name(base_table)
    table_ident = quoted_identifier(base_table)
    stage_ident = quoted_identifier(stage_table)
    geometry_ident = quoted_identifier(geometry_table)
    core_columns = meadow_core_columns(columns)
    insert_columns = core_columns + ["bbox_polygon"]
    update_columns = [column for column in core_columns if column != "source_id"] + [
        "bbox_polygon",
    ]
    select_sql = ",\n    ".join([f"s.{column}" for column in core_columns] + [
        meadow_bbox_polygon_sql("s"),
    ])
    update_sql = ",\n    ".join(f"{column} = VALUES({column})" for column in update_columns)

    return f"""INSERT INTO {table_ident} (
    {", ".join(insert_columns)}
)
SELECT
    {select_sql}
FROM {stage_ident} s
ON DUPLICATE KEY UPDATE
    {update_sql};

DELETE m
FROM {table_ident} m
LEFT JOIN {stage_ident} s ON s.source_id = m.source_id
WHERE s.source_id IS NULL;

INSERT INTO {geometry_ident} (meadow_id, geom_geojson)
SELECT m.id, s.geom_geojson
FROM {stage_ident} s
INNER JOIN {table_ident} m ON m.source_id = s.source_id
ON DUPLICATE KEY UPDATE
    geom_geojson = VALUES(geom_geojson),
    updated_at = CURRENT_TIMESTAMP;

DROP TABLE IF EXISTS {stage_ident};
"""


def write_sql_files(
    output_dir: Path,
    export: pd.DataFrame,
    *,
    max_size_bytes: int | None = None,
) -> list[Path]:
    output_dir.mkdir(parents=True, exist_ok=True)
    base = "meadows_import"
    suffix = ".sql"
    columns = list(export.columns)
    col_list = ", ".join(columns)
    rows = dataframe_rows(export)
    stage_table = stage_table_name(MEADOWS_EXPORT_TABLE)
    header_sql = meadow_stage_table_sql(stage_table)
    footer_sql = meadow_stage_merge_sql(MEADOWS_EXPORT_TABLE, columns)

    paths: list[Path] = []
    shard_idx = 1
    path = sharded_import_path(output_dir, base, suffix, shard_idx)
    fh = path.open("w", encoding="utf-8")
    paths.append(path)
    fh.write(header_sql)
    size = utf8_byte_length(header_sql)

    batch_row_sqls: list[str] = []

    def rotate() -> None:
        nonlocal fh, shard_idx, path, size
        fh.close()
        shard_idx += 1
        path = sharded_import_path(output_dir, base, suffix, shard_idx)
        fh = path.open("w", encoding="utf-8")
        paths.append(path)
        size = 0

    def flush_batch() -> None:
        nonlocal size, batch_row_sqls
        if not batch_row_sqls:
            return
        stmt = (
            f"INSERT INTO {quoted_identifier(stage_table)} ({col_list}) VALUES "
            + ", ".join(batch_row_sqls)
            + ";\n"
        )
        stmt_b = utf8_byte_length(stmt)
        if max_size_bytes is not None and stmt_b > max_size_bytes:
            print(
                "Warning: single SQL INSERT batch exceeds --output-max-size-mb; writing it anyway.",
                flush=True,
            )
        if (
            max_size_bytes is not None
            and size > 0
            and size + stmt_b > max_size_bytes
        ):
            rotate()
        fh.write(stmt)
        size += stmt_b
        batch_row_sqls.clear()

    for row in rows:
        inner = ", ".join(sql_literal(v) for v in row)
        batch_row_sqls.append(f"({inner})")
        if len(batch_row_sqls) >= SQL_INSERT_BATCH_SIZE:
            flush_batch()

    flush_batch()
    footer_b = utf8_byte_length(footer_sql)
    if (
        max_size_bytes is not None
        and size > 0
        and size + footer_b > max_size_bytes
    ):
        rotate()
    fh.write(footer_sql)
    fh.close()
    return paths


def import_to_database(export: pd.DataFrame, config: DatabaseConfig, batch_size: int = 10000) -> None:
    try:
        pymysql = importlib.import_module("pymysql")
    except ImportError as exc:
        raise RuntimeError(
            "Direct database import requires PyMySQL. Install it with "
            "'python -m pip install PyMySQL' or add it from requirements.txt."
        ) from exc

    columns = list(export.columns)
    column_sql = ", ".join(columns)
    placeholder_sql = ", ".join(["%s"] * len(columns))
    stage_table = stage_table_name(config.table)
    stage_ident = quoted_identifier(stage_table)
    insert_sql = f"INSERT INTO {stage_ident} ({column_sql}) VALUES ({placeholder_sql})"
    merge_sql = meadow_stage_merge_sql(config.table, columns)
    rows = dataframe_rows(export)

    connection = pymysql.connect(
        host=config.host,
        port=config.port,
        user=config.user,
        password=config.password,
        database=config.name,
        charset="utf8mb4",
        autocommit=False,
    )
    try:
        with connection.cursor() as cursor:
            print(f"Preparing staging table {stage_table}...")
            for statement in [
                part.strip()
                for part in meadow_stage_table_sql(stage_table).split(";\n")
                if part.strip()
            ]:
                cursor.execute(statement)
            total_rows = len(rows)
            for start in range(0, total_rows, batch_size):
                end = min(start + batch_size, total_rows)
                print(f"Staging rows {start + 1:,}-{end:,} of {total_rows:,}...")
                cursor.executemany(insert_sql, rows[start:end])
            print(f"Merging staged rows into database table {config.table}...")
            for statement in [part.strip() for part in merge_sql.split(";\n") if part.strip()]:
                cursor.execute(statement)
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
        try:
            with connection.cursor() as cursor:
                cursor.execute(f"DROP TABLE IF EXISTS {stage_ident}")
            connection.commit()
        except Exception:
            connection.rollback()
        connection.close()


def compute_category_distance(
    category_name: str,
    frame: gpd.GeoDataFrame,
    meadow_geometries: MeadowGeometries,
    detailed_timings: bool = False,
) -> tuple[str, np.ndarray, float]:
    category_start = perf_counter()
    index_start = perf_counter()
    category = build_category_index(category_name, frame)
    timed_substep(detailed_timings, f"{category_name} index build", index_start)
    distances = compute_nearest_distances(
        meadow_geometries,
        category,
        detailed_timings=detailed_timings,
    )
    return category_name, distances, perf_counter() - category_start


def main() -> None:
    total_start = perf_counter()
    args = parse_args()
    validate_inputs(args)
    set_http_request_rate_limit(args.elevation_max_http_requests_per_minute)
    if _http_request_rate_limiter is not None:
        print(
            f"Capping elevation HTTP at {args.elevation_max_http_requests_per_minute:,} "
            "GETs/minute (rolling 60s window): catalog and dataset-feed XML plus ZIP downloads."
        )
    db_config: DatabaseConfig | None = None

    osm_start = perf_counter()
    osm_cache_path = args.osm_cache or default_osm_cache_path(args.osm)
    cache_load_start = perf_counter()
    osm_payload = load_osm_cache(osm_cache_path, args.osm)
    timed_substep(args.detailed_timings, "OSM cache load", cache_load_start)
    if osm_payload is None:
        print("Extracting OSM meadow polygons, roads, paths, waterways, and settlements...")
        extract_start = perf_counter()
        meadows, osm_features = extract_osm_data(
            args.osm,
            detailed_timings=args.detailed_timings,
        )
        timed_substep(args.detailed_timings, "OSM data extraction", extract_start)

        cache_save_start = perf_counter()
        save_osm_cache(osm_cache_path, args.osm, meadows, osm_features)
        timed_substep(args.detailed_timings, "OSM cache save", cache_save_start)
    else:
        meadows, osm_features = osm_payload
        print(f"Loaded cached OSM meadow data from {osm_cache_path}.")
    timed_print("OSM extraction", osm_start)

    clip_start = perf_counter()
    before_clip = len(meadows)
    meadows = clip_meadows_to_czech_republic(meadows)
    if before_clip != len(meadows):
        print(
            f"Clipped meadows to Czech Republic bounds: {before_clip:,} → {len(meadows):,} polygons."
        )
    timed_substep(args.detailed_timings, "Czech bounds clip", clip_start)

    if args.limit is not None:
        limit_start = perf_counter()
        meadows = meadows.iloc[: args.limit].reset_index(drop=True)
        timed_print("Limit filter", limit_start)

    if args.max_area_hectares is not None:
        filter_start = perf_counter()
        meadows = meadows.loc[meadows["area_ha"] <= args.max_area_hectares].reset_index(drop=True)
        timed_print("Area filter", filter_start)

    print(f"Loaded {len(meadows):,} meadow polygons from OSM.")

    elevation_manifest_path = args.elevation_manifest
    if len(meadows):
        elevation_prepare_start = perf_counter()
        print("Preparing elevation tiles for the current meadow footprint...")
        elevation_manifest_path = prepare_elevation_manifest_for_meadows(
            meadows,
            osd_url=args.elevation_osd_url,
            download_dir=args.elevation_download_dir,
            tile_dir=args.elevation_tile_dir,
            manifest_path=args.elevation_manifest,
            timeout=args.elevation_timeout,
            max_workers=args.elevation_max_workers,
            catalog_limit=args.elevation_catalog_limit,
        )
        timed_print("Elevation tile preparation", elevation_prepare_start)

        elevation_start = perf_counter()
        print("Computing terrain flatness metrics...")
        terrain_metrics = compute_terrain_metrics(
            meadows,
            elevation_manifest_path,
            detailed_timings=args.detailed_timings,
        )
        meadows["average_elevation_deviation_m"] = (
            terrain_metrics.average_elevation_deviation_m
        )
        meadows["largest_flat_patch_m2"] = terrain_metrics.largest_flat_patch_m2
        meadows["largest_flat_patch_share"] = terrain_metrics.largest_flat_patch_share
        meadows["flat_area_share"] = terrain_metrics.flat_area_share
        meadows["terrain_roughness_p80_m"] = terrain_metrics.terrain_roughness_p80_m
        timed_print("Terrain metric computation", elevation_start)
    else:
        print("No meadows matched the current filters; skipping elevation preparation.")
        meadows["average_elevation_deviation_m"] = np.full(len(meadows), np.nan)
        meadows["largest_flat_patch_m2"] = np.full(len(meadows), np.nan)
        meadows["largest_flat_patch_share"] = np.full(len(meadows), np.nan)
        meadows["flat_area_share"] = np.full(len(meadows), np.nan)
        meadows["terrain_roughness_p80_m"] = np.full(len(meadows), np.nan)

    meadow_geometry_start = perf_counter()
    meadow_geometries = prepare_meadow_geometries(meadows)
    timed_substep(args.detailed_timings, "Meadow geometry preparation", meadow_geometry_start)

    with ThreadPoolExecutor(max_workers=len(DISTANCE_CATEGORIES)) as executor:
        future_map = {}
        for category_name in DISTANCE_CATEGORIES:
            print(f"Computing nearest {category_name} distance...")
            future = executor.submit(
                compute_category_distance,
                category_name,
                osm_features[category_name],
                meadow_geometries,
                args.detailed_timings,
            )
            future_map[future] = category_name

        for future in as_completed(future_map):
            category_name, distances, elapsed = future.result()
            meadows[f"nearest_{category_name}_m"] = distances
            print(f"Nearest {category_name} distance took {format_elapsed(elapsed)}.")

    skip_preview = args.skip_preview
    auto_skipped_preview = False
    if args.import_to_db and not skip_preview:
        skip_preview = True
        auto_skipped_preview = True
        print("Skipping preview GeoJSON export during database import.")

    build_start = perf_counter()
    print("Building processed export frame...")
    export, display = build_export_frame(
        meadows,
        meadow_geometries,
        simplify_tolerance=args.simplify_tolerance,
        detailed_timings=args.detailed_timings,
    )
    timed_print("Export frame build", build_start)

    preview_path: Path | None = None
    if skip_preview:
        if not auto_skipped_preview:
            print("Skipping preview GeoJSON export.")
    else:
        preview_start = perf_counter()
        print("Writing preview GeoJSON...")
        preview_path = write_preview(args.output_dir, export, display)
        timed_print("Preview GeoJSON export", preview_start)

    csv_paths: list[Path] | None = None
    sql_paths: list[Path] | None = None
    max_out_bytes = max_output_size_bytes(args.output_max_size_mb)

    if args.import_to_db:
        config_start = perf_counter()
        print("Loading database configuration...")
        db_config = load_local_db_config(local_db_config_path())
        timed_print("Database config load", config_start)

        import_start = perf_counter()
        print("Importing processed data into MySQL...")
        import_to_database(export, db_config)
        timed_print("Database import", import_start)
    elif args.output_type == "csv":
        csv_start = perf_counter()
        print("Writing CSV import file(s)...")
        csv_paths = write_csv_files(
            args.output_dir, export, max_size_bytes=max_out_bytes
        )
        timed_print("CSV export", csv_start)
    else:
        sql_start = perf_counter()
        print("Writing SQL import file(s)...")
        sql_paths = write_sql_files(
            args.output_dir, export, max_size_bytes=max_out_bytes
        )
        timed_print("SQL export", sql_start)

    metadata_start = perf_counter()
    print("Writing build metadata...")
    write_metadata(
        args.output_dir,
        export,
        preview_path,
        csv_paths=csv_paths,
        sql_paths=sql_paths,
        imported_to_db=args.import_to_db,
        config=db_config,
        osm_path=args.osm,
        elevation_manifest_path=elevation_manifest_path,
    )
    timed_print("Metadata export", metadata_start)

    if args.import_to_db:
        print(f"Finished. Loaded {len(export):,} meadows into MySQL table {db_config.table}.")
    else:
        written = csv_paths or sql_paths or []
        names = ", ".join(str(path) for path in written)
        print(f"Finished. Import into MySQL using: {names}")
    print(f"Total runtime: {format_elapsed(perf_counter() - total_start)}.")


if __name__ == "__main__":
    main()
