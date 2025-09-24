-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 24, 2025 at 12:24 PM
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
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(150) NOT NULL,
  `role` enum('super','manager','staff') NOT NULL DEFAULT 'manager',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `last_login_ip` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`id`, `email`, `password`, `name`, `role`, `is_active`, `last_login_at`, `last_login_ip`, `created_at`, `updated_at`) VALUES
(1, 'rflores091902@gmail.com', '$2y$10$FN9gjuDPMAsSy/87R8ZpqOI7UxM3N71D8UkMHf9rs0VWZjroBUobu', 'randy', 'super', 1, NULL, NULL, '2025-09-19 06:50:29', '2025-09-19 06:52:40'),
(2, 'ndformeloza@gmail.com', '$2y$10$bMaBDBImS1qsKKelCnc8AeFfM6.LTtNpUDD1asW8Lwlvfu5PU0QVO', 'Nicole', '', 1, NULL, NULL, '2025-09-22 13:32:27', '2025-09-22 13:32:27'),
(3, 'ndformeloza+1@gmail.com', '$2y$10$CvMQZnZIb4c8nxHtFWYWROOkdDV9d.7hdqOejcKa67NTYC75um0fq', 'Nicole', '', 1, NULL, NULL, '2025-09-22 13:35:17', '2025-09-22 13:35:17');

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

--
-- Dumping data for table `app_settings`
--

INSERT INTO `app_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES
(1, 'function_room_mode', 'admin_only', '2025-09-19 07:14:45');

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
  `user_id` int(11) DEFAULT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'attendance',
  `speaker_id` int(11) DEFAULT NULL,
  `bg_url` varchar(255) NOT NULL,
  `signature_url` varchar(255) DEFAULT NULL,
  `signatory_name` varchar(150) DEFAULT NULL,
  `cert_awardee_name` varchar(255) DEFAULT NULL,
  `seminar_title` varchar(255) DEFAULT NULL,
  `cert_number_format` varchar(128) NOT NULL DEFAULT 'NC-{YYYY}-{SEQ4}',
  `cert_number` varchar(64) DEFAULT NULL,
  `pos_signatory_name_x` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_signatory_name_y` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_signature_url_x` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_signature_url_y` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_cert_awardee_name_x` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_cert_awardee_name_y` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_cert_number_x` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_cert_number_y` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_seminar_title_x` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_seminar_title_y` decimal(8,2) NOT NULL DEFAULT 0.00,
  `show_awardee` tinyint(1) NOT NULL DEFAULT 1,
  `show_seminar` tinyint(1) NOT NULL DEFAULT 1,
  `show_number` tinyint(1) NOT NULL DEFAULT 1,
  `file_path` varchar(255) DEFAULT NULL,
  `issued_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `certificates`
--

INSERT INTO `certificates` (`id`, `user_id`, `type`, `speaker_id`, `bg_url`, `signature_url`, `signatory_name`, `cert_awardee_name`, `seminar_title`, `cert_number_format`, `cert_number`, `pos_signatory_name_x`, `pos_signatory_name_y`, `pos_signature_url_x`, `pos_signature_url_y`, `pos_cert_awardee_name_x`, `pos_cert_awardee_name_y`, `pos_cert_number_x`, `pos_cert_number_y`, `pos_seminar_title_x`, `pos_seminar_title_y`, `show_awardee`, `show_seminar`, `show_number`, `file_path`, `issued_at`, `created_at`, `updated_at`) VALUES
(7, NULL, 'Attendance', NULL, 'uploads/certificates/837c6fae2c2290bd05afec7f.jpg', NULL, NULL, '{USER_NAME}', '{SEMINAR_TITLE}', 'NC-{YYYY}-{SEQ4}', NULL, 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, 43.53, 53.65, 0.00, 0.00, 0, 0, 1, '', '2025-09-24 15:04:00', '2025-09-24 15:04:00', '2025-09-24 15:18:51');

-- --------------------------------------------------------

--
-- Table structure for table `certificate_signatures`
--

CREATE TABLE `certificate_signatures` (
  `id` int(11) NOT NULL,
  `certificate_id` int(11) NOT NULL,
  `indicator` varchar(100) NOT NULL,
  `signature_url` varchar(255) NOT NULL,
  `pos_x` decimal(8,2) NOT NULL DEFAULT 0.00,
  `pos_y` decimal(8,2) NOT NULL DEFAULT 0.00,
  `width_pct` decimal(5,2) NOT NULL DEFAULT 25.00,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `certificate_signatures`
--

INSERT INTO `certificate_signatures` (`id`, `certificate_id`, `indicator`, `signature_url`, `pos_x`, `pos_y`, `width_pct`, `enabled`, `created_at`) VALUES
(13, 7, 'pres', 'uploads/certificates/2483c3c8b59fdcf5d65c2729.png', 14.41, 83.11, 9.00, 1, '2025-09-24 15:04:01'),
(14, 7, 'jer', 'uploads/certificates/2be6e3829ad9d5c87cb54a73.png', 46.24, 74.46, 7.00, 1, '2025-09-24 15:04:01'),
(15, 7, 'nat', 'uploads/certificates/cdfbbc39b441263bf513f3b0.png', 81.00, 86.62, 7.00, 1, '2025-09-24 15:04:05');

-- --------------------------------------------------------

--
-- Table structure for table `function_rooms`
--

CREATE TABLE `function_rooms` (
  `id` int(11) NOT NULL,
  `day_id` int(11) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `label` varchar(150) DEFAULT NULL,
  `room_number` varchar(50) NOT NULL,
  `day_number` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `day_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(150) DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `function_rooms`
