# Czech Meadow Finder

This project builds a meadow finder for the Czech Republic using:

- `AgriculturalArea.gpkg` as the LPIS grassland parcel source
- `czech-republic-260324.osm.pbf` for roads, paths, waterways, and settlement place points
- Python for offline preprocessing
- MySQL + PHP for the hosted app
- Leaflet for the browser map UI

## Project Layout

- `scripts/prepare_meadows.py`: offline preprocessing pipeline
- `database/schema.sql`: MySQL schema for the hosted meadow table
- `public/index.php`: main Leaflet application
- `public/api/meadows.php`: GeoJSON filter API
- `public/api/config.php`: database configuration loader

## 1. Install Python Dependencies

```bash
python -m pip install -r requirements.txt
```

## 2. Download the Source Data

The large map source files are not included in this repository.

Download these files before running the preprocessing script:

- `AgriculturalArea.gpkg` from [Agricultural Area of Czech Republic](https://geoportal.gov.cz/atom/MZe/AgriculturalArea.gpkg) or the [dataset page](https://data.gov.cz/dataset?iri=https%3A%2F%2Fdata.gov.cz%2Fzdroj%2Fdatov%C3%A9-sady%2F00020478%2Fae813a4158ceedecf2d60b63ed586cad)
- the Czech Republic OSM extract from [Geofabrik](https://download.geofabrik.de/europe/czech-republic.html) or directly from [dataset page](https://download.geofabrik.de/europe/czech-republic-latest.osm.pbf)

Place both files in the repository root.

The script expects these default filenames:

- `AgriculturalArea.gpkg`
- `czech-republic-260324.osm.pbf`

## 3. Build the Upload Files

Run a pilot first:

```bash
python scripts/prepare_meadows.py --limit 500
```

Profile the expensive sub-steps without changing the outputs:

```bash
python scripts/prepare_meadows.py --limit 500 --detailed-timings
```

Run the full export:

```bash
python scripts/prepare_meadows.py
```

Skip the preview GeoJSON when you only need the import payload and metadata:

```bash
python scripts/prepare_meadows.py --skip-preview
```

Run the full build and import directly into your local MySQL database from `public/api/config.local.php`:

```bash
python scripts/prepare_meadows.py --import
```

Outputs are written into `data/processed/`:

- `meadows_import.csv`: upload this into MySQL
- `meadows_preview.geojson`: preview output for GIS or browser inspection
- `build_metadata.json`: small build summary

When `--skip-preview` is used, the script skips `meadows_preview.geojson` and records only the files it actually wrote in `build_metadata.json`.

When `--import` is used, the script truncates the `meadows` table first, imports rows directly into MySQL, still writes the preview and metadata files unless `--skip-preview` is set, and skips the CSV export.

## 4. Create the Database

Create the `meadows` table from `database/schema.sql`.

Then either import `data/processed/meadows_import.csv` using phpMyAdmin or another web-based MySQL import tool, or run `python scripts/prepare_meadows.py --import` to load it automatically from Python.

Expected CSV column order:

1. `source_id`
2. `source_type`
3. `land_cover_code`
4. `area_ha`
5. `area_m2`
6. `nearest_road_m`
7. `nearest_path_m`
8. `nearest_water_m`
9. `nearest_river_m`
10. `nearest_settlement_m`
11. `centroid_lat`
12. `centroid_lng`
13. `min_lat`
14. `min_lng`
15. `max_lat`
16. `max_lng`
17. `geom_geojson`

## 5. Configure PHP

Copy:

- `public/api/config.local.php.example`

to:

- `public/api/config.local.php`

Then fill in your real MySQL credentials.

## 6. Deploy

Upload the `public/` directory to your PHP hosting and make sure it is served as your web root, or copy its contents into the site root.

If your host uses a subdirectory for the app, keep the `api/` and `assets/` paths together under the same document root.

## Notes

- LPIS data is in `EPSG:5514`, which is appropriate for area and distance calculations in Czechia.
- The hosted app does not compute spatial distances live. All heavy GIS work happens locally in Python before upload.
- The API filters by viewport and numeric values only, which keeps PHP and MySQL simple enough for shared hosting.
