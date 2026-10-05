-- Tracks, per player, whether their accepted profile picture has actually been
-- uploaded to the public club website (www.saltcoatsvictoria.co.uk) — a separate
-- concern from player_photo_reviews (which tracks whether the photo itself has
-- been reviewed/approved internally).
CREATE TABLE IF NOT EXISTS player_website_photos (
  player_id INT UNSIGNED NOT NULL,
  uploaded_to_website TINYINT(1) NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (player_id),
  CONSTRAINT fk_player_website_photos_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
