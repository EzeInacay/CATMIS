-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 14, 2026 at 05:25 AM
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
-- Database: `catmis`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(255) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`log_id`, `user_id`, `action`, `timestamp`) VALUES
(1, 1, 'Logged in', '2026-04-13 02:06:07'),
(2, 1, 'Posted payment OR-2026-1001 for student Juan Dela Cruz', '2026-04-13 02:06:07'),
(3, 1, 'Posted payment OR-2026-1002 for student Juan Dela Cruz', '2026-04-13 02:06:07'),
(4, 1, 'Posted payment OR-2026-1003 for student Maria Santos', '2026-04-13 02:06:07'),
(5, 1, 'Posted payment OR-2026-1004 for student Pedro Garcia', '2026-04-13 02:06:07'),
(6, 1, 'Posted payment OR-2026-1005 for student Ana Lopez', '2026-04-13 02:06:07'),
(7, 1, 'Applied discount to Maria Santos (Academic Scholarship)', '2026-04-13 02:06:07'),
(8, 1, 'Applied penalty to Carlo Reyes (late payment)', '2026-04-13 02:06:07'),
(9, 1, 'Logged in', '2026-04-13 02:47:36'),
(10, 1, 'Logged in', '2026-04-13 06:20:42'),
(11, 1, 'Logged in', '2026-04-13 06:22:42'),
(12, 1, 'Logged in', '2026-04-14 03:02:11');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `method` enum('Cash','GCash','Bank Transfer') NOT NULL,
  `or_number` varchar(50) NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `posted_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`payment_id`, `account_id`, `student_id`, `amount`, `method`, `or_number`, `payment_date`, `posted_by`) VALUES
(1, 1, 1, 5000.00, 'Cash', 'OR-2026-1001', '2026-04-13 02:06:07', 1),
(2, 1, 1, 5000.00, 'GCash', 'OR-2026-1002', '2026-04-13 02:06:07', 1),
(3, 2, 2, 5000.00, 'Cash', 'OR-2026-1003', '2026-04-13 02:06:07', 1),
(4, 3, 3, 17450.00, 'Bank Transfer', 'OR-2026-1004', '2026-04-13 02:06:07', 1),
(5, 4, 4, 3000.00, 'Cash', 'OR-2026-1005', '2026-04-13 02:06:07', 1);

-- --------------------------------------------------------

--
-- Table structure for table `school_years`
--

CREATE TABLE `school_years` (
  `sy_id` int(11) NOT NULL,
  `name` varchar(20) NOT NULL,
  `status` enum('active','archived') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `school_years`
--

INSERT INTO `school_years` (`sy_id`, `name`, `status`) VALUES
(1, '2024-2025', 'archived'),
(2, '2025-2026', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `section_id` int(11) NOT NULL,
  `section_name` varchar(50) NOT NULL,
  `grade_level` varchar(10) NOT NULL,
  `sy_id` int(11) NOT NULL,
  `teacher_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`section_id`, `section_name`, `grade_level`, `sy_id`, `teacher_id`) VALUES
