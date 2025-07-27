-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Anamakine: localhost:3306
-- Üretim Zamanı: 27 Tem 2025, 13:45:38
-- Sunucu sürümü: 11.4.7-MariaDB
-- PHP Sürümü: 8.3.23

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Veritabanı: `bursawe5_karacakaya`
--

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `company_settings`
--

CREATE TABLE `company_settings` (
  `id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `tax_office` varchar(100) DEFAULT NULL,
  `tax_number` varchar(50) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `logo_path` varchar(255) DEFAULT NULL,
  `default_vat_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `default_vat_rate_normal` decimal(5,2) DEFAULT 18.00,
  `default_vat_rate_fixed_asset` decimal(5,2) DEFAULT 8.00
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `company_settings`
--

INSERT INTO `company_settings` (`id`, `company_name`, `address`, `phone`, `tax_office`, `tax_number`, `email`, `logo_path`, `default_vat_rate`, `updated_at`, `default_vat_rate_normal`, `default_vat_rate_fixed_asset`) VALUES
(1, 'Karacakaya Ltd. Şti.', 'Nilüfer/Bursa', '02241110000', 'Nilüfer', '33322211122', 'deneme@mailci.com', 'uploads/logos/company_logo_1752912782.png', 1.00, '2025-07-19 13:48:03', 1.00, 20.00);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `customer_name` varchar(255) NOT NULL,
  `tax_office` varchar(100) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `contact_person` varchar(255) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` mediumtext DEFAULT NULL,
  `credit_limit` decimal(10,2) DEFAULT 0.00,
  `fixed_asset_credit_limit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `current_debt` decimal(10,2) DEFAULT 0.00,
  `fixed_asset_current_debt` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) NOT NULL,
  `last_updated_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `last_payment_date` date DEFAULT NULL,
  `last_payment_amount` decimal(10,2) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `customers`
--

INSERT INTO `customers` (`id`, `user_id`, `customer_name`, `tax_office`, `tax_id`, `contact_person`, `phone`, `email`, `address`, `credit_limit`, `fixed_asset_credit_limit`, `current_debt`, `fixed_asset_current_debt`, `created_at`, `updated_at`, `created_by`, `last_updated_by`, `is_active`, `first_name`, `last_name`, `address_line1`, `address_line2`, `city`, `state`, `zip_code`, `country`, `last_payment_date`, `last_payment_amount`) VALUES
(1, 5, 'Örnek Temizlik', 'gebze', '5165165165156', 'yavuz demir', '05331579834', 'Yvz.demirr@gmail.com', '', 75000.00, 0.00, 30595.00, 0.00, '2025-07-18 14:15:37', '2025-07-19 15:20:50', 1, 1, 1, 'yavuz', 'demir', '', '', '', '', '', '', NULL, NULL),
(2, 6, 'Ahmet Toptancilik', 'Uluda?', '65151616165165166', 'Ahmet Toptanc?', '05333331155', 'deneme@mail.com', 'bursa nilüfer bursa bursa', 30999.98, 100000.00, -12745.00, 18200.00, '2025-07-18 16:26:40', '2025-07-19 16:16:30', 1, 1, 1, 'Ahmet', 'Toptanci', 'bursa nilüfer bursa', 'bursa', 'bursa', 'nilüfer', '16000', 'türkiye', '2025-07-18', 45000.00);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customer_payments`
--

CREATE TABLE `customer_payments` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `debt_type_paid_for` varchar(20) DEFAULT 'normal'
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `customer_payments`
--

INSERT INTO `customer_payments` (`id`, `customer_id`, `payment_date`, `amount`, `payment_method`, `notes`, `created_by`, `created_at`, `debt_type_paid_for`) VALUES
(1, 2, '2025-07-18', 45000.00, 'Kredi Kartı', '', 1, '2025-07-18 23:58:12', 'normal');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `customer_product_prices`
--

