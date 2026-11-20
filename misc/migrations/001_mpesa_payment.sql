-- M-Pesa STK Push payments. Run once on an existing database:
--   mysql -u root -p ecommerce < misc/migrations/001_mpesa_payment.sql
-- (Fresh Docker databases get this table from misc/ecommerce.sql.)

CREATE TABLE IF NOT EXISTS `payment` (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `order_no` int DEFAULT NULL,
  `user_id` int NOT NULL,
  `merchant_request_id` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `checkout_request_id` varchar(64) COLLATE utf8mb4_general_ci NOT NULL,
  `phone` varchar(12) COLLATE utf8mb4_general_ci NOT NULL,
  `amount` int NOT NULL,
  `status` enum('pending','success','failed','cancelled') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `result_code` int DEFAULT NULL,
  `result_desc` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `mpesa_receipt` varchar(32) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `callback_payload` text COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  UNIQUE KEY `checkout_request_id` (`checkout_request_id`),
  UNIQUE KEY `mpesa_receipt` (`mpesa_receipt`),
  KEY `order_no` (`order_no`),
  KEY `user_status` (`user_id`, `status`),
  CONSTRAINT `payment_order_fk` FOREIGN KEY (`order_no`) REFERENCES `orders` (`order_no`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `payment_user_fk` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
