-- Backup of current XAMPP hris database
CREATE DATABASE IF NOT EXISTS hris CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hris;
SET FOREIGN_KEY_CHECKS=0;

DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `email` varchar(255) DEFAULT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  `workhours` decimal(10,2) DEFAULT NULL,
  `Break_Time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_attendance_email_time_in` (`email`,`time_in`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `attendance` (`id`, `created_at`, `email`, `time_in`, `time_out`, `workhours`, `Break_Time`) VALUES
('1', '2026-10-20 08:00:00', 'juan.delacruz@example.com', '2026-10-20 08:00:00', '2026-10-20 17:00:00', '8.00', '2026-10-20 12:00:00'),
('2', '2026-10-21 08:00:00', 'juan.delacruz@example.com', '2026-10-21 08:00:00', '2026-10-21 17:00:00', '8.00', '2026-10-21 12:00:00'),
('3', '2026-10-20 08:15:00', 'maria.santos@example.com', '2026-10-20 08:15:00', '2026-10-20 17:15:00', '8.00', '2026-10-20 12:00:00'),
('4', '2026-10-21 07:55:00', 'maria.santos@example.com', '2026-10-21 07:55:00', '2026-10-21 16:55:00', '8.00', '2026-10-21 12:00:00'),
('5', '2026-10-20 09:00:00', 'paolo.mendoza@example.com', '2026-10-20 09:00:00', '2026-10-20 18:00:00', '8.00', '2026-10-20 12:00:00'),
('6', '2026-10-21 08:30:00', 'paolo.mendoza@example.com', '2026-10-21 08:30:00', '2026-10-21 17:30:00', '8.00', '2026-10-21 12:00:00');

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `departments_code_key` (`code`),
  UNIQUE KEY `departments_name_key` (`name`),
  CONSTRAINT `departments_status_check` CHECK (`status` in ('Active','Inactive'))
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `departments` (`id`, `code`, `name`, `description`, `status`, `created_at`, `updated_at`) VALUES
('1', 'HRD', 'Human Resources', 'Recruitment, employee relations and leave administration', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('2', 'IT', 'Information Technology', 'Software development, systems and technical support', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('3', 'FIN', 'Finance', 'Accounting, payroll and budgeting', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(20) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) NOT NULL,
  `suffix` varchar(20) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `department_id` bigint(20) DEFAULT NULL,
  `position_id` bigint(20) DEFAULT NULL,
  `date_hired` date NOT NULL,
  `employment_type` varchar(50) NOT NULL DEFAULT 'Full-time',
  `employment_status` varchar(30) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `employees_employee_id_key` (`employee_id`),
  KEY `idx_employees_department` (`department_id`),
  KEY `idx_employees_position` (`position_id`),
  KEY `idx_employees_status` (`employment_status`),
  KEY `idx_employees_last_name` (`last_name`),
  CONSTRAINT `fk_employee_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_employee_position` FOREIGN KEY (`position_id`) REFERENCES `positions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `employee_status_check` CHECK (`employment_status` in ('Active','Inactive','On Leave','Suspended'))
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `employees` (`id`, `employee_id`, `first_name`, `middle_name`, `last_name`, `suffix`, `date_of_birth`, `gender`, `phone`, `email`, `address`, `department_id`, `position_id`, `date_hired`, `employment_type`, `employment_status`, `created_at`, `updated_at`) VALUES
('1', 'EMP-001', 'Juan', 'Santos', 'Dela Cruz', NULL, '1995-03-14', 'Male', '09171234501', 'juan.delacruz@example.com', '123 Rizal Ave, Quezon City', '2', '2', '2023-06-01', 'Full-time', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('2', 'EMP-002', 'Maria', 'Lopez', 'Santos', NULL, '1993-08-22', 'Female', '09171234502', 'maria.santos@example.com', '45 Mabini St, Makati City', '3', '3', '2022-01-10', 'Full-time', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('3', 'EMP-003', 'Paolo', 'Garcia', 'Mendoza', NULL, '1999-11-05', 'Male', '09171234503', 'paolo.mendoza@example.com', '78 Bonifacio Rd, Pasig City', '2', '4', '2025-02-17', 'Full-time', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('4', 'EMP-004', 'Ana', 'Cruz', 'Reyes', NULL, '1990-05-30', 'Female', '09171234504', 'ana.reyes@example.com', '9 Luna St, Mandaluyong City', '1', '1', '2021-04-05', 'Full-time', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('5', 'EMP-005', 'Carlo', 'Ramos', 'Villanueva', NULL, '1988-12-12', 'Male', '09171234505', 'carlo.villanueva@example.com', '210 Aguinaldo Hwy, Cavite', '2', '5', '2020-09-14', 'Full-time', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

DROP TABLE IF EXISTS `leave`;
CREATE TABLE `leave` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `annual_leave` smallint(6) DEFAULT NULL,
  `sick_leave` smallint(6) DEFAULT NULL,
  `personal_leave` smallint(6) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_leave_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `leave` (`id`, `created_at`, `annual_leave`, `sick_leave`, `personal_leave`, `email`) VALUES
('1', '2026-10-22 21:50:27', '12', '4', '3', 'juan.delacruz@example.com'),
('2', '2026-10-22 21:50:27', '12', '6', '3', 'maria.santos@example.com'),
('3', '2026-10-22 21:50:27', '12', '6', '3', 'paolo.mendoza@example.com');

DROP TABLE IF EXISTS `leave_balances`;
CREATE TABLE `leave_balances` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `leave_type_id` bigint(20) NOT NULL,
  `year` int(11) NOT NULL,
  `allocated_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `used_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `remaining_days` decimal(6,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_balances_emp_type_year_key` (`employee_id`,`leave_type_id`,`year`),
  KEY `idx_lb_type` (`leave_type_id`),
  CONSTRAINT `fk_lb_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lb_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `leave_balances` (`id`, `employee_id`, `leave_type_id`, `year`, `allocated_days`, `used_days`, `remaining_days`, `created_at`, `updated_at`) VALUES
('1', '1', '1', '2026', '12.00', '3.00', '9.00', '2026-10-22 21:50:27', '2026-10-22 22:10:38'),
('2', '1', '2', '2026', '6.00', '2.00', '4.00', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('3', '1', '3', '2026', '3.00', '0.00', '3.00', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('4', '2', '1', '2026', '12.00', '0.00', '12.00', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('5', '2', '2', '2026', '6.00', '0.00', '6.00', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('6', '2', '3', '2026', '3.00', '0.00', '3.00', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('7', '3', '1', '2026', '12.00', '0.00', '12.00', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('8', '3', '2', '2026', '6.00', '0.00', '6.00', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('9', '3', '3', '2026', '3.00', '0.00', '3.00', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

DROP TABLE IF EXISTS `leave_request_logs`;
CREATE TABLE `leave_request_logs` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `leave_request_id` bigint(20) NOT NULL,
  `employee_id` bigint(20) NOT NULL,
  `action` varchar(30) NOT NULL,
  `old_status` varchar(30) DEFAULT NULL,
  `new_status` varchar(30) DEFAULT NULL,
  `performed_by_id` bigint(20) DEFAULT NULL,
  `performed_by_name` varchar(255) DEFAULT NULL,
  `performed_by_email` varchar(255) DEFAULT NULL,
  `performed_by_role` varchar(30) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_leave_request_logs_employee` (`employee_id`,`created_at`),
  KEY `idx_leave_request_logs_request` (`leave_request_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `leave_request_logs` (`id`, `leave_request_id`, `employee_id`, `action`, `old_status`, `new_status`, `performed_by_id`, `performed_by_name`, `performed_by_email`, `performed_by_role`, `remarks`, `created_at`) VALUES
('1', '1', '1', 'Filed', NULL, 'Pending', '1', 'Juan Dela Cruz', 'juan.delacruz@example.com', 'employee', 'Fever and flu, with medical certificate', '2026-10-01 21:50:27'),
('2', '1', '1', 'Approved', 'Pending', 'Approved', '4', 'Ana Reyes', 'ana.reyes@example.com', 'hr', 'Medical certificate received', '2026-10-02 18:50:27'),
('3', '2', '1', 'Filed', NULL, 'Pending', '1', 'Juan Dela Cruz', 'juan.delacruz@example.com', 'employee', 'Family vacation in Baguio', '2026-10-19 21:50:27'),
('4', '3', '2', 'Filed', NULL, 'Pending', '2', 'Maria Santos', 'maria.santos@example.com', 'employee', 'Bank and government errands', '2026-10-10 21:50:27'),
('5', '3', '2', 'Rejected', 'Pending', 'Rejected', '4', 'Ana Reyes', 'ana.reyes@example.com', 'hr', 'Month-end closing, please refile next week', '2026-10-11 21:50:27'),
('6', '4', '2', 'Filed', NULL, 'Pending', '2', 'Maria Santos', 'maria.santos@example.com', 'employee', 'Out-of-town trip', '2026-10-16 21:50:27'),
('7', '4', '2', 'Cancelled', 'Pending', 'Cancelled', '2', 'Maria Santos', 'maria.santos@example.com', 'employee', NULL, '2026-10-17 21:50:27'),
('8', '5', '3', 'Filed', NULL, 'Pending', '3', 'Paolo Mendoza', 'paolo.mendoza@example.com', 'employee', 'Dental appointment', '2026-10-20 21:50:27'),
('9', '2', '1', 'Approved', 'Pending', 'Approved', '4', NULL, NULL, 'hr', NULL, '2026-10-22 22:10:38');

DROP TABLE IF EXISTS `leave_requests`;
CREATE TABLE `leave_requests` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) NOT NULL,
  `leave_type_id` bigint(20) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `number_of_days` decimal(6,2) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Pending',
  `approved_by` int(11) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_lr_emp` (`employee_id`),
  KEY `idx_lr_status` (`status`),
  KEY `idx_lr_start_end` (`start_date`,`end_date`),
  KEY `idx_lr_type` (`leave_type_id`),
  CONSTRAINT `fk_lr_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_lr_type` FOREIGN KEY (`leave_type_id`) REFERENCES `leave_types` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `chk_lr_dates` CHECK (`end_date` >= `start_date`),
  CONSTRAINT `chk_lr_days` CHECK (`number_of_days` > 0),
  CONSTRAINT `leave_requests_status_check` CHECK (`status` in ('Pending','Approved','Rejected','Cancelled'))
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `leave_requests` (`id`, `employee_id`, `leave_type_id`, `start_date`, `end_date`, `number_of_days`, `reason`, `status`, `approved_by`, `approved_at`, `created_at`) VALUES
('1', '1', '2', '2026-10-02', '2026-10-03', '2.00', 'Fever and flu, with medical certificate', 'Approved', '4', '2026-10-02 18:50:27', '2026-10-01 21:50:27'),
('2', '1', '1', '2026-11-05', '2026-11-07', '3.00', 'Family vacation in Baguio', 'Approved', '4', '2026-10-22 22:10:38', '2026-10-19 21:50:27'),
('3', '2', '3', '2026-10-12', '2026-10-12', '1.00', 'Bank and government errands', 'Rejected', '4', '2026-10-11 21:50:27', '2026-10-10 21:50:27'),
('4', '2', '1', '2026-10-29', '2026-10-30', '2.00', 'Out-of-town trip', 'Cancelled', NULL, NULL, '2026-10-16 21:50:27'),
('5', '3', '2', '2026-10-24', '2026-10-24', '1.00', 'Dental appointment', 'Pending', NULL, NULL, '2026-10-20 21:50:27');

DROP TABLE IF EXISTS `leave_types`;
CREATE TABLE `leave_types` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `leave_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `default_days` int(11) NOT NULL DEFAULT 0,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `leave_types_leave_name_key` (`leave_name`),
  CONSTRAINT `leave_types_status_check` CHECK (`status` in ('Active','Inactive'))
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `leave_types` (`id`, `leave_name`, `description`, `default_days`, `status`, `created_at`, `updated_at`) VALUES
('1', 'Annual Leave', 'Paid time off for vacation, rest, and personal recreation', '12', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('2', 'Sick Leave', 'Paid leave for illness, medical appointments, and health recovery', '6', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('3', 'Personal Leave', 'Leave for urgent personal matters or emergencies', '3', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('4', 'Maternity Leave', 'Paid leave for female employees during childbirth and postpartum recovery', '105', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('5', 'Paternity Leave', 'Paid leave for married male employees upon child delivery', '7', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('6', 'Bereavement Leave', 'Leave taken upon the death of an immediate family member', '3', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('7', 'Emergency Leave', 'Leave for unforeseen emergencies, calamities, or disasters', '3', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

DROP TABLE IF EXISTS `positions`;
CREATE TABLE `positions` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `code` varchar(20) NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `positions_code_key` (`code`),
  UNIQUE KEY `positions_title_key` (`title`),
  CONSTRAINT `positions_status_check` CHECK (`status` in ('Active','Inactive'))
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `positions` (`id`, `code`, `title`, `description`, `status`, `created_at`, `updated_at`) VALUES
('1', 'HRO', 'HR Officer', 'Handles leave approvals and employee records', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('2', 'DEV', 'Software Developer', 'Builds and maintains internal applications', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('3', 'ACC', 'Accountant', 'Prepares financial reports and payroll', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('4', 'ITS', 'IT Support Specialist', 'Provides helpdesk and hardware support', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('5', 'SYS', 'System Administrator', 'Manages servers, accounts and system security', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `employee_id` bigint(20) DEFAULT NULL,
  `username` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(30) NOT NULL DEFAULT 'employee',
  `status` varchar(20) NOT NULL DEFAULT 'Active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_username_key` (`username`),
  KEY `idx_users_employee` (`employee_id`),
  CONSTRAINT `fk_user_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` (`id`, `employee_id`, `username`, `password`, `role`, `status`, `created_at`, `updated_at`) VALUES
('1', '1', 'juan', '$2y$10$IJ0.EAgv/fsvX5yKvdU7A.JvgvdaP7Q6/hvtqGf7qRMqwd8m1oBSC', 'employee', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('2', '2', 'maria', '$2y$10$7PzeGw.Gr9Bqhf0nMX7nD.RmQ6xeqCRrxl6o054UXhM0S2gK//HOi', 'employee', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('3', '3', 'paolo', '$2y$10$GqQKJqQ41sr07OqIVKygneLpdHfyUI7bHfuKir2eVWDvnob9LP1Iu', 'employee', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('4', '4', 'hr', '$2y$10$Gt32fab9DNjxVD.vloBwzuITCMkdwhcQavqqG1jUZn0dZ/S1FRJ2m', 'hr', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27'),
('5', '5', 'admin', '$2y$10$.DcT05bVrYvEkxfiUa2CFuLVeC6p4gjtVkTTund7df85yBwbjleJO', 'admin', 'Active', '2026-10-22 21:50:27', '2026-10-22 21:50:27');

SET FOREIGN_KEY_CHECKS=1;