(1, 'Mabini', '7', 2, 1),
(2, 'Rizal', '7', 2, 1),
(3, 'Bonifacio', '8', 2, 2),
(4, 'Luna', '9', 2, 2),
(5, 'Emerald', '10', 2, 3),
(6, 'Diamond', '10', 2, 3),
(7, 'STEM-A', '11', 2, 1),
(8, 'ABM-A', '11', 2, 2),
(9, 'STEM-A', '12', 2, 3),
(10, 'HUMSS-A', '12', 2, 3);

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `student_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `grade_level` varchar(10) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`student_id`, `user_id`, `section_id`, `grade_level`, `section`) VALUES
(1, 6, 7, '11', 'STEM-A'),
(2, 7, 7, '11', 'STEM-A'),
(3, 8, 8, '11', 'ABM-A'),
(4, 9, 5, '10', 'Emerald'),
(5, 10, 5, '10', 'Emerald'),
(6, 11, 6, '10', 'Diamond'),
(7, 12, 1, '7', 'Mabini'),
(8, 13, 1, '7', 'Mabini'),
(9, 14, 2, '7', 'Rizal'),
(10, 15, 4, '9', 'Luna');

-- --------------------------------------------------------

--
-- Table structure for table `student_ledgers`
--

CREATE TABLE `student_ledgers` (
  `ledger_id` int(11) NOT NULL,
  `account_id` int(11) NOT NULL,
  `entry_type` enum('CHARGE','PAYMENT','DISCOUNT','PENALTY','ADJUSTMENT') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `posted_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_ledgers`
--

INSERT INTO `student_ledgers` (`ledger_id`, `account_id`, `entry_type`, `amount`, `remarks`, `posted_by`, `created_at`) VALUES
(1, 1, 'CHARGE', 18550.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(2, 1, 'PAYMENT', 5000.00, 'OR#1001 - Cash - 1st installment', 1, '2026-04-13 02:06:07'),
(3, 1, 'PAYMENT', 5000.00, 'OR#1002 - GCash - 2nd installment', 1, '2026-04-13 02:06:07'),
(4, 2, 'CHARGE', 18550.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(5, 2, 'DISCOUNT', 500.00, 'Academic Scholarship discount', 1, '2026-04-13 02:06:07'),
(6, 2, 'PAYMENT', 5000.00, 'OR#1003 - Cash', 1, '2026-04-13 02:06:07'),
(7, 3, 'CHARGE', 17450.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(8, 3, 'PAYMENT', 17450.00, 'OR#1004 - Bank Transfer - Full', 1, '2026-04-13 02:06:07'),
(9, 4, 'CHARGE', 15250.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(10, 4, 'PAYMENT', 3000.00, 'OR#1005 - Cash', 1, '2026-04-13 02:06:07'),
(11, 5, 'CHARGE', 15250.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(12, 5, 'PENALTY', 200.00, 'Late payment penalty', 1, '2026-04-13 02:06:07'),
(13, 6, 'CHARGE', 15250.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(14, 7, 'CHARGE', 12050.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(15, 8, 'CHARGE', 12050.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(16, 9, 'CHARGE', 12050.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07'),
(17, 10, 'CHARGE', 15250.00, 'SY 2025-2026 Total Assessment', 1, '2026-04-13 02:06:07');

-- --------------------------------------------------------

--
-- Table structure for table `teachers`
--

CREATE TABLE `teachers` (
  `teacher_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `teachers`
--

INSERT INTO `teachers` (`teacher_id`, `user_id`) VALUES
(1, 3),
(2, 4),
(3, 5);

-- --------------------------------------------------------

--
-- Table structure for table `tuition_accounts`
--

