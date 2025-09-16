-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Generation Time: Sep 05, 2025 at 09:09 AM
-- Server version: 8.0.42
-- PHP Version: 8.4.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vxjtgclw_luigitals_wallet`
--

-- --------------------------------------------------------

--
-- Table structure for table `bills`
--

CREATE TABLE `bills` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `category_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `due_date` date DEFAULT NULL,
  `is_recurring` tinyint(1) DEFAULT '0',
  `recurring_period` enum('weekly','monthly','quarterly','yearly') DEFAULT 'monthly',
  `auto_pay` tinyint(1) DEFAULT '0',
  `priority` enum('low','medium','high') DEFAULT 'medium',
  `status` enum('pending','paid','overdue') DEFAULT 'pending',
  `threshold_warning` decimal(15,2) DEFAULT '0.00',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `bills`
--

INSERT INTO `bills` (`id`, `user_id`, `category_id`, `name`, `amount`, `due_date`, `is_recurring`, `recurring_period`, `auto_pay`, `priority`, `status`, `threshold_warning`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 2, 'Electricity', 300.00, '2025-10-05', 1, 'monthly', 0, 'high', 'paid', 400.00, '', '2025-09-05 05:54:41', '2025-09-05 05:56:30'),
(2, 1, 11, 'Rent', 6000.00, '2025-10-05', 1, 'monthly', 0, 'high', 'pending', 0.00, '', '2025-09-05 05:56:12', '2025-09-05 05:56:12'),
(3, 1, 2, 'Electricity', 300.00, '2025-10-05', 1, 'monthly', 0, 'high', 'overdue', 400.00, '', '2025-09-05 05:56:30', '2025-09-05 06:04:28'),
(4, 1, 11, 'Rent', 6000.00, '2025-10-05', 1, 'monthly', 0, 'high', 'pending', 0.00, '', '2025-09-05 05:56:31', '2025-09-05 05:56:31'),
(5, 1, 4, 'TamTam', 300.00, '2025-11-05', 0, 'monthly', 0, 'low', 'paid', 0.00, '', '2025-09-05 06:06:07', '2025-09-05 06:06:18'),
(6, 1, 4, 'TamTam', 300.00, '2025-11-05', 0, 'monthly', 0, 'low', 'pending', 0.00, '', '2025-09-05 06:06:20', '2025-09-05 06:06:20');

-- --------------------------------------------------------

--
-- Stand-in structure for view `bill_summary`
-- (See below for the actual view)
--
CREATE TABLE `bill_summary` (
`overdue_amount` decimal(37,2)
,`overdue_bills` bigint
,`paid_bills` bigint
,`pending_amount` decimal(37,2)
,`pending_bills` bigint
,`user_id` int
);

-- --------------------------------------------------------

--
-- Table structure for table `budgets`
--

CREATE TABLE `budgets` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `category_id` int DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `allocated_amount` decimal(15,2) NOT NULL,
  `spent_amount` decimal(15,2) DEFAULT '0.00',
  `period_start` date NOT NULL,
  `period_end` date NOT NULL,
  `alert_threshold` decimal(5,2) DEFAULT '80.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `budgets`
--

