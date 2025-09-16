<?php
// Updated ajax/pay_bill.php with payment methods support
require_once '../config/database.php';
require_once '../config/payment_methods.php';
requireLogin();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $bill_id = intval($input['bill_id'] ?? 0);
    $payment_method = sanitizeInput($input['payment_method'] ?? 'bank');
    $use_external_payment = isset($input['use_external_payment']) && $input['use_external_payment'] === true;
    $payment_amount = floatval($input['payment_amount'] ?? 0); // For partial payments
    
    if ($bill_id <= 0) {
        throw new Exception('Invalid bill ID.');
    }
    
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Get bill details
    $bill = $db->fetchOne(
        "SELECT b.*, c.name as category_name FROM bills b 
         LEFT JOIN categories c ON b.category_id = c.id 
         WHERE b.id = ? AND b.user_id = ? AND b.status IN ('pending', 'overdue', 'partial')",
        [$bill_id, $user_id]
    );
    
    if (!$bill) {
        throw new Exception('Bill not found or already paid.');
    }
    
    // Determine payment amount
    $remaining_balance = $bill['remaining_balance'] ?? $bill['amount'];
    $amount_to_pay = $payment_amount > 0 ? min($payment_amount, $remaining_balance) : $remaining_balance;
    
    // Check if payment method is external
    $external_methods = ['mpesa', 'binance', 'paypal', 'stripe'];
    $is_external_method = in_array($payment_method, $external_methods);
    
    if ($is_external_method && $use_external_payment) {
        // Initialize payment config
        $paymentConfig = new PaymentMethodsConfig($db, $user_id);
        
        if (!$paymentConfig->isPaymentMethodEnabled($payment_method)) {
            throw new Exception("Payment method {$payment_method} is not configured or enabled.");
        }
        
        $db->beginTransaction();
        
        // Create transaction record (pending status)
        $current_balance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
        $balance = $current_balance ? $current_balance['current_balance'] : 0;
        $new_balance = $balance - $amount_to_pay; // This will be updated when payment is confirmed
        
        $transaction_id = $db->query(
            "INSERT INTO transactions (user_id, bill_id, category_id, type, amount, description, payment_method, balance_after, transaction_date, status) 
             VALUES (?, ?, ?, 'expense', ?, ?, ?, ?, NOW(), 'pending')",
            [
                $user_id,
                $bill_id,
                $bill['category_id'],
                $amount_to_pay,
                "Bill payment: " . $bill['name'],
                $payment_method,
                $new_balance
            ]
        );
        
        // Process external payment
        try {
            $payment_result = $paymentConfig->processPayment(
                $payment_method,
                $amount_to_pay,
                'KES', // Should be configurable
                "Bill payment: " . $bill['name'],
                $transaction_id
            );
            
            if ($payment_result['success']) {
                $db->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Payment initiated successfully',
                    'payment_method' => $payment_method,
                    'amount' => $amount_to_pay,
                    'external_data' => $payment_result,
                    'transaction_id' => $transaction_id
                ]);
            } else {
                throw new Exception('Payment processing failed');
            }
            
        } catch (Exception $e) {
            $db->rollback();
            throw $e;
        }
        
    } else {
        // Standard internal payment processing
        // Check current balance
        $balance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
        
        if (!$balance || $balance['current_balance'] < $amount_to_pay) {
            throw new Exception('Insufficient funds to pay this bill.');
        }
        
        $db->beginTransaction();
        
        // Create transaction record
        $newBalance = $balance['current_balance'] - $amount_to_pay;
        
        $db->execute(
            "INSERT INTO transactions (user_id, bill_id, category_id, type, amount, description, payment_method, balance_after, transaction_date) 
             VALUES (?, ?, ?, 'expense', ?, ?, ?, ?, NOW())",
            [
                $user_id,
                $bill_id,
                $bill['category_id'],
                $amount_to_pay,
                "Bill payment: " . $bill['name'],
                $payment_method,
                $newBalance
            ]
        );
        
        // Update bill status and remaining balance
        $new_remaining_balance = $remaining_balance - $amount_to_pay;
        $new_status = $new_remaining_balance <= 0 ? 'paid' : 'partial';
        
        $db->execute(
            "UPDATE bills SET 
             remaining_balance = ?, 
             status = ?, 
             updated_at = NOW() 
             WHERE id = ?",
            [$new_remaining_balance, $new_status, $bill_id]
        );
        
        // Update wallet balance
        $db->execute(
            "UPDATE wallet_balance SET 
             current_balance = ?,
             total_expenses = total_expenses + ?,
             updated_at = NOW()
             WHERE user_id = ?",
            [$newBalance, $amount_to_pay, $user_id]
        );
        
        // Create notification
        $db->execute(
            "INSERT INTO notifications (user_id, title, message, type, related_bill_id) 
             VALUES (?, ?, ?, ?, ?)",
            [
                $user_id,
                'Bill Payment Successful',
                "Payment of " . formatMoney($amount_to_pay) . " for {$bill['name']} has been processed.",
                'success',
                $bill_id
            ]
        );
        
        // If recurring bill, create next instance
        if ($new_status === 'paid' && $bill['is_recurring'] && $bill['recurring_period']) {
            $nextDueDate = date('Y-m-d', strtotime($bill['due_date'] . ' +1 ' . $bill['recurring_period']));
            
            $db->execute(
                "INSERT INTO bills (user_id, category_id, name, amount, remaining_balance, due_date, is_recurring, recurring_period, auto_pay, priority, threshold_warning, notes, preferred_payment_method) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $user_id,
                    $bill['category_id'],
                    $bill['name'],
                    $bill['amount'],
                    $bill['amount'], // New bill starts with full amount
                    $nextDueDate,
                    $bill['is_recurring'],
                    $bill['recurring_period'],
                    $bill['auto_pay'],
                    $bill['priority'],
                    $bill['threshold_warning'],
                    $bill['notes'],
                    $payment_method
                ]
            );
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => $new_status === 'paid' ? 'Bill paid successfully' : 'Partial payment made successfully',
            'new_balance' => $newBalance,
            'amount_paid' => $amount_to_pay,
            'remaining_bill_balance' => $new_remaining_balance,
            'bill_status' => $new_status
        ]);
    }
    
} catch (Exception $e) {
    if ($db->connection->inTransaction()) {
        $db->rollback();
    }
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

<?php
// Updated bills.php - Enhanced Bill Payment Modal (replace existing payment modals)
?>

<!-- Enhanced Bill Payment Modal -->
<div id="payment-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-md">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Pay Bill</h3>
            <button onclick="closePaymentModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div id="payment-details" class="mb-4">
            <!-- Bill details will be populated here --></div>    <form id="payment-form" class="space-y-4">
        <input type="hidden" id="payment-bill-id">        <div>
            <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Payment Amount</label>
            <input type="number" id="payment-amount" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00">
            <p class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?> mt-1">Leave empty to pay full amount</p>
        </div>        <div>
            <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Payment Method</label>
            <select id="bill-payment-method" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" onchange="toggleBillExternalPayment()">
                <option value="bank">🏦 Bank Transfer</option>
                <option value="cash">💵 Cash</option>
                <option value="mobile_money">📱 Mobile Money</option>
                <option value="card">💳 Debit/Credit Card</option>
                <?php
                // Get enabled external payment methods
                if (class_exists('PaymentMethodsConfig')) {
                    $paymentConfig = new PaymentMethodsConfig($db, $user_id);
                    $enabledMethods = $paymentConfig->getEnabledPaymentMethods();
                    $availableMethods = $paymentConfig->getAvailablePaymentMethods();                    foreach ($enabledMethods as $method):
                        $methodData = $availableMethods[$method['method_name']];
                    ?>
                        <option value="<?= $method['method_name'] ?>" data-external="true">
                            <?= $method['method_name'] === 'mpesa' ? '📱' : ($method['method_name'] === 'binance' ? '₿' : '💳') ?> 
                            <?= $methodData['name'] ?>
                        </option>
                    <?php endforeach;
                }
                ?>
            </select>
        </div>        <!-- External Payment Options for Bills -->
        <div id="bill-external-payment-options" class="hidden">
            <div class="flex items-center p-3 <?= $darkMode ? 'bg-blue-900' : 'bg-blue-50' ?> rounded-lg">
                <input type="checkbox" id="bill-use-external-payment" value="1" class="rounded border-gray-300 text-blue-600">
                <label for="bill-use-external-payment" class="ml-2 text-sm <?= $darkMode ? 'text-blue-200' : 'text-blue-800' ?>">
                    Process payment through external gateway
                </label>
            </div>
            <p class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?> mt-2">
                If unchecked, this will be recorded as a manual payment
            </p>
        </div>        <div class="flex space-x-3 pt-4">
            <button type="button" onclick="closePaymentModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                Cancel
            </button>
            <button type="submit" class="flex-1 bg-blue-600 hover:bg-blue-700 text-white py-2 px-4 rounded-lg transition-colors">
                <i class="fas fa-credit-card mr-2"></i><span id="bill-pay-btn-text">Pay Bill</span>
            </button>
        </div>
    </form>
</div>
</div><script>
// Enhanced bill payment functions
let currentBillData = null;function payBillPartial(billId) {
fetch(ajax/get_bill.php?id=${billId})
.then(response => response.json())
.then(data => {
if (data.success) {
const bill = data.bill;
currentBillData = bill;
const remainingBalance = bill.remaining_balance || bill.amount;            document.getElementById('payment-details').innerHTML = `
                <div class="p-4 bg-gray-100 dark:bg-gray-700 rounded-lg">
                    <h4 class="font-semibold text-gray-800 dark:text-white">${bill.name}</h4>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Total Amount: ${currency} ${parseFloat(bill.amount).toFixed(2)}</p>
                    <p class="text-sm text-gray-600 dark:text-gray-400">Remaining: ${currency} ${parseFloat(remainingBalance).toFixed(2)}</p>
                </div>
            `;            document.getElementById('payment-bill-id').value = billId;
            document.getElementById('payment-amount').max = remainingBalance;
            document.getElementById('payment-amount').placeholder = `Max: ${currency} ${parseFloat(remainingBalance).toFixed(2)}`;            // Set preferred payment method if available
            if (bill.preferred_payment_method) {
                document.getElementById('bill-payment-method').value = bill.preferred_payment_method;
                toggleBillExternalPayment();
            }            document.getElementById('payment-modal').classList.remove('hidden');
        } else {
            showNotification(data.message || 'Error loading bill', 'error');
        }
    })
    .catch(error => {
        showNotification('Network error. Please try again.', 'error');
    });
}function payBillFull(billId) {
payBillPartial(billId);
// Set amount to full remaining balance
setTimeout(() => {
const remainingBalance = currentBillData.remaining_balance || currentBillData.amount;
document.getElementById('payment-amount').value = remainingBalance;
}, 100);
}function toggleBillExternalPayment() {
const select = document.getElementById('bill-payment-method');
const options = document.getElementById('bill-external-payment-options');
const btnText = document.getElementById('bill-pay-btn-text');
const selectedOption = select.options[select.selectedIndex];if (selectedOption.dataset.external === 'true') {
    options.classList.remove('hidden');
    const useExternal = document.getElementById('bill-use-external-payment');
    if (useExternal.checked) {
        btnText.textContent = 'Process Payment';
    } else {
        btnText.textContent = 'Record Payment';
    }
} else {
    options.classList.add('hidden');
    btnText.textContent = 'Pay Bill';
}
}// Enhanced payment form submission
document.getElementById('payment-form').addEventListener('submit', async (e) => {
e.preventDefault();const billId = document.getElementById('payment-bill-id').value;
const paymentAmount = parseFloat(document.getElementById('payment-amount').value) || 0;
const paymentMethod = document.getElementById('bill-payment-method').value;
const useExternal = document.getElementById('bill-use-external-payment').checked;
const externalMethods = ['mpesa', 'binance', 'paypal', 'stripe'];if (externalMethods.includes(paymentMethod) && useExternal) {
    // Show processing modal
    document.getElementById('payment-processing-modal').classList.remove('hidden');
    updateProcessingModal(paymentMethod);
}const paymentData = {
    bill_id: parseInt(billId),
    payment_method: paymentMethod,
    use_external_payment: useExternal,
    payment_amount: paymentAmount
};try {
    const response = await fetch('ajax/pay_bill.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(paymentData)
    });    const result = await response.json();    if (result.success) {
        if (externalMethods.includes(paymentMethod) && useExternal) {
            handleBillExternalPaymentResponse(result);
        } else {
            closePaymentModal();
            showNotification(result.message, 'success');
            setTimeout(() => location.reload(), 1000);
        }
    } else {
        closeProcessingModal();
        showNotification(result.message || 'Error processing payment', 'error');
    }
} catch (error) {
    closeProcessingModal();
    showNotification('Network error. Please try again.', 'error');
}
});function handleBillExternalPaymentResponse(result) {
const paymentMethod = result.payment_method;
const title = document.getElementById('processing-title');
const message = document.getElementById('processing-message');
const icon = document.getElementById('processing-icon');
const instructions = document.getElementById('payment-instructions');
const actions = document.getElementById('payment-actions');icon.className = 'fas fa-check text-green-600 text-2xl';switch (paymentMethod) {
    case 'mpesa':
        title.textContent = 'M-Pesa Bill Payment';
        message.textContent = 'Check your phone to complete the bill payment.';
        instructions.innerHTML = `
            <div class="p-4 bg-green-50 dark:bg-green-900 rounded-lg mb-4">
                <div class="flex items-center">
                    <i class="fas fa-mobile-alt text-green-600 text-2xl mr-3"></i>
                    <div>
                        <p class="font-medium text-green-800 dark:text-green-200">STK Push Sent</p>
                        <p class="text-sm text-green-600 dark:text-green-300">Amount: ${result.external_data.currency || 'KES'} ${result.amount}</p>
                    </div>
                </div>
            </div>
        `;
        break;    case 'binance':
        if (result.external_data.checkout_url) {
            title.textContent = 'Binance Bill Payment';
            message.textContent = 'Redirecting to Binance Pay for bill payment.';
            setTimeout(() => {
                window.open(result.external_data.checkout_url, '_blank');
                closeProcessingModal();
            }, 2000);
        }
        break;
}instructions.classList.remove('hidden');
actions.classList.remove('hidden');
}// Add checkbox event listener for bill payments
document.getElementById('bill-use-external-payment')?.addEventListener('change', function() {
const btnText = document.getElementById('bill-pay-btn-text');
if (this.checked) {
btnText.textContent = 'Process Payment';
} else {
btnText.textContent = 'Record Payment';
}
});
</script>