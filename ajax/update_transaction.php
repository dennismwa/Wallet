<?php
// Updated ajax/update_transaction.php with payment methods support
require_once '../config/database.php';
require_once '../config/payment_methods.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Validate input
    $id = intval($_POST['id'] ?? 0);
    $type = sanitizeInput($_POST['type'] ?? '');
    $amount = floatval($_POST['amount'] ?? 0);
    $description = sanitizeInput($_POST['description'] ?? '');
    $category_id = intval($_POST['category_id'] ?? 0);
    $payment_method = sanitizeInput($_POST['payment_method'] ?? 'cash');
    $reference_number = sanitizeInput($_POST['reference_number'] ?? '');
    $notes = sanitizeInput($_POST['notes'] ?? '');
    $transaction_date = $_POST['transaction_date'] ?? '';
    
    if ($id <= 0 || empty($type) || $amount <= 0 || empty($description) || $category_id <= 0) {
        throw new Exception('Please fill in all required fields.');
    }
    
    if (!in_array($type, ['income', 'expense'])) {
        throw new Exception('Invalid transaction type.');
    }
    
    // Validate amount
    if ($amount > 999999999.99) {
        throw new Exception('Amount is too large.');
    }
    
    // Parse and validate transaction date
    if (!empty($transaction_date)) {
        $parsed_date = DateTime::createFromFormat('Y-m-d\TH:i', $transaction_date);
        if (!$parsed_date) {
            throw new Exception('Invalid transaction date format.');
        }
        $formatted_date = $parsed_date->format('Y-m-d H:i:s');
    }
    
    // Get original transaction
    $originalTransaction = $db->fetchOne(
        "SELECT * FROM transactions WHERE id = ? AND user_id = ?",
        [$id, $user_id]
    );
    
    if (!$originalTransaction) {
        throw new Exception('Transaction not found.');
    }
    
    // Check if transaction is pending (from external payment) - these can't be edited normally
    if ($originalTransaction['status'] === 'pending') {
        throw new Exception('Cannot edit pending transactions. Please wait for payment completion or cancel the payment first.');
    }
    
    // Verify category belongs to user or is default
    $category = $db->fetchOne(
        "SELECT * FROM categories WHERE id = ? AND (user_id = ? OR is_default = 1)",
        [$category_id, $user_id]
    );
    
    if (!$category) {
        throw new Exception('Invalid category selected.');
    }
    
    // Validate payment method
    $internal_methods = ['cash', 'bank', 'mobile_money', 'card'];
    $external_methods = ['mpesa', 'binance', 'paypal', 'stripe'];
    $all_methods = array_merge($internal_methods, $external_methods);
    
    if (!in_array($payment_method, $all_methods)) {
        throw new Exception('Invalid payment method.');
    }
    
    // For external methods being used manually, they're treated as internal
    $is_manual_external = in_array($payment_method, $external_methods);
    
    // Start transaction
    $db->beginTransaction();
    
    // Get current balance
    $currentBalance = $db->fetchOne(
        "SELECT current_balance FROM wallet_balance WHERE user_id = ?",
        [$user_id]
    );
    
    $balance = $currentBalance['current_balance'];
    
    // Reverse original transaction impact
    if ($originalTransaction['type'] === 'income') {
        $balance -= $originalTransaction['amount'];
    } else {
        $balance += $originalTransaction['amount'];
    }
    
    // Apply new transaction impact
    $newBalance = $type === 'income' ? $balance + $amount : $balance - $amount;
    
    // Check for negative balance on expenses
    if ($type === 'expense' && $newBalance < 0) {
        throw new Exception('Insufficient funds for this transaction.');
    }
    
    // Update transaction
    $updateParams = [
        $type, $amount, $description, $category_id,
        $payment_method, $reference_number, $notes, $newBalance,
        $id, $user_id
    ];
    
    $updateQuery = "UPDATE transactions SET 
                   type = ?, amount = ?, description = ?, category_id = ?, 
                   payment_method = ?, reference_number = ?, notes = ?, balance_after = ?,
                   updated_at = NOW()";
    
    // Add transaction_date to update if provided
    if (!empty($formatted_date)) {
        $updateQuery .= ", transaction_date = ?";
        array_splice($updateParams, -2, 0, $formatted_date);
    }
    
    $updateQuery .= " WHERE id = ? AND user_id = ?";
    
    $db->execute($updateQuery, $updateParams);
    
    // Update wallet balance
    $db->execute(
        "UPDATE wallet_balance SET 
         current_balance = ?,
         total_income = total_income - ? + ?,
         total_expenses = total_expenses - ? + ?,
         updated_at = NOW()
         WHERE user_id = ?",
        [
            $newBalance,
            $originalTransaction['type'] === 'income' ? $originalTransaction['amount'] : 0,
            $type === 'income' ? $amount : 0,
            $originalTransaction['type'] === 'expense' ? $originalTransaction['amount'] : 0,
            $type === 'expense' ? $amount : 0,
            $user_id
        ]
    );
    
    // Update balance_after for subsequent transactions
    // This is complex - we need to recalculate all subsequent transaction balances
    $balanceChange = 0;
    
    // Calculate the net change from the update
    if ($originalTransaction['type'] === 'income') {
        $balanceChange -= $originalTransaction['amount'];
    } else {
        $balanceChange += $originalTransaction['amount'];
    }
    
    if ($type === 'income') {
        $balanceChange += $amount;
    } else {
        $balanceChange -= $amount;
    }
    
    // Update all subsequent transactions
    if ($balanceChange != 0) {
        $transactionDate = !empty($formatted_date) ? $formatted_date : $originalTransaction['transaction_date'];
        
        $db->execute(
            "UPDATE transactions SET 
             balance_after = balance_after + ?
             WHERE user_id = ? AND transaction_date > ? AND id != ?",
            [$balanceChange, $user_id, $transactionDate, $id]
        );
    }
    
    // Update budget tracking if category or amount changed for expenses
    if ($type === 'expense' || $originalTransaction['type'] === 'expense') {
        $currentMonth = date('Y-m');
        
        // Update budget for original category if it changed
        if ($originalTransaction['category_id'] != $category_id && $originalTransaction['type'] === 'expense') {
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
                [$currentMonth, $user_id, $originalTransaction['category_id']]
            );
        }
        
        // Update budget for new category
        if ($type === 'expense') {
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
        }
    }
    
    // Create notification for significant changes
    $significantChange = abs($amount - $originalTransaction['amount']) >= 1000 || 
                        $type !== $originalTransaction['type'] ||
                        $category_id !== $originalTransaction['category_id'];
    
    if ($significantChange) {
        $db->execute(
            "INSERT INTO notifications (user_id, title, message, type) 
             VALUES (?, ?, ?, ?)",
            [
                $user_id,
                'Transaction Updated',
                "Transaction '{$description}' has been updated with significant changes.",
                'info'
            ]
        );
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Transaction updated successfully',
        'new_balance' => $newBalance,
        'transaction_id' => $id
    ]);
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollback();
    }
    
    // Log the error for debugging
    error_log("Update transaction error: " . $e->getMessage() . " | User ID: " . $user_id . " | Transaction ID: " . ($id ?? 'unknown'));
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