INSERT INTO `budgets` (`id`, `user_id`, `category_id`, `name`, `allocated_amount`, `spent_amount`, `period_start`, `period_end`, `alert_threshold`, `created_at`, `updated_at`) VALUES
(1, 1, 11, 'Monthly', 22000.00, 0.00, '2025-09-05', '2025-10-05', 80.00, '2025-09-05 06:03:07', '2025-09-05 06:03:07'),
(2, 1, 4, 'Food', 5000.00, 300.00, '2025-09-05', '2025-10-05', 80.00, '2025-09-05 06:03:44', '2025-09-05 06:06:38');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `name` varchar(50) NOT NULL,
  `icon` varchar(50) DEFAULT 'fas fa-money-bill',
  `color` varchar(20) DEFAULT '#204cb0',
  `is_default` tinyint(1) DEFAULT '0',
  `user_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `icon`, `color`, `is_default`, `user_id`, `created_at`) VALUES
(1, 'Rent', 'fas fa-home', '#e74c3c', 1, 1, '2025-09-04 21:43:38'),
(2, 'Electricity', 'fas fa-bolt', '#f39c12', 1, 1, '2025-09-04 21:43:38'),
(3, 'Water', 'fas fa-tint', '#3498db', 1, 1, '2025-09-04 21:43:38'),
(4, 'Food & Groceries', 'fas fa-shopping-cart', '#16ac2e', 1, 1, '2025-09-04 21:43:38'),
(5, 'Fuel', 'fas fa-gas-pump', '#9b59b6', 1, 1, '2025-09-04 21:43:38'),
(6, 'Clothing', 'fas fa-tshirt', '#1abc9c', 1, 1, '2025-09-04 21:43:38'),
(7, 'Entertainment', 'fas fa-film', '#ff6b6b', 1, 1, '2025-09-04 21:43:38'),
(8, 'Healthcare', 'fas fa-medkit', '#fd79a8', 1, 1, '2025-09-04 21:43:38'),
(9, 'Transportation', 'fas fa-bus', '#fdcb6e', 1, 1, '2025-09-04 21:43:38'),
(10, 'Miscellaneous', 'fas fa-ellipsis-h', '#6c5ce7', 1, 1, '2025-09-04 21:43:38'),
(11, 'Rent', 'fas fa-home', '#e74c3c', 1, 1, '2025-09-04 23:55:26'),
(12, 'Electricity', 'fas fa-bolt', '#f39c12', 1, 1, '2025-09-04 23:55:26'),
(13, 'Water', 'fas fa-tint', '#3498db', 1, 1, '2025-09-04 23:55:26'),
(14, 'Food & Groceries', 'fas fa-shopping-cart', '#16ac2e', 1, 1, '2025-09-04 23:55:26'),
(15, 'Fuel', 'fas fa-gas-pump', '#9b59b6', 1, 1, '2025-09-04 23:55:26'),
(16, 'Clothing', 'fas fa-tshirt', '#1abc9c', 1, 1, '2025-09-04 23:55:26'),
(17, 'Entertainment', 'fas fa-film', '#ff6b6b', 1, 1, '2025-09-04 23:55:26'),
(18, 'Healthcare', 'fas fa-medkit', '#fd79a8', 1, 1, '2025-09-04 23:55:26'),
(19, 'Transportation', 'fas fa-bus', '#fdcb6e', 1, 1, '2025-09-04 23:55:26'),
(20, 'Miscellaneous', 'fas fa-ellipsis-h', '#6c5ce7', 1, 1, '2025-09-04 23:55:26'),
(21, 'Salary', 'fas fa-money-check-alt', '#27ae60', 1, 1, '2025-09-04 23:55:26'),
(22, 'Business', 'fas fa-briefcase', '#2980b9', 1, 1, '2025-09-04 23:55:26'),
(23, 'Investment', 'fas fa-chart-line', '#8e44ad', 1, 1, '2025-09-04 23:55:26'),
(24, 'Gift', 'fas fa-gift', '#e67e22', 1, 1, '2025-09-04 23:55:26');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','warning','success','error') DEFAULT 'info',
  `is_read` tinyint(1) DEFAULT '0',
  `related_bill_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`, `is_read`, `related_bill_id`, `created_at`) VALUES
(1, 1, 'New Bill Added', 'Bill \'Electricity\' for KES 300.00 has been added.', 'info', 0, NULL, '2025-09-05 05:54:41'),
(2, 1, 'New Bill Added', 'Bill \'Rent\' for KES 6,000.00 has been added.', 'info', 0, NULL, '2025-09-05 05:56:12'),
(3, 1, 'Bill Paid Successfully', 'Payment of KES 300.00 for Electricity has been processed.', 'success', 0, 1, '2025-09-05 05:56:30'),
(4, 1, 'New Bill Added', 'Bill \'Rent\' for KES 6,000.00 has been added.', 'info', 0, NULL, '2025-09-05 05:56:31'),
(5, 1, 'New Bill Added', 'Bill \'TamTam\' for KES 300.00 has been added.', 'info', 0, NULL, '2025-09-05 06:06:07'),
(6, 1, 'Bill Paid Successfully', 'Payment of KES 300.00 for TamTam has been processed.', 'success', 0, 5, '2025-09-05 06:06:18'),
(7, 1, 'New Bill Added', 'Bill \'TamTam\' for KES 300.00 has been added.', 'info', 0, NULL, '2025-09-05 06:06:20');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `user_id`, `setting_key`, `setting_value`, `created_at`, `updated_at`) VALUES
(1, 1, 'dark_mode', '1', '2025-09-04 21:43:38', '2025-09-05 06:00:59'),
(2, 1, 'currency', 'KES', '2025-09-04 21:43:38', '2025-09-04 21:43:38'),
(3, 1, 'notifications_enabled', '1', '2025-09-04 21:43:38', '2025-09-04 21:43:38'),
(4, 1, 'auto_backup', '1', '2025-09-04 21:43:38', '2025-09-04 21:43:38'),
(5, 1, 'dashboard_layout', 'grid', '2025-09-04 21:43:38', '2025-09-04 21:43:38'),
(6, 1, 'date_format', 'Y-m-d', '2025-09-04 21:43:38', '2025-09-04 21:43:38'),
(7, 1, 'salary_day', '1', '2025-09-04 21:43:38', '2025-09-04 21:43:38'),
(8, 1, 'low_balance_alert', '5000', '2025-09-04 21:43:38', '2025-09-04 21:43:38'),
(9, 1, 'high_expense_alert', '10000', '2025-09-04 21:43:38', '2025-09-04 21:43:38');

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `bill_id` int DEFAULT NULL,
  `category_id` int DEFAULT NULL,
  `type` enum('income','expense','transfer') NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `transaction_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `payment_method` enum('cash','bank','mobile_money','card') DEFAULT 'cash',
  `reference_number` varchar(100) DEFAULT NULL,
  `balance_after` decimal(15,2) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`id`, `user_id`, `bill_id`, `category_id`, `type`, `amount`, `description`, `transaction_date`, `payment_method`, `reference_number`, `balance_after`, `notes`, `created_at`) VALUES
