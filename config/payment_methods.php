<?php
// config/payment_methods.php

class PaymentMethodsConfig {
    private $db;
    private $user_id;
    
    public function __construct($db, $user_id) {
        $this->db = $db;
        $this->user_id = $user_id;
    }
    
    /**
     * Get all available payment methods
     */
    public function getAvailablePaymentMethods() {
        return [
            'mpesa' => [
                'name' => 'M-Pesa',
                'icon' => 'fas fa-mobile-alt',
                'color' => '#00d13a',
                'fields' => [
                    'consumer_key' => 'Consumer Key',
                    'consumer_secret' => 'Consumer Secret',
                    'shortcode' => 'Business Shortcode',
                    'passkey' => 'Passkey',
                    'environment' => 'Environment (sandbox/production)'
                ]
            ],
            'binance' => [
                'name' => 'Binance Pay',
                'icon' => 'fab fa-bitcoin',
                'color' => '#f0b90b',
                'fields' => [
                    'api_key' => 'API Key',
                    'api_secret' => 'API Secret',
                    'merchant_id' => 'Merchant ID',
                    'environment' => 'Environment (sandbox/production)'
                ]
            ],
            'paypal' => [
                'name' => 'PayPal',
                'icon' => 'fab fa-paypal',
                'color' => '#0070ba',
                'fields' => [
                    'client_id' => 'Client ID',
                    'client_secret' => 'Client Secret',
                    'environment' => 'Environment (sandbox/production)'
                ]
            ],
            'stripe' => [
                'name' => 'Stripe',
                'icon' => 'fab fa-stripe',
                'color' => '#635bff',
                'fields' => [
                    'publishable_key' => 'Publishable Key',
                    'secret_key' => 'Secret Key',
                    'webhook_secret' => 'Webhook Secret'
                ]
            ]
        ];
    }
    
    /**
     * Get user's configured payment methods
     */
    public function getUserPaymentMethods() {
        return $this->db->fetchAll(
            "SELECT * FROM payment_methods WHERE user_id = ? ORDER BY method_name",
            [$this->user_id]
        );
    }
    
    /**
     * Get enabled payment methods for user
     */
    public function getEnabledPaymentMethods() {
        return $this->db->fetchAll(
            "SELECT * FROM payment_methods WHERE user_id = ? AND is_enabled = 1 ORDER BY method_name",
            [$this->user_id]
        );
    }
    
    /**
     * Save payment method configuration
     */
    public function savePaymentMethod($method_name, $config, $is_enabled = false) {
        // Encrypt sensitive data
        $encrypted_config = [];
        foreach ($config as $key => $value) {
            if (in_array($key, ['api_key', 'api_secret', 'consumer_secret', 'client_secret', 'secret_key', 'webhook_secret', 'passkey'])) {
                $encrypted_config[$key] = $this->encrypt($value);
            } else {
                $encrypted_config[$key] = $value;
            }
        }
        
        $this->db->execute(
            "INSERT INTO payment_methods (user_id, method_name, configuration, is_enabled, callback_url, webhook_url) 
             VALUES (?, ?, ?, ?, ?, ?) 
             ON DUPLICATE KEY UPDATE 
             configuration = VALUES(configuration), 
             is_enabled = VALUES(is_enabled),
             callback_url = VALUES(callback_url),
             webhook_url = VALUES(webhook_url)",
            [
                $this->user_id,
                $method_name,
                json_encode($encrypted_config),
                $is_enabled ? 1 : 0,
                $this->generateCallbackUrl($method_name),
                $this->generateWebhookUrl($method_name)
            ]
        );
        
        return true;
    }
    
    /**
     * Get payment method configuration
     */
    public function getPaymentMethodConfig($method_name) {
        $config = $this->db->fetchOne(
            "SELECT * FROM payment_methods WHERE user_id = ? AND method_name = ?",
            [$this->user_id, $method_name]
        );
        
        if (!$config) {
            return null;
        }
        
        // Decrypt sensitive data for display (masked)
        $decrypted_config = json_decode($config['configuration'], true);
        foreach ($decrypted_config as $key => $value) {
            if (in_array($key, ['api_key', 'api_secret', 'consumer_secret', 'client_secret', 'secret_key', 'webhook_secret', 'passkey'])) {
                $decrypted_config[$key] = $this->maskSensitiveData($value);
            }
        }
        
        $config['configuration'] = $decrypted_config;
        return $config;
    }
    
