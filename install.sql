CREATE TABLE users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  username VARCHAR(50) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('owner','manager','cashier') NOT NULL DEFAULT 'owner',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(200) NOT NULL,
  barcode VARCHAR(80) NULL,
  unit VARCHAR(20) NOT NULL DEFAULT 'pcs',
  min_stock DECIMAL(12,3) NOT NULL DEFAULT 0,
  track_expiry TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_barcode (barcode)
);

CREATE TABLE sales (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sale_type ENUM('retail','wholesale') NOT NULL,
  invoice_no VARCHAR(40) NOT NULL,
  sale_date DATETIME NOT NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_mode ENUM('cash','upi','bank','mixed','credit') NOT NULL DEFAULT 'cash',
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sale_date (sale_date),
  INDEX idx_sale_type (sale_type)
);

CREATE TABLE stock_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  move_type ENUM('purchase','sale','adjust','opening') NOT NULL,
  qty_in DECIMAL(12,3) NOT NULL DEFAULT 0,
  qty_out DECIMAL(12,3) NOT NULL DEFAULT 0,
  move_date DATETIME NOT NULL,
  ref_table VARCHAR(30) NULL,
  ref_id INT NULL,
  note VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product (product_id),
  INDEX idx_move_date (move_date)
);

CREATE TABLE stock_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  batch_no VARCHAR(60) NULL,
  expiry_date DATE NULL,
  qty_available DECIMAL(12,3) NOT NULL DEFAULT 0,
  cost_rate DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_expiry (expiry_date),
  INDEX idx_product_batch (product_id, batch_no)
);