--

INSERT INTO `function_rooms` (`id`, `day_id`, `name`, `label`, `room_number`, `day_number`, `day_date`, `description`, `location`, `capacity`, `is_active`, `created_at`) VALUES
(7, 25, 'Engr. Randy Flores', 'this is a test', '1', 1, '2025-09-15', 'testing room', NULL, NULL, 1, '2025-09-22 15:02:22'),
(8, 26, 'function room 2', 'function room 2', '1', 2, '2025-10-16', 'testing', NULL, NULL, 1, '2025-09-24 16:29:54'),
(9, 27, 'seminar room', 'this is a test', '1', 3, '2025-10-17', 'testing', NULL, NULL, 1, '2025-09-24 16:30:54'),
(10, 28, 'seminar room1', 'this is a test', '1', 4, '2025-10-18', 'this is a test', NULL, NULL, 1, '2025-09-24 16:32:19');

-- --------------------------------------------------------

--
-- Table structure for table `function_room_days`
--

CREATE TABLE `function_room_days` (
  `id` int(11) NOT NULL,
  `day_date` date NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `day` date NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `label` varchar(150) NOT NULL DEFAULT 'Day',
  `is_enabled` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `function_room_days`
--

INSERT INTO `function_room_days` (`id`, `day_date`, `room_id`, `day`, `enabled`, `start_time`, `end_time`, `label`, `is_enabled`) VALUES
(25, '2025-10-15', NULL, '0000-00-00', 1, NULL, NULL, 'Day 1', 1),
(26, '2025-10-16', NULL, '0000-00-00', 1, NULL, NULL, 'Day 2', 1),
(27, '2025-10-17', NULL, '0000-00-00', 1, NULL, NULL, 'Day 3', 1),
(28, '2025-10-18', NULL, '0000-00-00', 1, NULL, NULL, 'Day 4', 1);

-- --------------------------------------------------------

--
-- Table structure for table `function_room_days_backup`
--

CREATE TABLE `function_room_days_backup` (
  `id` int(11) NOT NULL DEFAULT 0,
  `room_id` int(11) NOT NULL,
  `day` date NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `quizzes`
--

CREATE TABLE `quizzes` (
  `id` int(11) NOT NULL,
  `speaker_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `day_id` int(11) DEFAULT NULL,
  `questions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`questions`)),
  `options` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`options`)),
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`answers`)),
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quizzes`
--

INSERT INTO `quizzes` (`id`, `speaker_id`, `room_id`, `day_id`, `questions`, `options`, `answers`, `created_at`) VALUES
(1, 1, 7, 25, '[\"sino si Nicole Diane Formeloza\",\"kelan mo ko sinagot ?\"]', '[[\"love love?\",\"Future wife\",\"ewan ko \",\"crush\"],[]]', '[1,null]', '2025-09-22 17:09:35'),
(2, 2, 8, 26, '[\"Question 1: What is the correct syntax for a for loop that prints numbers 0 through 4?\",\"count = 0 while count < 3:     print(count)     count += 1\",\"Question 3: Which of the following keywords is used to exit a loop prematurely?\",\"Question 4: What does the continue statement do in a loop?\",\"Question 5 (Coding — fill in the paragraph):  Complete the following paragraph to write a program that prints the even numbers from 1 to 10 using a loop:  Start by using a for loop with the range() function from 1 to 11. Inside the loop, check if the number is even by using the modulus operator (%). If the number is even, print it. Use an if statement to check if the number modulo 2 equals 0.\"]', '[[\"A. for i in range(5): print(i)\",\"B. for i = 0 to 4: print(i)\",\"C. for i in range(0, 5] print(i)\",\"D. for (i in range(5)) { print(i) }\"],[\"A. 0 1 2\",\"B. 1 2 3\",\"C. 0 1 2 3\",\"D. 1 2\"],[\"A. continue\",\"B. end\",\"C. stop\",\"D. break\"],[\"A. Exits the loop entirely\",\"B. Skips the rest of the code in the loop for the current iteration\",\"C. Ends the program\",\"D. Restarts the loop from the beginning\"],[]]', '[0,0,0,0,null]', '2025-09-24 18:10:21');

-- --------------------------------------------------------

--
-- Table structure for table `quiz_responses`
--

CREATE TABLE `quiz_responses` (
  `id` int(11) NOT NULL,
  `quiz_id` int(11) NOT NULL,
  `speaker_id` int(11) NOT NULL,
  `room_id` int(11) DEFAULT NULL,
  `day_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`answers`)),
  `score` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `quiz_responses`
