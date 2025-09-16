<?php
// Updated ajax/add_transaction.php with full payment methods support
require_once '../config/database.php';
require_once '../config/payment_methods.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Validate input
    $type = sanitizeInput($_POST['type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = sanitizeInput($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $payment_method = sanitizeInput($_POST['payment_method'] ?? 'cash');
    $use_external_payment = isset($_POST['use_external_payment']) && $_POST['use_external_payment'] === '1';
    $reference_number = sanitizeInput($_POST['reference_number'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $transaction_date = $_POST['transaction_date'] ?? date('Y-m-d H:i:s');

    // Basic validation
    if (empty($type) || $amount <= 0 || empty($description) || $category_id <= 0) {
        throw new Exception('Please fill in all required fields.');
    }

    if (!in_array($type, ['income', 'expense'])) {
        throw new Exception('Invalid transaction type.');
    }
    
    // Validate amount
    if ($amount > 999999999.99) {
        throw new Exception('Amount is too large.');
    }
    
    // Validate transaction date
    $parsed_date = DateTime::createFromFormat('Y-m-d\TH:i', $transaction_date);
    if (!$parsed_date) {
        $parsed_date = new DateTime();
    }
    $formatted_date = $parsed_date->format('Y-m-d H:i:s');

    // Verify category belongs to user or is default
    $category = $db->fetchOne(
        "SELECT * FROM categories WHERE id = ? AND (user_id = ? OR is_default = 1)",
        [$category_id, $user_id]
    );
    
    if (!$category) {
        throw new Exception('Invalid category selected.');
    }

    // Check if payment method is external
    $external_methods = ['mpesa', 'binance', 'paypal', 'stripe'];
    $is_external_method = in_array($payment_method, $external_methods);

    if ($is_external_method && $use_external_payment) {
        // Process external payment
        $paymentConfig = new PaymentMethodsConfig($db, $user_id);
        
        if (!$paymentConfig->isPaymentMethodEnabled($payment_method)) {
            throw new Exception("Payment method {$payment_method} is not enabled. Please configure it in settings.");
        }
        
        $db->beginTransaction();
        
        // Get current balance for balance_after calculation
        $currentBalance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
        $balance = $currentBalance ? $currentBalance['current_balance'] : 0;
        
        // Calculate what balance would be after transaction (for record keeping)
        $balance_after = $type === 'income' ? $balance + $amount : $balance - $amount;
        
        // For expenses, check if we would have sufficient funds when payment completes
        if ($type === 'expense' && $balance_after < 0) {
            throw new Exception('Insufficient funds for this transaction.');
        }
        
        // Create pending transaction
        $db->execute(
            "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after, transaction_date, status, reference_number, notes) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?)",
            [$user_id, $category_id, $type, $amount, $description, $payment_method, $balance_after, $formatted_date, $reference_number, $notes]
        );
        
        $transactionId = $db->lastInsertId();
        
        // Process external payment
        try {
            $payment_result = $paymentConfig->processPayment(
                $payment_method, 
                $amount, 
                'KES', // Default currency, should be configurable
                $description,
                $transactionId
            );
            
            if ($payment_result['success']) {
                $db->commit();
                
                $response = [
                    'success' => true,
                    'message' => 'Payment initiated successfully',
                    'transaction_id' => $transactionId,
                    'payment_method' => $payment_method,
                    'external_data' => $payment_result
                ];
                
                // Add method-specific response data
                if ($payment_method === 'mpesa' && isset($payment_result['checkout_request_id'])) {
                    $response['message'] = 'M-Pesa payment request sent. Please complete payment on your phone.';
                    $response['checkout_request_id'] = $payment_result['checkout_request_id'];
                } elseif ($payment_method === 'binance' && isset($payment_result['checkout_url'])) {
                    $response['message'] = 'Redirecting to Binance Pay...';
                    $response['checkout_url'] = $payment_result['checkout_url'];
                } elseif ($payment_method === 'paypal' && isset($payment_result['approval_url'])) {
                    $response['message'] = 'Redirecting to PayPal...';
                    $response['approval_url'] = $payment_result['approval_url'];
                } elseif ($payment_method === 'stripe' && isset($payment_result['client_secret'])) {
                    $response['message'] = 'Processing Stripe payment...';
                    $response['client_secret'] = $payment_result['client_secret'];
                }
                
                echo json_encode($response);
            } else {
                throw new Exception('Payment processing failed: ' . ($payment_result['message'] ?? 'Unknown error'));
            }
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } else {
        // Standard internal transaction processing
        $internal_methods = ['cash', 'bank', 'mobile_money', 'card'];
        if ($is_external_method && !$use_external_payment) {
            // Allow external methods to be used as manual/internal transactions
            $internal_methods[] = $payment_method;
        } elseif (!in_array($payment_method, $internal_methods)) {
            throw new Exception('Invalid payment method for internal transactions.');
        }
        
        // Start transaction
        $db->beginTransaction();
        
        // Get current balance
        $currentBalance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
        
        if (!$currentBalance) {
            $db->execute("INSERT INTO wallet_balance (user_id) VALUES (?)", [$user_id]);
            $balance = 0;
        } else {
            $balance = $currentBalance['current_balance'];
        }
        
        // Calculate new balance
        $newBalance = $type === 'income' ? $balance + $amount : $balance - $amount;
        
        // Check for negative balance on expenses
        if ($type === 'expense' && $newBalance < 0) {
            throw new Exception('Insufficient funds for this transaction.');
        }
        
        // Insert transaction
        $db->execute(
            "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after, transaction_date, reference_number, notes, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed')",
            [$user_id, $category_id, $type, $amount, $description, $payment_method, $newBalance, $formatted_date, $reference_number, $notes]
        );
        
        $transactionId = $db->lastInsertId();
        
        // Update wallet balance
        $db->execute(
            "UPDATE wallet_balance SET 
             current_balance = ?,
             total_income = total_income + ?,
             total_expenses = total_expenses + ?,
             updated_at = NOW()
             WHERE user_id = ?",
            [
                $newBalance,
                $type === 'income' ? $amount : 0,
                $type === 'expense' ? $amount : 0,
                $user_id
            ]
        );
        
        // Create notification for large transactions
        if ($amount >= 10000) { // Configurable threshold
            $notificationTitle = $type === 'income' ? 'Large Income Received' : 'Large Expense Recorded';
            $notificationMessage = "Large {$type} of " . formatMoney($amount, 'KES') . " recorded: {$description}";
            $notificationType = $type === 'income' ? 'success' : 'warning';
            
            $db->execute(
                "INSERT INTO notifications (user_id, title, message, type) 
                 VALUES (?, ?, ?, ?)",
                [$user_id, $notificationTitle, $notificationMessage, $notificationType]
            );
        }
        
        // Budget tracking - update spent amounts for expense categories
        if ($type === 'expense') {
            $currentMonth = date('Y-m');
            $db->execute(
                "UPDATE budgets SET spent_amount = (
                    SELECT COALESCE(SUM(t.amount), 0) 
                    FROM transactions t 
                    WHERE t.user_id = budgets.user_id 
                    AND t.category_id = budgets.category_id 
                    AND t.type = 'expense'
                    AND DATE_FORMAT(t.transaction_date, '%Y-%m') = ?
                    AND t.status = 'completed'
                )
                WHERE user_id = ? AND category_id = ?",
                [$currentMonth, $user_id, $category_id]
            );
            
            // Check for budget alerts
            $budget = $db->fetchOne(
                "SELECT * FROM budgets 
                 WHERE user_id = ? AND category_id = ? 
                 AND ? BETWEEN period_start AND period_end",
                [$user_id, $category_id, date('Y-m-d')]
            );
            
            if ($budget && $budget['spent_amount'] > 0) {
                $percentage = ($budget['spent_amount'] / $budget['allocated_amount']) * 100;
                
                if ($percentage >= $budget['alert_threshold']) {
                    $alertTitle = $percentage >= 100 ? 'Budget Exceeded!' : 'Budget Alert';
                    $alertMessage = "You've spent " . number_format($percentage, 1) . "% of your '{$budget['name']}' budget.";
                    $alertType = $percentage >= 100 ? 'error' : 'warning';
                    
                    $db->execute(
                        "INSERT INTO notifications (user_id, title, message, type) 
                         VALUES (?, ?, ?, ?)",
                        [$user_id, $alertTitle, $alertMessage, $alertType]
                    );
                }
            }
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Transaction added successfully',
            'new_balance' => $newBalance,
            'transaction_id' => $transactionId
        ]);
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    // Log the error for debugging
    error_log("Add transaction error: " . $e->getMessage() . " | User ID: " . $user_id);
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
