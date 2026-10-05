-- Migration: Adds slug-based routing support for clubs.
-- Execute only if the clubs table lacks the slug column/index.
ALTER TABLE clubs ADD COLUMN slug VARCHAR(120) NOT NULL AFTER name;
CREATE UNIQUE INDEX clubs_slug_unique ON clubs (slug);
UPDATE clubs SET slug = LOWER(REPLACE(name, ' ', '-')) WHERE slug IS NULL OR slug = '';
