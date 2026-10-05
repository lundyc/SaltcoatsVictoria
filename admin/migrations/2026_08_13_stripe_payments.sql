-- Stripe payments: Checkout Session payment links, transaction ledger, refunds
-- See hub/lib/stripe.php for the code that reads/writes these tables. The
-- application also creates these idempotently via ensureStripeSchema() on
-- first use, so running this file by hand is optional/for reference.
--
-- `stripe_payment_links` records every Checkout Session created for a
-- sponsorship agreement's outstanding balance (the "payment link" an admin
-- copies or emails to a sponsor).
--
-- `stripe_transactions` is the canonical Stripe transaction ledger that
-- powers the Stripe Dashboard and the "Stripe transactions" report. One row
-- per payment intent that has reached a terminal state.
--
-- `stripe_webhook_events` is an idempotency guard for Stripe's at-least-once
-- webhook delivery (same purpose as `social_posts.dedupe_key`).
--
-- `stripe_refunds` is an audit trail of refunds, whether initiated from the
-- Hub or directly in Stripe (reconciled via webhook).

CREATE TABLE IF NOT EXISTS `stripe_payment_links` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agreement_id` INT UNSIGNED NOT NULL,
  `stripe_checkout_session_id` VARCHAR(190) NOT NULL,
  `stripe_payment_intent_id` VARCHAR(190) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'gbp',
  `url` VARCHAR(500) NOT NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'open',
  `sent_to_email` VARCHAR(190) DEFAULT NULL,
  `sent_at` DATETIME DEFAULT NULL,
  `created_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME DEFAULT NULL,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_payment_links_session` (`stripe_checkout_session_id`),
  KEY `idx_stripe_payment_links_agreement` (`agreement_id`, `status`),
  CONSTRAINT `fk_stripe_payment_links_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `sponsorship_agreements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stripe_transactions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agreement_id` INT UNSIGNED NOT NULL,
  `payment_link_id` INT UNSIGNED DEFAULT NULL,
  `stripe_payment_intent_id` VARCHAR(190) NOT NULL,
  `stripe_checkout_session_id` VARCHAR(190) DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'gbp',
  `status` VARCHAR(30) NOT NULL DEFAULT 'succeeded',
  `refunded_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `raw_payload` MEDIUMTEXT DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_transactions_intent` (`stripe_payment_intent_id`),
  KEY `idx_stripe_transactions_agreement` (`agreement_id`, `status`),
  KEY `idx_stripe_transactions_link` (`payment_link_id`),
  CONSTRAINT `fk_stripe_transactions_agreement` FOREIGN KEY (`agreement_id`) REFERENCES `sponsorship_agreements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stripe_transactions_link` FOREIGN KEY (`payment_link_id`) REFERENCES `stripe_payment_links` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stripe_webhook_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `stripe_event_id` VARCHAR(190) NOT NULL,
  `type` VARCHAR(100) NOT NULL,
  `processed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_webhook_events_event` (`stripe_event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stripe_refunds` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` INT UNSIGNED NOT NULL,
  `stripe_refund_id` VARCHAR(190) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `reason` VARCHAR(255) DEFAULT NULL,
  `initiated_by` BIGINT UNSIGNED DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_stripe_refunds_refund` (`stripe_refund_id`),
  KEY `idx_stripe_refunds_transaction` (`transaction_id`),
  CONSTRAINT `fk_stripe_refunds_transaction` FOREIGN KEY (`transaction_id`) REFERENCES `stripe_transactions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
