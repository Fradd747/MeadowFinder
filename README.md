# Czech Meadow Finder

This project builds a meadow finder for the Czech Republic using:

- `czech-republic-260324.osm.pbf` as the OpenStreetMap source for meadow polygons tagged `landuse=meadow`
- the same OSM extract for roads, paths, waterways, and settlement place points
- Python for offline preprocessing
- MariaDB/MySQL + PHP for the hosted app
- Leaflet for the browser map UI

## Project Layout

- `scripts/prepare_meadows.py`: offline preprocessing pipeline
- `database/schema.sql`: MariaDB/MySQL schema for the hosted meadow tables
- `public/index.php`: main Leaflet application
- `public/api/meadows.php`: GeoJSON filter API
- `public/config/config.php`: database configuration loader

## 1. Install Python Dependencies

```bash
python -m pip install -r requirements.txt
```

This now includes `rasterio`, which is required for DEM tile extraction and meadow elevation-deviation sampling.

## 2. Download the Source Data

The large map source files are not included in this repository.

Download this file before running the preprocessing script:

- the Czech Republic OSM extract from [Geofabrik](https://download.geofabrik.de/europe/czech-republic.html) or directly from [dataset page](https://download.geofabrik.de/europe/czech-republic-latest.osm.pbf)

Place the file in the repository root.

The script expects these default filenames:

- `czech-republic-260324.osm.pbf`

## 3. Build the Upload Files

`prepare_meadows.py` now runs the full offline pipeline in one pass:

1. identify meadow polygons from OSM
2. resolve the CUZK elevation catalog and keep only tiles intersecting the selected meadows
3. download missing elevation ZIP/TIFF files into the local cache
4. compute terrain flatness and distance metrics
5. write the processed outputs

Run a pilot first:

```bash
python scripts/prepare_meadows.py --limit 500 --output-type csv
```

Profile the expensive sub-steps without changing the outputs:

```bash
python scripts/prepare_meadows.py --limit 500 --output-type csv --detailed-timings
```

Run the full export:

```bash
python scripts/prepare_meadows.py --output-type csv
```

For a SQL import bundle with batched staged `INSERT` statements (500 rows per statement), use `--output-type sql`. The generated SQL now loads into a staging table, merges rows by `source_id`, refreshes `meadow_geometries`, and preserves stable `meadows.id` values for unchanged meadows. To split large exports across multiple files, add e.g. `--output-max-size-mb 50` (applies to CSV or SQL shards).

The script writes or reuses:

- `data/elevation/downloads/`: cached elevation ZIP archives
- `data/elevation/tiles/`: extracted elevation TIFF tiles
- `data/elevation/manifest.json`: run-specific manifest for the current meadow footprint
- `data/processed/`: processed meadow outputs

Skip the preview GeoJSON when you only need the import payload and metadata:

```bash
python scripts/prepare_meadows.py --output-type csv --skip-preview
```

Run the full build and import directly into your local MariaDB/MySQL database from `public/config/config.local.php`:

```bash
python scripts/prepare_meadows.py --import
```

Useful elevation-specific overrides:

```bash
python scripts/prepare_meadows.py --output-type csv --elevation-download-dir data/elevation/downloads --elevation-tile-dir data/elevation/tiles --elevation-manifest data/elevation/manifest.json --elevation-max-workers 8 --elevation-timeout 60
```

`scripts/prepare_elevation_data.py` still exists as a compatibility wrapper if you want to prefill the full national elevation cache manually, but the normal workflow no longer requires running it first.

Outputs are written into `data/processed/`:

- `meadows_import.csv` (with `--output-type csv`) or `meadows_import.sql` (with `--output-type sql`): upload or run against MariaDB/MySQL; optional `--output-max-size-mb` adds `meadows_import_2.csv` / `_2.sql`, and so on
- `meadows_preview.geojson`: preview output for GIS or browser inspection
- `build_metadata.json`: small build summary

When `--skip-preview` is used, the script skips `meadows_preview.geojson` and records only the files it actually wrote in `build_metadata.json`.

When `--import` is used, the script stages rows in a temporary import table, merges them into `meadows` by `source_id`, refreshes `meadow_geometries`, still writes the preview and metadata files unless `--skip-preview` is set, and skips file export (`--output-type` is not required).

## 4. Create the Database

Create the tables from `database/schema.sql` (`meadows`, `meadow_geometries`, `users`, `user_favourite_meadows`).

Use a recent MariaDB/MySQL release with InnoDB spatial index support for `POLYGON` columns.

Then either:

- run `python scripts/prepare_meadows.py --import` to stage and merge data directly from Python
- generate `--output-type sql` and run the resulting `.sql` files in order using phpMyAdmin or the SQL client

The import flow now merges by `source_id` instead of truncating `meadows`, so existing `meadows.id` values stay stable for unchanged rows and `user_favourite_meadows` remains valid unless a meadow truly disappears from the dataset.

CSV output is still available, but it is now primarily a staging/export artifact for custom workflows rather than a direct final-table import format.

Expected CSV column order:

1. `source_id`
2. `source_type`
3. `land_cover_code`
4. `area_ha`
5. `area_m2`
6. `average_elevation_deviation_m`
7. `largest_flat_patch_m2`
8. `largest_flat_patch_share`
9. `flat_area_share`
10. `terrain_roughness_p80_m`
11. `nearest_road_m`
12. `nearest_path_m`
13. `nearest_water_m`
14. `nearest_river_m`
15. `nearest_settlement_m`
16. `nearest_building_m` (nearest non-excluded building; selected utility/harmless structure types are ignored during preprocessing)
17. `centroid_lat`
18. `centroid_lng`
19. `min_lat`
20. `min_lng`
21. `max_lat`
22. `max_lng`
23. `geom_geojson`

Terrain columns:

- `average_elevation_deviation_m`: legacy broad terrain-variation signal kept for reference
- `largest_flat_patch_m2`: area of the biggest connected flat part of the meadow
- `largest_flat_patch_share`: largest connected flat part divided by meadow area
- `flat_area_share`: total flat DEM area divided by meadow area
- `terrain_roughness_p80_m`: 80th percentile of local elevation relief within the meadow

## 5. Configure PHP

Copy:

- `public/config/config.local.php.example`

to:

- `public/config/config.local.php`

Then fill in your real MySQL credentials.

## 6. Google Sign-In (oblíbené louky)

The hosted app can optionally use **Google OAuth** for accounts and per-user favourite meadows. Set these in `public/config/config.local.php` or via environment variables:

- `google_client_id` / `MEADOW_GOOGLE_CLIENT_ID`
- `google_client_secret` / `MEADOW_GOOGLE_CLIENT_SECRET`
- `oauth_redirect_uri` / `MEADOW_OAUTH_REDIRECT_URI` — must be the **exact** callback URL registered in Google Cloud (for example `https://your-domain.example/api/auth_callback.php` when `public/` is the document root).
- Optionally `app_home_url` / `MEADOW_APP_HOME_URL` if automatic redirect after login should go to a fixed URL (otherwise it is derived from the script path).

**Google Cloud Console — checklist**

1. Open [Google Cloud Console](https://console.cloud.google.com/) and select or create a project.
2. **APIs & Services → OAuth consent screen**: choose **External** (unless everyone uses Google Workspace in your org). Enter app name, user support email, and developer contact. Under scopes, add **openid**, **email**, and **profile**. When the app is public, add your **Authorized domains** and link **Application privacy policy** and **Terms of service** to the deployed URLs of `privacy.php` and `terms.php`.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID → Web application**:
   - **Authorized JavaScript origins**: your site origin(s), e.g. `https://your-domain.example` (for local testing you can add `http://localhost:8080` or whatever port you use).
   - **Authorized redirect URIs**: the same URL as `oauth_redirect_uri` above (must match character for character).
4. Copy the **Client ID** and **Client secret** into `public/config/config.local.php` (never commit secrets).
5. While the OAuth app is in **Testing**, add every sign-in account under **Test users**. To open sign-in to everyone, publish the app and complete any verification Google requests.

Production should use **HTTPS** for the redirect URI and session cookies. Local development may use `http://localhost` with a matching redirect URI.

## 7. Deploy

Upload the `public/` directory to your PHP hosting and make sure it is served as your web root, or copy its contents into the site root.

If your host uses a subdirectory for the app, keep the `api/` and `assets/` paths together under the same document root.

## Notes

- The preprocessing script projects OSM geometry into `EPSG:5514`, which is appropriate for area and distance calculations in Czechia.
- Elevation tiles come from CUZK DMR4G in `EPSG:3045`, so the preprocessing pipeline reprojects meadows before raster sampling.
- Flatness is derived from local DEM relief in a small moving window. The default preprocessing marks pixels as flat when local relief is at most `1.5 m`, then exports both connected-flat-patch and overall flat-area metrics.
- Meadow candidates come from OpenStreetMap polygons tagged `landuse=meadow`, so output quality depends on local OSM coverage and tagging consistency.
- The hosted app does not compute spatial distances live. All heavy GIS work happens locally in Python before upload.
- The API now uses a spatial bbox prefilter on precomputed meadow envelopes, then applies the numeric filters in SQL.
- Display geometry is stored in `meadow_geometries`, so viewport counts and cluster queries can stay on the slimmer `meadows` table.
