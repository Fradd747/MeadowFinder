from __future__ import annotations

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import csv
import importlib
import json
import pickle
import re
from dataclasses import dataclass
from pathlib import Path
from time import perf_counter
from typing import Iterable

import geopandas as gpd
import numpy as np
import osmium
import pandas as pd
import shapely
from osmium.filter import KeyFilter
from pyproj import CRS
from shapely import STRtree, from_wkb
from shapely.geometry.base import BaseGeometry
from shapely.ops import substring


WGS84 = "EPSG:4326"
LPIS_CRS = CRS.from_epsg(5514)
DEFAULT_LAYER = "agriculturalarea"
GRASSLAND_CODE = "PermanentGrassland"
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
DISTANCE_CATEGORIES = ("road", "path", "water", "river", "settlement")
SEGMENTED_INDEX_CATEGORIES = {"water", "river"}
INDEX_SEGMENT_LENGTH_METERS = 1_000.0


@dataclass
class CategoryGeometry:
    name: str
    geometries: np.ndarray
    tree: STRtree | None


@dataclass
class MeadowGeometries:
    all_geometries: np.ndarray
    valid_mask: np.ndarray
    valid_geometries: np.ndarray


@dataclass
class DatabaseConfig:
    host: str
    port: int
    name: str
    user: str
    password: str
    table: str


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(
        description="Prepare meadow polygons and precomputed proximity metrics."
    )
    parser.add_argument(
        "--lpis",
        type=Path,
        default=Path("AgriculturalArea.gpkg"),
        help="Path to the LPIS GeoPackage.",
    )
    parser.add_argument(
        "--osm",
        type=Path,
        default=Path("czech-republic-260324.osm.pbf"),
        help="Path to the OSM PBF extract for Czech Republic.",
    )
    parser.add_argument(
        "--layer",
        default=DEFAULT_LAYER,
        help="GeoPackage layer name with agricultural parcels.",
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
        default=12.0,
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
    return parser.parse_args()


def validate_inputs(args: argparse.Namespace) -> None:
    missing = [str(path) for path in [args.lpis, args.osm] if not path.exists()]
    if missing:
        raise FileNotFoundError(f"Missing input files: {', '.join(missing)}")

    args.output_dir.mkdir(parents=True, exist_ok=True)


def format_elapsed(seconds: float) -> str:
    return f"{seconds:.2f}s"


def project_root() -> Path:
    return Path(__file__).resolve().parents[1]


def local_db_config_path() -> Path:
    return project_root() / "public" / "api" / "config.local.php"


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


def load_meadows(lpis_path: Path, layer: str, limit: int | None) -> gpd.GeoDataFrame:
    where_clause = f"agricult_2 = '{GRASSLAND_CODE}'"
    meadows = gpd.read_file(
        lpis_path,
        layer=layer,
        where=where_clause,
        engine="pyogrio",
    )
    if limit is not None:
        meadows = meadows.iloc[:limit].reset_index(drop=True)

    if meadows.crs is None:
        meadows = meadows.set_crs(LPIS_CRS)
    else:
        meadows = meadows.to_crs(LPIS_CRS)

    meadows["source_id"] = meadows["agricultur"]
    meadows["source_type"] = "lpis"
    meadows["land_cover_code"] = meadows["agricult_2"]
    meadows["area_ha"] = meadows["agricult_3"].astype(float)
    meadows["area_m2"] = meadows.geometry.area
    return meadows


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
    return gpd.GeoDataFrame(geometry=gpd.GeoSeries([], crs=LPIS_CRS))


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
    projected = frame.to_crs(LPIS_CRS)
    timed_substep(detailed_timings, f"OSM {name} reprojection", frame_start)
    return projected


def load_osm_cache(
    cache_path: Path, osm_path: Path
) -> dict[str, gpd.GeoDataFrame] | None:
    if not cache_path.exists():
        return None

    try:
        with cache_path.open("rb") as handle:
            payload = pickle.load(handle)
    except (OSError, pickle.PickleError):
        return None

    if payload.get("source") != osm_source_signature(osm_path):
        return None

    features = payload.get("features")
    if not isinstance(features, dict):
        return None

    required_categories = {"road", "path", "water", "river", "settlement"}
    if not required_categories.issubset(features):
        return None
    return features


def save_osm_cache(
    cache_path: Path, osm_path: Path, features: dict[str, gpd.GeoDataFrame]
) -> None:
    cache_path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "source": osm_source_signature(osm_path),
        "features": features,
    }
    with cache_path.open("wb") as handle:
        pickle.dump(payload, handle, protocol=pickle.HIGHEST_PROTOCOL)


