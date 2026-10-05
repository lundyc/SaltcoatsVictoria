-- Generalises match-photo tagging from "players only" to "anyone" (manager, staff,
-- fans, etc). tagged_people is now the single registry of who can be tagged in a
-- photo — a 'player' row links back to players.id (reusing their existing avatar /
-- action shots as face-match reference photos); every other category stores its own
-- reference photos in tagged_people_photos.
CREATE TABLE IF NOT EXISTS tagged_people (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  category ENUM('player','manager','staff','fan','other') NOT NULL DEFAULT 'other',
  player_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tagged_people_player (player_id),
  CONSTRAINT fk_tagged_people_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tagged_people_photos (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tagged_person_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tagged_people_photos_person (tagged_person_id),
  CONSTRAINT fk_tagged_people_photos_person FOREIGN KEY (tagged_person_id) REFERENCES tagged_people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- match_photo_tags previously referenced players.id directly; it ships unused (this
-- table was added earlier the same day and has no rows yet), so it's simplest to
-- recreate it pointing at tagged_people instead of migrating data that doesn't exist.
DROP TABLE IF EXISTS match_photo_tags;
CREATE TABLE match_photo_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  match_photo_id INT UNSIGNED NOT NULL,
  tagged_person_id INT UNSIGNED NOT NULL,
  confidence FLOAT NULL,
  source ENUM('auto','manual') NOT NULL DEFAULT 'auto',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_match_photo_tag (match_photo_id, tagged_person_id),
  KEY idx_match_photo_tags_person (tagged_person_id),
  CONSTRAINT fk_match_photo_tags_photo FOREIGN KEY (match_photo_id) REFERENCES match_photos(id) ON DELETE CASCADE,
  CONSTRAINT fk_match_photo_tags_person FOREIGN KEY (tagged_person_id) REFERENCES tagged_people(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
