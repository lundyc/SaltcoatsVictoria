-- Adds a table for player "action shots" — extra photos per player (beyond their single
-- profile picture) used when generating graphics such as Man of the Match, Goal and
-- Player Sponsor posters.
CREATE TABLE IF NOT EXISTS player_action_shots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  player_id INT UNSIGNED NOT NULL,
  filename VARCHAR(255) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_player_action_shots_player_id (player_id),
  CONSTRAINT fk_player_action_shots_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