(1, 1, NULL, 10, 'income', 100000.00, 'Sept', '2025-09-05 00:49:24', 'cash', NULL, 100000.00, NULL, '2025-09-04 21:49:24'),
(2, 1, NULL, 7, 'income', 8000.00, 'Phone Fix', '2025-09-05 00:50:18', 'cash', NULL, 108000.00, NULL, '2025-09-04 21:50:18'),
(3, 1, NULL, 13, 'expense', 200.00, 'Water', '2025-09-05 08:53:11', 'cash', NULL, 107800.00, NULL, '2025-09-05 05:53:11'),
(4, 1, NULL, 5, 'expense', 1000.00, '-', '2025-09-05 08:53:48', 'cash', NULL, 106800.00, NULL, '2025-09-05 05:53:48'),
(5, 1, 1, 2, 'expense', 300.00, 'Bill payment: Electricity', '2025-09-05 08:56:30', 'bank', NULL, 106500.00, NULL, '2025-09-05 05:56:30'),
(6, 1, 5, 4, 'expense', 300.00, 'Bill payment: TamTam', '2025-09-05 09:06:18', 'bank', NULL, 106200.00, NULL, '2025-09-05 06:06:18');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `salary` decimal(15,2) DEFAULT '0.00',
  `currency` varchar(10) DEFAULT 'KES',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `email`, `full_name`, `salary`, `currency`, `created_at`, `updated_at`) VALUES
(1, 'Lui', '$2y$12$Qz0x4OxrxL7N5ia928qLfe8bJniIHzCVvH9f8o6dozDRHoEP9kb3C', '', 'Luidigital', 100000.00, 'KES', '2025-09-04 21:43:38', '2025-09-05 05:57:32');

-- --------------------------------------------------------

--
-- Stand-in structure for view `user_monthly_summary`
-- (See below for the actual view)
--
CREATE TABLE `user_monthly_summary` (
`month` varchar(7)
,`monthly_expenses` decimal(37,2)
,`monthly_income` decimal(37,2)
,`transaction_count` bigint
,`user_id` int
,`username` varchar(50)
);