CREATE TABLE `customer_product_prices` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_by` int(11) NOT NULL,
  `last_updated_by` int(11) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `customer_product_prices`
--

INSERT INTO `customer_product_prices` (`id`, `customer_id`, `product_id`, `price`, `created_at`, `updated_at`, `created_by`, `last_updated_by`) VALUES
(6, 1, 3, 70.00, '2025-07-18 15:56:05', '2025-07-18 15:56:05', 1, NULL),
(5, 1, 2, 125.00, '2025-07-18 15:56:05', '2025-07-18 15:56:05', 1, NULL),
(4, 1, 1, 100.00, '2025-07-18 15:56:05', '2025-07-18 15:56:05', 1, NULL),
(7, 2, 1, 50.00, '2025-07-18 16:27:26', '2025-07-18 16:43:51', 1, 1),
(8, 2, 2, 155.00, '2025-07-18 16:27:26', '2025-07-18 16:43:51', 1, 1),
(9, 2, 3, 70.00, '2025-07-18 16:27:26', '2025-07-18 16:43:51', 1, 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `dealers`
--

CREATE TABLE `dealers` (
  `id` int(11) NOT NULL,
  `dealer_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` mediumtext DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `country` varchar(50) DEFAULT NULL,
  `tax_id` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `vat_rate` decimal(5,2) NOT NULL DEFAULT 0.00,
  `vat_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `invoice_status` enum('Ödenmedi','Kısmen Ödendi','Ödendi','İptal Edildi') DEFAULT 'Ödenmedi',
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `invoices`
--

