<?php
// payment_status.php - Track payment statuses and manage external payments
require_once 'config/database.php';
require_once 'config/payment_methods.php';
requireLogin();

$db = Database::getInstance();
$user_id = $_SESSION['user_id'];

// Get user settings
$settings = [];
$settingsResult = $db->fetchAll("SELECT setting_key, setting_value FROM settings WHERE user_id = ?", [$user_id]);
foreach ($settingsResult as $setting) {
    $settings[$setting['setting_key']] = $setting['setting_value'];
}

$darkMode = ($settings['dark_mode'] ?? '1') == '1';
$currency = $settings['currency'] ?? 'KES';

// Handle manual status updates
if ($_POST) {
    if (!validateCSRFToken($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'update_payment_status') {
                $payment_id = intval($_POST['payment_id']);
                $new_status = sanitizeInput($_POST['new_status']);
                
                if (!in_array($new_status, ['pending', 'completed', 'failed', 'cancelled'])) {
                    throw new Exception('Invalid payment status.');
                }
                
                $payment = $db->fetchOne(
                    "SELECT * FROM payment_transactions WHERE id = ? AND user_id = ?",
                    [$payment_id, $user_id]
                );
                
                if (!$payment) {
                    throw new Exception('Payment transaction not found.');
                }
                
                $db->beginTransaction();
                
                // Update payment status
                $db->execute(
                    "UPDATE payment_transactions SET status = ?, updated_at = NOW() WHERE id = ?",
                    [$new_status, $payment_id]
                );
                
                // If marking as completed manually, process the payment
                if ($new_status === 'completed' && $payment['status'] !== 'completed') {
                    processManualPaymentCompletion($db, $user_id, $payment);
                }
                
                $db->commit();
                $success = 'Payment status updated successfully!';
                
            } elseif ($action === 'retry_payment') {
                $payment_id = intval($_POST['payment_id']);
                $payment = $db->fetchOne(
                    "SELECT * FROM payment_transactions WHERE id = ? AND user_id = ?",
                    [$payment_id, $user_id]
                );
                
                if ($payment && in_array($payment['status'], ['failed', 'cancelled'])) {
                    $paymentConfig = new PaymentMethodsConfig($db, $user_id);
                    
                    // Retry the payment
                    $result = $paymentConfig->processPayment(
                        $payment['payment_method'],
                        $payment['amount'],
                        $payment['currency'],
                        'Retry payment - Transaction #' . $payment['id'],
                        $payment['transaction_id']
                    );
                    
                    if ($result['success']) {
                        $success = 'Payment retry initiated successfully!';
                    } else {
                        $error = 'Failed to retry payment. Please try again.';
                    }
                }
            }
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollback();
            }
            $error = $e->getMessage();
        }
    }
}

// Get payment transactions with filters
$status_filter = $_GET['status'] ?? '';
$method_filter = $_GET['method'] ?? '';
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = 20;
$offset = ($page - 1) * $per_page;

$whereConditions = ["pt.user_id = ?"];
$params = [$user_id];

if ($status_filter && in_array($status_filter, ['pending', 'completed', 'failed', 'cancelled'])) {
    $whereConditions[] = "pt.status = ?";
    $params[] = $status_filter;
}

if ($method_filter) {
    $whereConditions[] = "pt.payment_method = ?";
    $params[] = $method_filter;
}

$whereClause = implode(' AND ', $whereConditions);

// Get total count
$totalResult = $db->fetchOne(
    "SELECT COUNT(*) as total FROM payment_transactions pt WHERE $whereClause",
    $params
);
$totalRecords = $totalResult['total'];
$totalPages = ceil($totalRecords / $per_page);

// Get payment transactions
$payments = $db->fetchAll(
    "SELECT pt.*, t.description as transaction_description, t.type as transaction_type,
            b.name as bill_name
     FROM payment_transactions pt
     LEFT JOIN transactions t ON pt.transaction_id = t.id
     LEFT JOIN bills b ON t.bill_id = b.id
     WHERE $whereClause
     ORDER BY pt.created_at DESC
     LIMIT $per_page OFFSET $offset",
    $params
);