-- --------------------------------------------------------

--
-- Table structure for table `wallet_balance`
--

CREATE TABLE `wallet_balance` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `current_balance` decimal(15,2) DEFAULT '0.00',
  `last_salary_date` date DEFAULT NULL,
  `next_salary_date` date DEFAULT NULL,
  `total_income` decimal(15,2) DEFAULT '0.00',
  `total_expenses` decimal(15,2) DEFAULT '0.00',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `wallet_balance`
--

INSERT INTO `wallet_balance` (`id`, `user_id`, `current_balance`, `last_salary_date`, `next_salary_date`, `total_income`, `total_expenses`, `updated_at`) VALUES
(1, 1, 106200.00, NULL, NULL, 108000.00, 1800.00, '2025-09-05 06:06:18');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bills`
--
ALTER TABLE `bills`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_bills_user_due` (`user_id`,`due_date`),
  ADD KEY `idx_bills_status` (`status`),
  ADD KEY `idx_bills_user_recurring` (`user_id`,`is_recurring`);

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_budgets_alert` (`user_id`,`alert_threshold`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `related_bill_id` (`related_bill_id`),
  ADD KEY `idx_notifications_user_unread` (`user_id`,`is_read`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_setting` (`user_id`,`setting_key`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bill_id` (`bill_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `idx_transactions_user_date` (`user_id`,`transaction_date`),
  ADD KEY `idx_transactions_type` (`type`),
  ADD KEY `idx_transactions_amount` (`amount`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `wallet_balance`
--
ALTER TABLE `wallet_balance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bills`
--
ALTER TABLE `bills`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `wallet_balance`
--
ALTER TABLE `wallet_balance`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

-- --------------------------------------------------------

--
-- Structure for view `bill_summary`
--
DROP TABLE IF EXISTS `bill_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`vxjtgclw`@`localhost` SQL SECURITY DEFINER VIEW `bill_summary`  AS SELECT `b`.`user_id` AS `user_id`, count((case when (`b`.`status` = 'pending') then 1 end)) AS `pending_bills`, count((case when (`b`.`status` = 'overdue') then 1 end)) AS `overdue_bills`, count((case when (`b`.`status` = 'paid') then 1 end)) AS `paid_bills`, sum((case when (`b`.`status` = 'pending') then `b`.`amount` else 0 end)) AS `pending_amount`, sum((case when (`b`.`status` = 'overdue') then `b`.`amount` else 0 end)) AS `overdue_amount` FROM `bills` AS `b` GROUP BY `b`.`user_id` ;

-- --------------------------------------------------------

--
-- Structure for view `user_monthly_summary`
--
DROP TABLE IF EXISTS `user_monthly_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`vxjtgclw`@`localhost` SQL SECURITY DEFINER VIEW `user_monthly_summary`  AS SELECT `u`.`id` AS `user_id`, `u`.`username` AS `username`, date_format(`t`.`transaction_date`,'%Y-%m') AS `month`, sum((case when (`t`.`type` = 'income') then `t`.`amount` else 0 end)) AS `monthly_income`, sum((case when (`t`.`type` = 'expense') then `t`.`amount` else 0 end)) AS `monthly_expenses`, count(`t`.`id`) AS `transaction_count` FROM (`users` `u` left join `transactions` `t` on((`u`.`id` = `t`.`user_id`))) GROUP BY `u`.`id`, date_format(`t`.`transaction_date`,'%Y-%m') ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `bills`
--
ALTER TABLE `bills`
  ADD CONSTRAINT `bills_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `bills_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `budgets`
--
ALTER TABLE `budgets`
  ADD CONSTRAINT `budgets_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `budgets_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `categories_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`related_bill_id`) REFERENCES `bills` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `settings`
--
ALTER TABLE `settings`
  ADD CONSTRAINT `settings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `transactions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `transactions_ibfk_2` FOREIGN KEY (`bill_id`) REFERENCES `bills` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `transactions_ibfk_3` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `wallet_balance`
--
ALTER TABLE `wallet_balance`
  ADD CONSTRAINT `wallet_balance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
