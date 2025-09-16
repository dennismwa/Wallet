<?php
// ajax/payment_callback.php
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
    
    // Log the callback
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
        
        // Update payment transaction
        $db->execute(
            "UPDATE payment_transactions SET 
             status = ?, 
             gateway_response = ?, 
             updated_at = NOW() 
             WHERE user_id = ? AND external_transaction_id = ?",
            [$status, json_encode($data), $user_id, $checkout_request_id]
        );
        
        if ($status === 'completed') {
            // Get payment details
            $payment = $db->fetchOne(
                "SELECT * FROM payment_transactions WHERE user_id = ? AND external_transaction_id = ?",
                [$user_id, $checkout_request_id]
            );
            
            if ($payment) {
                processSuccessfulPayment($db, $user_id, $payment, $callback);
            }
        }
    }
}

function handleBinanceCallback($db, $user_id, $data) {
    // Binance Pay callback
    if (isset($data['bizType']) && $data['bizType'] === 'PAY') {
        $prepay_id = $data['data']['prepayId'];
        $status = $data['bizStatus'] === 'PAY_SUCCESS' ? 'completed' : 'failed';
        
        // Update payment transaction
        $db->execute(
            "UPDATE payment_transactions SET 
             status = ?, 
             gateway_response = ?, 
             updated_at = NOW() 
             WHERE user_id = ? AND external_transaction_id = ?",
            [$status, json_encode($data), $user_id, $prepay_id]
        );
        
        if ($status === 'completed') {
            $payment = $db->fetchOne(
                "SELECT * FROM payment_transactions WHERE user_id = ? AND external_transaction_id = ?",
                [$user_id, $prepay_id]
            );
            
            if ($payment) {
                processSuccessfulPayment($db, $user_id, $payment, $data['data']);
            }
        }
    }
}

function handlePayPalCallback($db, $user_id, $data) {
    // PayPal webhook handling
    if (isset($data['event_type']) && $data['event_type'] === 'PAYMENT.CAPTURE.COMPLETED') {
        $payment_id = $data['resource']['id'];
        $status = 'completed';
        
        // Update payment transaction
        $db->execute(
            "UPDATE payment_transactions SET 
             status = ?, 
             gateway_response = ?, 
             updated_at = NOW() 
             WHERE user_id = ? AND external_transaction_id = ?",
            [$status, json_encode($data), $user_id, $payment_id]
        );
        
        $payment = $db->fetchOne(
            "SELECT * FROM payment_transactions WHERE user_id = ? AND external_transaction_id = ?",
            [$user_id, $payment_id]
        );
        
        if ($payment) {
            processSuccessfulPayment($db, $user_id, $payment, $data['resource']);
        }
    }
}

function handleStripeCallback($db, $user_id, $data) {
    // Stripe webhook handling
    if (isset($data['type']) && $data['type'] === 'payment_intent.succeeded') {
        $payment_id = $data['data']['object']['id'];
        $status = 'completed';
        
        // Update payment transaction
        $db->execute(
            "UPDATE payment_transactions SET 
             status = ?, 
             gateway_response = ?, 
             updated_at = NOW() 
             WHERE user_id = ? AND external_transaction_id = ?",
            [$status, json_encode($data), $user_id, $payment_id]
        );
        
        $payment = $db->fetchOne(
            "SELECT * FROM payment_transactions WHERE user_id = ? AND external_transaction_id = ?",
            [$user_id, $payment_id]
        );
        
        if ($payment) {
            processSuccessfulPayment($db, $user_id, $payment, $data['data']['object']);
        }
    }
}

