CREATE TABLE IF NOT EXISTS meadows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_id VARCHAR(64) NOT NULL,
    source_type VARCHAR(16) NOT NULL DEFAULT 'lpis',
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
    bbox_polygon POLYGON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_meadows_source_id (source_id),
    KEY idx_meadows_area_m2 (area_m2),
    KEY idx_meadows_largest_flat_patch_share (largest_flat_patch_share),
    KEY idx_meadows_flat_area_share (flat_area_share),
    KEY idx_meadows_terrain_roughness_p80_m (terrain_roughness_p80_m),
    KEY idx_meadows_nearest_road_m (nearest_road_m),
    KEY idx_meadows_nearest_path_m (nearest_path_m),
    KEY idx_meadows_nearest_water_m (nearest_water_m),
    KEY idx_meadows_nearest_river_m (nearest_river_m),
    KEY idx_meadows_nearest_settlement_m (nearest_settlement_m),
    KEY idx_meadows_nearest_building_m (nearest_building_m),
    SPATIAL INDEX idx_meadows_bbox_polygon (bbox_polygon)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meadow_geometries (
    meadow_id BIGINT UNSIGNED NOT NULL,
    geom_geojson MEDIUMTEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (meadow_id),
    CONSTRAINT fk_meadow_geometries_meadow FOREIGN KEY (meadow_id) REFERENCES meadows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    google_sub VARCHAR(255) NOT NULL,
    email VARCHAR(320) NOT NULL,
    display_name VARCHAR(255) NOT NULL,
    avatar_url VARCHAR(2048) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_google_sub (google_sub)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_favourite_meadows (
    user_id BIGINT UNSIGNED NOT NULL,
    source_id VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, source_id),
    KEY idx_fav_source_id (source_id),
    CONSTRAINT fk_fav_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
