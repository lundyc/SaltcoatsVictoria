-- Match-day photo gallery: photos uploaded against a specific fixture, auto-tagged with
-- the players face-matching finds in them (see hub/tools/face_match.py), filterable
-- later by player, match and which kit was worn.
CREATE TABLE IF NOT EXISTS match_photos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_fixture_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  kit ENUM('home','away','third') NULL,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_match_photos_fixture (match_fixture_id),
  CONSTRAINT fk_match_photos_fixture FOREIGN KEY (match_fixture_id) REFERENCES match_fixtures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS match_photo_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_photo_id INT UNSIGNED NOT NULL,
  player_id INT UNSIGNED NOT NULL,
  confidence FLOAT NULL,
  source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_photo_tag (match_photo_id, player_id),
  KEY idx_match_photo_tags_player (player_id),
  CONSTRAINT fk_match_photo_tags_photo FOREIGN KEY (match_photo_id) REFERENCES match_photos(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_photo_tags_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
