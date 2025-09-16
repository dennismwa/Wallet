<?php
// Enhanced ajax/payment_callback.php with improved transaction handling
require_once '../config/database.php';
require_once '../config/payment_methods.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $method = sanitizeInput($_GET['method'] ?? '');
    $user_id = intval($_GET['user_id'] ?? 0);
    
    if (!$method || !$user_id) {
        throw new Exception('Invalid callback parameters');
    }
    
    $paymentConfig = new PaymentMethodsConfig($db, $user_id);
    
    // Get raw POST data
    $raw_data = file_get_contents('php://input');
    $callback_data = json_decode($raw_data, true) ?: $_POST;
    
    // Log the callback for debugging
    error_log("Payment callback received - Method: $method, User: $user_id, Data: " . $raw_data);
    
    switch ($method) {
        case 'mpesa':
            handleMpesaCallback($db, $user_id, $callback_data);
            break;
        case 'binance':
            handleBinanceCallback($db, $user_id, $callback_data);
            break;
        case 'paypal':
            handlePayPalCallback($db, $user_id, $callback_data);
            break;
        case 'stripe':
            handleStripeCallback($db, $user_id, $callback_data);
            break;
        default:
            throw new Exception('Unsupported payment method');
    }
    
    echo json_encode(['success' => true, 'message' => 'Callback processed successfully']);
    
} catch (Exception $e) {
    error_log("Payment callback error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function handleMpesaCallback($db, $user_id, $data) {
    // M-Pesa STK Push callback
    if (isset($data['Body']['stkCallback'])) {
        $callback = $data['Body']['stkCallback'];
        $checkout_request_id = $callback['CheckoutRequestID'];
        $result_code = $callback['ResultCode'];
        
        $status = $result_code == 0 ? 'completed' : 'failed';
        $failure_reason = $result_code != 0 ? ($callback['ResultDesc'] ?? 'Payment failed') : null;
        
        // Find the pending transaction
        $pendingTransaction = $db->fetchOne(
            "SELECT * FROM transactions 
             WHERE user_id = ? AND payment_method = 'mpesa' AND status = 'pending' 
             AND reference_number = ? OR description LIKE '%' ? '%'
             ORDER BY created_at DESC LIMIT 1",
            [$user_id, $checkout_request_id, $checkout_request_id]
        );
        
        if (!$pendingTransaction) {
            // Create payment transaction record for tracking
            $db->execute(
                "INSERT INTO payment_transactions 
                 (user_id, payment_method, external_transaction_id, amount, currency, status, gateway_response) 
                 VALUES (?, 'mpesa', ?, 0, 'KES', ?, ?)",
                [$user_id, $checkout_request_id, $status, json_encode($data)]
            );
            return;
        }
        
        $db->beginTransaction();
        
        try {
            if ($status === 'completed') {
                // Extract transaction details from callback
                $amount = 0;
                $mpesa_receipt = '';
                $phone_number = '';
                
                if (isset($callback['CallbackMetadata']['Item'])) {
                    foreach ($callback['CallbackMetadata']['Item'] as $item) {
                        switch ($item['Name']) {
                            case 'Amount':
                                $amount = $item['Value'];
                                break;
                            case 'MpesaReceiptNumber':
                                $mpesa_receipt = $item['Value'];
                                break;
                            case 'PhoneNumber':
                                $phone_number = $item['Value'];
                                break;
                        }
                    }
                }
                
                // Verify the amount matches
                if (abs($amount - $pendingTransaction['amount']) > 0.01) {
                    throw new Exception('Amount mismatch in M-Pesa callback');
                }
                
                // Complete the transaction
                processSuccessfulPayment($db, $user_id, $pendingTransaction, [
                    'mpesa_receipt' => $mpesa_receipt,
                    'phone_number' => $phone_number,
                    'checkout_request_id' => $checkout_request_id
                ]);
                
            } else {
                // Mark transaction as failed
                $db->execute(
                    "UPDATE transactions SET status = 'failed', notes = ?, updated_at = NOW() 
                     WHERE id = ?",
                    [$failure_reason, $pendingTransaction['id']]
                );
                
                // Create failure notification
                $db->execute(
                    "INSERT INTO notifications (user_id, title, message, type) 
                     VALUES (?, ?, ?, ?)",
                    [
                        $user_id,
                        'M-Pesa Payment Failed',
                        "Payment of " . formatMoney($pendingTransaction['amount'], 'KES') . " via M-Pesa failed: " . $failure_reason,
                        'error'
                    ]
                );
            }
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }
}

function handleBinanceCallback($db, $user_id, $data) {
    // Binance Pay callback
    if (isset($data['bizType']) && $data['bizType'] === 'PAY') {
        $prepay_id = $data['data']['prepayId'];
        $status = $data['bizStatus'] === 'PAY_SUCCESS' ? 'completed' : 'failed';
        
        // Find the pending transaction
        $pendingTransaction = $db->fetchOne(
            "SELECT * FROM transactions 
             WHERE user_id = ? AND payment_method = 'binance' AND status = 'pending' 
             AND reference_number = ? OR description LIKE '%' ? '%'
             ORDER BY created_at DESC LIMIT 1",
            [$user_id, $prepay_id, $prepay_id]
        );
        
        if (!$pendingTransaction) {
            return; // Transaction not found
        }
        
        $db->beginTransaction();
        
        try {
            if ($status === 'completed') {
                processSuccessfulPayment($db, $user_id, $pendingTransaction, [
                    'binance_order_id' => $data['data']['orderId'] ?? '',
                    'prepay_id' => $prepay_id
                ]);
            } else {
                // Mark transaction as failed
                $failure_reason = $data['data']['failReason'] ?? 'Payment failed';
                $db->execute(
                    "UPDATE transactions SET status = 'failed', notes = ?, updated_at = NOW() 
                     WHERE id = ?",
                    [$failure_reason, $pendingTransaction['id']]
                );
                
                // Create failure notification
                $db->execute(
                    "INSERT INTO notifications (user_id, title, message, type) 
                     VALUES (?, ?, ?, ?)",
                    [
                        $user_id,
                        'Binance Pay Payment Failed',
                        "Payment of " . formatMoney($pendingTransaction['amount'], 'KES') . " via Binance Pay failed: " . $failure_reason,
                        'error'
                    ]
                );
            }
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }
}

function handlePayPalCallback($db, $user_id, $data) {
    // PayPal webhook handling
    if (isset($data['event_type']) && $data['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
        $payment_id = $data['resource']['id'];
        $status = 'completed';
        
        // Find the pending transaction
        $pendingTransaction = $db->fetchOne(
            "SELECT * FROM transactions 
             WHERE user_id = ? AND payment_method = 'paypal' AND status = 'pending' 
             AND reference_number = ? OR description LIKE '%' ? '%'
             ORDER BY created_at DESC LIMIT 1",
            [$user_id, $payment_id, $payment_id]
        );
        
        if ($pendingTransaction) {
            processSuccessfulPayment($db, $user_id, $pendingTransaction, [
                'paypal_payment_id' => $payment_id,
                'paypal_payer_id' => $data['resource']['payer']['payer_id'] ?? ''
            ]);
        }
    }
}

function handleStripeCallback($db, $user_id, $data) {
    // Stripe webhook handling
    if (isset($data['type']) && $data['type'] === 'payment_intent.succeeded') {
        $payment_id = $data['data']['object']['id'];
        $status = 'completed';
        
        // Find the pending transaction
        $pendingTransaction = $db->fetchOne(
            "SELECT * FROM transactions 
             WHERE user_id = ? AND payment_method = 'stripe' AND status = 'pending' 
             AND reference_number = ? OR description LIKE '%' ? '%'
             ORDER BY created_at DESC LIMIT 1",
            [$user_id, $payment_id, $payment_id]
        );
        
        if ($pendingTransaction) {
            processSuccessfulPayment($db, $user_id, $pendingTransaction, [
                'stripe_payment_intent_id' => $payment_id,
                'stripe_charge_id' => $data['data']['object']['latest_charge'] ?? ''
            ]);
        }
    }
}

function processSuccessfulPayment($db, $user_id, $transaction, $gateway_data) {
    $db->beginTransaction();
    
    try {
        // Update transaction status to completed
        $db->execute(
            "UPDATE transactions SET status = 'completed', updated_at = NOW() WHERE id = ?",
            [$transaction['id']]
        );
        
        // Get current wallet balance
        $currentBalance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
        $balance = $currentBalance ? $currentBalance['current_balance'] : 0;
        
        // Calculate the balance change needed
        if ($transaction['type'] === 'income') {
            $newBalance = $balance + $transaction['amount'];
            
            // Update wallet balance
            $db->execute(
                "UPDATE wallet_balance SET 
                 current_balance = ?, 
                 total_income = total_income + ?, 
                 updated_at = NOW() 
                 WHERE user_id = ?",
                [$newBalance, $transaction['amount'], $user_id]
            );
            
        } else { // expense
            $newBalance = $balance - $transaction['amount'];
            
            // Check if we have sufficient funds
            if ($newBalance < 0) {
                throw new Exception('Insufficient wallet balance to complete payment');
            }
            
            // Update wallet balance
            $db->execute(
                "UPDATE wallet_balance SET 
                 current_balance = ?, 
                 total_expenses = total_expenses + ?, 
                 updated_at = NOW() 
                 WHERE user_id = ?",
                [$newBalance, $transaction['amount'], $user_id]
            );
        }
        
        // Update the transaction's balance_after
        $db->execute(
            "UPDATE transactions SET balance_after = ? WHERE id = ?",
            [$newBalance, $transaction['id']]
        );
        
        // Update subsequent transactions' balance_after
        $db->execute(
            "UPDATE transactions SET balance_after = balance_after + ? 
             WHERE user_id = ? AND transaction_date > ? AND id != ?",
            [
                $transaction['type'] === 'income' ? $transaction['amount'] : -$transaction['amount'],
                $user_id,
                $transaction['transaction_date'],
                $transaction['id']
            ]
        );
        
        // Update budget tracking for expenses
        if ($transaction['type'] === 'expense') {
            $currentMonth = date('Y-m', strtotime($transaction['transaction_date']));
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
                [$currentMonth, $user_id, $transaction['category_id']]
            );
        }
        
        // Create payment record for tracking
        $external_transaction_id = $gateway_data['mpesa_receipt'] ?? 
                                   $gateway_data['binance_order_id'] ?? 
                                   $gateway_data['paypal_payment_id'] ?? 
                                   $gateway_data['stripe_payment_intent_id'] ?? 
                                   'unknown';
        
        $db->execute(
            "INSERT INTO payment_transactions 
             (user_id, transaction_id, payment_method, external_transaction_id, amount, currency, status, gateway_response) 
             VALUES (?, ?, ?, ?, ?, 'KES', 'completed', ?)",
            [
                $user_id, 
                $transaction['id'], 
                $transaction['payment_method'], 
                $external_transaction_id,
                $transaction['amount'], 
                json_encode($gateway_data)
            ]
        );
        
        // Create success notification
        $payment_method_name = ucfirst($transaction['payment_method']);
        switch ($transaction['payment_method']) {
            case 'mpesa':
                $payment_method_name = 'M-Pesa';
                break;
            case 'binance':
                $payment_method_name = 'Binance Pay';
                break;
            case 'paypal':
                $payment_method_name = 'PayPal';
                break;
            case 'stripe':
                $payment_method_name = 'Stripe';
                break;
        }
        
        $db->execute(
            "INSERT INTO notifications (user_id, title, message, type) 
             VALUES (?, ?, ?, ?)",
            [
                $user_id,
                'Payment Successful',
                "Payment of " . formatMoney($transaction['amount'], 'KES') . " via {$payment_method_name} has been completed successfully.",
                'success'
            ]
        );
        
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollback();
        
        // Log the error
        error_log("Payment processing error: " . $e->getMessage() . " | User: $user_id | Transaction: " . $transaction['id']);
        
        // Mark transaction as failed
        $db->execute(
            "UPDATE transactions SET status = 'failed', notes = ?, updated_at = NOW() WHERE id = ?",
            ['Payment processing failed: ' . $e->getMessage(), $transaction['id']]
        );
        
        // Create error notification
        $db->execute(
            "INSERT INTO notifications (user_id, title, message, type) 
             VALUES (?, ?, ?, ?)",
            [
                $user_id,
                'Payment Processing Error',
                "There was an error processing your payment. Please contact support if you were charged.",
                'error'
            ]
        );
        
        throw $e;
    }
}

// Webhook verification functions (implement based on payment provider requirements)
function verifyWebhookSignature($method, $raw_data, $signature) {
    // This is a simplified version - implement proper verification for each payment method
    switch ($method) {
        case 'mpesa':
            // M-Pesa doesn't typically use webhook signatures, but you should validate IP whitelist
            return true;
            
        case 'binance':
            // Implement Binance Pay signature verification
            // $secret = get_binance_webhook_secret();
            // $computed_signature = hash_hmac('sha512', $raw_data, $secret);
            // return hash_equals($signature, $computed_signature);
            return true;
            
        case 'paypal':
            // Implement PayPal webhook signature verification
            // Use PayPal's webhook verification API
            return true;
            
        case 'stripe':
            // Implement Stripe webhook signature verification
            // $endpoint_secret = get_stripe_endpoint_secret();
            // $computed_signature = hash_hmac('sha256', $raw_data, $endpoint_secret);
            // return hash_equals($signature, 'sha256=' . $computed_signature);
            return true;
            
        default:
            return false;
    }
}

// Webhook handler for direct webhook calls (separate from callback)
function handleWebhookRequest() {
    $db = Database::getInstance();
    $method = sanitizeInput($_GET['method'] ?? '');
    $user_id = intval($_GET['user_id'] ?? 0);
    
    if (!$method || !$user_id) {
        throw new Exception('Invalid webhook parameters');
    }
    
    // Get raw POST data
    $raw_data = file_get_contents('php://input');
    $webhook_data = json_decode($raw_data, true);
    
    // Log webhook for debugging
    $db->execute(
        "INSERT INTO webhook_logs (user_id, payment_method, webhook_data, created_at) VALUES (?, ?, ?, NOW())",
        [$user_id, $method, $raw_data]
    );
    
    // Verify webhook signature (implement based on payment provider)
    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if (!verifyWebhookSignature($method, $raw_data, $signature)) {
        throw new Exception('Invalid webhook signature');
    }
    
    // Process webhook based on method
    switch ($method) {
        case 'mpesa':
            // M-Pesa confirmation webhook
            if (isset($webhook_data['TransactionType'])) {
                processMpesaConfirmation($db, $user_id, $webhook_data);
            }
            break;
            
        case 'binance':
            // Binance webhook
            if (isset($webhook_data['bizType'])) {
                handleBinanceCallback($db, $user_id, $webhook_data);
            }
            break;
            
        case 'paypal':
            // PayPal webhook
            handlePayPalCallback($db, $user_id, $webhook_data);
            break;
            
        case 'stripe':
            // Stripe webhook
            handleStripeCallback($db, $user_id, $webhook_data);
            break;
            
        default:
            throw new Exception('Unsupported webhook method');
    }
}

function processMpesaConfirmation($db, $user_id, $data) {
    // Process M-Pesa confirmation for direct payments (not STK Push)
    $transaction_id = $data['TransID'];
    $amount = $data['TransAmount'];
    $phone = $data['MSISDN'];
    $account_ref = $data['BillRefNumber'] ?? '';
    
    // Check if we have a pending transaction for this reference
    $pendingTransaction = null;
    if (!empty($account_ref)) {
        $pendingTransaction = $db->fetchOne(
            "SELECT * FROM transactions 
             WHERE user_id = ? AND payment_method = 'mpesa' AND status = 'pending' 
             AND (reference_number = ? OR description LIKE '%' ? '%')
             ORDER BY created_at DESC LIMIT 1",
            [$user_id, $account_ref, $account_ref]
        );
    }
    
    if ($pendingTransaction) {
        // Complete existing transaction
        if (abs($amount - $pendingTransaction['amount']) <= 0.01) {
            processSuccessfulPayment($db, $user_id, $pendingTransaction, [
                'mpesa_receipt' => $transaction_id,
                'phone_number' => $phone,
                'account_reference' => $account_ref
            ]);
        }
    } else {
        // Create new transaction for unsolicited payment
        $db->beginTransaction();
        
        try {
            // Get current balance
            $currentBalance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
            $balance = $currentBalance ? $currentBalance['current_balance'] : 0;
            $newBalance = $balance + $amount;
            
            // Create transaction
            $db->execute(
                "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after, reference_number, status) 
                 VALUES (?, 1, 'income', ?, ?, 'mpesa', ?, ?, 'completed')",
                [
                    $user_id,
                    $amount,
                    "M-Pesa payment from {$phone}",
                    $newBalance,
                    $transaction_id
                ]
            );
            
            $transactionId = $db->lastInsertId();
            
            // Update wallet balance
            $db->execute(
                "INSERT INTO wallet_balance (user_id, current_balance, total_income) 
                 VALUES (?, ?, ?) 
                 ON DUPLICATE KEY UPDATE 
                 current_balance = current_balance + ?, 
                 total_income = total_income + ?",
                [$user_id, $newBalance, $amount, $amount, $amount]
            );
            
            // Create notification
            $db->execute(
                "INSERT INTO notifications (user_id, title, message, type) 
                 VALUES (?, ?, ?, ?)",
                [
                    $user_id,
                    'M-Pesa Payment Received',
                    "Received " . formatMoney($amount, 'KES') . " via M-Pesa from {$phone}",
                    'success'
                ]
            );
            
            // Log payment transaction
            $db->execute(
                "INSERT INTO payment_transactions 
                 (user_id, transaction_id, payment_method, external_transaction_id, amount, currency, status, gateway_response) 
                 VALUES (?, ?, 'mpesa', ?, ?, 'KES', 'completed', ?)",
                [$user_id, $transactionId, $transaction_id, $amount, json_encode($data)]
            );
            
            $db->commit();
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
    }
}

// Additional utility functions for payment processing
function getPaymentMethodConfig($db, $user_id, $method_name) {
    $config = $db->fetchOne(
        "SELECT configuration FROM payment_methods WHERE user_id = ? AND method_name = ? AND is_enabled = 1",
        [$user_id, $method_name]
    );
    
    return $config ? json_decode($config['configuration'], true) : null;
}

function formatMoney($amount, $currency = 'KES') {
    return $currency . ' ' . number_format((float)$amount, 2);
}

function logPaymentEvent($db, $user_id, $event_type, $payment_method, $data) {
    $db->execute(
        "INSERT INTO payment_logs (user_id, event_type, payment_method, event_data, created_at) 
         VALUES (?, ?, ?, ?, NOW())",
        [$user_id, $event_type, $payment_method, json_encode($data)]
    );
}

// Rate limiting for webhook calls
function checkWebhookRateLimit($db, $user_id, $method) {
    $recent_calls = $db->fetchOne(
        "SELECT COUNT(*) as count FROM webhook_logs 
         WHERE user_id = ? AND payment_method = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
        [$user_id, $method]
    );
    
    return $recent_calls['count'] < 10; // Max 10 calls per minute
}
?>