INSERT INTO `invoices` (`id`, `order_id`, `invoice_number`, `invoice_date`, `due_date`, `subtotal_amount`, `vat_rate`, `vat_amount`, `total_amount`, `paid_amount`, `invoice_status`, `created_by`, `created_at`, `last_updated_by`, `updated_at`, `notes`) VALUES
(1, 1, 'INV-20250718162318-1', '2025-07-18', '2025-08-17', 33250.00, 0.00, 0.00, 0.00, 34000.00, 'Ödendi', 3, '2025-07-18 13:23:18', 1, '2025-07-18 20:25:06', ''),
(2, 5, 'INV-20250718233317-5', '2025-07-18', '2025-08-17', 1375.00, 0.00, 0.00, 0.00, 0.00, 'Ödenmedi', 1, '2025-07-18 20:33:17', 1, '2025-07-18 20:33:17', ''),
(3, 6, 'INV-20250719105134-6', '2025-07-19', '2025-08-18', 28895.00, 0.00, 0.00, 0.00, 0.00, 'Ödenmedi', 1, '2025-07-19 07:51:34', 1, '2025-07-19 07:51:34', ''),
(4, 7, 'INV-20250719115726-7', '2025-07-19', '2025-08-18', 295.00, 20.00, 59.00, 354.00, 0.00, 'Ödenmedi', 1, '2025-07-19 08:57:26', 1, '2025-07-19 08:57:26', ''),
(5, 8, 'INV-20250719132447-8', '2025-07-19', '2025-08-18', 1630.00, 20.00, 326.00, 1956.00, 0.00, 'Ödenmedi', 1, '2025-07-19 10:24:47', 1, '2025-07-19 10:24:47', ''),
(6, 11, 'INV-20250719161649-11', '2025-07-19', '2025-08-18', 18475.00, 1.00, 184.75, 18659.75, 0.00, 'Ödenmedi', 1, '2025-07-19 13:16:49', 1, '2025-07-19 13:16:49', '');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
  `dealer_id` int(11) NOT NULL,
  `order_date` timestamp NULL DEFAULT current_timestamp(),
  `customer_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `order_status` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `last_updated_by` int(11) DEFAULT NULL,
  `sales_manager_approved_by` int(11) DEFAULT NULL,
  `accounting_approved_by` int(11) DEFAULT NULL,
  `shipment_approved_by` int(11) DEFAULT NULL,
  `shipment_date` date DEFAULT NULL,
  `delivery_plate_number` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `current_approver_role_id` int(11) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT 'Belirtilmedi',
  `payment_status` varchar(50) DEFAULT 'Beklemede',
  `invoice_status` varchar(50) DEFAULT 'Beklemede',
  `sales_manager_approved_date` datetime DEFAULT NULL,
  `accounting_approved_date` datetime DEFAULT NULL,
  `order_code` varchar(50) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `orders`
--

INSERT INTO `orders` (`id`, `dealer_id`, `order_date`, `customer_id`, `total_amount`, `order_status`, `created_by`, `last_updated_by`, `sales_manager_approved_by`, `accounting_approved_by`, `shipment_approved_by`, `shipment_date`, `delivery_plate_number`, `notes`, `current_approver_role_id`, `payment_method`, `payment_status`, `invoice_status`, `sales_manager_approved_date`, `accounting_approved_date`, `order_code`, `invoice_number`) VALUES
(1, 1, '2025-07-18 13:19:20', NULL, 33250.00, 'İptal Edildi', 1, 1, NULL, 3, NULL, NULL, NULL, '', NULL, 'Nakit', 'Beklemede', 'Faturaland?', NULL, '2025-07-18 16:19:52', 'ORD-1', NULL),
(2, 2, '2025-07-18 13:27:57', NULL, 30625.00, 'İptal Edildi', 1, 1, NULL, 1, NULL, NULL, NULL, '', NULL, 'Cari Hesap', 'Beklemede', 'Beklemede', NULL, '2025-07-18 16:41:55', 'ORD-2', NULL),
(3, 2, '2025-07-18 15:55:31', NULL, 5000.00, 'Teslim Edildi', 1, 1, NULL, 1, NULL, NULL, NULL, '', NULL, 'Kredi Kart?', 'Beklemede', 'Beklemede', NULL, '2025-07-18 23:18:32', 'ORD-3', NULL),
(4, 1, '2025-07-18 20:17:39', NULL, 10000.00, 'Sevkiyatta', 1, 1, NULL, 1, 1, '2025-07-18', '25ss555', '', NULL, 'Nakit', 'Beklemede', 'Beklemede', NULL, '2025-07-18 23:17:44', 'ORD-4', NULL),
(5, 1, '2025-07-18 20:32:49', NULL, 1375.00, 'Sevkiyatta', 1, 1, NULL, 1, NULL, NULL, NULL, '', NULL, 'Nakit', 'Beklemede', 'Faturaland?', NULL, '2025-07-18 23:33:03', NULL, 'INV-20250718233317-5'),
(6, 1, '2025-07-19 07:51:14', NULL, 28895.00, 'Teslim Edildi', 1, 1, NULL, 1, NULL, NULL, NULL, '', NULL, 'Cari Hesap', 'Beklemede', 'Faturalandı', NULL, '2025-07-19 10:51:25', NULL, 'INV-20250719105134-6'),
(7, 1, '2025-07-19 08:57:12', NULL, 295.00, 'Sevkiyatta', 1, 1, NULL, 1, NULL, NULL, NULL, '', NULL, 'Cari Hesap', 'Beklemede', 'Faturalandı', NULL, '2025-07-19 11:57:17', NULL, 'INV-20250719115726-7'),
(8, 2, '2025-07-19 10:24:19', NULL, 1630.00, 'Faturalandı', 1, 1, NULL, 1, NULL, NULL, NULL, '', NULL, 'Cari Hesap', 'Beklemede', 'Faturalandı', NULL, '2025-07-19 13:24:31', NULL, 'INV-20250719132447-8'),
(9, 1, '2025-07-19 12:12:56', NULL, 1110.00, 'Muhasebe Onayı Bekliyor', 1, 1, NULL, NULL, NULL, NULL, NULL, '', 4, 'Cari Hesap', 'Beklemede', 'Beklemede', NULL, NULL, NULL, NULL),
(10, 1, '2025-07-19 12:20:50', NULL, 295.00, 'Muhasebe Onayı Bekliyor', 1, 1, NULL, NULL, NULL, NULL, NULL, '', 4, 'Cari Hesap', 'Beklemede', 'Beklemede', NULL, NULL, NULL, NULL),
(11, 2, '2025-07-19 13:16:30', NULL, 18475.00, 'Faturalandı', 1, 1, NULL, 1, NULL, NULL, NULL, '', NULL, 'Kredi Kartı', 'Beklemede', 'Faturalandı', NULL, '2025-07-19 16:16:35', NULL, 'INV-20250719161649-11'),
(12, 2, '2025-07-19 14:43:28', NULL, 275.00, 'Reddedildi', 1, 1, NULL, NULL, NULL, NULL, NULL, '\nReddetme Notu: Kredi kartı çekilmedi.', NULL, 'Kredi Kartı', 'Beklemede', 'Beklemede', NULL, NULL, NULL, NULL),
(13, 2, '2025-07-19 14:43:28', NULL, 275.00, 'Muhasebe Onayı Bekliyor', 1, 1, NULL, NULL, NULL, NULL, NULL, '', 4, 'Kredi Kartı', 'Beklemede', 'Beklemede', NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `total_price` decimal(10,2) NOT NULL,
  `vat_rate` decimal(5,2) DEFAULT NULL,
  `unit_price_excluding_vat` decimal(15,2) DEFAULT NULL,
  `vat_amount_per_unit` decimal(15,2) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `order_items`
--

INSERT INTO `order_items` (`id`, `order_id`, `product_id`, `quantity`, `unit_price`, `total_price`, `vat_rate`, `unit_price_excluding_vat`, `vat_amount_per_unit`) VALUES
(1, 1, 1, 100, 100.00, 10000.00, NULL, NULL, NULL),
(2, 1, 2, 74, 125.00, 9250.00, NULL, NULL, NULL),
(3, 1, 3, 200, 70.00, 14000.00, NULL, NULL, NULL),
(4, 2, 1, 100, 50.00, 5000.00, NULL, NULL, NULL),
(5, 2, 2, 75, 155.00, 11625.00, NULL, NULL, NULL),
(6, 2, 3, 200, 70.00, 14000.00, NULL, NULL, NULL),
(7, 3, 1, 100, 50.00, 5000.00, NULL, NULL, NULL),
(8, 4, 1, 100, 100.00, 10000.00, NULL, NULL, NULL),
(9, 5, 2, 11, 125.00, 1375.00, NULL, NULL, NULL),
(10, 6, 1, 100, 100.00, 10000.00, NULL, NULL, NULL),
(11, 6, 2, 75, 125.00, 9375.00, NULL, NULL, NULL),
(12, 6, 3, 136, 70.00, 9520.00, NULL, NULL, NULL),
(13, 7, 1, 1, 100.00, 100.00, NULL, NULL, NULL),
(14, 7, 2, 1, 125.00, 125.00, NULL, NULL, NULL),
(15, 7, 3, 1, 70.00, 70.00, NULL, NULL, NULL),
(16, 8, 1, 7, 50.00, 350.00, NULL, NULL, NULL),
(17, 8, 2, 6, 155.00, 930.00, NULL, NULL, NULL),
(18, 8, 3, 5, 70.00, 350.00, NULL, NULL, NULL),
(19, 9, 1, 4, 100.00, 400.00, NULL, NULL, NULL),
(20, 9, 2, 4, 125.00, 500.00, NULL, NULL, NULL),
(21, 9, 3, 3, 70.00, 210.00, NULL, NULL, NULL),
(22, 10, 1, 1, 100.00, 100.00, NULL, NULL, NULL),
(23, 10, 2, 1, 125.00, 125.00, NULL, NULL, NULL),
(24, 10, 3, 1, 70.00, 70.00, NULL, NULL, NULL),
(25, 11, 1, 1, 50.00, 50.00, NULL, NULL, NULL),
(26, 11, 2, 1, 155.00, 155.00, NULL, NULL, NULL),
(27, 11, 3, 1, 70.00, 70.00, NULL, NULL, NULL),
(28, 11, 6, 1, 2000.00, 2000.00, NULL, NULL, NULL),
(29, 11, 8, 60, 200.00, 12000.00, NULL, NULL, NULL),
(30, 11, 7, 2, 1750.00, 3500.00, NULL, NULL, NULL),
(31, 11, 5, 1, 200.00, 200.00, NULL, NULL, NULL),
(32, 11, 4, 1, 500.00, 500.00, NULL, NULL, NULL),
(33, 12, 2, 1, 155.00, 155.00, 1.00, 153.47, 1.53),
(34, 12, 3, 1, 70.00, 70.00, 1.00, 69.31, 0.69),
(35, 12, 1, 1, 50.00, 50.00, 1.00, 49.50, 0.50),
(36, 13, 2, 1, 155.00, 155.00, 1.00, 153.47, 1.53),
(37, 13, 3, 1, 70.00, 70.00, 1.00, 69.31, 0.69),
(38, 13, 1, 1, 50.00, 50.00, 1.00, 49.50, 0.50);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `payment_attachments`
--

CREATE TABLE `payment_attachments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `attachment_type` varchar(50) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `sku` varchar(50) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `product_type` enum('Normal','Demirbas') NOT NULL DEFAULT 'Normal',
  `category_id` int(11) DEFAULT NULL,
  `image_url` varchar(255) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `last_updated_by` int(11) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `vat_rate` decimal(5,2) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `products`
