-- =============================================================================
-- MediBoard - Tenant Database Schema
-- =============================================================================
-- Per-client isolated database. One database per corporate group.
-- Provisioned automatically via OnboardTenant command + CreateTenantJob.
--
-- Authentication, credential, and audit log tables are omitted from this
-- reference schema for security reasons.
--
-- See: docs/DATABASE.md for entity relationships and design decisions.
-- =============================================================================

SET FOREIGN_KEY_CHECKS=0;

-- -----------------------------------------------------------------------------
-- CLINICAL CORE
-- -----------------------------------------------------------------------------

CREATE TABLE `appointments` (
  `id`           int NOT NULL AUTO_INCREMENT,
  `patient_id`   int NOT NULL,
  `facility_id`  int NOT NULL,
  `doctor_id`    int NOT NULL,
  `session_date` date DEFAULT NULL,
  `session_time` time DEFAULT NULL,
  `referred_by`  varchar(200) DEFAULT NULL,
  `entry_by`     int NOT NULL,
  `notes`        varchar(200) DEFAULT NULL,
  `is_active`    tinyint NOT NULL DEFAULT '1',
  `created_at`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patient_id`  (`patient_id`),
  KEY `facility_id` (`facility_id`),
  KEY `doctor_id`   (`doctor_id`),
  KEY `session_date`(`session_date`),
  KEY `session_time`(`session_time`),
  KEY `is_active`   (`is_active`),
  KEY `created_at`  (`created_at`),
  KEY `updated_at`  (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- consultation_type supports in-person, teleconsultation, and home visits.
-- notes and vitals stored as JSON - structure defined by the clinical team
-- per facility without requiring schema changes.
-- attachments JSON holds file references (stored in tenant-isolated storage).
CREATE TABLE `consultations` (
  `id`                int NOT NULL AUTO_INCREMENT,
  `patient_id`        int NOT NULL,
  `facility_id`       int NOT NULL,
  `doctor_id`         int NOT NULL,
  `referred_by`       varchar(200) DEFAULT NULL,
  `appointment_id`    int DEFAULT NULL,
  `entry_by`          int DEFAULT NULL,
  `notes`             json DEFAULT NULL COMMENT 'clinical notes: chief complaints, observation, diagnosis, treatment',
  `followup_datetime` datetime DEFAULT NULL,
  `followup_details`  varchar(500) DEFAULT NULL,
  `is_follow_up`      tinyint(1) DEFAULT '0',
  `consultation_type` enum('in_person','tele','home_visit') DEFAULT 'in_person',
  `vitals`            json DEFAULT NULL,
  `attachments`       json DEFAULT NULL,
  `is_active`         tinyint NOT NULL DEFAULT '1',
  `created_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patient_id`     (`patient_id`),
  KEY `facility_id`    (`facility_id`),
  KEY `doctor_id`      (`doctor_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `is_active`      (`is_active`),
  KEY `created_at`     (`created_at`),
  KEY `updated_at`     (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- medicine JSON stores dosage schedule: morning / afternoon / evening / night / sos
CREATE TABLE `prescriptions` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `consult_id` int NOT NULL,
  `medicine`   json NOT NULL COMMENT 'dosage schedule: morning, afternoon, evening, night, sos',
  `entry_by`   int NOT NULL,
  `is_active`  tinyint NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `consult_id` (`consult_id`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- PATIENT
-- -----------------------------------------------------------------------------

-- Patients do not have login credentials in the current implementation.
-- ref1/ref2/ref3 are legacy inline columns superseded by the ref_tags table.
-- dr_ref stores the referring doctor's name as a free-text field.
-- membership JSON holds plan details (card number, issue date, expiry).
CREATE TABLE `patients` (
  `id`                int NOT NULL AUTO_INCREMENT,
  `salutation`        varchar(10) DEFAULT '',
  `name`              varchar(100) NOT NULL,
  `gender`            enum('m','f','o') NOT NULL COMMENT 'm: Male, f: Female, o: Other',
  `dob`               date DEFAULT NULL,
  `blood_group`       enum('A+','A-','B+','B-','AB+','AB-','O+','O-') DEFAULT NULL,
  `occup_cat_id`      tinyint DEFAULT NULL,
  `occup_desc`        varchar(100) DEFAULT NULL,
  `email_id`          varchar(100) DEFAULT NULL,
  `mobile_id`         varchar(50) DEFAULT NULL,
  `alt_mobile_id`     varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(50) DEFAULT NULL,
  `address`           varchar(500) DEFAULT NULL,
  `city_id`           int DEFAULT NULL,
  `province_id`       tinyint DEFAULT NULL,
  `country_id`        int DEFAULT NULL,
  `pincode`           varchar(6) DEFAULT NULL,
  `photo`             varchar(100) DEFAULT NULL,
  `allergies`         json DEFAULT NULL,
  `notes`             json DEFAULT NULL,
  `dr_ref`            varchar(100) DEFAULT NULL COMMENT 'Referring doctor (free text)',
  `data_source`       varchar(100) DEFAULT NULL,
  `membership`        json DEFAULT NULL,
  `is_active`         tinyint NOT NULL DEFAULT '1',
  `created_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`         (`name`),
  KEY `gender`       (`gender`),
  KEY `dob`          (`dob`),
  KEY `email_id`     (`email_id`),
  KEY `mobile_id`    (`mobile_id`),
  KEY `city_id`      (`city_id`),
  KEY `province_id`  (`province_id`),
  KEY `country_id`   (`country_id`),
  KEY `is_active`    (`is_active`),
  KEY `created_at`   (`created_at`),
  KEY `updated_at`   (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Structured JSON medical, habit, allergy, and vaccine history per patient.
CREATE TABLE `patient_history` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `details`    json NOT NULL,
  `is_active`  tinyint NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `patient_id` (`patient_id`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Field definitions for the patient history form - configurable per deployment.
-- Cached in Redis; changes here invalidate the cache automatically on next request.
CREATE TABLE `patient_history_meta` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `details`    json NOT NULL,
  `is_active`  tinyint NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Polymorphic patient identifier table.
-- Each facility maintains its own numbering scheme (registration numbers,
-- book numbers, etc.) without schema changes. meta_id references ref_tag_meta
-- which defines what each identifier type means per facility.
-- Composite index (facility_id, patient_id, meta_id) covers the primary lookup.
CREATE TABLE `ref_tags` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `facility_id` int NOT NULL,
  `patient_id`  int NOT NULL,
  `meta_id`     tinyint NOT NULL,
  `ref_ids`     varchar(100) NOT NULL,
  `created_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `comb_index` (`facility_id`,`patient_id`,`meta_id`),
  KEY `ref_ids`    (`ref_ids`),
  KEY `meta_id`    (`meta_id`),
  KEY `patient_id` (`patient_id`),
  KEY `facility_id`(`facility_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Defines what each meta_id means (e.g. 1 = Registration No, 2 = Book No).
CREATE TABLE `ref_tag_meta` (
  `id`         tinyint NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `is_active`  tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `is_active` (`is_active`),
  KEY `name`      (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- LABORATORY
-- -----------------------------------------------------------------------------

CREATE TABLE `lab_categories` (
  `id`            int NOT NULL AUTO_INCREMENT,
  `name`          varchar(100) NOT NULL,
  `description`   varchar(100) DEFAULT NULL,
  `display_order` int DEFAULT '0',
  `icon`          varchar(50) DEFAULT NULL,
  `color_code`    varchar(7) DEFAULT '#6B7280',
  `is_active`     tinyint NOT NULL DEFAULT '1',
  `created_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- is_panel=1 marks a virtual sub-category that groups real sub-categories.
-- Panel membership is defined in lab_panel_subcategories.
CREATE TABLE `lab_sub_categories` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `name`        varchar(100) NOT NULL,
  `is_panel`    tinyint(1) NOT NULL DEFAULT '0' COMMENT '0=regular subcategory, 1=panel combining multiple subcategories',
  `category_id` int NOT NULL,
  `is_active`   tinyint NOT NULL DEFAULT '1',
  `created_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `category_id` (`category_id`),
  KEY `is_active`   (`is_active`),
  KEY `idx_is_panel`(`is_panel`),
  KEY `created_at`  (`created_at`),
  KEY `updated_at`  (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Maps panel sub-categories (is_panel=1) to their component sub-categories.
-- Allows a single panel selection to expand into multiple test groups
-- without duplicating test definitions.
-- display_order controls rendering sequence in PDF reports.
CREATE TABLE `lab_panel_subcategories` (
  `id`                       int NOT NULL AUTO_INCREMENT,
  `panel_subcategory_id`     int NOT NULL COMMENT 'The panel sub-category (is_panel=1)',
  `component_subcategory_id` int NOT NULL COMMENT 'The component sub-category included in this panel',
  `display_order`            int NOT NULL DEFAULT '0' COMMENT 'Order of appearance in reports',
  `is_active`                tinyint NOT NULL DEFAULT '1',
  `created_at`               timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`               timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_panel_component` (`panel_subcategory_id`,`component_subcategory_id`),
  KEY `idx_panel_subcategory_id`     (`panel_subcategory_id`),
  KEY `idx_component_subcategory_id` (`component_subcategory_id`),
  KEY `is_active`                    (`is_active`),
  KEY `created_at`                   (`created_at`),
  KEY `updated_at`                   (`updated_at`),
  CONSTRAINT `fk_panel_subcategory`     FOREIGN KEY (`panel_subcategory_id`)     REFERENCES `lab_sub_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_component_subcategory` FOREIGN KEY (`component_subcategory_id`) REFERENCES `lab_sub_categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  COMMENT='Maps panel sub-categories to their component sub-categories';

-- ref_value: human-readable reference range string (legacy, still displayed).
-- ref_value_structured: JSON reference ranges supporting age/gender-specific
-- normals without additional tables. Used by the abnormal detection engine.
-- parent_id supports hierarchical tests (e.g. CBC → Haemoglobin, WBC, etc.).
-- rep_display / inv_display control visibility in reports vs invoice.
CREATE TABLE `lab_test_catalog` (
  `id`                   int NOT NULL AUTO_INCREMENT,
  `name`                 varchar(150) NOT NULL,
  `sub_category_id`      int NOT NULL,
  `parent_id`            int DEFAULT NULL,
  `price`                decimal(10,0) DEFAULT NULL,
  `ref_value`            varchar(2000) DEFAULT NULL,
  `ref_value_structured` json DEFAULT NULL,
  `ref_units`            varchar(50) DEFAULT NULL,
  `rep_display`          int NOT NULL DEFAULT '1',
  `inv_display`          int NOT NULL DEFAULT '1',
  `is_active`            tinyint NOT NULL DEFAULT '1',
  `created_at`           timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`            (`name`),
  KEY `sub_category_id` (`sub_category_id`),
  KEY `is_active`       (`is_active`),
  KEY `display`         (`rep_display`),
  KEY `inv_display`     (`inv_display`),
  KEY `created_at`      (`created_at`),
  KEY `updated_at`      (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- is_active uses three states: 1=active, -1=edit-in-progress, 0=deleted.
-- The -1 state allows in-progress lab entry without making results visible.
CREATE TABLE `lab_reports` (
  `id`                  int NOT NULL AUTO_INCREMENT,
  `consult_id`          int NOT NULL,
  `lab_incharge_id`     int DEFAULT NULL,
  `final_result`        json DEFAULT NULL,
  `collection_datetime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_datetime`   datetime DEFAULT CURRENT_TIMESTAMP,
  `report_datetime`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_active`           tinyint NOT NULL DEFAULT '1' COMMENT '-1=edit in progress, 0=deleted, 1=active',
  `created_at`          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `consult_id`      (`consult_id`),
  KEY `lab_incharge_id` (`lab_incharge_id`),
  KEY `is_active`       (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- BILLING
-- -----------------------------------------------------------------------------

-- bill_data JSON: line items, fees, taxes, discounts.
-- receipt_data JSON: payment confirmation details.
-- Separated to allow invoice generation before payment is collected.
CREATE TABLE `invoices` (
  `id`                int NOT NULL AUTO_INCREMENT,
  `consult_id`        int NOT NULL,
  `bill_data`         json NOT NULL,
  `entry_by`          int NOT NULL,
  `receipt_data`      json DEFAULT NULL,
  `payment_status_id` tinyint DEFAULT NULL,
  `payment_method_id` tinyint DEFAULT NULL,
  `is_active`         tinyint NOT NULL DEFAULT '1',
  `created_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `consult_id`      (`consult_id`),
  KEY `payment_status`  (`payment_status_id`),
  KEY `payment_method`  (`payment_method_id`),
  KEY `is_active`       (`is_active`),
  KEY `created_at`      (`created_at`),
  KEY `updated_at`      (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- payment_data JSON: gateway response, transaction ID, amount received.
CREATE TABLE `receipts` (
  `id`           int NOT NULL,
  `invoice_id`   int NOT NULL,
  `payment_data` json DEFAULT NULL,
  `is_active`    tinyint(1) NOT NULL DEFAULT '1',
  `created_at`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `invoice_id` (`invoice_id`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `fees_and_charges` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `name`        varchar(100) NOT NULL,
  `min_charge`  mediumint NOT NULL DEFAULT '0',
  `max_charge`  int NOT NULL,
  `facility_id` int NOT NULL,
  `is_active`   tinyint NOT NULL DEFAULT '1',
  `created_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`        (`name`),
  KEY `facility_id` (`facility_id`),
  KEY `is_active`   (`is_active`),
  KEY `created_at`  (`created_at`),
  KEY `updated_at`  (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- PHARMACY / INVENTORY
-- -----------------------------------------------------------------------------

-- Medicines are split across three tables:
--   medicine_names  - generic drug name + composition
--   medicine_brands - branded product linked to generic name
--   medicine_stock  - per-batch stock levels, expiry, pricing
-- This allows a single generic drug to have multiple brands and
-- each brand to have multiple stock batches at different prices.

CREATE TABLE `medicine_names` (
  `id`                   int NOT NULL AUTO_INCREMENT,
  `name`                 varchar(100) NOT NULL,
  `chemical_composition` varchar(400) DEFAULT NULL,
  `short_description`    varchar(402) DEFAULT NULL,
  `description`          text,
  `is_active`            tinyint NOT NULL DEFAULT '1',
  `created_at`           timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`           timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`                 (`name`) USING BTREE,
  KEY `chemical_composition` (`chemical_composition`) USING BTREE,
  KEY `is_active`            (`is_active`),
  KEY `created_at`           (`created_at`),
  KEY `updated_at`           (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `medicine_brands` (
  `id`                int NOT NULL AUTO_INCREMENT,
  `category_id`       tinyint NOT NULL,
  `name`              varchar(100) NOT NULL,
  `manufacture_id`    smallint DEFAULT NULL,
  `chemical_name`     varchar(100) DEFAULT NULL,
  `medicine_id`       int NOT NULL,
  `volume`            varchar(50) NOT NULL,
  `units`             varchar(10) DEFAULT NULL,
  `short_description` varchar(200) DEFAULT NULL,
  `description`       text,
  `is_active`         tinyint NOT NULL DEFAULT '1',
  `created_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`           (`name`) USING BTREE,
  KEY `manufacture_id` (`manufacture_id`) USING BTREE,
  KEY `category_id`    (`category_id`),
  KEY `medicine_id`    (`medicine_id`),
  KEY `is_active`      (`is_active`),
  KEY `created_at`     (`created_at`),
  KEY `updated_at`     (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- total_stock = added - consumed (maintained at application layer).
-- mrp: printed retail price; price: actual dispensing price.
CREATE TABLE `medicine_stock` (
  `id`                int NOT NULL AUTO_INCREMENT,
  `medicine_brand_id` int NOT NULL,
  `tablets_per_strip` tinyint NOT NULL DEFAULT '1',
  `expiry_date`       date DEFAULT NULL,
  `batch_id`          varchar(100) DEFAULT NULL,
  `supplier_id`       int DEFAULT NULL,
  `mrp`               decimal(6,2) NOT NULL,
  `price`             decimal(6,2) DEFAULT NULL,
  `added`             int NOT NULL DEFAULT '0',
  `total_stock`       int NOT NULL DEFAULT '0',
  `consumed`          int NOT NULL DEFAULT '0',
  `is_active`         tinyint NOT NULL DEFAULT '1',
  `created_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `medicine_brand_id` (`medicine_brand_id`),
  KEY `expiry`            (`expiry_date`),
  KEY `supplier_id`       (`supplier_id`),
  KEY `total_stock`       (`total_stock`),
  KEY `availability`      (`added`),
  KEY `consumption`       (`consumed`),
  KEY `is_active`         (`is_active`),
  KEY `created_at`        (`created_at`),
  KEY `updated_at`        (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Miscellaneous consumables (syringes, gloves, etc.) - separate from medicines.
CREATE TABLE `misc_items` (
  `id`             int NOT NULL AUTO_INCREMENT,
  `name`           varchar(100) NOT NULL,
  `manufacture_id` int DEFAULT NULL,
  `description`    varchar(200) DEFAULT NULL,
  `is_active`      tinyint NOT NULL DEFAULT '1',
  `created_at`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `name`           (`name`),
  KEY `manufacture_id` (`manufacture_id`),
  KEY `is_active`      (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `misc_stock` (
  `id`            int NOT NULL AUTO_INCREMENT,
  `misc_id`       int NOT NULL,
  `items_per_box` tinyint NOT NULL DEFAULT '1',
  `expiry_date`   date DEFAULT NULL,
  `supplier_id`   int DEFAULT NULL,
  `batch_id`      varchar(100) DEFAULT NULL,
  `mrp`           decimal(10,2) NOT NULL,
  `price`         decimal(10,2) NOT NULL,
  `added`         int NOT NULL DEFAULT '0',
  `total_stock`   int NOT NULL DEFAULT '0',
  `consumed`      int NOT NULL DEFAULT '0',
  `is_active`     tinyint NOT NULL DEFAULT '1',
  `created_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `misc_id`     (`misc_id`),
  KEY `expiry`      (`expiry_date`),
  KEY `supplier_id` (`supplier_id`),
  KEY `total_stock` (`total_stock`),
  KEY `availability`(`added`),
  KEY `consumption` (`consumed`),
  KEY `is_active`   (`is_active`),
  KEY `created_at`  (`created_at`),
  KEY `updated_at`  (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `stockists` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `name`       varchar(100) NOT NULL,
  `is_active`  tinyint NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Shared inventory table (legacy - superseded by medicine_stock / misc_stock).
CREATE TABLE `inventory` (
  `id`                int NOT NULL AUTO_INCREMENT,
  `product_id`        int NOT NULL,
  `facility_id`       int NOT NULL,
  `batch_id`          varchar(100) NOT NULL,
  `expiry_date`       date NOT NULL,
  `price`             float NOT NULL,
  `availability`      smallint NOT NULL COMMENT 'number of strips',
  `tablets_per_strip` tinyint NOT NULL COMMENT 'number of tablets per strip',
  `group_id`          int DEFAULT NULL,
  `entry_by`          int NOT NULL,
  `is_active`         tinyint NOT NULL DEFAULT '1',
  `created_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `product_id`   (`product_id`),
  KEY `facility_id`  (`facility_id`),
  KEY `batch_id`     (`batch_id`),
  KEY `expiry_date`  (`expiry_date`),
  KEY `is_active`    (`is_active`),
  KEY `created_at`   (`created_at`),
  KEY `updated_at`   (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- STAFF
-- -----------------------------------------------------------------------------

-- doctor_facility_mapping: many-to-many between staff and facilities.
-- A doctor can be active at multiple facilities within the same tenant.
CREATE TABLE `doctor_facility_mapping` (
  `id`          int NOT NULL AUTO_INCREMENT,
  `doctor_id`   int NOT NULL,
  `facility_id` int NOT NULL,
  `is_active`   tinyint NOT NULL DEFAULT '1',
  `created_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_doctor_facility` (`doctor_id`,`facility_id`),
  KEY `doctor_id`   (`doctor_id`),
  KEY `facility_id` (`facility_id`),
  KEY `is_active`   (`is_active`),
  KEY `created_at`  (`created_at`),
  KEY `updated_at`  (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- -----------------------------------------------------------------------------
-- CONTACT DEDUPLICATION
-- -----------------------------------------------------------------------------

-- Normalised mobile storage: composite unique on (isd_code, mob_number)
-- prevents duplicate entries across patients.
CREATE TABLE `mobiles` (
  `id`         int NOT NULL AUTO_INCREMENT,
  `isd_code`   varchar(5) NOT NULL,
  `mob_number` varchar(15) NOT NULL,
  `is_active`  tinyint NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `isd_mob` (`isd_code`,`mob_number`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE `emails` (
  `id`            int NOT NULL AUTO_INCREMENT,
  `email_address` varchar(100) NOT NULL,
  `is_active`     tinyint NOT NULL DEFAULT '1',
  `created_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_address` (`email_address`),
  KEY `is_active`  (`is_active`),
  KEY `created_at` (`created_at`),
  KEY `updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

SET FOREIGN_KEY_CHECKS=1;