    /**
     * Check if payment method is enabled and configured
     */
    public function isPaymentMethodEnabled($method_name) {
        $config = $this->db->fetchOne(
            "SELECT is_enabled FROM payment_methods WHERE user_id = ? AND method_name = ?",
            [$this->user_id, $method_name]
        );
        
        return $config && $config['is_enabled'] == 1;
    }
    
    /**
     * Process payment through external gateway
     */
    public function processPayment($method_name, $amount, $currency, $description, $transaction_id = null) {
        $config = $this->getPaymentMethodConfig($method_name);
        
        if (!$config || !$config['is_enabled']) {
            throw new Exception("Payment method {$method_name} is not available");
        }
        
        switch ($method_name) {
            case 'mpesa':
                return $this->processMpesaPayment($config, $amount, $currency, $description, $transaction_id);
            case 'binance':
                return $this->processBinancePayment($config, $amount, $currency, $description, $transaction_id);
            case 'paypal':
                return $this->processPayPalPayment($config, $amount, $currency, $description, $transaction_id);
            case 'stripe':
                return $this->processStripePayment($config, $amount, $currency, $description, $transaction_id);
            default:
                throw new Exception("Unsupported payment method: {$method_name}");
        }
    }
    
    /**
     * Process M-Pesa STK Push
     */
    private function processMpesaPayment($config, $amount, $currency, $description, $transaction_id) {
        $configuration = json_decode($config['configuration'], true);
        
        // Decrypt sensitive data
        $consumer_key = $this->decrypt($configuration['consumer_key']);
        $consumer_secret = $this->decrypt($configuration['consumer_secret']);
        $passkey = $this->decrypt($configuration['passkey']);
        $shortcode = $configuration['shortcode'];
        $environment = $configuration['environment'] ?? 'sandbox';
        
        $base_url = $environment === 'production' 
            ? 'https://api.safaricom.co.ke' 
            : 'https://sandbox.safaricom.co.ke';
        
        // Get OAuth token
        $credentials = base64_encode($consumer_key . ':' . $consumer_secret);
        $auth_response = $this->makeHttpRequest(
            $base_url . '/oauth/v1/generate?grant_type=client_credentials',
            'GET',
            [],
            ['Authorization: Basic ' . $credentials]
        );
        
        if (!$auth_response || !isset($auth_response['access_token'])) {
            throw new Exception('Failed to authenticate with M-Pesa API');
        }
        
        $access_token = $auth_response['access_token'];
        
        // STK Push request
        $timestamp = date('YmdHis');
        $password = base64_encode($shortcode . $passkey . $timestamp);
        
        $stk_data = [
            'BusinessShortCode' => $shortcode,
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => '254700000000', // This should be the customer's phone number
            'PartyB' => $shortcode,
            'PhoneNumber' => '254700000000', // Customer phone number
            'CallBackURL' => $config['callback_url'],
            'AccountReference' => 'LUIDIGITALS',
            'TransactionDesc' => $description
        ];
        
        $stk_response = $this->makeHttpRequest(
            $base_url . '/mpesa/stkpush/v1/processrequest',
            'POST',
            $stk_data,
            [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/json'
            ]
        );
        
        // Log payment transaction
        $this->logPaymentTransaction(
            $method_name,
            $stk_response['CheckoutRequestID'] ?? null,
            $amount,
            $currency,
            'pending',
            $stk_response,
            $transaction_id
        );
        
        return [
            'success' => isset($stk_response['CheckoutRequestID']),
            'checkout_request_id' => $stk_response['CheckoutRequestID'] ?? null,
            'response' => $stk_response
        ];
    }
    
