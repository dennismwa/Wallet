<?php
// payment_settings.php
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

// Initialize payment methods config
$paymentConfig = new PaymentMethodsConfig($db, $user_id);

// Handle form submissions
if ($_POST) {
    if (!validateCSRFToken($_POST['_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        try {
            $action = $_POST['action'] ?? '';
            
            if ($action === 'save_payment_method') {
                $method_name = sanitizeInput($_POST['method_name']);
                $is_enabled = isset($_POST['is_enabled']) ? 1 : 0;
                
                // Get method configuration fields
                $config = [];
                $available_methods = $paymentConfig->getAvailablePaymentMethods();
                
                if (isset($available_methods[$method_name])) {
                    foreach ($available_methods[$method_name]['fields'] as $field_key => $field_name) {
                        if (isset($_POST[$field_key])) {
                            $config[$field_key] = sanitizeInput($_POST[$field_key]);
                        }
                    }
                }
                
                $paymentConfig->savePaymentMethod($method_name, $config, $is_enabled);
                $success = ucfirst($method_name) . ' payment method configuration saved successfully!';
                
            } elseif ($action === 'toggle_payment_method') {
                $method_name = sanitizeInput($_POST['method_name']);
                $is_enabled = intval($_POST['is_enabled']);
                
                $current_config = $paymentConfig->getPaymentMethodConfig($method_name);
                if ($current_config) {
                    $config = json_decode($current_config['configuration'], true);
                    $paymentConfig->savePaymentMethod($method_name, $config, $is_enabled);
                    $success = ucfirst($method_name) . ' has been ' . ($is_enabled ? 'enabled' : 'disabled');
                }
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$availableMethods = $paymentConfig->getAvailablePaymentMethods();
$userMethods = $paymentConfig->getUserPaymentMethods();

// Index user methods by method name for easier access
$userMethodsIndexed = [];
foreach ($userMethods as $method) {
    $userMethodsIndexed[$method['method_name']] = $method;
}

$themeClass = $darkMode ? 'dark' : '';
?>
<!DOCTYPE html>
<html lang="en" class="<?= $themeClass ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Methods - <?= APP_NAME ?></title>
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
        
        .gradient-primary { background: linear-gradient(135deg, <?= PRIMARY_COLOR ?> 0%, #1e40af 100%); }
    </style>
</head>
<body class="<?= $darkMode ? 'dark bg-gray-900' : 'bg-gray-50' ?> font-sans">
    
    <!-- Navigation (simplified for this example) -->
    <nav class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between h-16">
                <div class="flex items-center">
                    <a href="dashboard.php" class="flex items-center space-x-2 text-xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">
                        <i class="fas fa-wallet gradient-primary text-white p-2 rounded-lg"></i>
                        <span>Luidigitals</span>
                    </a>
                </div>
                <div class="flex items-center space-x-4">
                    <a href="settings.php" class="<?= $darkMode ? 'text-gray-300 hover:text-white' : 'text-gray-600 hover:text-gray-800' ?>">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Settings
                    </a>
                </div>
            </div>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold <?= $darkMode ? 'text-white' : 'text-gray-900' ?>">Payment Methods</h1>
            <p class="mt-2 <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Configure and manage your payment gateway integrations</p>
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
        
        <!-- Payment Methods Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($availableMethods as $method_key => $method): ?>
                <?php 
                $userMethod = $userMethodsIndexed[$method_key] ?? null;
                $isConfigured = $userMethod && $userMethod['configuration'];
                $isEnabled = $userMethod && $userMethod['is_enabled'];
                ?>
                <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg payment-card border-2 <?= $isEnabled ? 'border-green-500' : ($isConfigured ? 'border-yellow-500' : 'border-gray-200') ?>">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center space-x-3">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: <?= $method['color'] ?>;">
                                <i class="<?= $method['icon'] ?> text-white text-xl"></i>
                            </div>
                            <div>
                                <h3 class="font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>"><?= $method['name'] ?></h3>
                                <p class="text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">
                                    <?= $isEnabled ? 'Active' : ($isConfigured ? 'Configured' : 'Not configured') ?>
                                </p>
                            </div>
                        </div>
                        
                        <?php if ($isConfigured): ?>
                            <div class="flex items-center space-x-2">
                                <button 
                                    onclick="togglePaymentMethod('<?= $method_key ?>', <?= $isEnabled ? 0 : 1 ?>)"
                                    class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 <?= $isEnabled ? 'bg-green-500' : 'bg-gray-200' ?>"
                                >
                                    <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform <?= $isEnabled ? 'translate-x-6' : 'translate-x-1' ?>"></span>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Configuration Status -->
                    <div class="mb-4">
                        <?php if ($isEnabled): ?>
                            <div class="flex items-center text-green-600">
                                <i class="fas fa-check-circle mr-2"></i>
                                <span class="text-sm font-medium">Active & Configured</span>
                            </div>
                        <?php elseif ($isConfigured): ?>
                            <div class="flex items-center text-yellow-600">
                                <i class="fas fa-exclamation-circle mr-2"></i>
                                <span class="text-sm font-medium">Configured but Disabled</span>
                            </div>
                        <?php else: ?>
                            <div class="flex items-center text-gray-500">
                                <i class="fas fa-cog mr-2"></i>
                                <span class="text-sm">Not Configured</span>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Webhook URLs (if configured) -->
                    <?php if ($isConfigured): ?>
                        <div class="mb-4 p-3 <?= $darkMode ? 'bg-gray-700' : 'bg-gray-50' ?> rounded-lg">
                            <h4 class="text-sm font-medium <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-2">Integration URLs</h4>
                            <div class="space-y-2 text-xs">
                                <div>
                                    <span class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Callback:</span>
                                    <code class="block mt-1 p-1 <?= $darkMode ? 'bg-gray-800 text-gray-300' : 'bg-white text-gray-700' ?> rounded text-xs break-all">
                                        <?= $userMethod['callback_url'] ?>
                                    </code>
                                </div>
                                <div>
                                    <span class="<?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">Webhook:</span>
                                    <code class="block mt-1 p-1 <?= $darkMode ? 'bg-gray-800 text-gray-300' : 'bg-white text-gray-700' ?> rounded text-xs break-all">
                                        <?= $userMethod['webhook_url'] ?>
                                    </code>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Action Button -->
                    <button 
                        onclick="openConfigModal('<?= $method_key ?>')" 
                        class="w-full gradient-primary text-white py-2 px-4 rounded-lg hover:opacity-90 transition-opacity"
                    >
                        <i class="fas fa-<?= $isConfigured ? 'edit' : 'plus' ?> mr-2"></i>
                        <?= $isConfigured ? 'Edit Configuration' : 'Configure Now' ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Recent Payment Transactions -->
        <div class="mt-8">
            <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 shadow-lg">
                <h3 class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?> mb-4">Recent Payment Transactions</h3>
                
                <?php
                $recentPayments = $db->fetchAll(
                    "SELECT pt.*, t.description 
                     FROM payment_transactions pt 
                     LEFT JOIN transactions t ON pt.transaction_id = t.id 
                     WHERE pt.user_id = ? 
                     ORDER BY pt.created_at DESC 
                     LIMIT 10",
                    [$user_id]
                );
                ?>
                
                <?php if (empty($recentPayments)): ?>
                    <div class="text-center py-8 <?= $darkMode ? 'text-gray-400' : 'text-gray-500' ?>">
                        <i class="fas fa-credit-card text-4xl mb-4"></i>
                        <p>No payment transactions yet</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="w-full">
                            <thead class="<?= $darkMode ? 'bg-gray-700' : 'bg-gray-50' ?>">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase">Method</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase">Amount</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium <?= $darkMode ? 'text-gray-300' : 'text-gray-500' ?> uppercase">Transaction ID</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y <?= $darkMode ? 'divide-gray-700' : 'divide-gray-200' ?>">
                                <?php foreach ($recentPayments as $payment): ?>
                                    <tr>
                                        <td class="px-4 py-3 text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>">
                                            <?= formatDate($payment['created_at'], 'M j, Y g:i A') ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex items-center">
                                                <div class="w-6 h-6 rounded flex items-center justify-center mr-2" style="background-color: <?= $availableMethods[$payment['payment_method']]['color'] ?? '#6b7280' ?>;">
                                                    <i class="<?= $availableMethods[$payment['payment_method']]['icon'] ?? 'fas fa-credit-card' ?> text-white text-xs"></i>
                                                </div>
                                                <span class="text-sm <?= $darkMode ? 'text-white' : 'text-gray-900' ?>">
                                                    <?= $availableMethods[$payment['payment_method']]['name'] ?? ucfirst($payment['payment_method']) ?>
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-4 py-3 text-sm font-medium <?= $darkMode ? 'text-white' : 'text-gray-900' ?>">
                                            <?= $payment['currency'] ?> <?= number_format($payment['amount'], 2) ?>
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?= 
                                                $payment['status'] === 'completed' ? 'bg-green-100 text-green-800' :
                                                ($payment['status'] === 'pending' ? 'bg-yellow-100 text-yellow-800' : 
                                                ($payment['status'] === 'failed' ? 'bg-red-100 text-red-800' : 'bg-gray-100 text-gray-800'))
                                            ?>">
                                                <?= ucfirst($payment['status']) ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm <?= $darkMode ? 'text-gray-400' : 'text-gray-600' ?>">
                                            <?= $payment['external_transaction_id'] ?? 'N/A' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Configuration Modal -->
    <div id="config-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
        <div class="<?= $darkMode ? 'bg-gray-800' : 'bg-white' ?> rounded-xl p-6 w-full max-w-2xl max-h-[90vh] overflow-y-auto">
            <div class="flex items-center justify-between mb-6">
                <h3 id="modal-title" class="text-lg font-semibold <?= $darkMode ? 'text-white' : 'text-gray-800' ?>">Configure Payment Method</h3>
                <button onclick="closeConfigModal()" class="<?= $darkMode ? 'text-gray-400 hover:text-white' : 'text-gray-500 hover:text-gray-700' ?>">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="config-form" method="POST" class="space-y-4">
                <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
                <input type="hidden" name="action" value="save_payment_method">
                <input type="hidden" name="method_name" id="modal-method-name">
                
                <div id="config-fields" class="space-y-4">
                    <!-- Dynamic fields will be populated here -->
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" name="is_enabled" id="modal-is-enabled" class="rounded border-gray-300 text-blue-600">
                    <label for="modal-is-enabled" class="ml-2 text-sm <?= $darkMode ? 'text-gray-300' : 'text-gray-700' ?>">
                        Enable this payment method
                    </label>
                </div>
                
                <div class="flex space-x-3 pt-4">
                    <button type="button" onclick="closeConfigModal()" class="flex-1 <?= $darkMode ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-gray-200 hover:bg-gray-300 text-gray-800' ?> py-2 px-4 rounded-lg transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 gradient-primary text-white py-2 px-4 rounded-lg hover:opacity-90 transition-opacity">
                        <i class="fas fa-save mr-2"></i>Save Configuration
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Toggle Form (Hidden) -->
    <form id="toggle-form" method="POST" style="display: none;">
        <input type="hidden" name="_token" value="<?= generateCSRFToken() ?>">
        <input type="hidden" name="action" value="toggle_payment_method">
        <input type="hidden" name="method_name" id="toggle-method-name">
        <input type="hidden" name="is_enabled" id="toggle-is-enabled">
    </form>
    
    <script>
        const availableMethods = <?= json_encode($availableMethods) ?>;
        const userMethods = <?= json_encode($userMethodsIndexed) ?>;
        const isDarkMode = <?= $darkMode ? 'true' : 'false' ?>;
        
        function openConfigModal(methodKey) {
            const method = availableMethods[methodKey];
            const userMethod = userMethods[methodKey] || {};
            
            document.getElementById('modal-title').textContent = `Configure ${method.name}`;
            document.getElementById('modal-method-name').value = methodKey;
            document.getElementById('modal-is-enabled').checked = userMethod.is_enabled == 1;
            
            // Generate form fields
            const fieldsContainer = document.getElementById('config-fields');
            fieldsContainer.innerHTML = '';
            
            // Add method info
            const infoDiv = document.createElement('div');
            infoDiv.className = `p-4 rounded-lg ${isDarkMode ? 'bg-gray-700' : 'bg-blue-50'} mb-4`;
            infoDiv.innerHTML = `
                <div class="flex items-center space-x-3 mb-3">
                    <div class="w-10 h-10 rounded-lg flex items-center justify-center" style="background-color: ${method.color};">
                        <i class="${method.icon} text-white"></i>
                    </div>
                    <h4 class="font-medium ${isDarkMode ? 'text-white' : 'text-gray-800'}">${method.name} Configuration</h4>
                </div>
                <p class="text-sm ${isDarkMode ? 'text-gray-300' : 'text-gray-600'}">
                    Enter your ${method.name} API credentials. All sensitive data will be encrypted and stored securely.
                </p>
            `;
            fieldsContainer.appendChild(infoDiv);
            
            // Generate input fields
            Object.entries(method.fields).forEach(([fieldKey, fieldLabel]) => {
                const fieldDiv = document.createElement('div');
                fieldDiv.className = 'space-y-2';
                
                const currentValue = userMethod.configuration ? 
                    (JSON.parse(userMethod.configuration)[fieldKey] || '') : '';
                
                const inputType = fieldKey.includes('secret') || fieldKey.includes('key') || fieldKey === 'passkey' ? 'password' : 
                                 fieldKey === 'environment' ? 'select' : 'text';
                
                let fieldHTML = `
                    <label class="block text-sm font-medium ${isDarkMode ? 'text-gray-300' : 'text-gray-700'}">${fieldLabel}</label>
                `;
                
                if (inputType === 'select' && fieldKey === 'environment') {
                    fieldHTML += `
                        <select name="${fieldKey}" class="w-full ${isDarkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800'} border rounded-lg px-3 py-2" required>
                            <option value="sandbox" ${currentValue === 'sandbox' ? 'selected' : ''}>Sandbox/Test</option>
                            <option value="production" ${currentValue === 'production' ? 'selected' : ''}>Production/Live</option>
                        </select>
                    `;
                } else {
                    fieldHTML += `
                        <input 
                            type="${inputType}" 
                            name="${fieldKey}" 
                            value="${currentValue}" 
                            class="w-full ${isDarkMode ? 'bg-gray-700 border-gray-600 text-white' : 'bg-white border-gray-300 text-gray-800'} border rounded-lg px-3 py-2" 
                            ${fieldKey.includes('key') || fieldKey.includes('secret') || fieldKey === 'shortcode' || fieldKey === 'merchant_id' ? 'required' : ''}
                            placeholder="Enter your ${fieldLabel.toLowerCase()}"
                        >
                    `;
                }
                
                fieldDiv.innerHTML = fieldHTML;
                fieldsContainer.appendChild(fieldDiv);
            });
            
            document.getElementById('config-modal').classList.remove('hidden');
        }
        
        function closeConfigModal() {
            document.getElementById('config-modal').classList.add('hidden');
        }
        
        function togglePaymentMethod(methodKey, isEnabled) {
            document.getElementById('toggle-method-name').value = methodKey;
            document.getElementById('toggle-is-enabled').value = isEnabled;
            document.getElementById('toggle-form').submit();
        }
        
        // Form validation
        document.getElementById('config-form').addEventListener('submit', function(e) {
            const requiredFields = this.querySelectorAll('input[required], select[required]');
            let isValid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    isValid = false;
                    field.classList.add('border-red-500');
                } else {
                    field.classList.remove('border-red-500');
                }
            });
            
            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
        
        // Close modal on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeConfigModal();
            }
        });
    </script>
</body>
</html>