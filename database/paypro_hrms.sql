

DROP DATABASE IF EXISTS paypro_hrms;
CREATE DATABASE paypro_hrms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE paypro_hrms;

CREATE TABLE roles (
  role_id      INT AUTO_INCREMENT PRIMARY KEY,
  role_name    VARCHAR(30) NOT NULL UNIQUE,
  description  VARCHAR(150)
) ENGINE=InnoDB;

CREATE TABLE departments (
  department_id INT AUTO_INCREMENT PRIMARY KEY,
  dept_code     VARCHAR(10) NOT NULL UNIQUE,
  dept_name     VARCHAR(80) NOT NULL,
  description   VARCHAR(200)
) ENGINE=InnoDB;

CREATE TABLE positions (
  position_id  INT AUTO_INCREMENT PRIMARY KEY,
  title        VARCHAR(80) NOT NULL,
  grade        VARCHAR(10) NOT NULL,
  min_salary   DECIMAL(12,2) NOT NULL DEFAULT 0,
  max_salary   DECIMAL(12,2) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE employees (
  employee_id     INT AUTO_INCREMENT PRIMARY KEY,
  emp_number      VARCHAR(20) NOT NULL UNIQUE,
  first_name      VARCHAR(50) NOT NULL,
  last_name       VARCHAR(50) NOT NULL,
  national_id     VARCHAR(25) UNIQUE,
  date_of_birth   DATE,
  gender          ENUM('Male','Female','Other') DEFAULT 'Other',
  email           VARCHAR(100) UNIQUE,
  phone           VARCHAR(25),
  address         VARCHAR(200),
  department_id   INT,
  position_id     INT,
  manager_id      INT NULL,
  hire_date       DATE NOT NULL,
  employment_type ENUM('Permanent','Contract','Intern','Part-time') DEFAULT 'Permanent',
  basic_salary    DECIMAL(12,2) NOT NULL DEFAULT 0,
  housing_allowance   DECIMAL(12,2) NOT NULL DEFAULT 0,
  transport_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
  bank_name       VARCHAR(60),
  bank_account    VARCHAR(40),
  nssa_number     VARCHAR(30),
  tax_number      VARCHAR(30),
  status          ENUM('Active','Suspended','Terminated') DEFAULT 'Active',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_emp_dept  FOREIGN KEY (department_id) REFERENCES departments(department_id) ON DELETE SET NULL,
  CONSTRAINT fk_emp_pos   FOREIGN KEY (position_id)   REFERENCES positions(position_id)   ON DELETE SET NULL,
  CONSTRAINT fk_emp_mgr   FOREIGN KEY (manager_id)    REFERENCES employees(employee_id)   ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NULL UNIQUE,
  username      VARCHAR(40) NOT NULL UNIQUE,
  email         VARCHAR(100) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role_id       INT NOT NULL,
  status        ENUM('Active','Disabled') DEFAULT 'Active',
  last_login    DATETIME NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_user_role FOREIGN KEY (role_id)     REFERENCES roles(role_id),
  CONSTRAINT fk_user_emp  FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE departments ADD COLUMN head_employee_id INT NULL,
  ADD CONSTRAINT fk_dept_head FOREIGN KEY (head_employee_id) REFERENCES employees(employee_id) ON DELETE SET NULL;

-- ---------------------------------------------------------------
-- 2. ATTENDANCE & LEAVE
-- ---------------------------------------------------------------
CREATE TABLE attendance (
  attendance_id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  work_date     DATE NOT NULL,
  time_in       TIME NULL,
  time_out      TIME NULL,
  hours_worked  DECIMAL(5,2) DEFAULT 0,
  overtime_hours DECIMAL(5,2) DEFAULT 0,
  status        ENUM('Present','Absent','Late','On Leave','Half Day') DEFAULT 'Present',
  remarks       VARCHAR(200),
  UNIQUE KEY uq_emp_day (employee_id, work_date),
  CONSTRAINT fk_att_emp FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE leave_types (
  leave_type_id INT AUTO_INCREMENT PRIMARY KEY,
  type_name     VARCHAR(50) NOT NULL UNIQUE,
  days_per_year INT NOT NULL DEFAULT 0,
  is_paid       TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE leave_applications (
  leave_id      INT AUTO_INCREMENT PRIMARY KEY,
  employee_id   INT NOT NULL,
  leave_type_id INT NOT NULL,
  start_date    DATE NOT NULL,
  end_date      DATE NOT NULL,
  days_requested INT NOT NULL,
  reason        VARCHAR(300),
  status        ENUM('Pending','Approved','Rejected','Cancelled') DEFAULT 'Pending',
  applied_on    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  reviewed_by   INT NULL,
  reviewed_on   DATETIME NULL,
  review_comment VARCHAR(300),
  CONSTRAINT fk_lv_emp  FOREIGN KEY (employee_id)   REFERENCES employees(employee_id) ON DELETE CASCADE,
  CONSTRAINT fk_lv_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(leave_type_id),
  CONSTRAINT fk_lv_rev  FOREIGN KEY (reviewed_by)   REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 3. PERFORMANCE MANAGEMENT
-- ---------------------------------------------------------------
CREATE TABLE appraisal_periods (
  period_id  INT AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL,
  start_date DATE NOT NULL,
  end_date   DATE NOT NULL,
  status     ENUM('Open','Closed') DEFAULT 'Open'
) ENGINE=InnoDB;

CREATE TABLE kpi_criteria (
  criteria_id INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(80) NOT NULL,
  description VARCHAR(250),
  weight      DECIMAL(5,2) NOT NULL DEFAULT 20.00,  -- percentage weight, all rows should total 100
  max_score   INT NOT NULL DEFAULT 5,
  active      TINYINT(1) DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE performance_appraisals (
  appraisal_id INT AUTO_INCREMENT PRIMARY KEY,
  employee_id  INT NOT NULL,
  period_id    INT NOT NULL,
  reviewer_id  INT NOT NULL,             -- users.user_id
  total_score  DECIMAL(5,2) DEFAULT 0,   -- weighted score out of 100
  rating       ENUM('Outstanding','Exceeds Expectations','Meets Expectations','Needs Improvement','Unsatisfactory') NULL,
  bonus_percentage DECIMAL(5,2) DEFAULT 0,
  strengths    VARCHAR(500),
  improvements VARCHAR(500),
  status       ENUM('Draft','Submitted','Approved') DEFAULT 'Draft',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_emp_period (employee_id, period_id),
  CONSTRAINT fk_ap_emp    FOREIGN KEY (employee_id) REFERENCES employees(employee_id) ON DELETE CASCADE,
  CONSTRAINT fk_ap_period FOREIGN KEY (period_id)   REFERENCES appraisal_periods(period_id),
  CONSTRAINT fk_ap_rev    FOREIGN KEY (reviewer_id) REFERENCES users(user_id)
) ENGINE=InnoDB;

CREATE TABLE appraisal_scores (
  score_id     INT AUTO_INCREMENT PRIMARY KEY,
  appraisal_id INT NOT NULL,
  criteria_id  INT NOT NULL,
  score        DECIMAL(5,2) NOT NULL,
  remark       VARCHAR(250),
  CONSTRAINT fk_sc_ap  FOREIGN KEY (appraisal_id) REFERENCES performance_appraisals(appraisal_id) ON DELETE CASCADE,
  CONSTRAINT fk_sc_cr  FOREIGN KEY (criteria_id)  REFERENCES kpi_criteria(criteria_id)
) ENGINE=InnoDB;

-- Rating -> bonus rules. This table is the performance/payroll integration point.
CREATE TABLE bonus_rules (
  rule_id     INT AUTO_INCREMENT PRIMARY KEY,
  min_score   DECIMAL(5,2) NOT NULL,
  max_score   DECIMAL(5,2) NOT NULL,
  rating      VARCHAR(40) NOT NULL,
  bonus_percentage DECIMAL(5,2) NOT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 4. PAYROLL MANAGEMENT
-- ---------------------------------------------------------------
CREATE TABLE payroll_periods (
  payroll_period_id INT AUTO_INCREMENT PRIMARY KEY,
  period_month  TINYINT NOT NULL,
  period_year   SMALLINT NOT NULL,
  pay_date      DATE NOT NULL,
  status        ENUM('Draft','Processed','Paid') DEFAULT 'Draft',
  processed_by  INT NULL,
  processed_on  DATETIME NULL,
  UNIQUE KEY uq_month_year (period_month, period_year),
  CONSTRAINT fk_pp_user FOREIGN KEY (processed_by) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE payslips (
  payslip_id        INT AUTO_INCREMENT PRIMARY KEY,
  payroll_period_id INT NOT NULL,
  employee_id       INT NOT NULL,
  appraisal_id      INT NULL,                       -- links the bonus back to an appraisal
  basic_salary      DECIMAL(12,2) NOT NULL DEFAULT 0,
  housing_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
  transport_allowance DECIMAL(12,2) NOT NULL DEFAULT 0,
  overtime_pay      DECIMAL(12,2) NOT NULL DEFAULT 0,
  performance_bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
  bonus_percentage  DECIMAL(5,2)  NOT NULL DEFAULT 0,
  gross_pay         DECIMAL(12,2) NOT NULL DEFAULT 0,
  paye              DECIMAL(12,2) NOT NULL DEFAULT 0,
  aids_levy         DECIMAL(12,2) NOT NULL DEFAULT 0,
  nssa              DECIMAL(12,2) NOT NULL DEFAULT 0,
  unpaid_leave_deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
  total_deductions  DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_pay           DECIMAL(12,2) NOT NULL DEFAULT 0,
  days_worked       INT NOT NULL DEFAULT 0,
  generated_on      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_period_emp (payroll_period_id, employee_id),
  CONSTRAINT fk_ps_period FOREIGN KEY (payroll_period_id) REFERENCES payroll_periods(payroll_period_id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_emp    FOREIGN KEY (employee_id)       REFERENCES employees(employee_id) ON DELETE CASCADE,
  CONSTRAINT fk_ps_ap     FOREIGN KEY (appraisal_id)      REFERENCES performance_appraisals(appraisal_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- PAYE bands are data, not code, so ZIMRA changes are a settings edit and not a rebuild.
CREATE TABLE tax_bands (
  band_id     INT AUTO_INCREMENT PRIMARY KEY,
  min_amount  DECIMAL(12,2) NOT NULL,
  max_amount  DECIMAL(12,2) NULL,        -- NULL = no upper limit
  rate        DECIMAL(5,4) NOT NULL,     -- 0.2000 = 20%
  deduction   DECIMAL(12,2) NOT NULL DEFAULT 0,
  currency    VARCHAR(5) DEFAULT 'USD'
) ENGINE=InnoDB;

-- ---------------------------------------------------------------
-- 5. NOTIFICATIONS, AUDIT TRAIL, SETTINGS
-- ---------------------------------------------------------------
CREATE TABLE notifications (
  notification_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id  INT NOT NULL,
  title    VARCHAR(120) NOT NULL,
  message  VARCHAR(300),
  link     VARCHAR(200),
  is_read  TINYINT(1) DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_nt_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
  log_id      INT AUTO_INCREMENT PRIMARY KEY,
  user_id     INT NULL,
  username    VARCHAR(40),
  action      VARCHAR(30) NOT NULL,      -- CREATE / UPDATE / DELETE / LOGIN / PROCESS ...
  module      VARCHAR(40) NOT NULL,
  record_id   INT NULL,
  description VARCHAR(300),
  ip_address  VARCHAR(45),
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_date (created_at)
) ENGINE=InnoDB;

CREATE TABLE settings (
  setting_key   VARCHAR(50) PRIMARY KEY,
  setting_value VARCHAR(150) NOT NULL,
  description   VARCHAR(200)
) ENGINE=InnoDB;

-- =====================================================================
-- SEED DATA
-- =====================================================================
INSERT INTO roles (role_name, description) VALUES
('admin','Full system access including user and settings management'),
('hr','Employee records, leave, payroll processing and reports'),
('manager','Views own department, approves leave and appraises staff'),
('employee','Self-service: payslips, attendance, leave and appraisals');

INSERT INTO departments (dept_code, dept_name, description) VALUES
('ICT','Information & Communication Technology','Systems, support and development'),
('FIN','Finance','Accounts, payroll and procurement'),
('HR','Human Resources','Recruitment, welfare and staff records'),
('OPS','Operations','Service delivery and logistics'),
('MKT','Marketing','Sales, brand and customer relations');

INSERT INTO positions (title, grade, min_salary, max_salary) VALUES
('Systems Developer','C1',600,1200),
('IT Support Officer','D2',350,700),
('Accountant','C1',650,1300),
('HR Officer','C2',500,950),
('Operations Supervisor','C2',480,900),
('Marketing Executive','D1',400,800),
('Departmental Manager','B1',1200,2400);

INSERT INTO employees
(emp_number,first_name,last_name,national_id,date_of_birth,gender,email,phone,address,department_id,position_id,hire_date,employment_type,basic_salary,housing_allowance,transport_allowance,bank_name,bank_account,nssa_number,tax_number)
VALUES
('EMP001','Tendai','Moyo','63-1234567X63','1988-04-12','Male','tendai.moyo@company.co.zw','+263771000001','12 Samora Machel Ave, Harare',1,7,'2016-02-01','Permanent',1800.00,300.00,120.00,'CBZ Bank','01123456789','NS100001','TX100001'),
('EMP002','Rutendo','Chirwa','63-2234567A12','1993-09-30','Female','rutendo.chirwa@company.co.zw','+263771000002','5 Fife Street, Bulawayo',1,1,'2019-07-15','Permanent',900.00,150.00,90.00,'Steward Bank','01123456790','NS100002','TX100002'),
('EMP003','Farai','Ncube','63-3234567B22','1996-01-22','Male','farai.ncube@company.co.zw','+263771000003','88 Kwame Nkrumah, Harare',1,2,'2021-03-01','Contract',520.00,80.00,60.00,'CABS','01123456791','NS100003','TX100003'),
('EMP004','Nyasha','Dube','63-4234567C33','1990-11-05','Female','nyasha.dube@company.co.zw','+263771000004','21 Second Street, Gweru',2,3,'2018-05-20','Permanent',1050.00,180.00,90.00,'CBZ Bank','01123456792','NS100004','TX100004'),
('EMP005','Kudzai','Mutasa','63-5234567D44','1994-06-18','Female','kudzai.mutasa@company.co.zw','+263771000005','7 Robert Mugabe Way, Mutare',3,4,'2020-01-10','Permanent',780.00,120.00,80.00,'NMB Bank','01123456793','NS100005','TX100005'),
('EMP006','Tapiwa','Sibanda','63-6234567E55','1992-02-27','Male','tapiwa.sibanda@company.co.zw','+263771000006','14 Leopold Takawira, Harare',4,5,'2017-08-01','Permanent',760.00,110.00,80.00,'ZB Bank','01123456794','NS100006','TX100006'),
('EMP007','Chiedza','Mangwiro','63-7234567F66','1997-12-09','Female','chiedza.mangwiro@company.co.zw','+263771000007','3 Josiah Tongogara, Kwekwe',5,6,'2022-04-04','Part-time','480.00',70.00,60.00,'Steward Bank','01123456795','NS100007','TX100007');

UPDATE employees SET manager_id = 1 WHERE employee_id IN (2,3);
UPDATE departments SET head_employee_id = 1 WHERE department_id = 1;

-- Default password for every seeded account is:  Password@123
INSERT INTO users (employee_id, username, email, password_hash, role_id) VALUES
(NULL,'admin','admin@company.co.zw','$2y$12$4CQppC78LlgBUruIzndwauoKLiohbKv2KeKIa6BciJB87KWmAaEh2',1),
(5,'hr.kudzai','kudzai.mutasa@company.co.zw','$2y$12$4CQppC78LlgBUruIzndwauoKLiohbKv2KeKIa6BciJB87KWmAaEh2',2),
(1,'mgr.tendai','tendai.moyo@company.co.zw','$2y$12$4CQppC78LlgBUruIzndwauoKLiohbKv2KeKIa6BciJB87KWmAaEh2',3),
(2,'emp.rutendo','rutendo.chirwa@company.co.zw','$2y$12$4CQppC78LlgBUruIzndwauoKLiohbKv2KeKIa6BciJB87KWmAaEh2',4);

INSERT INTO leave_types (type_name, days_per_year, is_paid) VALUES
('Annual Leave',22,1),('Sick Leave',30,1),('Maternity Leave',98,1),
('Compassionate Leave',12,1),('Unpaid Leave',0,0),('Study Leave',10,1);

INSERT INTO kpi_criteria (name, description, weight, max_score) VALUES
('Quality of work','Accuracy, thoroughness and standard of output',25,5),
('Productivity','Volume of work completed against targets',20,5),
('Teamwork','Cooperation, knowledge sharing and support of colleagues',15,5),
('Punctuality & attendance','Timekeeping and reliability',15,5),
('Initiative & problem solving','Works independently and improves processes',15,5),
('Communication','Clarity in reporting, email and meetings',10,5);

INSERT INTO bonus_rules (min_score, max_score, rating, bonus_percentage) VALUES
(90.00,100.00,'Outstanding',15.00),
(80.00,89.99,'Exceeds Expectations',10.00),
(65.00,79.99,'Meets Expectations',5.00),
(50.00,64.99,'Needs Improvement',0.00),
(0.00,49.99,'Unsatisfactory',0.00);

INSERT INTO appraisal_periods (name, start_date, end_date, status) VALUES
('H1 2026 Review','2026-01-01','2026-06-30','Open'),
('H2 2025 Review','2025-07-01','2025-12-31','Closed');

-- Illustrative monthly USD PAYE bands. Verify against the current ZIMRA
-- tax tables before use; they are stored as data so they can be updated.
INSERT INTO tax_bands (min_amount, max_amount, rate, deduction) VALUES
(0.00,100.00,0.0000,0.00),
(100.01,300.00,0.2000,20.00),
(300.01,1000.00,0.2500,35.00),
(1000.01,2000.00,0.3000,85.00),
(2000.01,3000.00,0.3500,185.00),
(3000.01,NULL,0.4000,335.00);

INSERT INTO settings (setting_key, setting_value, description) VALUES
('company_name','Zimtech Solutions (Pvt) Ltd','Name printed on payslips and reports'),
('company_address','15 Nelson Mandela Avenue, Harare','Company postal address'),
('currency','USD','Payroll currency symbol'),
('nssa_rate','0.045','NSSA employee contribution rate (4.5%)'),
('nssa_ceiling','700','Maximum monthly insurable earnings for NSSA'),
('aids_levy_rate','0.03','AIDS levy charged on PAYE payable'),
('overtime_rate','1.5','Overtime multiplier on hourly rate'),
('working_days','22','Standard working days per month'),
('working_hours','8','Standard working hours per day');

-- Sample attendance for the current month so reports are not empty
INSERT INTO attendance (employee_id, work_date, time_in, time_out, hours_worked, status) VALUES
(2, CURDATE() - INTERVAL 3 DAY, '08:00:00','17:00:00',8.00,'Present'),
(2, CURDATE() - INTERVAL 2 DAY, '08:25:00','17:00:00',7.50,'Late'),
(2, CURDATE() - INTERVAL 1 DAY, '08:00:00','17:00:00',8.00,'Present'),
(3, CURDATE() - INTERVAL 2 DAY, '08:05:00','17:00:00',8.00,'Present'),
(4, CURDATE() - INTERVAL 1 DAY, '07:55:00','17:30:00',8.50,'Present');