--

INSERT INTO `products` (`id`, `product_name`, `sku`, `price`, `stock_quantity`, `product_type`, `category_id`, `image_url`, `created_by`, `last_updated_by`, `description`, `is_active`, `created_at`, `updated_at`, `vat_rate`) VALUES
(1, 'Damacana Su 19 L.', 'PRD001', 125.50, 100, 'Normal', 2, 'uploads/products/product_687a6eb8675cc5.27983144.jpg', NULL, 1, 'Damacana Su 19 L.', 1, '2025-07-17 19:00:55', '2025-07-19 16:54:41', 1.00),
(2, 'Bardak Su 200 ML', 'PRD002', 200.00, 75, 'Normal', 3, 'uploads/products/product_687a6f2192ee83.66061602.jpg', NULL, 1, 'Bardak Su 200 ML', 1, '2025-07-17 19:00:55', '2025-07-19 16:54:56', 1.00),
(3, '0.5 L Pet Su', 'PRD003', 75.25, 200, '', 1, 'uploads/products/product_687a6f2a2e7d95.03495158.jpg', NULL, 1, '0.5 L Pet Su', 1, '2025-07-17 19:00:55', '2025-07-19 18:01:28', 1.00),
(4, '80 x 120 EURO Palet', 'euro01', 500.00, 5000, 'Demirbas', 5, 'uploads/products/product_687b85f64f3773.74884928.png', 1, 1, '80x120 Euro Ahşap Palet', 1, '2025-07-19 11:48:06', '2025-07-19 15:42:14', 20.00),
(5, '100 x 120 Ahşap Palet', 'PLT02', 200.00, 1000, 'Demirbas', 6, 'uploads/products/product_687b870b39ceb5.36990159.jpg', 1, 1, '100 x 120 Ahşap Palet', 1, '2025-07-19 11:52:43', '2025-07-19 16:51:17', 20.00),
(6, 'Damacana Plastik Paleti Alt Kısmı', 'DMCNPLT01', 2000.00, 1000, 'Demirbas', 7, 'uploads/products/product_687b87b4a229a0.16452076.jpg', 1, 1, 'Damacana Plastik Paleti Alt', 1, '2025-07-19 11:55:32', '2025-07-19 15:42:29', 20.00),
(7, 'Damacana Plastik Paleti Üst Kısmı', 'DMCNPLT02', 1750.00, 2000, 'Demirbas', 7, 'uploads/products/product_687b87e3deb607.62373746.jpg', 1, 1, 'Damacana Plastik Paleti Üst Kısmı', 1, '2025-07-19 11:56:19', '2025-07-19 15:42:37', 20.00),
(8, 'Boş Damacana Şişe', 'DMCN01', 200.00, 80000, 'Demirbas', 4, 'uploads/products/product_687b8864153921.78785873.png', 1, 1, 'Boş Damacana Şişe 19 L.', 1, '2025-07-19 11:58:28', '2025-07-19 15:42:20', 20.00);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_categories`
--

CREATE TABLE `product_categories` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `product_categories`
--

INSERT INTO `product_categories` (`id`, `category_name`, `created_at`) VALUES
(1, 'Pet', '2025-07-19 12:41:11'),
(2, 'Damacana', '2025-07-19 12:41:16'),
(3, 'Bardak', '2025-07-19 12:41:19'),
(4, 'Boş Damacana', '2025-07-19 12:41:31'),
(5, 'Euro Palet', '2025-07-19 12:41:36'),
(6, 'Ahşap Palet', '2025-07-19 12:41:41'),
(7, 'Damacana Paletleri', '2025-07-19 12:41:48');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `product_dependencies`
--

CREATE TABLE `product_dependencies` (
  `id` int(11) NOT NULL,
  `trigger_product_type` varchar(50) NOT NULL COMMENT 'Tetikleyici ürün tipi (PET, Bardak, Damacana)',
  `dependent_product_id` int(11) NOT NULL COMMENT 'Otomatik eklenecek ürünün ID''si',
  `quantity_per_unit` decimal(10,2) NOT NULL DEFAULT 1.00 COMMENT 'Tetikleyici ürünün her 1 adedi için eklenecek miktar',
  `is_editable` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Kullanıcının değiştirebilir olup olmadığı',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `product_dependencies`
--

INSERT INTO `product_dependencies` (`id`, `trigger_product_type`, `dependent_product_id`, `quantity_per_unit`, `is_editable`, `created_at`, `updated_at`) VALUES
(11, 'PET', 4, 1.00, 0, '2025-07-19 14:15:45', '2025-07-19 14:15:45'),
(9, 'Bardak', 5, 1.00, 0, '2025-07-19 12:48:07', '2025-07-19 12:48:07'),
(7, 'Damacana', 6, 1.00, 0, '2025-07-19 12:48:01', '2025-07-19 12:48:01'),
(6, 'Damacana', 8, 60.00, 0, '2025-07-19 12:48:01', '2025-07-19 12:48:01'),
(8, 'Damacana', 7, 2.00, 0, '2025-07-19 12:48:01', '2025-07-19 12:48:01');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `role_name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `display_name` varchar(100) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `roles`
--

