-- Facebook manual override tracking (matchday publishing strategy change).
--
-- Live match events (goal, cards, subs, penalty, general match update) are
-- now off by default for automatic Facebook publishing (see
-- lib/facebook_publisher.php facebook_post_type_defaults()). Administrators
-- can still deliberately publish an individual event to Facebook via
-- "Post to Facebook Anyway" in the match console; this column distinguishes
-- that deliberate manual override from a normal automatic/manual publish in
-- facebook_diagnostics.php. Overrides still go through the same atomic
-- fingerprint dedupe as every other publish — this column is audit-only, it
-- does not change duplicate handling.

ALTER TABLE `social_posts`
  ADD COLUMN `is_override` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_automatic`;