--

INSERT INTO `quiz_responses` (`id`, `quiz_id`, `speaker_id`, `room_id`, `day_id`, `user_id`, `answers`, `score`, `created_at`) VALUES
(1, 1, 1, 7, 25, 10, '[1,\"September 28, 2024\"]', 1, '2025-09-22 17:30:36'),
(4, 1, 1, 7, 25, 9, '[2,\"Hala, diko alam to sir.  Hahaha\\n\\n-JB\"]', 1, '2025-09-22 17:31:34'),
(5, 1, 1, 7, 25, 11, '[0,\"09\\/28\\/25\"]', 0, '2025-09-22 20:44:24'),
(6, 2, 2, 8, 26, 11, '[0,0,0,0,\"di ko po alam\"]', 4, '2025-09-24 18:11:06');

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
  `room_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `photo_url` varchar(255) DEFAULT NULL,
  `presentation_url` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `speakers`
--

INSERT INTO `speakers` (`id`, `room_id`, `name`, `title`, `bio`, `start_time`, `end_time`, `photo`, `photo_url`, `presentation_url`, `created_at`) VALUES
(1, 7, 'Randy', 'testing', 'testingi be', '10:00:00', '13:52:00', NULL, 'uploads/speakers/205864712e66712c.jpg', 'uploads/presentations/de8092bea30319d1d4933d18.pdf', '2025-09-22 15:03:26'),
(2, 8, 'Gengr. Rod Panisan', 'oic of gin', 'He is magaling tumagay ng tumagay', '08:00:00', '09:00:00', NULL, 'uploads/speakers/7f5d0c1fa1ece22c.jpg', 'uploads/presentations/f71cc38e1d6272e5ef125ea8.pdf', '2025-09-24 16:36:00'),
(3, 9, 'Jericho morales', 'Sr. Citizen', 'gay', '08:00:00', '09:00:00', NULL, 'uploads/speakers/f095909186d4c5b0.jpg', 'uploads/presentations/48841b067d2e976d882b3061.pdf', '2025-09-24 16:38:08'),
(4, 10, 'Neekol', 'testing', 'testing', '08:00:00', '09:00:00', NULL, 'uploads/speakers/ce840e3d5b2a414f.jpg', 'uploads/presentations/41d3bbd48cc20a6c51340113.pdf', '2025-09-24 16:39:29');

-- --------------------------------------------------------

--
-- Table structure for table `speaker_attendance`
--

CREATE TABLE `speaker_attendance` (
  `id` int(11) NOT NULL,
  `speaker_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `room_id` int(11) DEFAULT NULL,
  `day_id` int(11) DEFAULT NULL,
  `attendee_name` varchar(255) NOT NULL,
  `attendee_email` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_addr` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `speaker_attendance`
--

INSERT INTO `speaker_attendance` (`id`, `speaker_id`, `user_id`, `room_id`, `day_id`, `attendee_name`, `attendee_email`, `created_at`, `ip_addr`, `user_agent`) VALUES
(1, 1, NULL, NULL, NULL, 'Test-JB', 'bergadojb08@gmail.com', '2025-09-22 17:29:55', '192.168.0.172', 'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36'),
(2, 1, NULL, NULL, NULL, 'Nikol', 'ndformeloza@gmail.com', '2025-09-22 17:30:12', '192.168.0.181', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6_2 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 [FBAN/FBIOS;FBAV/525.0.0.24.106;FBBV/791202578;FBDV/iPhone14,7;FBMD/iPhone;FBSN/iOS;FBSV/18.6.2;FBSS/3;FBCR/;FBID/phone;FBLC/en_US;FBOP/80]'),
(3, 1, NULL, NULL, NULL, 'Randy Pogi', 'floresrandy.m+69@gmail.com', '2025-09-22 20:43:57', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'),
(4, 1, NULL, NULL, NULL, 'randy', 'floresrandy.m@gmail.com', '2025-09-24 16:56:49', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'),
(5, 2, NULL, NULL, NULL, 'Randy', 'floresrandy.m@gmail.com', '2025-09-24 17:15:58', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36'),
(6, 3, NULL, NULL, NULL, 'Randy Pogi', 'floresrandy.m@gmail.com', '2025-09-24 18:11:54', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36');

-- --------------------------------------------------------

--
-- Table structure for table `speaker_evaluations`
--

CREATE TABLE `speaker_evaluations` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `speaker_id` int(10) UNSIGNED NOT NULL,
  `room_id` int(10) UNSIGNED NOT NULL,
  `day_id` int(10) UNSIGNED NOT NULL,
  `content_quality` tinyint(3) UNSIGNED NOT NULL,
  `speaker_clarity` tinyint(3) UNSIGNED NOT NULL,
  `engagement` tinyint(3) UNSIGNED NOT NULL,
  `overall` tinyint(3) UNSIGNED NOT NULL,
  `comments` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `speaker_evaluations`
--

INSERT INTO `speaker_evaluations` (`id`, `user_id`, `speaker_id`, `room_id`, `day_id`, `content_quality`, `speaker_clarity`, `engagement`, `overall`, `comments`, `created_at`) VALUES
(1, 11, 1, 7, 25, 5, 5, 5, 5, 'parang bobo', '2025-09-23 09:39:24'),
(2, 11, 2, 8, 26, 5, 5, 5, 5, 'testing', '2025-09-24 18:11:23');

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
(1, 'Randy', 'floresrandy.m@gmail.com', '$2y$10$pxNE58fcGtAiVw0UGad/OedBFM80sqHissQGywMQwnnBWfHzm86wm', '2025-08-02 18:10:09', 1, 1, 0, '2025-09-19 13:29:10', NULL),
(3, 'Jericho Morales', 'jerichomorales81@gmail.com', '$2y$10$NeC65FTU3OZYuyYfeteVfOo0i9gx2gFKIsXcjUrTAlDF93qBPxhmK', '2025-08-02 18:32:16', 0, 0, 0, NULL, NULL),
(4, 'Jemuel Agban', 'jemuelagban09@gmail.com', '$2y$10$jC3Tff1O63hji5nicG0jrOZTYq1GchP3h14baWwA5ddfVD2x4jZM2', '2025-08-02 18:57:17', 0, 0, 0, NULL, NULL),
(7, 'Jericho M', 'jerichomorales81+1@gmail.com', '$2y$10$fx0FBZ3k/hwwtuNIBh3paetz1Xh09OMlWWA0IbA0igNzSwBefBZbe', '2025-08-02 19:23:57', 0, 0, 0, NULL, NULL),
(8, 'Engr. Randy Flores', 'floresrandy.m+2@gmail.com', '$2y$10$ervxj5jcJL1urQhBCTaiTOm0.Kkyci6IHIg/xbWDghsRB7tohaKJm', '2025-09-18 21:11:57', 1, 1, 0, '2025-09-18 21:20:45', NULL),
(9, 'Test-JB', 'bergadojb08@gmail.com', '$2y$10$YVuR2H0QebeZ8I2lEebXkew5r/lmoaPgKMg7MoVUKZ6dILMJSdO.a', '2025-09-22 17:29:08', 1, 1, 0, '2025-09-23 12:00:32', NULL),
(10, 'Nikol', 'ndformeloza@gmail.com', '$2y$10$hBKdKQaRzFFTQfthWu9cGOQNicRVJiDlB5dHAtaRxaLotWr/7Z8fu', '2025-09-22 17:29:25', 1, 1, 0, '2025-09-23 11:33:42', NULL),
(11, 'Randy Flores', 'floresrandy.m+69@gmail.com', '$2y$10$6NmNQyqosFOQhwTJmrfshuxYbxnLjCfO1gws3pdElTYUStblbWNym', '2025-09-22 20:42:36', 1, 1, 0, '2025-09-23 08:57:44', NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_admin_email` (`email`);

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
  ADD UNIQUE KEY `uq_cert_number` (`cert_number`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_speaker_id` (`speaker_id`),
  ADD KEY `idx_type` (`type`);

--
-- Indexes for table `certificate_signatures`
--
ALTER TABLE `certificate_signatures`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_certificate_id` (`certificate_id`);

--
-- Indexes for table `function_rooms`
--
ALTER TABLE `function_rooms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fr_daynum` (`day_number`),
  ADD KEY `idx_fr_daydate` (`day_date`),
  ADD KEY `idx_day_id` (`day_id`);

--
-- Indexes for table `function_room_days`
--
ALTER TABLE `function_room_days`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_room_day` (`room_id`,`day`),
  ADD KEY `idx_room_id` (`room_id`),
  ADD KEY `idx_day_date` (`day_date`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`email`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_quiz_speaker` (`speaker_id`),
  ADD KEY `idx_quiz_room` (`room_id`),
  ADD KEY `idx_quiz_day` (`day_id`);

--
-- Indexes for table `quiz_responses`
--
ALTER TABLE `quiz_responses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_quiz_user` (`quiz_id`,`user_id`),
  ADD UNIQUE KEY `uq_quiz_user` (`quiz_id`,`user_id`),
  ADD KEY `idx_qr_speaker` (`speaker_id`),
  ADD KEY `idx_qr_room` (`room_id`),
  ADD KEY `idx_qr_day` (`day_id`);

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
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_speakers_room` (`room_id`);

--
-- Indexes for table `speaker_attendance`
--
ALTER TABLE `speaker_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_speaker_email` (`speaker_id`,`attendee_email`),
  ADD UNIQUE KEY `uq_attend_email` (`speaker_id`,`attendee_email`),
  ADD UNIQUE KEY `uq_attend_user` (`speaker_id`,`user_id`),
  ADD KEY `idx_speaker_att_speaker` (`speaker_id`),
  ADD KEY `idx_speaker_att_room` (`room_id`),
  ADD KEY `idx_speaker_att_day` (`day_id`);

--
-- Indexes for table `speaker_evaluations`
--
ALTER TABLE `speaker_evaluations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_speaker` (`user_id`,`speaker_id`),
  ADD UNIQUE KEY `uq_eval_user` (`speaker_id`,`user_id`),
  ADD KEY `idx_speaker` (`speaker_id`),
  ADD KEY `idx_room_day` (`room_id`,`day_id`),
  ADD KEY `idx_user` (`user_id`);

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
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `admin_settings`
--
ALTER TABLE `admin_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `app_settings`
--
ALTER TABLE `app_settings`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `certificate_signatures`
--
ALTER TABLE `certificate_signatures`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `function_rooms`
--
ALTER TABLE `function_rooms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `function_room_days`
--
ALTER TABLE `function_room_days`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `quizzes`
--
ALTER TABLE `quizzes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `quiz_responses`
--
ALTER TABLE `quiz_responses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `seminars`
--
ALTER TABLE `seminars`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `speakers`
--
ALTER TABLE `speakers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `speaker_attendance`
--
ALTER TABLE `speaker_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `speaker_evaluations`
--
ALTER TABLE `speaker_evaluations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

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
  ADD CONSTRAINT `fk_cert_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cert_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `certificate_signatures`
--
ALTER TABLE `certificate_signatures`
  ADD CONSTRAINT `fk_cert_sig_cert` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `function_rooms`
--
ALTER TABLE `function_rooms`
  ADD CONSTRAINT `fk_fr_day` FOREIGN KEY (`day_id`) REFERENCES `function_room_days` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `function_room_days`
--
ALTER TABLE `function_room_days`
  ADD CONSTRAINT `fk_room_days_room` FOREIGN KEY (`room_id`) REFERENCES `function_rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `quizzes`
--
ALTER TABLE `quizzes`
  ADD CONSTRAINT `fk_quiz_day` FOREIGN KEY (`day_id`) REFERENCES `function_room_days` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_quiz_room` FOREIGN KEY (`room_id`) REFERENCES `function_rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_quiz_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `quiz_responses`
--
ALTER TABLE `quiz_responses`
  ADD CONSTRAINT `fk_qr_day` FOREIGN KEY (`day_id`) REFERENCES `function_room_days` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_qr_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `quizzes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_qr_room` FOREIGN KEY (`room_id`) REFERENCES `function_rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_qr_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `seminars`
--
ALTER TABLE `seminars`
  ADD CONSTRAINT `fk_seminar_room` FOREIGN KEY (`room_id`) REFERENCES `function_rooms` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_seminar_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `speakers`
--
ALTER TABLE `speakers`
  ADD CONSTRAINT `fk_speakers_room` FOREIGN KEY (`room_id`) REFERENCES `function_rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `speaker_attendance`
--
ALTER TABLE `speaker_attendance`
  ADD CONSTRAINT `fk_speaker_att_day` FOREIGN KEY (`day_id`) REFERENCES `function_room_days` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_speaker_att_room` FOREIGN KEY (`room_id`) REFERENCES `function_rooms` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_speaker_att_speaker` FOREIGN KEY (`speaker_id`) REFERENCES `speakers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