function processSuccessfulPayment($db, $user_id, $payment, $gateway_data) {
    $db->beginTransaction();
    
    try {
        // Create wallet transaction if not exists
        if (!$payment['transaction_id']) {
            // Get current balance
            $balance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
            $current_balance = $balance ? $balance['current_balance'] : 0;
            $new_balance = $current_balance + $payment['amount'];
            
            // Create transaction record
            $db->execute(
                "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after, reference_number) 
                 VALUES (?, ?, 'income', ?, ?, ?, ?, ?)",
                [
                    $user_id, 
                    1, // Default income category
                    $payment['amount'], 
                    "Payment via " . ucfirst($payment['payment_method']),
                    $payment['payment_method'],
                    $new_balance,
                    $payment['external_transaction_id']
                ]
            );
            
            $transaction_id = $db->lastInsertId();
            
            // Update wallet balance
            $db->execute(
                "INSERT INTO wallet_balance (user_id, current_balance, total_income) 
                 VALUES (?, ?, ?) 
                 ON DUPLICATE KEY UPDATE 
                 current_balance = current_balance + ?, 
                 total_income = total_income + ?",
                [$user_id, $new_balance, $payment['amount'], $payment['amount'], $payment['amount']]
            );
            
            // Update payment transaction with wallet transaction ID
            $db->execute(
                "UPDATE payment_transactions SET transaction_id = ? WHERE id = ?",
                [$transaction_id, $payment['id']]
            );
            
            // Create notification
            $db->execute(
                "INSERT INTO notifications (user_id, title, message, type) 
                 VALUES (?, ?, ?, ?)",
                [
                    $user_id,
                    'Payment Received',
                    "Payment of " . formatMoney($payment['amount'], $payment['currency']) . " via " . ucfirst($payment['payment_method']) . " has been processed successfully.",
                    'success'
                ]
            );
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}
?>

<?php
// ajax/payment_webhook.php
require_once '../config/database.php';
require_once '../config/payment_methods.php';

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $method = sanitizeInput($_GET['method'] ?? '');
    $user_id = intval($_GET['user_id'] ?? 0);
    
    if (!$method || !$user_id) {
        throw new Exception('Invalid webhook parameters');
    }
    
    // Get raw POST data
    $raw_data = file_get_contents('php://input');
    $webhook_data = json_decode($raw_data, true);
    
    // Log webhook
    $db->execute(
        "INSERT INTO webhook_logs (user_id, payment_method, webhook_data) VALUES (?, ?, ?)",
        [$user_id, $method, json_encode($webhook_data)]
    );
    
    // Verify webhook signature (implement based on payment provider)
    if (!verifyWebhookSignature($method, $raw_data, $_SERVER['HTTP_X_SIGNATURE'] ?? '')) {
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
                processBinanceWebhook($db, $user_id, $webhook_data);
            }
            break;
            
        default:
            // Handle other payment methods
            break;
    }
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    error_log("Webhook error: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function verifyWebhookSignature($method, $raw_data, $signature) {
    // Implement signature verification based on payment method
    // This is a simplified version - implement proper verification
    return true;
}

function processMpesaConfirmation($db, $user_id, $data) {
    // Process M-Pesa confirmation
    $transaction_id = $data['TransID'];
    $amount = $data['TransAmount'];
    $phone = $data['MSISDN'];
    
    // Check if transaction already exists
    $existing = $db->fetchOne(
        "SELECT id FROM payment_transactions WHERE external_transaction_id = ?",
        [$transaction_id]
    );
    
    if (!$existing) {
        // Create new payment transaction
        $db->execute(
            "INSERT INTO payment_transactions (user_id, payment_method, external_transaction_id, amount, currency, status, gateway_response) 
             VALUES (?, 'mpesa', ?, ?, 'KES', 'completed', ?)",
            [$user_id, $transaction_id, $amount, json_encode($data)]
        );
        
        // Process the payment
        $payment = $db->fetchOne(
            "SELECT * FROM payment_transactions WHERE external_transaction_id = ?",
            [$transaction_id]
        );
        
        processSuccessfulPayment($db, $user_id, $payment, $data);
    }
}

function processBinanceWebhook($db, $user_id, $data) {
    // Process Binance webhook
    // Similar to M-Pesa confirmation processing
}
?>