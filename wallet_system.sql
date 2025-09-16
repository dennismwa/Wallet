-- Luidigitals Wallet System Database Structure
-- Database: vxjtgclw_luigitals_wallet

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    full_name VARCHAR(100),
    salary DECIMAL(15,2) DEFAULT 0.00,
    currency VARCHAR(10) DEFAULT 'KES',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Categories table for bill types
CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    icon VARCHAR(50) DEFAULT 'fas fa-money-bill',
    color VARCHAR(20) DEFAULT '#204cb0',
    is_default TINYINT(1) DEFAULT 0,
    user_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Bills table
CREATE TABLE IF NOT EXISTS bills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT,
    name VARCHAR(100) NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    due_date DATE,
    is_recurring TINYINT(1) DEFAULT 0,
    recurring_period ENUM('weekly', 'monthly', 'quarterly', 'yearly') DEFAULT 'monthly',
    auto_pay TINYINT(1) DEFAULT 0,
    priority ENUM('low', 'medium', 'high') DEFAULT 'medium',
    status ENUM('pending', 'paid', 'overdue') DEFAULT 'pending',
    threshold_warning DECIMAL(15,2) DEFAULT 0.00,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Transactions table
CREATE TABLE IF NOT EXISTS transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    bill_id INT NULL,
    category_id INT NULL,
    type ENUM('income', 'expense', 'transfer') NOT NULL,
    amount DECIMAL(15,2) NOT NULL,
    description VARCHAR(255),
    transaction_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    payment_method ENUM('cash', 'bank', 'mobile_money', 'card') DEFAULT 'cash',
    reference_number VARCHAR(100),
    balance_after DECIMAL(15,2),
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (bill_id) REFERENCES bills(id) ON DELETE SET NULL,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Wallet balance table
CREATE TABLE IF NOT EXISTS wallet_balance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    current_balance DECIMAL(15,2) DEFAULT 0.00,
    last_salary_date DATE,
    next_salary_date DATE,
    total_income DECIMAL(15,2) DEFAULT 0.00,
    total_expenses DECIMAL(15,2) DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Notifications table
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'warning', 'success', 'error') DEFAULT 'info',
    is_read TINYINT(1) DEFAULT 0,
    related_bill_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (related_bill_id) REFERENCES bills(id) ON DELETE SET NULL
);

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    setting_key VARCHAR(100) NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_setting (user_id, setting_key)
);

-- Budgets table
CREATE TABLE IF NOT EXISTS budgets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT,
    name VARCHAR(100) NOT NULL,
    allocated_amount DECIMAL(15,2) NOT NULL,
    spent_amount DECIMAL(15,2) DEFAULT 0.00,
    period_start DATE NOT NULL,
    period_end DATE NOT NULL,
    alert_threshold DECIMAL(5,2) DEFAULT 80.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
);

-- Insert default user
INSERT INTO users (username, password, full_name, salary) VALUES 
('Lui', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Luigi Administrator', 100000.00);

-- Get user ID for default data
SET @user_id = (SELECT id FROM users WHERE username = 'Lui');

-- Insert default categories
INSERT INTO categories (name, icon, color, is_default, user_id) VALUES
('Rent', 'fas fa-home', '#e74c3c', 1, @user_id),
('Electricity', 'fas fa-bolt', '#f39c12', 1, @user_id),
('Water', 'fas fa-tint', '#3498db', 1, @user_id),
('Food & Groceries', 'fas fa-shopping-cart', '#16ac2e', 1, @user_id),
('Fuel', 'fas fa-gas-pump', '#9b59b6', 1, @user_id),
('Clothing', 'fas fa-tshirt', '#1abc9c', 1, @user_id),
('Entertainment', 'fas fa-film', '#ff6b6b', 1, @user_id),
('Healthcare', 'fas fa-medkit', '#fd79a8', 1, @user_id),
('Transportation', 'fas fa-bus', '#fdcb6e', 1, @user_id),
('Miscellaneous', 'fas fa-ellipsis-h', '#6c5ce7', 1, @user_id);

-- Insert default wallet balance
INSERT INTO wallet_balance (user_id, current_balance) VALUES (@user_id, 0.00);

-- Insert default settings
INSERT INTO settings (user_id, setting_key, setting_value) VALUES
(@user_id, 'dark_mode', '1'),
(@user_id, 'currency', 'KES'),
(@user_id, 'notifications_enabled', '1'),
(@user_id, 'auto_backup', '1'),
(@user_id, 'dashboard_layout', 'grid'),
(@user_id, 'date_format', 'Y-m-d'),
(@user_id, 'salary_day', '1'),
(@user_id, 'low_balance_alert', '5000'),
(@user_id, 'high_expense_alert', '10000');

-- Create indexes for better performance
CREATE INDEX idx_transactions_user_date ON transactions(user_id, transaction_date);
CREATE INDEX idx_bills_user_due ON bills(user_id, due_date);
CREATE INDEX idx_notifications_user_unread ON notifications(user_id, is_read);
CREATE INDEX idx_transactions_type ON transactions(type);
CREATE INDEX idx_bills_status ON bills(status);