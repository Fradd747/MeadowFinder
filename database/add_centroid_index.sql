-- Optional. Speeds filtered cluster map queries. Safe to skip if this error appears:
-- Duplicate key name 'idx_meadows_centroid_lng_lat'
ALTER TABLE meadows
    ADD INDEX idx_meadows_centroid_lng_lat (centroid_lng, centroid_lat);
