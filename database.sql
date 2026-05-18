-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 17, 2026 at 09:08 PM
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
-- Database: `busiquip_final`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE IF NOT EXISTS `admin` (
  `ADMIN_ID` int(11) NOT NULL,
  `USERNAME` varchar(50) NOT NULL,
  `PASSWORD_HASH` varchar(255) NOT NULL,
  `EMAIL` varchar(100) DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT IGNORE INTO `admin` (`ADMIN_ID`, `USERNAME`, `PASSWORD_HASH`, `EMAIL`, `CREATED_AT`) VALUES
(1, 'admin', '$2y$10$qa5HXHhSVaGKrv9dhTeclukU6wZCWK6P4ohA0IACJ3U7OZtQ3etDe', 'admin@busiquip.com', '2026-05-14 09:26:14');

-- --------------------------------------------------------

--
-- Table structure for table `assignment`
--

CREATE TABLE IF NOT EXISTS `assignment` (
  `ASSIGN_ID` int(11) NOT NULL,
  `REP_FAULT_ID` int(11) DEFAULT NULL,
  `ASSIGN_DATE` date DEFAULT NULL,
  `DUE_DATE` date DEFAULT NULL,
  `STATUS` varchar(20) DEFAULT 'Assigned'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignment`
--

INSERT IGNORE INTO `assignment` (`ASSIGN_ID`, `REP_FAULT_ID`, `ASSIGN_DATE`, `DUE_DATE`, `STATUS`) VALUES
(1, 2, '2026-05-15', '2026-05-22', 'Assigned'),
(2, 1, '2026-05-15', '2026-05-24', 'Assigned'),
(3, 3, '2026-05-16', '2026-05-23', 'Assigned'),
(4, 4, '2026-05-16', '2026-05-23', 'Assigned'),
(5, 5, '2026-05-16', '2026-05-23', 'Assigned'),
(6, 6, '2026-05-16', '2026-05-23', 'Assigned'),
(7, 7, '2026-05-16', '2026-05-23', 'Assigned'),
(8, 8, '2026-05-17', '2026-05-24', 'Assigned'),
(9, 9, '2026-05-17', '2026-05-24', 'Assigned'),
(10, 12, '2026-05-17', '2026-05-24', 'Assigned');

-- --------------------------------------------------------

--
-- Table structure for table `assignment_inventory`
--

CREATE TABLE IF NOT EXISTS `assignment_inventory` (
  `ASSIGN_INV_ID` int(11) NOT NULL,
  `ASSIGN_ID` int(11) DEFAULT NULL,
  `ITEM_ID` int(11) DEFAULT NULL,
  `QUANTITY_USED` int(11) DEFAULT NULL,
  `UNIT_PRICE_AT_TIME` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `assignment_technician`
--

CREATE TABLE IF NOT EXISTS `assignment_technician` (
  `ASSIGN_ID` int(11) NOT NULL,
  `EMP_ID` int(11) NOT NULL,
  `ROLE_IN_JOB` varchar(30) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignment_technician`
--

INSERT IGNORE INTO `assignment_technician` (`ASSIGN_ID`, `EMP_ID`, `ROLE_IN_JOB`) VALUES
(1, 1, 'Technician'),
(2, 9, 'Technician'),
(3, 9, 'Technician'),
(4, 9, 'Technician'),
(5, 9, 'Technician'),
(6, 9, 'Technician'),
(7, 9, 'Technician'),
(8, 9, 'Technician'),
(9, 10, 'Technician'),
(10, 9, 'Technician');

-- --------------------------------------------------------

--
-- Table structure for table `client`
--

CREATE TABLE IF NOT EXISTS `client` (
  `CLIENT_ID` int(11) NOT NULL,
  `COMPANY_NAME` varchar(150) NOT NULL,
  `COMPANY_PHONE` varchar(30) DEFAULT NULL,
  `COMPANY_EMAIL` varchar(100) DEFAULT NULL,
  `COMPANY_ADDRESS` varchar(255) DEFAULT NULL,
  `CONTACT_PERSON_NAME` varchar(150) DEFAULT NULL,
  `CLIENT_TYPE` varchar(20) DEFAULT 'CORPORATE',
  `USERNAME` varchar(50) DEFAULT NULL,
  `PASSWORD_HASH` varchar(255) DEFAULT NULL,
  `WALLET_BALANCE` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client`
--

INSERT IGNORE INTO `client` (`CLIENT_ID`, `COMPANY_NAME`, `COMPANY_PHONE`, `COMPANY_EMAIL`, `COMPANY_ADDRESS`, `CONTACT_PERSON_NAME`, `CLIENT_TYPE`, `USERNAME`, `PASSWORD_HASH`, `WALLET_BALANCE`) VALUES
(1, 'ECOT', '+26879121232', 'ecot@gmail.com', '12344', 'MAZWI', 'GOVERNMENT', 'mhlond', '$2y$10$lL/u2cd6BLYfKiK75FoHEOTtSHVP1lA8QujWlaQoCiTl055YEQzee', 0.00),
(2, 'limko', '+26878787676', 'tsela@gmail.com', '123err', 'tsela', 'CORPORATE', 'LECTURE', '$2y$10$fKaH3cS654XnM/v8J8P8qeRzKZEdss/gEwwvhHkioFbhmLEqkIIdS', 0.00),
(3, 'Limko', '+26878180264', 'mazwinkhocy@gmail.com', 'Mbabane', 'Mazwo', 'GOVERNMENT', 'Mazwi', '$2y$10$4yPeqbgMc4zXW4yKk1QqCeXzeKBKwMQEuWv4hdppPn.LL9HNfzaCO', 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `client_confirmations`
--

CREATE TABLE IF NOT EXISTS `client_confirmations` (
  `id` int(11) NOT NULL,
  `fault_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `confirmation_status` enum('Pending','Confirmed','Rejected','Needs Rework') DEFAULT 'Pending',
  `confirmation_notes` text DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client_confirmations`
--

INSERT IGNORE INTO `client_confirmations` (`id`, `fault_id`, `client_id`, `confirmation_status`, `confirmation_notes`, `confirmed_at`, `created_at`) VALUES
(1, 3, 1, 'Confirmed', NULL, '2026-05-16 14:55:50', '2026-05-16 12:55:50'),
(2, 6, 1, 'Confirmed', NULL, '2026-05-16 18:23:40', '2026-05-16 16:23:40'),
(3, 7, 1, 'Confirmed', NULL, '2026-05-17 05:33:51', '2026-05-17 03:33:51'),
(4, 8, 1, 'Confirmed', NULL, '2026-05-17 06:23:47', '2026-05-17 04:23:47'),
(5, 9, 1, 'Confirmed', NULL, '2026-05-17 11:55:45', '2026-05-17 09:55:45');

-- --------------------------------------------------------

--
-- Table structure for table `client_product`
--

CREATE TABLE IF NOT EXISTS `client_product` (
  `CLIENT_PROD_ID` int(11) NOT NULL,
  `CLIENT_ID` int(11) DEFAULT NULL,
  `PROD_ID` int(11) DEFAULT NULL,
  `SERIAL_NUM` varchar(100) DEFAULT NULL,
  `PURCHASE_DATE` date DEFAULT NULL,
  `WARRANTY_END_DATE` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `company_settings`
--

CREATE TABLE IF NOT EXISTS `company_settings` (
  `id` int(11) NOT NULL,
  `company_name` varchar(150) DEFAULT 'BUSIQUIP ESWATINI',
  `company_balance` decimal(15,2) DEFAULT 50000.00,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `company_settings`
--

INSERT IGNORE INTO `company_settings` (`id`, `company_name`, `company_balance`, `last_updated`) VALUES
(1, 'BUSIQUIP ESWATINI', 55126.00, '2026-05-16 16:41:36');

-- --------------------------------------------------------

--
-- Table structure for table `employee`
--

CREATE TABLE IF NOT EXISTS `employee` (
  `EMP_ID` int(11) NOT NULL,
  `FULL_NAME` varchar(150) NOT NULL,
  `EMAIL` varchar(100) DEFAULT NULL,
  `ROLE` enum('Technician','Admin','Accountant') NOT NULL,
  `HIRE_DATE` date DEFAULT NULL,
  `HOURLY_RATE` decimal(10,2) DEFAULT NULL,
  `USERNAME` varchar(50) DEFAULT NULL,
  `PASSWORD_HASH` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee`
--

INSERT IGNORE INTO `employee` (`EMP_ID`, `FULL_NAME`, `EMAIL`, `ROLE`, `HIRE_DATE`, `HOURLY_RATE`, `USERNAME`, `PASSWORD_HASH`) VALUES
(1, 'muzi', 'muzi@gmail.com', 'Technician', '2026-05-15', 0.00, 'Tsela', '$2y$10$/CNG85tReaz8h/CSKCqsEud5pG.H7nqeLKEblYyVfOSPsKURTIae6'),
(8, 'Mark Accountant', 'mark@gmail.com', 'Accountant', '2026-05-15', 0.00, 'mark@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(9, 'John Technician', 'john@gmail.com', 'Technician', '2026-05-15', 0.00, 'john@gmail.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'),
(10, 'mukelo', 'mukelo@gmail.com', 'Technician', '2026-05-17', 2.00, 'mukelo@gmail.com', '$2y$10$.HuwFyy4/Ma8WbwGGI2Uuu4nGlK9abAsPhPK.zNd0lfzsqCZXnae.');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE IF NOT EXISTS `expenses` (
  `EXPENSE_ID` int(11) NOT NULL,
  `CATEGORY` varchar(100) NOT NULL,
  `AMOUNT` decimal(10,2) NOT NULL,
  `EXPENSE_DATE` date NOT NULL,
  `DESCRIPTION` text DEFAULT NULL,
  `CREATED_AT` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fault`
--

CREATE TABLE IF NOT EXISTS `fault` (
  `FAULT_ID` int(11) NOT NULL,
  `FAULT_TYPE` varchar(100) DEFAULT NULL,
  `FAULT_DESCRIPTION` text DEFAULT NULL,
  `DEFAULT_PRIORITY` varchar(10) DEFAULT NULL,
  `DEFAULT_SLA_DAYS` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fault`
--

INSERT IGNORE INTO `fault` (`FAULT_ID`, `FAULT_TYPE`, `FAULT_DESCRIPTION`, `DEFAULT_PRIORITY`, `DEFAULT_SLA_DAYS`) VALUES
(1, 'Hardware Failure', 'Physical damage or malfunction of computer hardware components', 'High', 2),
(2, 'Software / System Error', 'Application crashes, OS errors, software not responding', 'Medium', 3),
(3, 'Network / Connectivity', 'Internet down, LAN issues, Wi-Fi not connecting', 'High', 1),
(4, 'Power / Electrical', 'UPS failure, power surges, equipment not powering on', 'Critical', 1),
(5, 'Printer / Scanner Issue', 'Printer not printing, paper jams, scanner not working', 'Low', 5),
(6, 'Monitor / Display', 'Screen flickering, no display, resolution issues', 'Low', 5),
(7, 'Server / Storage', 'Server down, data inaccessible, storage failure', 'Critical', 1),
(8, 'Email / Communication', 'Email not sending or receiving, Outlook issues', 'Medium', 3),
(9, 'Security / Access', 'Password issues, account locked, unauthorized access', 'High', 2),
(10, 'Performance / Speed', 'Computer running slow, high CPU/memory usage', 'Medium', 4),
(11, 'POS / Till System', 'Point of sale terminal not working, transaction errors', 'High', 1),
(12, 'CCTV / Security System', 'Cameras offline, DVR/NVR not recording', 'Medium', 3),
(13, 'Telephone / PABX', 'Office phones not working, call routing issues', 'Medium', 3),
(14, 'Peripheral Device', 'Keyboard, mouse, USB devices not recognized', 'Low', 5);

-- --------------------------------------------------------

--
-- Table structure for table `fault_rejections`
--

CREATE TABLE IF NOT EXISTS `fault_rejections` (
  `id` int(11) NOT NULL,
  `fault_id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `rejection_reason` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_item`
--

CREATE TABLE IF NOT EXISTS `inventory_item` (
  `ITEM_ID` int(11) NOT NULL,
  `PROD_ID` int(11) DEFAULT NULL,
  `SERIAL_NUM` varchar(100) DEFAULT NULL,
  `STOCK_QTY` int(11) DEFAULT NULL,
  `UNIT_COST` decimal(10,2) DEFAULT NULL,
  `LOCATION` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_transaction`
--

CREATE TABLE IF NOT EXISTS `inventory_transaction` (
  `TRANS_ID` int(11) NOT NULL,
  `ITEM_ID` int(11) DEFAULT NULL,
  `TRANS_TYPE` varchar(20) DEFAULT NULL,
  `ASSIGN_ID` int(11) DEFAULT NULL,
  `QUANTITY` int(11) DEFAULT NULL,
  `TRANS_DATE` timestamp NOT NULL DEFAULT current_timestamp(),
  `EMP_ID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoice`
--

CREATE TABLE IF NOT EXISTS `invoice` (
  `INVOICE_ID` int(11) NOT NULL,
  `CLIENT_ID` int(11) DEFAULT NULL,
  `ASSIGN_ID` int(11) DEFAULT NULL,
  `INVOICE_DATE` date DEFAULT NULL,
  `DUE_DATE` date DEFAULT NULL,
  `STATUS` varchar(20) DEFAULT NULL,
  `TYPE` varchar(20) DEFAULT 'Invoice',
  `TOTAL` decimal(10,2) DEFAULT NULL,
  `PAID_AMOUNT` decimal(10,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice`
--

INSERT IGNORE INTO `invoice` (`INVOICE_ID`, `CLIENT_ID`, `ASSIGN_ID`, `INVOICE_DATE`, `DUE_DATE`, `STATUS`, `TYPE`, `TOTAL`, `PAID_AMOUNT`) VALUES
(1, 1, 2, '2026-05-15', '2026-05-29', 'Paid', 'Quotation', 395.00, 395.00),
(2, 1, 2, '2026-05-15', '2026-05-29', 'Paid', 'Quotation', 4731.00, 4731.00),
(3, 1, 2, '2026-05-15', '2026-05-29', 'Paid', 'Quotation', 1452.00, 0.00),
(4, 1, 2, '2026-05-15', '2026-05-29', 'Paid', 'Quotation', 1452.00, 1452.00),
(5, 2, 4, '2026-05-16', '2026-05-30', 'Pending Payment', 'Invoice', 618.00, 0.00),
(6, 2, 4, '2026-05-16', '2026-05-30', 'Approved', 'Quotation', 1164.50, 0.00),
(7, 1, 7, '2026-05-17', '2026-05-31', 'Paid', 'Invoice', 5928.50, 0.00),
(8, 1, 7, '2026-05-17', '2026-05-31', 'Paid', 'Invoice', 4782.50, 4782.50),
(9, 1, 8, '2026-05-17', '2026-05-31', 'Partial', 'Invoice', 2941.00, 29.00),
(10, 1, 9, '2026-05-17', '2026-05-31', 'Invoiced', 'Quotation', 613.00, 0.00),
(11, 1, 9, '2026-05-17', '2026-05-31', 'Pending Payment', 'Invoice', 613.00, 0.00),
(16, 1, 2, '2026-05-17', '2026-05-31', 'Paid', 'Quotation', 150.00, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_line`
--

CREATE TABLE IF NOT EXISTS `invoice_line` (
  `LINE_ID` int(11) NOT NULL,
  `INVOICE_ID` int(11) DEFAULT NULL,
  `DESCRIPTION` varchar(255) DEFAULT NULL,
  `QUANTITY` int(11) DEFAULT NULL,
  `UNIT_PRICE` decimal(10,2) DEFAULT NULL,
  `LINE_TOTAL` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_line`
--

INSERT IGNORE INTO `invoice_line` (`LINE_ID`, `INVOICE_ID`, `DESCRIPTION`, `QUANTITY`, `UNIT_PRICE`, `LINE_TOTAL`) VALUES
(1, 1, 'Labour – Technician time', 4, 85.00, 340.00),
(2, 1, 'Transport – Travel & fuel', 1, 55.00, 55.00),
(3, 2, 'Labour – Technician time', 55, 85.00, 4675.00),
(4, 2, 'Transport – Travel & fuel', 1, 56.00, 56.00),
(5, 3, 'Labour – Technician time', 6, 85.00, 510.00),
(6, 3, 'Transport – Travel & fuel', 1, 942.00, 942.00),
(7, 3, '787', 5, 0.00, 0.00),
(8, 4, 'Labour – Technician time', 6, 85.00, 510.00),
(9, 4, 'Transport – Travel & fuel', 1, 942.00, 942.00),
(10, 4, '787', 5, 0.00, 0.00),
(11, 5, 'Labour – Technician Time (6 hrs @ E85/hr)', 6, 85.00, 510.00),
(12, 5, 'Transport – Travel & Fuel (6 km @ E3.5/km)', 6, 3.50, 21.00),
(13, 5, 'Call-Out / Site Visit Fee', 1, 87.00, 87.00),
(14, 6, 'Labour – Technician Time (7 hrs @ E85/hr)', 7, 85.00, 595.00),
(15, 6, 'Transport – Travel & Fuel (7 km @ E3.5/km)', 7, 3.50, 24.50),
(16, 6, 'Call-Out / Site Visit Fee', 1, 545.00, 545.00),
(17, 7, 'Labour – Technician Time (67 hrs @ E85/hr)', 67, 85.00, 5695.00),
(18, 7, 'Transport – Travel & Fuel (65 km @ E3.5/km)', 65, 3.50, 227.50),
(19, 7, 'Call-Out / Site Visit Fee', 1, 6.00, 6.00),
(20, 8, 'Labour – Technician Time (56 hrs @ E85/hr)', 56, 85.00, 4760.00),
(21, 8, 'Transport – Travel & Fuel (5 km @ E3.5/km)', 5, 3.50, 17.50),
(22, 8, 'Call-Out / Site Visit Fee', 1, 5.00, 5.00),
(23, 9, 'Labour – Technician Time (6 hrs @ E85/hr)', 6, 85.00, 510.00),
(24, 9, 'Transport – Travel & Fuel (676 km @ E3.5/km)', 676, 3.50, 2366.00),
(25, 9, 'Call-Out / Site Visit Fee', 1, 65.00, 65.00),
(26, 10, 'Labour – Technician Time (7 hrs @ E85/hr)', 7, 85.00, 595.00),
(27, 10, 'Transport – Travel & Fuel (4 km @ E3.5/km)', 4, 3.50, 14.00),
(28, 10, 'Call-Out / Site Visit Fee', 1, 4.00, 4.00),
(29, 11, 'Labour – Technician Time (7 hrs @ E85/hr)', 7, 85.00, 595.00),
(30, 11, 'Transport – Travel & Fuel (4 km @ E3.5/km)', 4, 3.50, 14.00),
(31, 11, 'Call-Out / Site Visit Fee', 1, 4.00, 4.00),
(32, 16, 'Diagnostic Fee', 1, 150.00, 150.00);

-- --------------------------------------------------------

--
-- Table structure for table `invoice_tracking`
--

CREATE TABLE IF NOT EXISTS `invoice_tracking` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `performed_by_id` int(11) DEFAULT NULL,
  `performed_by_role` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoice_tracking`
--

INSERT IGNORE INTO `invoice_tracking` (`id`, `invoice_id`, `action`, `description`, `performed_by_id`, `performed_by_role`, `created_at`) VALUES
(1, 1, 'Quotation Submitted', 'Quotation created by technician John Technician for fault #1', 9, 'Technician', '2026-05-15 13:08:21'),
(2, 2, 'Quotation Submitted', 'Quotation created by technician John Technician for fault #1', 9, 'Technician', '2026-05-15 18:48:44'),
(3, 3, 'Quotation Submitted', 'Quotation created by technician John Technician for fault #1', 9, 'Technician', '2026-05-15 19:01:11'),
(4, 4, 'Quotation Submitted', 'Quotation created for fault #1', 0, 'Technician', '2026-05-15 19:32:55'),
(5, 5, 'Quotation Submitted', 'Quotation created by technician Technician for fault #4 (Total: E618)', 9, 'Technician', '2026-05-16 09:30:07'),
(6, 5, 'Invoice Generated', 'Invoice generated by accountant from quotation #5. Due: 2026-05-30', 8, 'Accountant', '2026-05-16 15:37:19'),
(7, 2, 'Payment Verified', 'Payment #1 of E4,731.00 verified by accountant. Invoice status: Paid', 8, 'Accountant', '2026-05-16 15:49:53'),
(8, 6, 'Quotation Submitted', 'Quotation created by technician Technician for fault #4 (Total: E1164.5)', 9, 'Technician', '2026-05-16 16:21:59'),
(9, 1, 'Payment Verified', 'Payment #2 of E395.00 verified by accountant. Invoice status: Paid', 8, 'Accountant', '2026-05-16 16:41:36'),
(10, 7, 'Quotation Submitted', 'Quotation created by technician Technician for fault #7 (Total: E5928.5)', 9, 'Technician', '2026-05-16 16:53:57'),
(11, 7, 'Quotation Approved', 'Quotation approved by accountant Mark Accountant', 8, 'Accountant', '2026-05-16 17:13:51'),
(12, 6, 'Quotation Approved', 'Quotation approved by accountant Mark Accountant', 8, 'Accountant', '2026-05-16 17:19:21'),
(13, 4, 'Quotation Approved', 'Approved by accountant Mark Accountant', 8, 'Accountant', '2026-05-16 17:27:49'),
(14, 8, 'Quotation Submitted', 'Quotation created by technician Technician for fault #7 (Total: E4782.5)', 9, 'Technician', '2026-05-16 17:53:45'),
(15, 8, 'Quotation Approved', 'Approved by accountant Mark Accountant', 8, 'Accountant', '2026-05-17 03:09:23'),
(16, 8, 'Invoice Generated', 'Invoice INV-0008 generated by accountant. Due: 2026-05-31. Notes: ', 8, 'Accountant', '2026-05-17 04:03:50'),
(17, 7, 'Invoice Generated', 'Invoice INV-0007 generated by accountant. Due: 2026-05-31. Notes: ', 8, 'Accountant', '2026-05-17 04:03:57'),
(18, 9, 'Quotation Submitted', 'Quotation created by technician Technician for fault #8 (Total: E2941)', 9, 'Technician', '2026-05-17 04:24:25'),
(19, 9, 'Quotation Approved', 'Approved by accountant Mark Accountant', 8, 'Accountant', '2026-05-17 04:24:59'),
(20, 9, 'Invoice Generated', 'Invoice INV-0009 generated by accountant. Due: 2026-05-31. Notes: ', 8, 'Accountant', '2026-05-17 04:25:19'),
(21, 10, 'Quotation Submitted', 'Quotation created by technician Technician for fault #9 (Total: E613)', 10, 'Technician', '2026-05-17 09:52:18'),
(22, 10, 'Quotation Approved', 'Approved by accountant Mark Accountant', 8, 'Accountant', '2026-05-17 09:55:06'),
(23, 11, 'Invoice Generated', 'Generated from quotation #10 by accountant', 8, 'Accountant', '2026-05-17 09:55:21'),
(24, 16, 'Quotation Submitted', 'Quotation created by technician Technician for fault #1 (Total: E150)', 9, 'Technician', '2026-05-17 18:21:10');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `user_type` varchar(50) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT IGNORE INTO `notifications` (`id`, `user_id`, `user_type`, `title`, `message`, `is_read`, `created_at`) VALUES
(1, 1, 'Admin', 'New Message from John Technician', 'ff', 0, '2026-05-15 13:06:51'),
(2, 2, 'Employee', 'New Quotation Submitted', 'Technician John Technician submitted quotation #1 (E395) for fault #1. Please review and create invoice.', 0, '2026-05-15 13:08:21'),
(3, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #1', 0, '2026-05-15 18:46:27'),
(4, 1, 'Admin', 'Fault Completed', 'Fault #1 marked completed by John Technician', 0, '2026-05-15 18:46:47'),
(5, 1, 'Client', 'Fault Resolved', 'Your fault #1 has been resolved. Please verify and confirm.', 0, '2026-05-15 18:46:47'),
(6, 2, 'Employee', 'New Quotation Submitted', 'Technician John Technician submitted quotation #2 (E4731) for fault #1. Please review and create invoice.', 0, '2026-05-15 18:48:44'),
(7, 2, 'Employee', 'New Quotation Submitted', 'Technician John Technician submitted quotation #3 (E1452) for fault #1. Please review and create invoice.', 0, '2026-05-15 19:01:11'),
(8, 2, 'Employee', 'New Quotation Submitted', 'Technician submitted quotation #4 for fault #1.', 0, '2026-05-15 19:32:55'),
(9, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #3', 0, '2026-05-16 08:54:38'),
(10, 1, 'Admin', 'Fault Completed', 'Fault #3 marked completed by John Technician', 0, '2026-05-16 08:55:17'),
(11, 1, 'Client', 'Fault Resolved', 'Your fault #3 has been resolved. Please verify and confirm.', 0, '2026-05-16 08:55:17'),
(12, 2, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0005 (E618.00) for fault #4. Please review and confirm.', 0, '2026-05-16 09:30:07'),
(13, 8, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0005 (E618.00) for fault #4. Please review and confirm.', 0, '2026-05-16 09:30:07'),
(14, 1, 'Admin', 'New Quotation Submitted', 'Technician Technician submitted QUO-0005 (E618.00) for fault #4.', 0, '2026-05-16 09:30:07'),
(15, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #4', 0, '2026-05-16 11:13:22'),
(16, 2, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #3. Please generate the invoice.', 0, '2026-05-16 12:55:50'),
(17, 8, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #3. Please generate the invoice.', 0, '2026-05-16 12:55:50'),
(18, 2, 'Client', 'Invoice Ready – INV-0005', 'Your invoice #5 for E618.00 has been generated and is due on 2026-05-30. Please make payment.', 0, '2026-05-16 15:37:19'),
(19, 1, 'Client', 'Payment Confirmed - INV-0002', 'Your payment of E4,731.00 for invoice INV-0002 has been verified and confirmed. Your receipt has been generated. Fault #1 is now Closed.', 0, '2026-05-16 15:49:53'),
(20, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #5', 0, '2026-05-16 15:59:56'),
(21, 1, 'Admin', 'Fault Completed', 'Fault #5 marked completed by John Technician', 0, '2026-05-16 16:00:25'),
(22, 1, 'Client', 'Fault Resolved', 'Your fault #5 has been resolved. Please verify and confirm.', 0, '2026-05-16 16:00:25'),
(23, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #6', 0, '2026-05-16 16:16:41'),
(24, 1, 'Admin', 'Fault Completed', 'Fault #6 marked completed by John Technician', 0, '2026-05-16 16:21:11'),
(25, 1, 'Client', 'Fault Resolved', 'Your fault #6 has been resolved. Please verify and confirm.', 0, '2026-05-16 16:21:11'),
(26, 2, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0006 (E1,164.50) for fault #4. Please review and confirm.', 0, '2026-05-16 16:21:59'),
(27, 8, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0006 (E1,164.50) for fault #4. Please review and confirm.', 0, '2026-05-16 16:21:59'),
(28, 1, 'Admin', 'New Quotation Submitted', 'Technician Technician submitted QUO-0006 (E1,164.50) for fault #4.', 0, '2026-05-16 16:21:59'),
(29, 2, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #6. Please generate the invoice.', 0, '2026-05-16 16:23:40'),
(30, 8, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #6. Please generate the invoice.', 0, '2026-05-16 16:23:40'),
(31, 1, 'Client', 'Payment Confirmed - INV-0001', 'Your payment of E395.00 for invoice INV-0001 has been verified and confirmed. Your receipt has been generated. Fault #1 is now Closed.', 0, '2026-05-16 16:41:37'),
(32, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #7', 0, '2026-05-16 16:53:29'),
(33, 2, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0007 (E5,928.50) for fault #7. Please review and confirm.', 0, '2026-05-16 16:53:57'),
(34, 8, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0007 (E5,928.50) for fault #7. Please review and confirm.', 0, '2026-05-16 16:53:57'),
(35, 1, 'Admin', 'New Quotation Submitted', 'Technician Technician submitted QUO-0007 (E5,928.50) for fault #7.', 0, '2026-05-16 16:53:57'),
(36, 1, 'Admin', 'Fault Completed', 'Fault #7 marked completed by John Technician', 0, '2026-05-16 17:22:19'),
(37, 1, 'Client', 'Fault Resolved', 'Your fault #7 has been resolved. Please verify and confirm.', 0, '2026-05-16 17:22:19'),
(38, 2, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0008 (E4,782.50) for fault #7. Please review and confirm.', 0, '2026-05-16 17:53:45'),
(39, 8, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0008 (E4,782.50) for fault #7. Please review and confirm.', 0, '2026-05-16 17:53:45'),
(40, 1, 'Admin', 'New Quotation Submitted', 'Technician Technician submitted QUO-0008 (E4,782.50) for fault #7.', 0, '2026-05-16 17:53:45'),
(41, 2, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #7. Please generate the invoice.', 0, '2026-05-17 03:33:51'),
(42, 8, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #7. Please generate the invoice.', 0, '2026-05-17 03:33:51'),
(43, 1, 'Client', 'Invoice INV-0008 Ready for Payment', 'Your invoice INV-0008 for E4,782.50 has been generated. Payment is due by 2026-05-31. Please log in to make payment.', 0, '2026-05-17 04:03:50'),
(44, 1, 'Admin', 'Invoice INV-0008 Generated', 'Accountant generated invoice INV-0008 (E4,782.50) for client. Due: 2026-05-31.', 0, '2026-05-17 04:03:50'),
(45, 1, 'Client', 'Invoice INV-0007 Ready for Payment', 'Your invoice INV-0007 for E5,928.50 has been generated. Payment is due by 2026-05-31. Please log in to make payment.', 0, '2026-05-17 04:03:57'),
(46, 1, 'Admin', 'Invoice INV-0007 Generated', 'Accountant generated invoice INV-0007 (E5,928.50) for client. Due: 2026-05-31.', 0, '2026-05-17 04:03:58'),
(47, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #8', 0, '2026-05-17 04:22:48'),
(48, 1, 'Admin', 'Fault Completed', 'Fault #8 marked completed by John Technician', 0, '2026-05-17 04:23:09'),
(49, 1, 'Client', 'Fault Resolved', 'Your fault #8 has been resolved. Please verify and confirm.', 0, '2026-05-17 04:23:09'),
(50, 2, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #8. Please generate the invoice.', 0, '2026-05-17 04:23:47'),
(51, 8, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #8. Please generate the invoice.', 0, '2026-05-17 04:23:47'),
(52, 2, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0009 (E2,941.00) for fault #8. Please review and confirm.', 0, '2026-05-17 04:24:25'),
(53, 8, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0009 (E2,941.00) for fault #8. Please review and confirm.', 0, '2026-05-17 04:24:25'),
(54, 1, 'Admin', 'New Quotation Submitted', 'Technician Technician submitted QUO-0009 (E2,941.00) for fault #8.', 0, '2026-05-17 04:24:25'),
(55, 1, 'Client', 'Invoice INV-0009 Ready for Payment', 'Your invoice INV-0009 for E2,941.00 has been generated. Payment is due by 2026-05-31. Please log in to make payment.', 0, '2026-05-17 04:25:19'),
(56, 1, 'Admin', 'Invoice INV-0009 Generated', 'Accountant generated invoice INV-0009 (E2,941.00) for client. Due: 2026-05-31.', 0, '2026-05-17 04:25:19'),
(57, 1, 'Admin', 'Work Started', 'Technician mukelo started work on fault #9', 0, '2026-05-17 09:51:39'),
(58, 8, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0010 (E613.00) for fault #9. Please review and confirm.', 0, '2026-05-17 09:52:18'),
(59, 1, 'Admin', 'New Quotation Submitted', 'Technician Technician submitted QUO-0010 (E613.00) for fault #9.', 0, '2026-05-17 09:52:18'),
(60, 1, 'Admin', 'Fault Completed', 'Fault #9 marked completed by mukelo', 0, '2026-05-17 09:53:36'),
(61, 1, 'Client', 'Fault Resolved', 'Your fault #9 has been resolved. Please verify and confirm.', 0, '2026-05-17 09:53:36'),
(62, 1, 'Client', 'Invoice Ready for Payment', 'Invoice #11 has been generated for E613.00. Due: 31 May 2026. Please log in to make payment.', 0, '2026-05-17 09:55:21'),
(63, 8, 'Employee', 'Fault Approved – Ready for Invoice', 'Client approved fault #9. Please generate the invoice.', 0, '2026-05-17 09:55:45'),
(64, 1, 'Admin', 'Fault Completed', 'Fault #4 marked completed by John Technician', 0, '2026-05-17 12:58:20'),
(65, 2, 'Client', 'Fault Resolved', 'Your fault #4 has been resolved. Please verify and confirm.', 0, '2026-05-17 12:58:20'),
(66, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #12', 0, '2026-05-17 14:43:09'),
(67, 1, 'Admin', 'Fault Completed', 'Fault #12 marked completed by John Technician', 0, '2026-05-17 14:43:33'),
(68, 3, 'Client', 'Fault Resolved', 'Your fault #12 has been resolved. Please verify and confirm.', 0, '2026-05-17 14:43:33'),
(69, 1, 'Admin', 'Work Started', 'Technician John Technician started work on fault #12', 0, '2026-05-17 17:29:06'),
(70, 1, 'Admin', 'Fault Completed', 'Fault #12 marked completed by John Technician', 0, '2026-05-17 18:20:44'),
(71, 3, 'Client', 'Fault Resolved', 'Your fault #12 has been resolved. Please verify and confirm.', 0, '2026-05-17 18:20:44'),
(72, 8, 'Employee', 'New Quotation Submitted', 'Technician Technician submitted quotation QUO-0016 (E150.00) for fault #1. Please review and confirm.', 0, '2026-05-17 18:21:10'),
(73, 1, 'Admin', 'New Quotation Submitted', 'Technician Technician submitted QUO-0016 (E150.00) for fault #1.', 0, '2026-05-17 18:21:10');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT IGNORE INTO `password_resets` (`id`, `email`, `token`, `expires_at`, `used`, `created_at`) VALUES
(1, 'ecot@gmail.com', '816a2fcd63fd58e2a5567ce4447dd337de1cf7b7f53da4828b1a5bf9d24b757b', '2026-05-17 07:30:56', 0, '2026-05-17 07:00:56');

-- --------------------------------------------------------

--
-- Table structure for table `payment`
--

CREATE TABLE IF NOT EXISTS `payment` (
  `PAYMENT_ID` int(11) NOT NULL,
  `INVOICE_ID` int(11) DEFAULT NULL,
  `PAYMENT_DATE` date DEFAULT NULL,
  `AMOUNT_PAID` decimal(10,2) DEFAULT NULL,
  `METHOD` varchar(30) DEFAULT NULL,
  `REFERENCE_NUMBER` varchar(100) DEFAULT NULL,
  `STATUS` varchar(20) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment`
--

INSERT IGNORE INTO `payment` (`PAYMENT_ID`, `INVOICE_ID`, `PAYMENT_DATE`, `AMOUNT_PAID`, `METHOD`, `REFERENCE_NUMBER`, `STATUS`) VALUES
(1, 2, '2026-05-15', 4731.00, 'Card Transfer', '', 'Verified'),
(2, 1, '2026-05-15', 395.00, 'Card Transfer', '', 'Verified');

-- --------------------------------------------------------

--
-- Table structure for table `payment_tracking`
--

CREATE TABLE IF NOT EXISTS `payment_tracking` (
  `id` int(11) NOT NULL,
  `payment_id` int(11) DEFAULT NULL,
  `action` varchar(100) DEFAULT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_tracking`
--

INSERT IGNORE INTO `payment_tracking` (`id`, `payment_id`, `action`, `old_status`, `new_status`, `notes`, `created_at`) VALUES
(1, 1, 'Verified', 'Pending', 'Verified', '', '2026-05-16 15:49:53'),
(2, 2, 'Verified', 'Pending', 'Verified', '', '2026-05-16 16:41:36');

-- --------------------------------------------------------

--
-- Table structure for table `product`
--

CREATE TABLE IF NOT EXISTS `product` (
  `PROD_ID` int(11) NOT NULL,
  `PROD_NAME` varchar(150) DEFAULT NULL,
  `PROD_DESCRIPTION` text DEFAULT NULL,
  `PROD_TYPE` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `receipt`
--

CREATE TABLE IF NOT EXISTS `receipt` (
  `RECEIPT_ID` int(11) NOT NULL,
  `PAYMENT_ID` int(11) DEFAULT NULL,
  `RECEIPT_DATE` date DEFAULT NULL,
  `RECEIPT_DATA` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `receipt`
--

INSERT IGNORE INTO `receipt` (`RECEIPT_ID`, `PAYMENT_ID`, `RECEIPT_DATE`, `RECEIPT_DATA`) VALUES
(1, 1, '2026-05-16', '{\"payment_id\":1,\"invoice_id\":2,\"company\":\"ECOT\",\"amount\":4731,\"method\":\"Card Transfer\",\"reference\":\"\",\"verified_by\":\"Mark Accountant\",\"verified_at\":\"2026-05-16 17:49:53\"}'),
(2, 2, '2026-05-16', '{\"payment_id\":2,\"invoice_id\":1,\"company\":\"ECOT\",\"amount\":395,\"method\":\"Card Transfer\",\"reference\":\"\",\"verified_by\":\"Mark Accountant\",\"verified_at\":\"2026-05-16 18:41:36\"}');

-- --------------------------------------------------------

--
-- Table structure for table `reported_fault`
--

CREATE TABLE IF NOT EXISTS `reported_fault` (
  `REP_FAULT_ID` int(11) NOT NULL,
  `CLIENT_ID` int(11) DEFAULT NULL,
  `CLIENT_PROD_ID` int(11) DEFAULT NULL,
  `FAULT_ID` int(11) DEFAULT NULL,
  `REPORT_DATE` datetime DEFAULT NULL,
  `STATUS` varchar(20) DEFAULT 'Pending',
  `PRIORITY` varchar(10) DEFAULT NULL,
  `REPORTED_BY` varchar(150) DEFAULT NULL,
  `DESCRIPTION` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `reported_fault`
--

INSERT IGNORE INTO `reported_fault` (`REP_FAULT_ID`, `CLIENT_ID`, `CLIENT_PROD_ID`, `FAULT_ID`, `REPORT_DATE`, `STATUS`, `PRIORITY`, `REPORTED_BY`, `DESCRIPTION`) VALUES
(1, 1, NULL, NULL, '2026-05-15 06:41:00', 'Assigned', 'High', 'MAZWI', 'kk'),
(2, 1, NULL, NULL, '2026-05-15 07:44:13', 'Assigned', 'Low', 'MAZWI', 'FAULT REFERENCE: BQ-2026-52733\nFAULT TITLE: gr rrgfg rhrh\nCATEGORY: Software / System Error\nEQUIPMENT TYPE: Server\nBRAND/MODEL: ytt r4\nSERIAL/ASSET NO: 565f566\nFAULT DATE/TIME: 2026-05-15 07:39\nIS OPERATIONAL: No\nOCCURRED BEFORE: No\nUSERS AFFECTED: 1\nFAULT LOCATION: gfg rhrrr\nDEPARTMENT/BRANCH: tr rhrgrhr\nPREFERRED CONTACT: Phone\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nhrgyr rhyryryr nhehh eegge ege'),
(3, 1, NULL, NULL, '2026-05-16 10:51:46', 'Client Approved', 'Low', 'LINDO', 'FAULT REFERENCE: BQ-2026-99322\nFAULT TITLE: PRINTER ISSUES\nCATEGORY: Network / Connectivity\nEQUIPMENT TYPE: Scanner\nBRAND/MODEL: 434343443\nSERIAL/ASSET NO: 45555\nFAULT DATE/TIME: 2026-05-16 10:49\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 13\nFAULT LOCATION: DOWN\nDEPARTMENT/BRANCH: IT\nPREFERRED CONTACT: Email\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nTI REFEF EGET EFEF EGFEF EFEF EFEF EFEF'),
(4, 2, NULL, NULL, '2026-05-16 11:12:36', 'Completed', 'Medium', 'tsela', 'FAULT REFERENCE: BQ-2026-80172\nFAULT TITLE: TOTANE\nCATEGORY: Printing / Scanning\nEQUIPMENT TYPE: Server\nFAULT DATE/TIME: 2026-05-16 11:10\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 1\nFAULT LOCATION: GFRFF\nDEPARTMENT/BRANCH: BUSSINESS\nPREFERRED CONTACT: Phone\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nTHE GEFG EGEG EGEVV  H'),
(5, 1, NULL, NULL, '2026-05-16 17:56:39', 'Completed', 'Medium', 'MAZWI', 'FAULT REFERENCE: BQ-2026-62859\nFAULT TITLE: r rtr ede\nCATEGORY: Software / System Error\nEQUIPMENT TYPE: Printer\nBRAND/MODEL: 4545444554\nSERIAL/ASSET NO: 1234555\nFAULT DATE/TIME: 2026-05-16 17:54\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 14\nFAULT LOCATION: civil\nPREFERRED CONTACT: Phone\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nthey priner is not printing, it started today. i'),
(6, 1, NULL, NULL, '2026-05-16 18:15:13', 'Client Approved', 'Medium', 'MAZWI', 'FAULT REFERENCE: BQ-2026-65587\nFAULT TITLE: hgfffdf\nCATEGORY: Hardware Failure\nEQUIPMENT TYPE: Other\nFAULT DATE/TIME: 2026-05-16 18:14\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 1\nDEPARTMENT/BRANCH: ttrrr\nPREFERRED CONTACT: Email\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\ngfffdf fdfer ttrre tt'),
(7, 1, NULL, NULL, '2026-05-16 18:52:29', 'Client Approved', 'Medium', 'MAZWI', 'FAULT REFERENCE: BQ-2026-79332\nFAULT TITLE: network issue\nCATEGORY: Software / System Error\nEQUIPMENT TYPE: Laptop\nBRAND/MODEL: 6566\nSERIAL/ASSET NO: 66t55\nFAULT DATE/TIME: 2026-05-16 18:50\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 1\nFAULT LOCATION: uyuy\nDEPARTMENT/BRANCH: education\nPREFERRED CONTACT: Email\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nthe priner was fist printing now it have'),
(8, 1, NULL, NULL, '2026-05-17 06:21:34', 'Client Approved', 'Medium', 'MAZWI', 'FAULT REFERENCE: BQ-2026-51382\nFAULT TITLE: printer jam\nCATEGORY: Power / Electrical\nEQUIPMENT TYPE: Printer\nBRAND/MODEL: 434343434\nSERIAL/ASSET NO: 545343434\nFAULT DATE/TIME: 2026-05-17 06:19\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 1\nFAULT LOCATION: room3\nDEPARTMENT/BRANCH: engineering\nPREFERRED CONTACT: Phone\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nfro yesryerday, the printer is no printing at all'),
(9, 1, NULL, NULL, '2026-05-17 11:46:09', 'Client Approved', 'Medium', 'MAZWI', 'FAULT REFERENCE: BQ-2026-97844\nFAULT TITLE: the wifi is down\nCATEGORY: Network / Connectivity\nEQUIPMENT TYPE: Router / Firewall\nBRAND/MODEL: 565 65554\nSERIAL/ASSET NO: TRTRR\nFAULT DATE/TIME: 2026-05-17 11:44\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 1\nFAULT LOCATION: DOT BUILDING\nPREFERRED CONTACT: Email\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nth e wifi is down from since yesterday'),
(10, 1, NULL, 5, '2026-05-17 12:58:48', 'Pending', 'Medium', 'MAZWI', 'FAULT REFERENCE: BQ-2026-83670\nFAULT TYPE: Printer / Scanner Issue\nEQUIPMENT TYPE: Scanner\nBRAND/MODEL: 12432211232\nSERIAL/ASSET NO: 43322322\nFAULT DATE/TIME: 2026-05-17 12:55\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 166434323322\nFAULT LOCATION: R766555TREE\nDEPARTMENT/BRANCH: 76654456544\nPREFERRED CONTACT: Email\nSERVICE VISIT REQUIRED: No\n\nDETAILED DESCRIPTION:\nTHREG TRERER ETERRE EERRE EGEER'),
(11, 1, NULL, 6, '2026-05-17 14:37:08', 'Pending', 'Medium', 'MAZWI', 'FAULT REFERENCE: BQ-2026-14489\nFAULT TYPE: Monitor / Display\nEQUIPMENT TYPE: Projector\nBRAND/MODEL: HP, LAISER\nSERIAL/ASSET NO: 64543343343\nFAULT DATE/TIME: 2026-05-17 14:31\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 1\nPREFERRED CONTACT: Email\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nTHE ETETGERF EEF EGEFE'),
(12, 3, NULL, 1, '2026-05-17 16:38:43', 'Completed', 'Low', 'Mazwo', 'FAULT REFERENCE: BQ-2026-45643\nFAULT TYPE: Hardware Failure\nEQUIPMENT TYPE: Printer\nBRAND/MODEL: Adddd\nSERIAL/ASSET NO: Hsbs\nFAULT DATE/TIME: 2026-05-17 16:37\nIS OPERATIONAL: Yes\nOCCURRED BEFORE: No\nUSERS AFFECTED: 1\nFAULT LOCATION: Hsvs\nDEPARTMENT/BRANCH: Babs\nPREFERRED CONTACT: Email\nSERVICE VISIT REQUIRED: Yes\n\nDETAILED DESCRIPTION:\nI was at school the printer stopped');

-- --------------------------------------------------------

--
-- Table structure for table `unified_messages`
--

CREATE TABLE IF NOT EXISTS `unified_messages` (
  `id` int(11) NOT NULL,
  `from_id` int(11) DEFAULT NULL,
  `from_type` enum('Client','Employee','Admin') DEFAULT NULL,
  `from_name` varchar(150) DEFAULT NULL,
  `to_id` int(11) DEFAULT NULL,
  `to_type` enum('Client','Employee','Admin') DEFAULT NULL,
  `to_name` varchar(150) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `priority` enum('Low','Normal','High','Urgent') DEFAULT 'Normal',
  `is_read` tinyint(1) DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `sent_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `unified_messages`
--

INSERT IGNORE INTO `unified_messages` (`id`, `from_id`, `from_type`, `from_name`, `to_id`, `to_type`, `to_name`, `subject`, `content`, `priority`, `is_read`, `read_at`, `sent_time`) VALUES
(1, 1, 'Client', 'ECOT (Government)', 1, 'Admin', NULL, 'Portal Inquiry', 'bhh', 'Normal', 0, NULL, '2026-05-14 16:13:10'),
(2, 9, 'Employee', 'John Technician', 1, 'Admin', 'Admin', 'ff', 'ffff', 'Normal', 0, NULL, '2026-05-15 13:06:51');

-- --------------------------------------------------------

--
-- Table structure for table `workflow_confirmations`
--

CREATE TABLE IF NOT EXISTS `workflow_confirmations` (
  `conf_id` int(11) NOT NULL,
  `REP_FAULT_ID` int(11) NOT NULL,
  `CLIENT_ID` int(11) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `confirmed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `work_log`
--

CREATE TABLE IF NOT EXISTS `work_log` (
  `LOG_ID` int(11) NOT NULL,
  `ASSIGN_ID` int(11) DEFAULT NULL,
  `EMP_ID` int(11) DEFAULT NULL,
  `LOG_DATE` timestamp NOT NULL DEFAULT current_timestamp(),
  `LOG_TYPE` varchar(20) DEFAULT NULL,
  `ACTION_TAKEN` text DEFAULT NULL,
  `HOURS_SPENT` decimal(5,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `work_log`
--

INSERT IGNORE INTO `work_log` (`LOG_ID`, `ASSIGN_ID`, `EMP_ID`, `LOG_DATE`, `LOG_TYPE`, `ACTION_TAKEN`, `HOURS_SPENT`) VALUES
(1, 2, 9, '2026-05-15 18:46:27', 'Start', 'Work started by technician', 0.00),
(2, 2, 9, '2026-05-15 18:46:47', 'Complete', 'hhh', 0.00),
(3, 3, 9, '2026-05-16 08:54:38', 'Start', 'Work started by technician', 0.00),
(4, 3, 9, '2026-05-16 08:55:17', 'Complete', 'done', 0.00),
(5, 4, 9, '2026-05-16 11:13:22', 'Start', 'Work started by technician', 0.00),
(6, 5, 9, '2026-05-16 15:59:56', 'Start', 'Work started by technician', 0.00),
(7, 5, 9, '2026-05-16 16:00:25', 'Complete', 'done', 0.00),
(8, 6, 9, '2026-05-16 16:16:41', 'Start', 'Work started by technician', 0.00),
(9, 6, 9, '2026-05-16 16:21:11', 'Complete', 'last', 0.00),
(10, 7, 9, '2026-05-16 16:53:29', 'Start', 'Work started by technician', 0.00),
(11, 7, 9, '2026-05-16 17:22:19', 'Complete', 'education', 0.00),
(12, 8, 9, '2026-05-17 04:22:48', 'Start', 'Work started by technician', 0.00),
(13, 8, 9, '2026-05-17 04:23:09', 'Complete', 'engineering done', 0.00),
(14, 9, 10, '2026-05-17 09:51:39', 'Start', 'Work started by technician', 0.00),
(15, 9, 10, '2026-05-17 09:53:36', 'Complete', 'dot done', 0.00),
(16, 4, 9, '2026-05-17 12:58:20', 'Complete', 'done', 0.00),
(17, 10, 9, '2026-05-17 14:43:09', 'Start', 'Work started by technician', 0.00),
(18, 10, 9, '2026-05-17 14:43:33', 'Complete', 'done now', 0.00),
(19, 10, 9, '2026-05-17 17:29:06', 'Start', 'Work started by technician', 0.00),
(20, 10, 9, '2026-05-17 18:20:44', 'Complete', 'done', 0.00);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD UNIQUE KEY `USERNAME` (`USERNAME`);

--
-- Indexes for table `assignment`
--
ALTER TABLE `assignment`
  ADD KEY `REP_FAULT_ID` (`REP_FAULT_ID`);

--
-- Indexes for table `assignment_inventory`
--
ALTER TABLE `assignment_inventory`
  ADD KEY `ASSIGN_ID` (`ASSIGN_ID`),
  ADD KEY `ITEM_ID` (`ITEM_ID`);

--
-- Indexes for table `assignment_technician`
--
ALTER TABLE `assignment_technician`
  ADD KEY `EMP_ID` (`EMP_ID`);

--
-- Indexes for table `client`
--
ALTER TABLE `client`
  ADD UNIQUE KEY `COMPANY_EMAIL` (`COMPANY_EMAIL`),
  ADD UNIQUE KEY `USERNAME` (`USERNAME`);

--
-- Indexes for table `client_confirmations`
--
ALTER TABLE `client_confirmations`
  ADD UNIQUE KEY `fault_id` (`fault_id`,`client_id`);

--
-- Indexes for table `client_product`
--
ALTER TABLE `client_product`
  ADD UNIQUE KEY `SERIAL_NUM` (`SERIAL_NUM`),
  ADD KEY `CLIENT_ID` (`CLIENT_ID`),
  ADD KEY `PROD_ID` (`PROD_ID`);

--
-- Indexes for table `company_settings`
--
ALTER TABLE `company_settings`

--
-- Indexes for table `employee`
--
ALTER TABLE `employee`
  ADD UNIQUE KEY `EMAIL` (`EMAIL`),
  ADD UNIQUE KEY `USERNAME` (`USERNAME`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`

--
-- Indexes for table `fault`
--
ALTER TABLE `fault`

--
-- Indexes for table `fault_rejections`
--
ALTER TABLE `fault_rejections`

--
-- Indexes for table `inventory_item`
--
ALTER TABLE `inventory_item`
  ADD KEY `PROD_ID` (`PROD_ID`);

--
-- Indexes for table `inventory_transaction`
--
ALTER TABLE `inventory_transaction`
  ADD KEY `ITEM_ID` (`ITEM_ID`),
  ADD KEY `ASSIGN_ID` (`ASSIGN_ID`),
  ADD KEY `EMP_ID` (`EMP_ID`);

--
-- Indexes for table `invoice`
--
ALTER TABLE `invoice`
  ADD KEY `CLIENT_ID` (`CLIENT_ID`),
  ADD KEY `ASSIGN_ID` (`ASSIGN_ID`);

--
-- Indexes for table `invoice_line`
--
ALTER TABLE `invoice_line`
  ADD KEY `INVOICE_ID` (`INVOICE_ID`);

--
-- Indexes for table `invoice_tracking`
--
ALTER TABLE `invoice_tracking`

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `idx_token` (`token`),
  ADD KEY `idx_email` (`email`);

--
-- Indexes for table `payment`
--
ALTER TABLE `payment`
  ADD KEY `INVOICE_ID` (`INVOICE_ID`);

--
-- Indexes for table `payment_tracking`
--
ALTER TABLE `payment_tracking`

--
-- Indexes for table `product`
--
ALTER TABLE `product`

--
-- Indexes for table `receipt`
--
ALTER TABLE `receipt`
  ADD KEY `PAYMENT_ID` (`PAYMENT_ID`);

--
-- Indexes for table `reported_fault`
--
ALTER TABLE `reported_fault`
  ADD KEY `CLIENT_ID` (`CLIENT_ID`),
  ADD KEY `CLIENT_PROD_ID` (`CLIENT_PROD_ID`),
  ADD KEY `FAULT_ID` (`FAULT_ID`);

--
-- Indexes for table `unified_messages`
--
ALTER TABLE `unified_messages`
  ADD KEY `to_id` (`to_id`),
  ADD KEY `from_id` (`from_id`);

--
-- Indexes for table `workflow_confirmations`
--
ALTER TABLE `workflow_confirmations`

--
-- Indexes for table `work_log`
--
ALTER TABLE `work_log`
  ADD KEY `ASSIGN_ID` (`ASSIGN_ID`),
  ADD KEY `EMP_ID` (`EMP_ID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admin`
--
ALTER TABLE `admin`
  MODIFY `ADMIN_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `assignment`
--
ALTER TABLE `assignment`
  MODIFY `ASSIGN_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `assignment_inventory`
--
ALTER TABLE `assignment_inventory`
  MODIFY `ASSIGN_INV_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `client`
--
ALTER TABLE `client`
  MODIFY `CLIENT_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `client_confirmations`
--
ALTER TABLE `client_confirmations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `client_product`
--
ALTER TABLE `client_product`
  MODIFY `CLIENT_PROD_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `employee`
--
ALTER TABLE `employee`
  MODIFY `EMP_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `EXPENSE_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fault`
--
ALTER TABLE `fault`
  MODIFY `FAULT_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `fault_rejections`
--
ALTER TABLE `fault_rejections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_item`
--
ALTER TABLE `inventory_item`
  MODIFY `ITEM_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `inventory_transaction`
--
ALTER TABLE `inventory_transaction`
  MODIFY `TRANS_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoice`
--
ALTER TABLE `invoice`
  MODIFY `INVOICE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `invoice_line`
--
ALTER TABLE `invoice_line`
  MODIFY `LINE_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `invoice_tracking`
--
ALTER TABLE `invoice_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=74;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payment`
--
ALTER TABLE `payment`
  MODIFY `PAYMENT_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payment_tracking`
--
ALTER TABLE `payment_tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `product`
--
ALTER TABLE `product`
  MODIFY `PROD_ID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipt`
--
ALTER TABLE `receipt`
  MODIFY `RECEIPT_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `reported_fault`
--
ALTER TABLE `reported_fault`
  MODIFY `REP_FAULT_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `unified_messages`
--
ALTER TABLE `unified_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `workflow_confirmations`
--
ALTER TABLE `workflow_confirmations`
  MODIFY `conf_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `work_log`
--
ALTER TABLE `work_log`
  MODIFY `LOG_ID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
-- DROP ... IF EXISTS guards make this script safe to re-run on existing databases.
--

-- assignment
ALTER TABLE `assignment`
  DROP FOREIGN KEY IF EXISTS `assignment_ibfk_1`;
ALTER TABLE `assignment`
  ADD CONSTRAINT `assignment_ibfk_1` FOREIGN KEY (`REP_FAULT_ID`) REFERENCES `reported_fault` (`REP_FAULT_ID`);

-- assignment_inventory
ALTER TABLE `assignment_inventory`
  DROP FOREIGN KEY IF EXISTS `assignment_inventory_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `assignment_inventory_ibfk_2`;
ALTER TABLE `assignment_inventory`
  ADD CONSTRAINT `assignment_inventory_ibfk_1` FOREIGN KEY (`ASSIGN_ID`) REFERENCES `assignment` (`ASSIGN_ID`),
  ADD CONSTRAINT `assignment_inventory_ibfk_2` FOREIGN KEY (`ITEM_ID`) REFERENCES `inventory_item` (`ITEM_ID`);

-- assignment_technician
ALTER TABLE `assignment_technician`
  DROP FOREIGN KEY IF EXISTS `assignment_technician_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `assignment_technician_ibfk_2`;
ALTER TABLE `assignment_technician`
  ADD CONSTRAINT `assignment_technician_ibfk_1` FOREIGN KEY (`ASSIGN_ID`) REFERENCES `assignment` (`ASSIGN_ID`),
  ADD CONSTRAINT `assignment_technician_ibfk_2` FOREIGN KEY (`EMP_ID`) REFERENCES `employee` (`EMP_ID`);

-- client_product
ALTER TABLE `client_product`
  DROP FOREIGN KEY IF EXISTS `client_product_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `client_product_ibfk_2`;
ALTER TABLE `client_product`
  ADD CONSTRAINT `client_product_ibfk_1` FOREIGN KEY (`CLIENT_ID`) REFERENCES `client` (`CLIENT_ID`),
  ADD CONSTRAINT `client_product_ibfk_2` FOREIGN KEY (`PROD_ID`) REFERENCES `product` (`PROD_ID`);

-- inventory_item
ALTER TABLE `inventory_item`
  DROP FOREIGN KEY IF EXISTS `inventory_item_ibfk_1`;
ALTER TABLE `inventory_item`
  ADD CONSTRAINT `inventory_item_ibfk_1` FOREIGN KEY (`PROD_ID`) REFERENCES `product` (`PROD_ID`);

-- inventory_transaction
ALTER TABLE `inventory_transaction`
  DROP FOREIGN KEY IF EXISTS `inventory_transaction_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `inventory_transaction_ibfk_2`,
  DROP FOREIGN KEY IF EXISTS `inventory_transaction_ibfk_3`;
ALTER TABLE `inventory_transaction`
  ADD CONSTRAINT `inventory_transaction_ibfk_1` FOREIGN KEY (`ITEM_ID`) REFERENCES `inventory_item` (`ITEM_ID`),
  ADD CONSTRAINT `inventory_transaction_ibfk_2` FOREIGN KEY (`ASSIGN_ID`) REFERENCES `assignment` (`ASSIGN_ID`),
  ADD CONSTRAINT `inventory_transaction_ibfk_3` FOREIGN KEY (`EMP_ID`) REFERENCES `employee` (`EMP_ID`);

-- invoice
ALTER TABLE `invoice`
  DROP FOREIGN KEY IF EXISTS `invoice_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `invoice_ibfk_2`;
ALTER TABLE `invoice`
  ADD CONSTRAINT `invoice_ibfk_1` FOREIGN KEY (`CLIENT_ID`) REFERENCES `client` (`CLIENT_ID`),
  ADD CONSTRAINT `invoice_ibfk_2` FOREIGN KEY (`ASSIGN_ID`) REFERENCES `assignment` (`ASSIGN_ID`);

-- invoice_line
ALTER TABLE `invoice_line`
  DROP FOREIGN KEY IF EXISTS `invoice_line_ibfk_1`;
ALTER TABLE `invoice_line`
  ADD CONSTRAINT `invoice_line_ibfk_1` FOREIGN KEY (`INVOICE_ID`) REFERENCES `invoice` (`INVOICE_ID`);

-- payment
ALTER TABLE `payment`
  DROP FOREIGN KEY IF EXISTS `payment_ibfk_1`;
ALTER TABLE `payment`
  ADD CONSTRAINT `payment_ibfk_1` FOREIGN KEY (`INVOICE_ID`) REFERENCES `invoice` (`INVOICE_ID`);

-- receipt
ALTER TABLE `receipt`
  DROP FOREIGN KEY IF EXISTS `receipt_ibfk_1`;
ALTER TABLE `receipt`
  ADD CONSTRAINT `receipt_ibfk_1` FOREIGN KEY (`PAYMENT_ID`) REFERENCES `payment` (`PAYMENT_ID`);

-- reported_fault
ALTER TABLE `reported_fault`
  DROP FOREIGN KEY IF EXISTS `reported_fault_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `reported_fault_ibfk_2`,
  DROP FOREIGN KEY IF EXISTS `reported_fault_ibfk_3`;
ALTER TABLE `reported_fault`
  ADD CONSTRAINT `reported_fault_ibfk_1` FOREIGN KEY (`CLIENT_ID`) REFERENCES `client` (`CLIENT_ID`),
  ADD CONSTRAINT `reported_fault_ibfk_2` FOREIGN KEY (`CLIENT_PROD_ID`) REFERENCES `client_product` (`CLIENT_PROD_ID`),
  ADD CONSTRAINT `reported_fault_ibfk_3` FOREIGN KEY (`FAULT_ID`) REFERENCES `fault` (`FAULT_ID`);

-- work_log
ALTER TABLE `work_log`
  DROP FOREIGN KEY IF EXISTS `work_log_ibfk_1`,
  DROP FOREIGN KEY IF EXISTS `work_log_ibfk_2`;
ALTER TABLE `work_log`
  ADD CONSTRAINT `work_log_ibfk_1` FOREIGN KEY (`ASSIGN_ID`) REFERENCES `assignment` (`ASSIGN_ID`),
  ADD CONSTRAINT `work_log_ibfk_2` FOREIGN KEY (`EMP_ID`) REFERENCES `employee` (`EMP_ID`);


/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
CREATE TABLE IF NOT EXISTS `api_tokens` (
  `token`      varchar(64)  NOT NULL,
  `user_id`    int(11)      NOT NULL,
  `user_type`  varchar(20)  NOT NULL COMMENT 'Client|Technician|Accountant|Admin',
  `user_name`  varchar(150) NOT NULL,
  `created_at` timestamp    NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp    NOT NULL,
  PRIMARY KEY (`token`),
  KEY `idx_user` (`user_id`, `user_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
