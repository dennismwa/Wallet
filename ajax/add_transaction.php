<?php
// Updated ajax/add_transaction.php with payment method support
require_once '../config/database.php';
require_once '../config/payment_methods.php';
requireLogin();

header('Content-Type: application/json');

try {
    $db = Database::getInstance();
    $user_id = $_SESSION['user_id'];
    
    // Validate input
    $type = sanitizeInput($type) ?? '';
amount=floatval(amount = floatval(amount=floatval(_POST['amount'] ?? 0);
description=sanitizeInput(description = sanitizeInput(
description=sanitizeInput(_POST['description'] ?? '');
    categoryid=intval(category_id = intval(
categoryid=intval(_POST['category_id'] ?? 0);
    paymentmethod=sanitizeInput(payment_method = sanitizeInput(
paymentmethod=sanitizeInput(_POST['payment_method'] ?? 'cash');
    useexternalpayment=isset(use_external_payment = isset(
useexternalpayment=isset(_POST['use_external_payment']) && $_POST['use_external_payment'] === '1';

if (empty($type) || $amount <= 0 || empty($description) || $category_id <= 0) {
    throw new Exception('Please fill in all required fields.');
}

if (!in_array($type, ['income', 'expense'])) {
    throw new Exception('Invalid transaction type.');
}

// Check if payment method is external (mpesa, binance, etc.)
$external_methods = ['mpesa', 'binance', 'paypal', 'stripe'];
$is_external_method = in_array($payment_method, $external_methods);

if ($is_external_method && $use_external_payment) {
    // Initialize payment config
    $paymentConfig = new PaymentMethodsConfig($db, $user_id);
    
    if (!$paymentConfig->isPaymentMethodEnabled($payment_method)) {
        throw new Exception("Payment method {$payment_method} is not enabled. Please configure it in settings.");
    }
    
    // For external payments, create a pending transaction first
    $db->beginTransaction();
    
    // Get current balance for balance_after calculation
    $currentBalance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
    $balance = $currentBalance ? $currentBalance['current_balance'] : 0;
    
    // For external payments, we don't update balance until payment is confirmed
    $balance_after = $type === 'income' ? $balance + $amount : $balance - $amount;
    
    // Create pending transaction
    $transactionId = $db->query(
        "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after, transaction_date, status) 
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), 'pending')",
        [$user_id, $category_id, $type, $amount, $description, $payment_method, $balance_after]
    );
    
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
    if (!in_array($payment_method, ['cash', 'bank', 'mobile_money', 'card'])) {
        throw new Exception('Invalid payment method for internal transactions.');
    }
    
    // Verify category belongs to user or is default
    $category = $db->fetchOne(
        "SELECT * FROM categories WHERE id = ? AND (user_id = ? OR is_default = 1)",
        [$category_id, $user_id]
    );
    
    if (!$category) {
        throw new Exception('Invalid category selected.');
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
    $transactionId = $db->query(
        "INSERT INTO transactions (user_id, category_id, type, amount, description, payment_method, balance_after, transaction_date) 
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$user_id, $category_id, $type, $amount, $description, $payment_method, $newBalance]
    );
    
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
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Transaction added successfully',
        'new_balance' => $newBalance,
        'transaction_id' => $transactionId
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
// Updated dashboard.php Quick Add Modal section (replace existing modal)
?>
<!-- Enhanced Quick Add Modal with Payment Methods -->
<div id="quick-add-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-lg">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Quick Add Transaction</h3>
            <button onclick="closeQuickAdd()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <form id="quick-add-form" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Type</label>
                    <select name="type" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                        <option value="">Select Type</option>
                        <option value="income">Income</option>
                        <option value="expense">Expense</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Amount</label>
                    <input type="number" name="amount" step="0.01" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="0.00" required>
                </div>
            </div>
        <div>
            <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Description</label>
            <input type="text" name="description" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" placeholder="Transaction description" required>
        </div>
        
        <div>
            <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Category</label>
            <select name="category_id" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required>
                <option value="">Select Category</option>
                <?php
                $categories = $db->fetchAll("SELECT * FROM categories WHERE user_id = ? OR is_default = 1 ORDER BY name", [$user_id]);
                foreach ($categories as $category):
                ?>
                    <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Payment Method</label>
            <select name="payment_method" id="payment-method-select" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2" required onchange="toggleExternalPayment()">
                <option value="cash">💵 Cash</option>
                <option value="bank">🏦 Bank Transfer</option>
                <option value="mobile_money">📱 Mobile Money</option>
                <option value="card">💳 Debit/Credit Card</option>
                <?php
                // Get enabled external payment methods
                $paymentConfig = new PaymentMethodsConfig($db, $user_id);
                $enabledMethods = $paymentConfig->getEnabledPaymentMethods();
                $availableMethods = $paymentConfig->getAvailablePaymentMethods();
                
                foreach ($enabledMethods as $method):
                    $methodData = $availableMethods[$method['method_name']];
                ?>
                    <option value="<?= $method['method_name'] ?>" data-external="true">
                        <?= $method['method_name'] === 'mpesa' ? '📱' : ($method['method_name'] === 'binance' ? '₿' : '💳') ?> 
                        <?= $methodData['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <!-- External Payment Options -->
        <div id="external-payment-options" class="hidden">
            <div class="flex items-center p-3 <?= $darkMode ? 'bg-blue-900' : 'bg-blue-50' ?> rounded-lg">
                <input type="checkbox" name="use_external_payment" id="use-external-payment" value="1" class="rounded border-gray-300 text-blue-600">
                <label for="use-external-payment" class="ml-2 text-sm <?= $darkMode ? 'text-blue-200' : 'text-blue-800' ?>">
                    Process payment through external gateway
                </label>
            </div>
            <p class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?> mt-2">
                If unchecked, this will be recorded as a manual transaction
            </p>
        </div>
        
        <div class="flex space-x-3 pt-4">
            <button type="button" onclick="closeQuickAdd()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                Cancel
            </button>
            <button type="submit" class="flex-1 gradient-primary text-white py-2 px-4 rounded-lg hover:opacity-90 transition-opacity">
                <span id="submit-btn-text">Add Transaction</span>
            </button>
        </div>
    </form>
</div>
</div>
<!-- Payment Processing Modal -->
<div id="payment-processing-modal" class="fixed inset-0 bg-black bg-opacity-50 z-60 hidden flex items-center justify-center p-4">
    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-md text-center">
        <div class="mb-4">
            <div class="w-16 h-16 mx-auto mb-4 rounded-full flex items-center justify-center" id="processing-icon-container">
                <i class="fas fa-spinner fa-spin text-blue-600 text-2xl" id="processing-icon"></i>
            </div>
            <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>" id="processing-title">Processing Payment</h3>
            <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>" id="processing-message">Please wait while we process your payment...</p>
        </div>
    <div id="payment-instructions" class="hidden">
        <!-- Payment-specific instructions will be shown here -->
    </div>
    
    <div id="payment-actions" class="space-y-3 hidden">
        <button onclick="closeProcessingModal()" class="w-full <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
            Close
        </button>
    </div>
</div>
</div>
<script>
// Enhanced JavaScript for payment methods
function toggleExternalPayment() {
    const select = document.getElementById('payment-method-select');
    const options = document.getElementById('external-payment-options');
    const submitBtn = document.getElementById('submit-btn-text');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption.dataset.external === 'true') {
        options.classList.remove('hidden');
        const useExternal = document.getElementById('use-external-payment');
        if (useExternal.checked) {
            submitBtn.textContent = 'Process Payment';
        } else {
            submitBtn.textContent = 'Record Transaction';
        }
    } else {
        options.classList.add('hidden');
        submitBtn.textContent = 'Add Transaction';
    }
}

// Update form submission to handle external payments
document.getElementById('quick-add-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    const paymentMethod = formData.get('payment_method');
    const useExternal = formData.get('use_external_payment') === '1';
    const externalMethods = ['mpesa', 'binance', 'paypal', 'stripe'];
    
    if (externalMethods.includes(paymentMethod) && useExternal) {
        // Show processing modal
        document.getElementById('payment-processing-modal').classList.remove('hidden');
        
        // Update processing modal based on payment method
        updateProcessingModal(paymentMethod);
    }
    
    try {
        const response = await fetch('ajax/add_transaction.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (externalMethods.includes(paymentMethod) && useExternal) {
                handleExternalPaymentResponse(result);
            } else {
                closeQuickAdd();
                showNotification(result.message, 'success');
                setTimeout(() => location.reload(), 1000);
            }
        } else {
            closeProcessingModal();
            showNotification(result.message, 'error');
        }
    } catch (error) {
        closeProcessingModal();
        showNotification('Network error. Please try again.', 'error');
    }
});

function updateProcessingModal(paymentMethod) {
    const title = document.getElementById('processing-title');
    const message = document.getElementById('processing-message');
    const iconContainer = document.getElementById('processing-icon-container');
    
    switch (paymentMethod) {
        case 'mpesa':
            title.textContent = 'M-Pesa Payment';
            message.textContent = 'Initiating M-Pesa STK Push...';
            iconContainer.style.backgroundColor = '#00d13a20';
            break;
        case 'binance':
            title.textContent = 'Binance Pay';
            message.textContent = 'Creating Binance payment...';
            iconContainer.style.backgroundColor = '#f0b90b20';
            break;
        default:
            title.textContent = 'Processing Payment';
            message.textContent = 'Please wait...';
    }
}

function handleExternalPaymentResponse(result) {
    const paymentMethod = result.payment_method;
    const title = document.getElementById('processing-title');
    const message = document.getElementById('processing-message');
    const icon = document.getElementById('processing-icon');
    const instructions = document.getElementById('payment-instructions');
    const actions = document.getElementById('payment-actions');
    
    // Stop spinner
    icon.className = 'fas fa-check text-green-600 text-2xl';
    
    switch (paymentMethod) {
        case 'mpesa':
            title.textContent = 'M-Pesa Request Sent';
            message.textContent = 'Check your phone and enter your M-Pesa PIN to complete the payment.';
            instructions.innerHTML = `
                <div class="p-4 bg-green-50 dark:bg-green-900 rounded-lg mb-4">
                    <div class="flex items-center">
                        <i class="fas fa-mobile-alt text-green-600 text-2xl mr-3"></i>
                        <div>
                            <p class="font-medium text-green-800 dark:text-green-200">STK Push Sent</p>
                            <p class="text-sm text-green-600 dark:text-green-300">Complete payment on your phone</p>
                        </div>
                    </div>
                </div>
            `;
            break;
            
        case 'binance':
            if (result.external_data.checkout_url) {
                title.textContent = 'Redirecting to Binance Pay';
                message.textContent = 'You will be redirected to complete your payment.';
                instructions.innerHTML = `
                    <div class="p-4 bg-yellow-50 dark:bg-yellow-900 rounded-lg mb-4">
                        <p class="text-yellow-800 dark:text-yellow-200">Redirecting to Binance Pay...</p>
                    </div>
                `;
                
                // Redirect to Binance checkout
                setTimeout(() => {
                    window.open(result.external_data.checkout_url, '_blank');
                    closeProcessingModal();
                }, 2000);
            }
            break;
    }
    
    instructions.classList.remove('hidden');
    actions.classList.remove('hidden');
}

function closeProcessingModal() {
    document.getElementById('payment-processing-modal').classList.add('hidden');
    closeQuickAdd();
}

// Add checkbox event listener
document.getElementById('use-external-payment')?.addEventListener('change', function() {
    const submitBtn = document.getElementById('submit-btn-text');
    if (this.checked) {
        submitBtn.textContent = 'Process Payment';
    } else {
        submitBtn.textContent = 'Record Transaction';
    }
});
</script>