CREATE TABLE `tuition_accounts` (
  `account_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `sy_id` int(11) NOT NULL,
  `base_fee` decimal(10,2) DEFAULT 0.00,
  `misc_fee` decimal(10,2) DEFAULT 0.00,
  `discount` decimal(10,2) DEFAULT 0.00,
  `penalties` decimal(10,2) DEFAULT 0.00,
  `balance` decimal(10,2) DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tuition_accounts`
--

INSERT INTO `tuition_accounts` (`account_id`, `student_id`, `sy_id`, `base_fee`, `misc_fee`, `discount`, `penalties`, `balance`, `updated_at`) VALUES
(1, 1, 2, 14000.00, 4550.00, 0.00, 0.00, 18550.00, '2026-04-13 02:06:07'),
(2, 2, 2, 14000.00, 4550.00, 500.00, 0.00, 18050.00, '2026-04-13 02:06:07'),
(3, 3, 2, 13000.00, 4450.00, 0.00, 0.00, 17450.00, '2026-04-13 02:06:07'),
(4, 4, 2, 11000.00, 4250.00, 0.00, 0.00, 15250.00, '2026-04-13 02:06:07'),
(5, 5, 2, 11000.00, 4250.00, 0.00, 200.00, 15450.00, '2026-04-13 02:06:07'),
(6, 6, 2, 11000.00, 4250.00, 0.00, 0.00, 15250.00, '2026-04-13 02:06:07'),
(7, 7, 2, 8500.00, 3550.00, 0.00, 0.00, 12050.00, '2026-04-13 02:06:07'),
(8, 8, 2, 8500.00, 3550.00, 0.00, 0.00, 12050.00, '2026-04-13 02:06:07'),
(9, 9, 2, 8500.00, 3550.00, 0.00, 0.00, 12050.00, '2026-04-13 02:06:07'),
(10, 10, 2, 11000.00, 4250.00, 0.00, 0.00, 15250.00, '2026-04-13 02:06:07');

-- --------------------------------------------------------

--
-- Table structure for table `tuition_fees`
--

CREATE TABLE `tuition_fees` (
  `fee_id` int(11) NOT NULL,
  `sy_id` int(11) NOT NULL,
  `grade_group` varchar(10) NOT NULL,
  `strand` varchar(20) DEFAULT NULL,
  `label` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `sort_order` int(3) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tuition_fees`
--

INSERT INTO `tuition_fees` (`fee_id`, `sy_id`, `grade_group`, `strand`, `label`, `amount`, `sort_order`) VALUES
(1, 2, '1-3', NULL, 'Tuition Fee', 8500.00, 1),
(2, 2, '1-3', NULL, 'Registration Fee', 500.00, 2),
(3, 2, '1-3', NULL, 'Testing Fee', 200.00, 3),
(4, 2, '1-3', NULL, 'Instructional Resources', 750.00, 4),
(5, 2, '1-3', NULL, 'Org Membership Fee', 150.00, 5),
(6, 2, '1-3', NULL, 'Lunch Program', 1200.00, 6),
(7, 2, '1-3', NULL, 'Athletic & Fine Arts', 300.00, 7),
(8, 2, '1-3', NULL, 'Library Fee', 200.00, 8),
(9, 2, '1-3', NULL, 'Energy & Communication', 250.00, 9),
(10, 2, '4-6', NULL, 'Tuition Fee', 9500.00, 1),
(11, 2, '4-6', NULL, 'Registration Fee', 500.00, 2),
(12, 2, '4-6', NULL, 'Testing Fee', 250.00, 3),
(13, 2, '4-6', NULL, 'Instructional Resources', 850.00, 4),
(14, 2, '4-6', NULL, 'Org Membership Fee', 150.00, 5),
(15, 2, '4-6', NULL, 'Lunch Program', 1200.00, 6),
(16, 2, '4-6', NULL, 'Athletic & Fine Arts', 350.00, 7),
(17, 2, '4-6', NULL, 'Library Fee', 200.00, 8),
(18, 2, '4-6', NULL, 'Energy & Communication', 250.00, 9),
(19, 2, '4-6', NULL, 'Computer Laboratory', 400.00, 10),
(20, 2, '7-10', NULL, 'Tuition Fee', 11000.00, 1),
(21, 2, '7-10', NULL, 'Registration Fee', 600.00, 2),
(22, 2, '7-10', NULL, 'Testing Fee', 300.00, 3),
(23, 2, '7-10', NULL, 'Instructional Resources', 1000.00, 4),
(24, 2, '7-10', NULL, 'Org Membership Fee', 200.00, 5),
(25, 2, '7-10', NULL, 'Lunch Program', 1200.00, 6),
(26, 2, '7-10', NULL, 'Athletic & Fine Arts', 400.00, 7),
(27, 2, '7-10', NULL, 'Library Fee', 250.00, 8),
(28, 2, '7-10', NULL, 'Energy & Communication', 300.00, 9),
(29, 2, '7-10', NULL, 'Computer/Chromebook Lab', 400.00, 10),
(30, 2, '7-10', NULL, 'Science/TLE Laboratory', 400.00, 11),
(31, 2, '11-12', 'STEM', 'Tuition Fee', 14000.00, 1),
(32, 2, '11-12', 'STEM', 'Registration Fee', 700.00, 2),
(33, 2, '11-12', 'STEM', 'Testing Fee', 350.00, 3),
(34, 2, '11-12', 'STEM', 'Instructional Resources', 1200.00, 4),
(35, 2, '11-12', 'STEM', 'Org Membership Fee', 250.00, 5),
(36, 2, '11-12', 'STEM', 'Lunch Program', 1200.00, 6),
(37, 2, '11-12', 'STEM', 'Athletic & Fine Arts', 400.00, 7),
(38, 2, '11-12', 'STEM', 'Library Fee', 300.00, 8),
(39, 2, '11-12', 'STEM', 'Energy & Communication', 350.00, 9),
(40, 2, '11-12', 'STEM', 'Computer/Chromebook Lab', 400.00, 10),
(41, 2, '11-12', 'STEM', 'Science Laboratory', 600.00, 11),
(42, 2, '11-12', 'ABM', 'Tuition Fee', 13000.00, 1),
(43, 2, '11-12', 'ABM', 'Registration Fee', 700.00, 2),
(44, 2, '11-12', 'ABM', 'Testing Fee', 350.00, 3),
(45, 2, '11-12', 'ABM', 'Instructional Resources', 1100.00, 4),
(46, 2, '11-12', 'ABM', 'Org Membership Fee', 250.00, 5),
(47, 2, '11-12', 'ABM', 'Lunch Program', 1200.00, 6),
(48, 2, '11-12', 'ABM', 'Athletic & Fine Arts', 400.00, 7),
(49, 2, '11-12', 'ABM', 'Library Fee', 300.00, 8),
(50, 2, '11-12', 'ABM', 'Energy & Communication', 350.00, 9),
(51, 2, '11-12', 'ABM', 'Computer/Chromebook Lab', 400.00, 10),
(52, 2, '11-12', 'HUMSS', 'Tuition Fee', 12500.00, 1),
(53, 2, '11-12', 'HUMSS', 'Registration Fee', 700.00, 2),
(54, 2, '11-12', 'HUMSS', 'Testing Fee', 350.00, 3),
(55, 2, '11-12', 'HUMSS', 'Instructional Resources', 1000.00, 4),
(56, 2, '11-12', 'HUMSS', 'Org Membership Fee', 250.00, 5),
(57, 2, '11-12', 'HUMSS', 'Lunch Program', 1200.00, 6),
(58, 2, '11-12', 'HUMSS', 'Athletic & Fine Arts', 400.00, 7),
(59, 2, '11-12', 'HUMSS', 'Library Fee', 300.00, 8),
(60, 2, '11-12', 'HUMSS', 'Energy & Communication', 350.00, 9),
(61, 2, '11-12', 'HUMSS', 'Computer/Chromebook Lab', 400.00, 10);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `student_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role` enum('admin','teacher','student') NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `student_number`, `email`, `password`, `full_name`, `role`, `status`, `created_at`) VALUES
(1, NULL, 'admin@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Admin', 'admin', 'active', '2026-04-13 02:06:07'),
(2, NULL, 'finance@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Rosario Delos Santos', 'admin', 'active', '2026-04-13 02:06:07'),
(3, NULL, 'mhoney@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ms. Honey Reyes', 'teacher', 'active', '2026-04-13 02:06:07'),
(4, NULL, 'jcruz@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mr. Jose Cruz', 'teacher', 'active', '2026-04-13 02:06:07'),
(5, NULL, 'agracia@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ms. Ana Gracia', 'teacher', 'active', '2026-04-13 02:06:07'),
(6, '2025-00001', 's00001@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Dela Cruz, Juan M.', 'student', 'active', '2026-04-13 02:06:07'),
(7, '2025-00002', 's00002@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Santos, Maria A.', 'student', 'active', '2026-04-13 02:06:07'),
(8, '2025-00003', 's00003@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Garcia, Pedro B.', 'student', 'active', '2026-04-13 02:06:07'),
(9, '2025-00004', 's00004@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Lopez, Ana C.', 'student', 'active', '2026-04-13 02:06:07'),
(10, '2025-00005', 's00005@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Reyes, Carlo D.', 'student', 'active', '2026-04-13 02:06:07'),
(11, '2025-00006', 's00006@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Mendoza, Liza E.', 'student', 'active', '2026-04-13 02:06:07'),
(12, '2025-00007', 's00007@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Flores, Ramon F.', 'student', 'active', '2026-04-13 02:06:07'),
(13, '2025-00008', 's00008@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Torres, Cynthia G.', 'student', 'active', '2026-04-13 02:06:07'),
(14, '2025-00009', 's00009@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Ramos, Benedict H.', 'student', 'active', '2026-04-13 02:06:07'),
(15, '2025-00010', 's00010@catmis.edu.ph', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Villanueva, Grace I.', 'student', 'active', '2026-04-13 02:06:07');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `or_number` (`or_number`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_payment_date` (`payment_date`),
  ADD KEY `posted_by` (`posted_by`);

--
-- Indexes for table `school_years`
--
ALTER TABLE `school_years`
  ADD PRIMARY KEY (`sy_id`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`section_id`),
  ADD KEY `idx_grade` (`grade_level`),
  ADD KEY `idx_sy` (`sy_id`),
  ADD KEY `teacher_id` (`teacher_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`student_id`),
  ADD UNIQUE KEY `user_id` (`user_id`),
  ADD KEY `idx_grade_section` (`grade_level`,`section`),
  ADD KEY `idx_section_id` (`section_id`);

--
-- Indexes for table `student_ledgers`
--
ALTER TABLE `student_ledgers`
  ADD PRIMARY KEY (`ledger_id`),
  ADD KEY `idx_account_id` (`account_id`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `posted_by` (`posted_by`);

--
-- Indexes for table `teachers`
--
ALTER TABLE `teachers`
  ADD PRIMARY KEY (`teacher_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `tuition_accounts`
--
ALTER TABLE `tuition_accounts`
  ADD PRIMARY KEY (`account_id`),
  ADD UNIQUE KEY `student_sy` (`student_id`,`sy_id`),
  ADD KEY `idx_student_id` (`student_id`),
  ADD KEY `idx_sy_id` (`sy_id`);

--
-- Indexes for table `tuition_fees`
--
ALTER TABLE `tuition_fees`
  ADD PRIMARY KEY (`fee_id`),
  ADD KEY `idx_grade_group` (`grade_group`,`sy_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD KEY `idx_role` (`role`),
  ADD KEY `idx_status` (`status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `school_years`
--
ALTER TABLE `school_years`
  MODIFY `sy_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `section_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `student_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `student_ledgers`
--
ALTER TABLE `student_ledgers`
  MODIFY `ledger_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `teachers`
--
ALTER TABLE `teachers`
  MODIFY `teacher_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `tuition_accounts`
--
ALTER TABLE `tuition_accounts`
  MODIFY `account_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `tuition_fees`
--
ALTER TABLE `tuition_fees`
  MODIFY `fee_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `tuition_accounts` (`account_id`),
  ADD CONSTRAINT `payments_ibfk_2` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `payments_ibfk_3` FOREIGN KEY (`posted_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `sections_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`),
  ADD CONSTRAINT `sections_ibfk_2` FOREIGN KEY (`sy_id`) REFERENCES `school_years` (`sy_id`);

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `students_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `students_ibfk_2` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`);

--
-- Constraints for table `student_ledgers`
--
ALTER TABLE `student_ledgers`
  ADD CONSTRAINT `student_ledgers_ibfk_1` FOREIGN KEY (`account_id`) REFERENCES `tuition_accounts` (`account_id`),
  ADD CONSTRAINT `student_ledgers_ibfk_2` FOREIGN KEY (`posted_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `teachers`
--
ALTER TABLE `teachers`
  ADD CONSTRAINT `teachers_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `tuition_accounts`
--
ALTER TABLE `tuition_accounts`
  ADD CONSTRAINT `tuition_accounts_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  ADD CONSTRAINT `tuition_accounts_ibfk_2` FOREIGN KEY (`sy_id`) REFERENCES `school_years` (`sy_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