// Get payment method statistics
$paymentStats = $db->fetchAll(
    "SELECT payment_method, status, COUNT(*) as count, SUM(amount) as total_amount
     FROM payment_transactions 
     WHERE user_id = ?
     GROUP BY payment_method, status
     ORDER BY payment_method, status",
    [$user_id]
);

// Initialize payment config
$paymentConfig = new PaymentMethodsConfig($db, $user_id);
$availableMethods = $paymentConfig->getAvailablePaymentMethods();

function processManualPaymentCompletion($db, $user_id, $payment) {
    // This function processes a manually marked completed payment
    if ($payment['transaction_id']) {
        $transaction = $db->fetchOne(
            "SELECT * FROM transactions WHERE id = ? AND user_id = ?",
            [$payment['transaction_id'], $user_id]
        );
        
        if ($transaction && $transaction['status'] === 'pending') {
            // Update transaction status
            $db->execute(
                "UPDATE transactions SET status = 'completed' WHERE id = ?",
                [$payment['transaction_id']]
            );
            
            // Update wallet balance
            $balance = $db->fetchOne("SELECT current_balance FROM wallet_balance WHERE user_id = ?", [$user_id]);
            $current_balance = $balance ? $balance['current_balance'] : 0;
            
            if ($transaction['type'] === 'income') {
                $new_balance = $current_balance + $payment['amount'];
                $db->execute(
                    "UPDATE wallet_balance SET 
                     current_balance = ?, 
                     total_income = total_income + ?, 
                     updated_at = NOW() 
                     WHERE user_id = ?",
                    [$new_balance, $payment['amount'], $user_id]
                );
            } else {
                $new_balance = $current_balance - $payment['amount'];
                $db->execute(
                    "UPDATE wallet_balance SET 
                     current_balance = ?, 
                     total_expenses = total_expenses + ?, 
                     updated_at = NOW() 
                     WHERE user_id = ?",
                    [$new_balance, $payment['amount'], $user_id]
                );
            }
            
            // Update transaction balance_after
            $db->execute(
                "UPDATE transactions SET balance_after = ? WHERE id = ?",
                [$new_balance, $payment['transaction_id']]
            );
            
            // Create notification
            $db->execute(
                "INSERT INTO notifications (user_id, title, message, type) 
                 VALUES (?, ?, ?, ?)",
                [
                    $user_id,
                    'Payment Completed',
                    "Payment of " . formatMoney($payment['amount'], $payment['currency']) . " via " . ucfirst($payment['payment_method']) . " has been marked as completed.",
                    'success'
                ]
            );
        }
    }
}

$themeClass = $darkMode ? 'dark' : '';
?>