    /**
     * Process Binance Pay payment
     */
    private function processBinancePayment($config, $amount, $currency, $description, $transaction_id) {
        $configuration = json_decode($config['configuration'], true);
        
        $api_key = $this->decrypt($configuration['api_key']);
        $api_secret = $this->decrypt($configuration['api_secret']);
        $merchant_id = $configuration['merchant_id'];
        $environment = $configuration['environment'] ?? 'sandbox';
        
        $base_url = $environment === 'production' 
            ? 'https://bpay.binanceapi.com' 
            : 'https://bpay.binanceapi.com'; // Binance doesn't have separate sandbox
        
        // Generate nonce and timestamp
        $nonce = uniqid();
        $timestamp = time() * 1000;
        
        $order_data = [
            'env' => [
                'terminalType' => 'WEB'
            ],
            'merchantTradeNo' => 'LUID_' . time() . '_' . $this->user_id,
            'orderAmount' => $amount,
            'currency' => $currency,
            'goods' => [
                'goodsType' => '01',
                'goodsCategory' => 'Z000',
                'referenceGoodsId' => 'WALLET_TOPUP',
                'goodsName' => $description
            ]
        ];
        
        $payload = json_encode($order_data);
        $signature = $this->generateBinanceSignature($payload, $api_secret, $nonce, $timestamp);
        
        $headers = [
            'Content-Type: application/json',
            'BinancePay-Timestamp: ' . $timestamp,
            'BinancePay-Nonce: ' . $nonce,
            'BinancePay-Certificate-SN: ' . $api_key,
            'BinancePay-Signature: ' . $signature
        ];
        
        $response = $this->makeHttpRequest(
            $base_url . '/binancepay/openapi/v2/order',
            'POST',
            $order_data,
            $headers
        );
        
        // Log payment transaction
        $this->logPaymentTransaction(
            $method_name,
            $response['data']['prepayId'] ?? null,
            $amount,
            $currency,
            'pending',
            $response,
            $transaction_id
        );
        
        return [
            'success' => $response['status'] === 'SUCCESS',
            'checkout_url' => $response['data']['checkoutUrl'] ?? null,
            'prepay_id' => $response['data']['prepayId'] ?? null,
            'response' => $response
        ];
    }
    
    /**
     * Log payment transaction
     */
    private function logPaymentTransaction($method, $external_id, $amount, $currency, $status, $response, $transaction_id = null) {
        $this->db->execute(
            "INSERT INTO payment_transactions 
             (user_id, transaction_id, payment_method, external_transaction_id, amount, currency, status, gateway_response) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $this->user_id,
                $transaction_id,
                $method,
                $external_id,
                $amount,
                $currency,
                $status,
                json_encode($response)
            ]
        );
    }
    
    /**
     * Make HTTP request
     */
    private function makeHttpRequest($url, $method, $data = [], $headers = []) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        
        if ($method === 'POST' && !empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false || $http_code >= 400) {
            throw new Exception("HTTP request failed: " . $response);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Generate callback URL
     */
    private function generateCallbackUrl($method) {
        return APP_URL . "/ajax/payment_callback.php?method={$method}&user_id={$this->user_id}";
    }
    
    /**
     * Generate webhook URL
     */
    private function generateWebhookUrl($method) {
        return APP_URL . "/ajax/payment_webhook.php?method={$method}&user_id={$this->user_id}";
    }
    
    /**
     * Generate Binance signature
     */
    private function generateBinanceSignature($payload, $secret, $nonce, $timestamp) {
        $payload_to_sign = $timestamp . "\n" . $nonce . "\n" . $payload . "\n";
        return strtoupper(hash_hmac('sha512', $payload_to_sign, $secret));
    }
    
    /**
     * Encrypt sensitive data
     */
    private function encrypt($data) {
        $key = hash('sha256', 'your_encryption_key_here'); // Use a proper key from config
        $iv = openssl_random_pseudo_bytes(16);
        $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }
    
    /**
     * Decrypt sensitive data
     */
    private function decrypt($data) {
        $key = hash('sha256', 'your_encryption_key_here'); // Use the same key
        $data = base64_decode($data);
        $iv = substr($data, 0, 16);
        $encrypted = substr($data, 16);
        return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
    }
    
    /**
     * Mask sensitive data for display
     */
    private function maskSensitiveData($data) {
        if (strlen($data) <= 8) {
            return str_repeat('*', strlen($data));
        }
        return substr($data, 0, 4) . str_repeat('*', strlen($data) - 8) . substr($data, -4);
    }
}
?>