-- =============================================================================
-- MediBoard - Shared Database Schema
-- =============================================================================
-- Read-only reference database shared across all tenants.
-- Contains: geographic data, facility registry, staff designations,
-- medicine types, payment configuration, and lab method definitions.
--
-- Tenant provisioning and storage configuration tables are omitted
-- from this reference schema.
--
-- See: docs/DATABASE.md for entity relationships and design decisions.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

-- -----------------------------------------------------------------------------
-- GEOGRAPHY
-- -----------------------------------------------------------------------------

CREATE TABLE `countries` (
  `id`            int NOT NULL,
  `name`          varchar(100) DEFAULT NULL,
  `currency_code` char(3) DEFAULT NULL,
  `currency_symbol` varchar(5) DEFAULT NULL,
  `timezone`      varchar(50) DEFAULT NULL,
  `isd_code`      varchar(20) DEFAULT NULL,
  `iso_code`      varchar(10) DEFAULT NULL,
  `iso_alpha2`    char(2) DEFAULT NULL,
  `iso_alpha3`    char(3) DEFAULT NULL,
  `is_active`     tinyint DEFAULT '1',
  `display_order` int DEFAULT '999',
  `created_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`           (`name`),
  KEY `isd_code`       (`isd_code`),
  KEY `iso_code`       (`iso_code`),
  KEY `idx_iso_alpha2` (`iso_alpha2`),
  KEY `idx_iso_alpha3` (`iso_alpha3`),
  KEY `idx_display_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `provinces` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `name`       varchar(50) NOT NULL,
  `region`     varchar(20) DEFAULT NULL,
  `code`       varchar(10) NOT NULL,
  `gst_code`   char(2) DEFAULT NULL,
  `country_id` int NOT NULL,
  `is_active`  tinyint DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `name`       (`name`),
  KEY `country_code`(`country_id`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `cities` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `name`        varchar(100) NOT NULL,
  `province_id` int NOT NULL,
  `created_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`        (`name`),
  KEY `province_id` (`province_id`),
  KEY `created_at`  (`created_at`),
  KEY `updated_at`  (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- FACILITY REGISTRY
-- -----------------------------------------------------------------------------

-- Facilities belong to a corporate group (tenant).
-- type enum supports future expansion to lab-only facilities.
-- opening_hours stored as JSON - structure varies by facility.
-- location columns (latitude, longitude) support future geo features.
CREATE TABLE `facilities` (
  `id`             int NOT NULL AUTO_INCREMENT,
  `name`           varchar(100) NOT NULL,
  `prefix`         varchar(10) DEFAULT NULL,
  `full_name`      varchar(100) NOT NULL,
  `group_id`       int DEFAULT NULL,
  `type`           enum('clinic','lab') NOT NULL DEFAULT 'clinic',
  `address`        varchar(300) DEFAULT NULL,
  `city_id`        int DEFAULT NULL,
  `province_id`    tinyint DEFAULT NULL,
  `country_id`     smallint DEFAULT NULL,
  `latitude`       decimal(11,8) DEFAULT NULL,
  `longitude`      decimal(11,8) DEFAULT NULL,
  `pincode`        varchar(6) DEFAULT NULL,
  `header_image`   varchar(50) DEFAULT NULL,
  `logo`           varchar(50) DEFAULT NULL,
  `opening_hours`  json DEFAULT NULL,
  `description`    text,
  `email_id`       varchar(200) DEFAULT NULL,
  `phone_num`      varchar(200) DEFAULT NULL,
  `contact_person` varchar(50) DEFAULT NULL,
  `website`        varchar(50) DEFAULT NULL,
  `is_active`      tinyint NOT NULL DEFAULT '1',
  `created_at`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name`     (`name`),
  KEY `full_name`   (`full_name`),
  KEY `group_id`    (`group_id`),
  KEY `province`    (`province_id`),
  KEY `city_id`     (`city_id`),
  KEY `type`        (`type`),
  KEY `is_active`   (`is_active`),
  KEY `location`    (`latitude`,`longitude`),
  KEY `created_at`  (`created_at`),
  KEY `updated_at`  (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- CLINICAL CONFIGURATION
-- -----------------------------------------------------------------------------

-- category enum supports filtering by role in staff assignment UI.
CREATE TABLE `designations` (
  `id`         tinyint NOT NULL AUTO_INCREMENT,
  `name`       varchar(50) NOT NULL,
  `category`   enum('medical','therapy','support','admin') DEFAULT 'medical',
  `is_active`  tinyint NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`       (`name`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `occupation_categories` (
  `id`          tinyint NOT NULL AUTO_INCREMENT,
  `name`        varchar(100) NOT NULL,
  `description` varchar(100) DEFAULT NULL,
  `is_active`   tinyint NOT NULL DEFAULT '1',
  `created_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`      (`name`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- PHARMACY REFERENCE
-- -----------------------------------------------------------------------------

-- units: the default dispensing unit for this medicine type (e.g. "mg", "ml").
-- acronym: short label used in prescription display (e.g. "TAB", "SYP").
CREATE TABLE `medicine_types` (
  `id`         tinyint NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `units`      varchar(50) NOT NULL,
  `acronym`    varchar(20) NOT NULL,
  `is_active`  tinyint NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`      (`name`),
  KEY `is_active` (`is_active`),
  KEY `created_at`(`created_at`),
  KEY `updated_at`(`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- PAYMENT CONFIGURATION
-- -----------------------------------------------------------------------------

CREATE TABLE `payment_methods` (
  `id`            tinyint NOT NULL AUTO_INCREMENT,
  `name`          varchar(50) NOT NULL,
  `description`   varchar(100) DEFAULT NULL,
  `display_order` tinyint DEFAULT '0',
  `is_active`     tinyint NOT NULL DEFAULT '1',
  `created_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`      (`name`),
  KEY `is_active` (`is_active`),
  KEY `created_at`(`created_at`),
  KEY `updated_at`(`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- color_code used for status badge rendering in the frontend.
CREATE TABLE `payment_statuses` (
  `id`            tinyint NOT NULL AUTO_INCREMENT,
  `name`          varchar(30) NOT NULL,
  `description`   varchar(100) DEFAULT NULL,
  `color_code`    varchar(7) DEFAULT '#6B7280',
  `display_order` tinyint DEFAULT '0',
  `is_active`     tinyint NOT NULL DEFAULT '1',
  `created_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`      (`name`),
  KEY `is_active` (`is_active`),
  KEY `created_at`(`created_at`),
  KEY `updated_at`(`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- LABORATORY REFERENCE
-- -----------------------------------------------------------------------------

-- Shared lab sample type definitions - referenced when ordering tests.
CREATE TABLE `lab_sample_types` (
  `id`             int NOT NULL AUTO_INCREMENT,
  `name`           varchar(50) NOT NULL,
  `short_name`     varchar(20) DEFAULT NULL,
  `description`    varchar(100) DEFAULT NULL,
  `container_type` varchar(100) DEFAULT NULL COMMENT 'e.g. Purple top (EDTA), Red top (Plain)',
  `typical_volume` varchar(50) DEFAULT NULL COMMENT 'e.g. 5ml, Spot sample',
  `storage_temp`   varchar(50) DEFAULT NULL COMMENT 'e.g. Room temp, 2-8°C, -20°C',
  `stability`      varchar(100) DEFAULT NULL COMMENT 'How long sample is stable',
  `display_order`  int DEFAULT '0',
  `is_active`      tinyint(1) NOT NULL DEFAULT '1',
  `created_at`     timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Shared test method definitions (ELISA, PCR, Microscopy, etc.).
-- applicable_samples uses SET type for multi-sample method support.
CREATE TABLE `lab_test_methods` (
  `id`                 int NOT NULL AUTO_INCREMENT,
  `name`               varchar(100) NOT NULL,
  `short_name`         varchar(50) DEFAULT NULL,
  `description`        text,
  `applicable_samples` set('blood','serum','plasma','urine','saliva','semen',
                           'swab','sputum','stool','tissue','csf','ascitic',
                           'pleural','synovial','amniotic','hair','nail',
                           'bone marrow','nasopharyngeal','wound','body fluids',
                           'whole blood','arterial blood','cells') DEFAULT NULL,
  `turnaround_time`    varchar(50) DEFAULT NULL,
  `equipment_required` varchar(200) DEFAULT NULL,
  `cost_category`      enum('low','medium','high','very_high') DEFAULT 'medium',
  `display_order`      int DEFAULT '0',
  `is_active`          tinyint(1) NOT NULL DEFAULT '1',
  `created_at`         timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS=1;