-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 18, 2025 at 03:39 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `natcondash1`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_settings`
--

CREATE TABLE `admin_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin_settings`
--

INSERT INTO `admin_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'cert_generation_enabled', '0', '2025-09-18 13:01:31'),
(2, 'cert_auto_enable_date', '2025-10-18', '2025-09-18 13:01:31');

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `id` int(10) UNSIGNED NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `speaker_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `attendee_name` varchar(255) NOT NULL,
  `attendee_email` varchar(255) DEFAULT NULL,
  `present_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance_records`
--

CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `seminar_id` int(11) DEFAULT NULL,
  `speaker_id` int(11) DEFAULT NULL,
  `completed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `type` enum('attendance','appearance','cpd') NOT NULL,
  `speaker_id` int(11) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `function_rooms`
--

CREATE TABLE `function_rooms` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `location` varchar(150) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `function_room_days`
--

CREATE TABLE `function_room_days` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `day` date NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `user_id` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `attempts` int(11) NOT NULL DEFAULT 0,
  `last_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`user_id`, `email`, `otp_hash`, `expires_at`, `attempts`, `last_sent_at`) VALUES
(1, 'floresrandy.m@gmail.com', '$2y$10$OPhBfnOrvpJbhe34P0uuBOcesdx2hr4ZM5AV2FAtBORyPUBzGn88C', '2025-09-18 21:13:12', 0, '2025-09-18 21:03:12');

-- --------------------------------------------------------

--
-- Table structure for table `seminars`
--

CREATE TABLE `seminars` (
  `id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `speaker_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `day` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `speakers`
--

CREATE TABLE `speakers` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `eligible_certificate_attendance` tinyint(1) NOT NULL DEFAULT 0,
  `eligible_certificate_appearance` tinyint(1) NOT NULL DEFAULT 0,
  `eligible_cpd_certificate` tinyint(1) NOT NULL DEFAULT 0,
  `eligibility_updated_at` datetime DEFAULT NULL,
  `eligibility_note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `created_at`, `eligible_certificate_attendance`, `eligible_certificate_appearance`, `eligible_cpd_certificate`, `eligibility_updated_at`, `eligibility_note`) VALUES
(1, 'Randy', 'floresrandy.m@gmail.com', '$2y$10$njtQ1/1T/bAxfczw2FC71ekewmKYOQBcesdFD25eWWJrZ1.rG0/M6', '2025-08-02 18:10:09', 0, 0, 0, NULL, NULL),
(3, 'Jericho Morales', 'jerichomorales81@gmail.com', '$2y$10$NeC65FTU3OZYuyYfeteVfOo0i9gx2gFKIsXcjUrTAlDF93qBPxhmK', '2025-08-02 18:32:16', 0, 0, 0, NULL, NULL),
(4, 'Jemuel Agban', 'jemuelagban09@gmail.com', '$2y$10$jC3Tff1O63hji5nicG0jrOZTYq1GchP3h14baWwA5ddfVD2x4jZM2', '2025-08-02 18:57:17', 0, 0, 0, NULL, NULL),
(7, 'Jericho M', 'jerichomorales81+1@gmail.com', '$2y$10$fx0FBZ3k/hwwtuNIBh3paetz1Xh09OMlWWA0IbA0igNzSwBefBZbe', '2025-08-02 19:23:57', 0, 0, 0, NULL, NULL),
(8, 'Engr. Randy Flores', 'floresrandy.m+2@gmail.com', '$2y$10$ervxj5jcJL1urQhBCTaiTOm0.Kkyci6IHIg/xbWDghsRB7tohaKJm', '2025-09-18 21:11:57', 1, 1, 0, '2025-09-18 21:20:45', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_settings`
--
ALTER TABLE `admin_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_setting_key` (`setting_key`);

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_app_setting_key` (`setting_key`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_speaker_id` (`speaker_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_speaker_present_at` (`speaker_id`,`present_at`);

--
-- Indexes for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_completed` (`user_id`,`completed`),
  ADD KEY `idx_seminar_id` (`seminar_id`),
  ADD KEY `idx_speaker_id` (`speaker_id`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_type_speaker` (`user_id`,`type`,`speaker_id`),
  ADD KEY `idx_user_type` (`user_id`,`type`),
  ADD KEY `fk_cert_speaker` (`speaker_id`);

--
-- Indexes for table `function_rooms`
--
ALTER TABLE `function_rooms`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `function_room_days`
--
ALTER TABLE `function_room_days`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_room_day` (`room_id`,`day`),
  ADD KEY `idx_room_id` (`room_id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`email`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `seminars`
--
ALTER TABLE `seminars`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_room_day_time` (`room_id`,`day`,`start_time`),
  ADD KEY `idx_speaker_id` (`speaker_id`);

--
-- Indexes for table `speakers`
--
ALTER TABLE `speakers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin_settings`
--
ALTER TABLE `admin_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `app_settings`
--
ALTER TABLE `app_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance_records`
--
ALTER TABLE `attendance_records`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `function_rooms`
--
ALTER TABLE `function_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `function_room_days`
--
ALTER TABLE `function_room_days`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `seminars`
--
ALTER TABLE `seminars`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `speakers`
--
ALTER TABLE `speakers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `fk_attendance_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_attendance_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `attendance_records`
--
ALTER TABLE `attendance_records`
  ADD CONSTRAINT `fk_attrec_seminar` FOREIGN KEY (`seminar_id`) REFERENCES `seminars` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attrec_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_attrec_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `fk_cert_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_cert_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `function_room_days`
--
ALTER TABLE `function_room_days`
  ADD CONSTRAINT `fk_room_days_room` FOREIGN KEY (`room_id`) REFERENCES `function_rooms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `seminars`
--
ALTER TABLE `seminars`
  ADD CONSTRAINT `fk_seminar_room` FOREIGN KEY (`room_id`) REFERENCES `function_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_seminar_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