<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Status - <?= APP_NAME ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        .dark { background-color: #0f172a; }
        .dark .bg-white { background-color: #1e293b !important; }
        .dark .text-gray-800 { color: #f1f5f9 !important; }
        .dark .text-gray-600 { color: #cbd5e1 !important; }
        .dark .text-gray-500 { color: #94a3b8 !important; }
        .dark .border-gray-200 { border-color: #334155 !important; }
        .dark .bg-gray-50 { background-color: #0f172a !important; }
        .dark .bg-gray-100 { background-color: #1e293b !important; }
        
        .payment-card { transition: all 0.3s ease; }
        .payment-card:hover { transform: translateY(-2px); box-shadow: 0 10px 25px rgba(0,0,0,0.15); }
        
        .status-pending { border-left: 4px solid #f59e0b; }
        .status-completed { border-left: 4px solid #10b981; }
        .status-failed { border-left: 4px solid #ef4444; }
        .status-cancelled { border-left: 4px solid #6b7280; }
        
        .gradient-primary { background: linear-gradient(135deg, <?= PRIMARY_COLOR ?> 0%, #1e40af 100%); }
    </style>
</head>
<body class="<?= $darkMode ? 'dark bg-gray-900' : 'bg-gray-50' ?> font-sans">
    
    <!-- Navigation -->
    <nav class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center space-x-2 text-xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">
                        <i class="fas fa-wallet gradient-primary text-white p-2 rounded-lg"></i>
                        <span>Payment Status</span>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="payment_settings.php" class="<?= $darkMode ? 'text-gray-300 hover:text-white' : 'text-gray-600 hover:text-gray-800' ?>">
                        <i class="fas fa-cog mr-2"></i>Settings
                    </a>
                    <a href="dashboard.php" class="<?= $darkMode ? 'text-gray-300 hover:text-white' : 'text-gray-600 hover:text-gray-800' ?>">
                        <i class="fas fa-home mr-2"></i>Dashboard
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-900' ?>">Payment Status Tracker</h1>
            <p class="mt-2 <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Monitor and manage your external payment transactions</p>
        </div>
        
        <!-- Alert Messages -->
        <?php if (isset($error)): ?>
            <div class="mb-6 bg-red-500 bg-opacity-20 border border-red-400 text-red-100 px-4 py-3 rounded-lg">
                <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($success)): ?>
            <div class="mb-6 bg-green-500 bg-opacity-20 border border-green-400 text-green-100 px-4 py-3 rounded-lg">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <?php
            $stats = ['pending' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
            $totalAmount = ['pending' => 0, 'completed' => 0, 'failed' => 0, 'cancelled' => 0];
            
            foreach ($paymentStats as $stat) {
                $stats[$stat['status']] += $stat['count'];
                $totalAmount[$stat['status']] += $stat['total_amount'];
            }
            ?>
            
            <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg payment-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Pending</p>
                        <p class="text-2xl font-bold text-yellow-600"><?= $stats['pending'] ?></p>
                        <p class="text-sm text-yellow-600"><?= formatMoney($totalAmount['pending'], $currency) ?></p>
                    </div>
                    <div class="w-12 h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-clock text-yellow-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg payment-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Completed</p>
                        <p class="text-2xl font-bold text-green-600"><?= $stats['completed'] ?></p>
                        <p class="text-sm text-green-600"><?= formatMoney($totalAmount['completed'], $currency) ?></p>
                    </div>
                    <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-check-circle text-green-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg payment-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Failed</p>
                        <p class="text-2xl font-bold text-red-600"><?= $stats['failed'] ?></p>
                        <p class="text-sm text-red-600"><?= formatMoney($totalAmount['failed'], $currency) ?></p>
                    </div>
                    <div class="w-12 h-12 bg-red-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-times-circle text-red-600 text-xl"></i>
                    </div>
                </div>
            </div>
            
            <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg payment-card">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?> text-sm font-medium">Cancelled</p>
                        <p class="text-2xl font-bold text-gray-600"><?= $stats['cancelled'] ?></p>
                        <p class="text-sm text-gray-600"><?= formatMoney($totalAmount['cancelled'], $currency) ?></p>
                    </div>
                    <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-ban text-gray-600 text-xl"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg mb-6">
            <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Filters</h3>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div>
                    <label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Status</label>
                    <select name="status" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="completed" <?= $status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="failed" <?= $status_filter === 'failed' ? 'selected' : '' ?>>Failed</option>
                        <option value="cancelled" <?= $status_filter === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                
                <div><label class="block text-sm font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?> mb-2">Payment Method</label>
<select name="method" class="w-full <?= $darkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800' ?> border rounded-lg px-3 py-2">
<option value="">All Methods</option>
<option value="mpesa" <?= $method_filter === 'mpesa' ? 'selected' : '' ?>>M-Pesa</option>
<option value="binance" <?= $method_filter === 'binance' ? 'selected' : '' ?>>Binance Pay</option>
<option value="paypal" <?= $method_filter === 'paypal' ? 'selected' : '' ?>>PayPal</option>
<option value="stripe" <?= $method_filter === 'stripe' ? 'selected' : '' ?>>Stripe</option>
</select>
</div>            <div class="md:col-span-2 flex items-end space-x-2">
                <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition-colors">
                    <i class="fas fa-search mr-2"></i>Filter
                </button>
                <a href="payment_status.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition-colors">
                    <i class="fas fa-times mr-2"></i>Clear
                </a>
            </div>
        </form>
    </div>    <!-- Payment Transactions -->
    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl shadow-lg overflow-hidden">
        <div class="px-6 py-4 border-b <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">
                    Payment Transactions (<?= number_format($totalRecords) ?> records)
                </h3>
                <div class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">
                    Page <?= $page ?> of <?= $totalPages ?>
                </div>
            </div>
        </div>        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="<?= $darkMode ? 'bg-gray-700' : 'bg-gray-50' ?>">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Date</th>
                        <th class="px-6 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Method</th>
                        <th class="px-6 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Description</th>
                        <th class="px-6 py-3 text-right text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Amount</th>
                        <th class="px-6 py-3 text-center text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-center text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase tracking-wider">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y <?= $darkMode ? 'divide-gray-700' : 'divide-gray-200' ?>">
                    <?php if (empty($payments)): ?>
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                                <i class="fas fa-credit-card text-4xl mb-4"></i>
                                <p class="text-lg mb-2">No payment transactions found</p>
                                <p>External payment transactions will appear here</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($payments as $payment): ?>
                            <tr class="status-<?= $payment['status'] ?> hover:bg-gray-50 dark:hover:bg-gray-700">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>"><?= formatDate($payment['created_at'], 'M j, Y') ?></div>
                                    <div class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>"><?= formatDate($payment['created_at'], 'g:i A') ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-lg flex items-center justify-center mr-3" style="background-color: <?= $availableMethods[$payment['payment_method']]['color'] ?? '#6b7280' ?>;">
                                            <i class="<?= $availableMethods[$payment['payment_method']]['icon'] ?? 'fas fa-credit-card' ?> text-white text-sm"></i>
                                        </div>
                                        <div>
                                            <div class="text-sm font-medium <?= $darkMode ? 'text-white' : 'text-gray-900' ?>">
                                                <?= $availableMethods[$payment['payment_method']]['name'] ?? ucfirst($payment['payment_method']) ?>
                                            </div>
                                            <?php if ($payment['external_transaction_id']): ?>
                                                <div class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                                                    ID: <?= substr($payment['external_transaction_id'], 0, 12) ?>...
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>">
                                        <?= htmlspecialchars($payment['transaction_description'] ?? 'Payment transaction') ?>
                                    </div>
                                    <?php if ($payment['bill_name']): ?>
                                        <div class="text-xs <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                                            Bill: <?= htmlspecialchars($payment['bill_name']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <div class="text-sm font-semibold <?= $darkMode ? 'text-white' : 'text-gray-900' ?>">
                                        <?= formatMoney($payment['amount'], $payment['currency']) ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= 
                                        $payment['status'] === 'completed' ? 'bg-green-100 text-green-800' :
                                        ($payment['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                        ($payment['status'] === 'failed' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'))
                                    ?>">
                                        <i class="fas fa-<?= 
                                            $payment['status'] === 'completed' ? 'check-circle' :
                                            ($payment['status'] === 'pending' ? 'clock' : 
                                            ($payment['status'] === 'failed' ? 'times-circle' : 'ban'))
                                        ?> mr-1"></i>
                                        <?= ucfirst($payment['status']) ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-center">
                                    <div class="flex items-center justify-center space-x-2">
                                        <button onclick="viewPaymentDetails(<?= $payment['id'] ?>)" class="text-blue-600 hover:text-blue-800 transition-colors" title="View Details">
                                            <i class="fas fa-eye"></i>
                                        </button>                                        <?php if ($payment['status'] === 'pending'): ?>
                                            <button onclick="updatePaymentStatus(<?= $payment['id'] ?>, 'completed')" class="text-green-600 hover:text-green-800 transition-colors" title="Mark as Completed">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button onclick="updatePaymentStatus(<?= $payment['id'] ?>, 'cancelled')" class="text-red-600 hover:text-red-800 transition-colors" title="Cancel Payment">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php endif; ?>                                        <?php if (in_array($payment['status'], ['failed', 'cancelled'])): ?>
                                            <button onclick="retryPayment(<?= $payment['id'] ?>)" class="text-orange-600 hover:text-orange-800 transition-colors" title="Retry Payment">
                                                <i class="fas fa-redo"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="px-6 py-4 border-t <?= $darkMode ? 'border-gray-700' : 'border-gray-200' ?>">
                <div class="flex items-center justify-between">
                    <div class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">
                        Showing <?= ($page - 1) * $per_page + 1 ?> to <?= min($page * $per_page, $totalRecords) ?> of <?= number_format($totalRecords) ?> results
                    </div>                    <div class="flex items-center space-x-2">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" 
                               class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition-colors">
                                <i class="fas fa-chevron-left"></i>
                            </a>
                        <?php endif; ?>                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                               class="px-3 py-2 rounded-lg transition-colors <?= $i === $page ? 'bg-blue-600 text-white' : ($darkMode ? 'bg-gray-700 text-gray-300 hover:bg-gray-600' : 'bg-gray-200 text-gray-700 hover:bg-gray-300') ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" 
                               class="bg-blue-600 hover:bg-blue-700 text-white px-3 py-2 rounded-lg transition-colors">
                                <i class="fas fa-chevron-right"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div><!-- Payment Details Modal -->
<div id="details-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between mb-6">
            <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Payment Details</h3>
            <button onclick="closeDetailsModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                <i class="fas fa-times"></i>
            </button>
        </div>        <div id="payment-details-content">
            <!-- Payment details will be populated here -->
        </div>
    </div>
</div><!-- Status Update Form (Hidden) -->
<form id="status-update-form" method="POST" style="display: none;">
    <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
    <input type="hidden" name="action" value="update_payment_status">
    <input type="hidden" name="payment_id" id="status-payment-id">
    <input type="hidden" name="new_status" id="status-new-status">
</form><!-- Retry Payment Form (Hidden) -->
<form id="retry-payment-form" method="POST" style="display: none;">
    <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
    <input type="hidden" name="action" value="retry_payment">
    <input type="hidden" name="payment_id" id="retry-payment-id">
</form><script>
    const payments = <?= json_encode($payments) ?>;
    const availableMethods = <?= json_encode($availableMethods) ?>;
    const isDarkMode = <?= $darkMode ? 'true' : 'false' ?>;    function viewPaymentDetails(paymentId) {
        const payment = payments.find(p => p.id == paymentId);
        if (!payment) return;        const method = availableMethods[payment.payment_method] || { name: payment.payment_method, color: '#6b7280', icon: 'fas fa-credit-card' };
        const gatewayResponse = payment.gateway_response ? JSON.parse(payment.gateway_response) : null;        const detailsContent = document.getElementById('payment-details-content');
        detailsContent.innerHTML = `
            <div class="space-y-6">
                <!-- Payment Overview -->
                <div class="border-b ${isDarkMode ? 'border-gray-700' : 'border-gray-200'} pb-4">
                    <div class="flex items-center space-x-4">
                        <div class="w-16 h-16 rounded-lg flex items-center justify-center" style="background-color: ${method.color};">
                            <i class="${method.icon} text-white text-2xl"></i>
                        </div>
                        <div>
                            <h4 class="text-xl font-semibold ${isDarkMode ? 'text-white' : 'text-gray-800'}">${method.name}</h4>
                            <p class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">Payment Transaction #${payment.id}</p>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-2 ${getStatusClasses(payment.status)}">
                                ${payment.status.toUpperCase()}
                            </span>
                        </div>
                    </div>
                </div>                <!-- Transaction Details -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <h5 class="font-medium ${isDarkMode ? 'text-white' : 'text-gray-800'} mb-3">Transaction Information</h5>
                        <dl class="space-y-2">
                            <div class="flex justify-between">
                                <dt class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">Amount:</dt>
                                <dd class="text-sm font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}">${payment.currency} ${parseFloat(payment.amount).toFixed(2)}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">Status:</dt>
                                <dd class="text-sm font-medium ${isDarkMode ? 'text-white' : 'text-gray-900'}">${payment.status.charAt(0).toUpperCase() + payment.status.slice(1)}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">Created:</dt>
                                <dd class="text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}">${formatDate(payment.created_at)}</dd>
                            </div>
                            <div class="flex justify-between">
                                <dt class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">Updated:</dt>
                                <dd class="text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}">${formatDate(payment.updated_at)}</dd>
                            </div>
                            ${payment.external_transaction_id ? `
                            <div class="flex justify-between">
                                <dt class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">External ID:</dt>
                                <dd class="text-sm font-mono ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}">${payment.external_transaction_id}</dd>
                            </div>
                            ` : ''}
                        </dl>
                    </div>                    <div>
                        <h5 class="font-medium ${isDarkMode ? 'text-white' : 'text-gray-800'} mb-3">Related Information</h5>
                        <dl class="space-y-2">
                            ${payment.transaction_description ? `
                            <div>
                                <dt class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">Description:</dt>
                                <dd class="text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}">${payment.transaction_description}</dd>
                            </div>
                            ` : ''}
                            ${payment.bill_name ? `
                            <div>
                                <dt class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">Related Bill:</dt>
                                <dd class="text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}">${payment.bill_name}</dd>
                            </div>
                            ` : ''}
                            ${payment.transaction_id ? `
                            <div>
                                <dt class="text-sm ${isDarkMode ? 'text-gray-400' : 'text-gray-600'}">Transaction ID:</dt>
                                <dd class="text-sm ${isDarkMode ? 'text-white' : 'text-gray-900'}">#${payment.transaction_id}</dd>
                            </div>
                            ` : ''}
                        </dl>
                    </div>
                </div>                ${gatewayResponse ? `
                <!-- Gateway Response -->
                <div class="border-t ${isDarkMode ? 'border-gray-700' : 'border-gray-200'} pt-4">
                    <h5 class="font-medium ${isDarkMode ? 'text-white' : 'text-gray-800'} mb-3">Gateway Response</h5>
                    <pre class="text-xs ${isDarkMode ? 'bg-gray-900 text-gray-300' : 'bg-gray-100 text-gray-800'} p-4 rounded-lg overflow-x-auto">${JSON.stringify(gatewayResponse, null, 2)}</pre>
                </div>
                ` : ''}
            </div>
        `;        document.getElementById('details-modal').classList.remove('hidden');
    }    function closeDetailsModal() {
        document.getElementById('details-modal').classList.add('hidden');
    }    function updatePaymentStatus(paymentId, newStatus) {
        if (confirm(`Are you sure you want to mark this payment as ${newStatus}?`)) {
            document.getElementById('status-payment-id').value = paymentId;
            document.getElementById('status-new-status').value = newStatus;
            document.getElementById('status-update-form').submit();
        }
    }    function retryPayment(paymentId) {
        if (confirm('Are you sure you want to retry this payment? This will initiate a new payment request.')) {
            document.getElementById('retry-payment-id').value = paymentId;
            document.getElementById('retry-payment-form').submit();
        }
    }    function getStatusClasses(status) {
        switch (status) {
            case 'completed':
                return 'bg-green-100 text-green-800';
            case 'pending':
                return 'bg-yellow-100 text-yellow-800';
            case 'failed':
                return 'bg-red-100 text-red-800';
            case 'cancelled':
                return 'bg-gray-100 text-gray-800';
            default:
                return 'bg-gray-100 text-gray-800';
        }
    }    function formatDate(dateString) {
        return new Date(dateString).toLocaleString();
    }    // Auto-refresh pending payments every 30 seconds
    setInterval(() => {
        const hasPending = payments.some(p => p.status === 'pending');
        if (hasPending) {
            location.reload();
        }
    }, 30000);
</script>
</body>
</html>