def extract_osm_features(
    osm_path: Path, detailed_timings: bool = False
) -> dict[str, gpd.GeoDataFrame]:
    factory = osmium.geom.WKBFactory()
    processor = (
        osmium.FileProcessor(str(osm_path))
        .with_locations()
        .with_filter(KeyFilter("highway", "waterway", "place"))
    )

    road_wkbs: list[str] = []
    path_wkbs: list[str] = []
    water_wkbs: list[str] = []
    river_wkbs: list[str] = []
    settlement_wkbs: list[str] = []

    scan_start = perf_counter()
    for obj in processor:
        tags = obj.tags
        try:
            if obj.is_node():
                if "place" in tags and tags["place"] in SETTLEMENT_VALUES:
                    settlement_wkbs.append(factory.create_point(obj))
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

    return {
        "road": build_feature_frame("road", road_wkbs, detailed_timings=detailed_timings),
        "path": build_feature_frame("path", path_wkbs, detailed_timings=detailed_timings),
        "water": build_feature_frame("water", water_wkbs, detailed_timings=detailed_timings),
        "river": build_feature_frame("river", river_wkbs, detailed_timings=detailed_timings),
        "settlement": build_feature_frame(
            "settlement", settlement_wkbs, detailed_timings=detailed_timings
        ),
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
    valid_geometries = all_geometries[valid_mask]
    return MeadowGeometries(
        all_geometries=all_geometries,
        valid_mask=valid_mask,
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


def to_geojson_text(geometries: Iterable[BaseGeometry]) -> list[str]:
    geometry_array = np.asarray(geometries, dtype=object)
    return shapely.to_geojson(geometry_array).tolist()


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
    display = gpd.GeoSeries(display_source, crs=LPIS_CRS).to_crs(WGS84)
    timed_substep(detailed_timings, "Display geometry reprojection", display_transform_start)
    return display


def compute_centroid_series(
    meadow_geometries: MeadowGeometries, detailed_timings: bool = False
) -> gpd.GeoSeries:
    centroid_start = perf_counter()
    centroids = gpd.GeoSeries(
        shapely.centroid(meadow_geometries.all_geometries),
        crs=LPIS_CRS,
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
    export = pd.DataFrame(
        {
            "source_id": meadows["source_id"],
            "source_type": meadows["source_type"],
            "land_cover_code": meadows["land_cover_code"],
            "area_ha": meadows["area_ha"],
            "area_m2": meadows["area_m2"],
            "nearest_road_m": meadows["nearest_road_m"],
            "nearest_path_m": meadows["nearest_path_m"],
            "nearest_water_m": meadows["nearest_water_m"],
            "nearest_river_m": meadows["nearest_river_m"],
            "nearest_settlement_m": meadows["nearest_settlement_m"],
            "centroid_lat": centroids.y,
            "centroid_lng": centroids.x,
            "min_lat": bounds[:, 1],
            "min_lng": bounds[:, 0],
            "max_lat": bounds[:, 3],
            "max_lng": bounds[:, 2],
            "geom_geojson": geom_geojson,
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


def write_csv(output_dir: Path, export: pd.DataFrame) -> Path:
    csv_path = output_dir / "meadows_import.csv"
    export.to_csv(csv_path, index=False, quoting=csv.QUOTE_MINIMAL)
    return csv_path


def write_metadata(
    output_dir: Path,
    export: pd.DataFrame,
    preview_path: Path | None,
    csv_path: Path | None,
    imported_to_db: bool,
    config: DatabaseConfig | None,
) -> None:
    outputs: dict[str, str] = {}
    if preview_path is not None:
        outputs["preview_geojson"] = preview_path.name
    if csv_path is not None:
        outputs["csv"] = csv_path.name
    if imported_to_db:
        outputs["database_table"] = config.table

    metadata = {
        "record_count": int(len(export)),
        "source_files": {
            "lpis": "AgriculturalArea.gpkg",
            "osm": "czech-republic-260324.osm.pbf",
        },
        "crs_for_distance_calculations": LPIS_CRS.to_string(),
        "outputs": outputs,
    }
    (output_dir / "build_metadata.json").write_text(
        json.dumps(metadata, indent=2),
        encoding="utf-8",
    )


def dataframe_rows(export: pd.DataFrame) -> list[tuple[object, ...]]:
    prepared = export.astype(object).where(export.notna(), None)
    return list(prepared.itertuples(index=False, name=None))


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
    insert_sql = f"INSERT INTO {config.table} ({column_sql}) VALUES ({placeholder_sql})"
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
            print(f"Truncating database table {config.table}...")
            cursor.execute(f"TRUNCATE TABLE {config.table}")
            total_rows = len(rows)
            for start in range(0, total_rows, batch_size):
                end = min(start + batch_size, total_rows)
                print(f"Inserting rows {start + 1:,}-{end:,} of {total_rows:,}...")
                cursor.executemany(insert_sql, rows[start:end])
        connection.commit()
    except Exception:
        connection.rollback()
        raise
    finally:
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
    db_config: DatabaseConfig | None = None

    load_start = perf_counter()
    print("Loading LPIS grassland polygons...")
    meadows = load_meadows(args.lpis, args.layer, args.limit)
    timed_print("LPIS load", load_start)

    if args.max_area_hectares is not None:
        filter_start = perf_counter()
        meadows = meadows.loc[meadows["area_ha"] <= args.max_area_hectares].reset_index(drop=True)
        timed_print("Area filter", filter_start)

    print(f"Loaded {len(meadows):,} meadow polygons.")

    osm_start = perf_counter()
    osm_cache_path = args.osm_cache or default_osm_cache_path(args.osm)
    cache_load_start = perf_counter()
    osm_features = load_osm_cache(osm_cache_path, args.osm)
    timed_substep(args.detailed_timings, "OSM cache load", cache_load_start)
    if osm_features is None:
        print("Extracting OSM roads, paths, waterways, and settlements...")
        extract_start = perf_counter()
        osm_features = extract_osm_features(args.osm, detailed_timings=args.detailed_timings)
        timed_substep(args.detailed_timings, "OSM feature extraction", extract_start)

        cache_save_start = perf_counter()
        save_osm_cache(osm_cache_path, args.osm, osm_features)
        timed_substep(args.detailed_timings, "OSM cache save", cache_save_start)
    else:
        print(f"Loaded cached OSM features from {osm_cache_path}.")
    timed_print("OSM extraction", osm_start)

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

    csv_path: Path | None = None
    if args.import_to_db:
        config_start = perf_counter()
        print("Loading database configuration...")
        db_config = load_local_db_config(local_db_config_path())
        timed_print("Database config load", config_start)

        import_start = perf_counter()
        print("Importing processed data into MySQL...")
        import_to_database(export, db_config)
        timed_print("Database import", import_start)
    else:
        csv_start = perf_counter()
        print("Writing CSV import file...")
        csv_path = write_csv(args.output_dir, export)
        timed_print("CSV export", csv_start)

    metadata_start = perf_counter()
    print("Writing build metadata...")
    write_metadata(
        args.output_dir,
        export,
        preview_path,
        csv_path=csv_path,
        imported_to_db=args.import_to_db,
        config=db_config,
    )
    timed_print("Metadata export", metadata_start)

    if args.import_to_db:
        print(f"Finished. Loaded {len(export):,} meadows into MySQL table {db_config.table}.")
    else:
        print(f"Finished. Upload {args.output_dir / 'meadows_import.csv'} to MySQL.")
    print(f"Total runtime: {format_elapsed(perf_counter() - total_start)}.")


if __name__ == "__main__":
    main()
