-- Add discount_approval_logs table to track discount approvals
CREATE TABLE IF NOT EXISTS `discount_approval_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `discount_id` int(11) NOT NULL,
  `approved_by` int(11) NOT NULL,
  `approved_at` datetime NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_discount_approval_logs_discount` (`discount_id`),
  KEY `fk_discount_approval_logs_user` (`approved_by`),
  CONSTRAINT `fk_discount_approval_logs_discount` FOREIGN KEY (`discount_id`) REFERENCES `discounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_discount_approval_logs_user` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