INSERT INTO `roles` (`id`, `role_name`, `description`, `created_at`, `display_name`) VALUES
(1, 'genel_mudur', 'S?n?rs?z yetkilere sahip en üst düzey kullan?c?.', '2025-07-17 18:51:59', 'Genel Müdür'),
(2, 'genel_mudur_yardimcisi', 'Tüm yetkilere sahip, admin olu?turma hariç.', '2025-07-17 18:51:59', 'Genel Müdür Yardımcısı'),
(3, 'satis_muduru', 'Belirli bayilerin sipari?lerini yönetme, bayi olu?turma.', '2025-07-17 18:51:59', 'Satış Müdürü'),
(4, 'muhasebe_muduru', 'Sipari? onay?, faturaland?rma, irsaliye ç?kt?s?.', '2025-07-17 18:51:59', 'Muhasebe Müdürü'),
(5, 'sevkiyat_sorumlusu', 'Onaylanm?? sipari?lerin sevkiyat?n? yönetme.', '2025-07-17 18:51:59', 'Sevkiyat Sorumlusu'),
(6, 'customer', NULL, '2025-07-18 10:59:52', 'Müşteri');

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `sales_managers_to_dealers`
--

CREATE TABLE `sales_managers_to_dealers` (
  `id` int(11) NOT NULL,
  `sales_manager_id` int(11) NOT NULL,
  `dealer_id` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `shipments`
--

CREATE TABLE `shipments` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `shipment_date` date NOT NULL,
  `delivery_date` date DEFAULT NULL,
  `shipper_company` varchar(100) DEFAULT NULL,
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `shipment_status` enum('Hazırlanıyor','Sevkiyatta','Teslim Edildi','İptal Edildi') DEFAULT NULL,
  `waybill_number` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `shipments`
--

INSERT INTO `shipments` (`id`, `order_id`, `shipment_date`, `delivery_date`, `shipper_company`, `vehicle_plate`, `shipment_status`, `waybill_number`, `notes`, `created_by`, `created_at`, `last_updated_by`, `updated_at`, `is_active`) VALUES
(1, 1, '0000-00-00', '2025-07-18', NULL, '34hgf55', '', 'f1515151', '', 1, '2025-07-18 13:34:02', 1, '2025-07-18 20:18:57', 0),
(2, 2, '0000-00-00', '2025-10-10', NULL, '34hgf55', '', 'f15151511', '', 1, '2025-07-18 20:16:40', 1, '2025-07-18 20:18:54', 0),
(3, 3, '0000-00-00', '2025-11-11', NULL, '34hgf55', 'Teslim Edildi', 'f15123', '', 1, '2025-07-18 20:18:47', 1, '2025-07-18 20:20:31', 1),
(4, 5, '0000-00-00', '2025-12-12', NULL, '34hgf55', '', 'f151515133', '', 1, '2025-07-18 20:33:38', 1, '2025-07-18 20:33:38', 1),
(5, 6, '0000-00-00', '2025-08-25', NULL, '34hgf55', 'Teslim Edildi', 'f151515484', '', 1, '2025-07-19 10:26:25', 1, '2025-07-19 10:36:46', 1),
(6, 7, '0000-00-00', '2026-02-25', NULL, '34hgf55', 'Hazırlanıyor', '342342342', '', 1, '2025-07-19 10:36:05', 1, '2025-07-19 10:36:05', 1);

-- --------------------------------------------------------

--
-- Tablo için tablo yapısı `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `theme_preference` varchar(50) DEFAULT 'light',
  `updated_at` datetime DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Tablo döküm verisi `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role_id`, `full_name`, `created_at`, `theme_preference`, `updated_at`) VALUES
(1, 'admin_gm', '$2y$10$P3BKhywWglPJ4wqDFOS6xuhKBLmw8xtuZzInbwvif51i2dSmfzEA6', 1, 'Admin Genel Müdür', '2025-07-17 18:52:10', 'dark', NULL),
(2, 'admin_gmy', 'hashed_gmy_password', 2, 'Admin Genel Müdür Yard?mc?s?', '2025-07-17 18:52:10', 'light', NULL),
(3, 'demo_muhasebe', '$2y$10$p71N08r.kqtyGWZfHX5oA.ijfTwnJaRBNk7dvwJVGfGKE0uCAoovm', 4, 'Muhasebe Müdürü', '2025-07-18 10:29:28', 'light', NULL),
(4, 'denememusteri', '$2y$10$rQSre..pTK4.bXUlIOZ4we/0bdR6u2F1ZHtkZbRIV.E8uK/EJDQH6', 6, 'Deneme musteri', '2025-07-18 11:05:42', 'light', NULL),
(5, 'denememmn', '$2y$10$2BQm/ciQeUkxGob7sd4ZH.htovwEqRCmt6XZL4jHiZJZCqGkz4uzK', 6, 'dnen?ee?', '2025-07-18 11:15:37', 'light', '2025-07-18 14:15:37'),
(6, 'ahmettoptan', '$2y$10$OMZB8.XhA5hNvCiWI5aOhOEBsfyXoo42IjIo4CHRm2fU5Y2jbYt/e', 6, 'Ahmet Toptanc?', '2025-07-18 13:26:40', 'light', '2025-07-18 16:26:40'),
(7, 'necmettin', '$2y$10$rSHnNJrTbftkzVypHtY7zu9Dc/WDDWAiniAwGLxLc9dUGKky4s8Ie', 3, 'Necmettin Demir', '2025-07-19 13:14:19', 'light', '2025-07-19 16:14:19'),
(8, 'arif', '$2y$10$NPlD9avPjMsxcObckFLJnuRfSs2Cnxk0KSt98rwRscVdDMccubO7m', 3, 'Arif', '2025-07-19 13:14:55', 'light', '2025-07-19 16:14:55');

--
-- Dökümü yapılmış tablolar için indeksler
--

--
-- Tablo için indeksler `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `last_updated_by` (`last_updated_by`);

--
-- Tablo için indeksler `customer_payments`
--
ALTER TABLE `customer_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Tablo için indeksler `customer_product_prices`
--
ALTER TABLE `customer_product_prices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `customer_id` (`customer_id`,`product_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `last_updated_by` (`last_updated_by`);

--
-- Tablo için indeksler `dealers`
--
ALTER TABLE `dealers`
  ADD PRIMARY KEY (`id`);

--
-- Tablo için indeksler `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `last_updated_by` (`last_updated_by`);

--
-- Tablo için indeksler `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_code` (`order_code`),
  ADD UNIQUE KEY `invoice_number` (`invoice_number`),
  ADD KEY `dealer_id` (`dealer_id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `last_updated_by` (`last_updated_by`),
  ADD KEY `sales_manager_approved_by` (`sales_manager_approved_by`),
  ADD KEY `accounting_approved_by` (`accounting_approved_by`),
  ADD KEY `shipment_approved_by` (`shipment_approved_by`);

--
-- Tablo için indeksler `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Tablo için indeksler `payment_attachments`
--
ALTER TABLE `payment_attachments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`);

--
-- Tablo için indeksler `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `fk_products_created_by` (`created_by`),
  ADD KEY `fk_products_last_updated_by` (`last_updated_by`),
  ADD KEY `fk_product_category` (`category_id`);

--
-- Tablo için indeksler `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Tablo için indeksler `product_dependencies`
--
ALTER TABLE `product_dependencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dependent_product_id` (`dependent_product_id`);

--
-- Tablo için indeksler `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Tablo için indeksler `sales_managers_to_dealers`
--
ALTER TABLE `sales_managers_to_dealers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sales_manager_id` (`sales_manager_id`,`dealer_id`),
  ADD KEY `dealer_id` (`dealer_id`);

--
-- Tablo için indeksler `shipments`
--
ALTER TABLE `shipments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_id` (`order_id`),
  ADD UNIQUE KEY `waybill_number` (`waybill_number`),
  ADD KEY `fk_shipments_created_by` (`created_by`),
  ADD KEY `fk_shipments_last_updated_by` (`last_updated_by`);

--
-- Tablo için indeksler `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `role_id` (`role_id`);

--
-- Dökümü yapılmış tablolar için AUTO_INCREMENT değeri
--

--
-- Tablo için AUTO_INCREMENT değeri `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Tablo için AUTO_INCREMENT değeri `customer_payments`
--
ALTER TABLE `customer_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Tablo için AUTO_INCREMENT değeri `customer_product_prices`
--
ALTER TABLE `customer_product_prices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Tablo için AUTO_INCREMENT değeri `dealers`
--
ALTER TABLE `dealers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Tablo için AUTO_INCREMENT değeri `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Tablo için AUTO_INCREMENT değeri `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

--
-- Tablo için AUTO_INCREMENT değeri `payment_attachments`
--
ALTER TABLE `payment_attachments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Tablo için AUTO_INCREMENT değeri `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Tablo için AUTO_INCREMENT değeri `product_dependencies`
--
ALTER TABLE `product_dependencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Tablo için AUTO_INCREMENT değeri `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Tablo için AUTO_INCREMENT değeri `sales_managers_to_dealers`
--
ALTER TABLE `sales_managers_to_dealers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Tablo için AUTO_INCREMENT değeri `shipments`
--
ALTER TABLE `shipments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Tablo için AUTO_INCREMENT değeri `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
