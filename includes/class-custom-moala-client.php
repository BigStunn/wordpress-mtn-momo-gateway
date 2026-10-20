<?php
/**
 * CORRECT Moala API Client - Based on Official Documentation
 * 
 * For WooCommerce (collecting payments):
 * - Endpoint: POST /v1/api/transaction/payment
 * - transactionType: "deposit" (collect payment from customer)
 * - Service codes: PAIEMENTMARCHAND_MTN_CM or PAIEMENTMARCHAND_ORANGE_CM
 * 
 * Save as: wp-content/plugins/wc-mtn-momo-gateway/includes/class-custom-moala-client.php
 */

namespace WC_MTN_MoMo;

class Custom_Moala_Client {
    
    private $base_url;
    private $app_key;
    private $secret_key;
    private $logger;
    
    public function __construct($base_url, $app_key, $secret_key, $logger = null) {
        $this->base_url = rtrim($base_url, '/');
        $this->app_key = trim($app_key);
        $this->secret_key = trim($secret_key);
        $this->logger = $logger;
    }
    
    /**
     * Log message
     */
    private function log($message) {
        if ($this->logger && is_callable($this->logger)) {
            call_user_func($this->logger, $message);
        }
    }
    
    /**
     * Generate signature: HMAC-SHA256(timestamp+method+endpoint+payload)
     * Per official documentation
     */
    private function generate_signature($timestamp, $method, $endpoint, $payload = '') {
        $message = $timestamp . $method . $endpoint . $payload;
        $signature = hash_hmac('sha256', $message, $this->secret_key);
        
        $this->log("Signature components:");
        $this->log("  timestamp: $timestamp");
        $this->log("  method: $method");
        $this->log("  endpoint: $endpoint");
        $this->log("  payload length: " . strlen($payload));
        $this->log("  signature: " . substr($signature, 0, 20) . "...");
        
        return $signature;
    }
    
    /**
     * Make authenticated API request
     */
    private function request($method, $endpoint, $data = null) {
        $url = $this->base_url . $endpoint;
        $timestamp = time();
        
        // Prepare body
        $payload = '';
        if ($data && ($method === 'POST' || $method === 'PUT')) {
            $payload = json_encode($data);
        }
        
        // Generate signature
        $signature = $this->generate_signature($timestamp, $method, $endpoint, $payload);
        
        $this->log("=== Moala API Request ===");
        $this->log("URL: $url");
        $this->log("Method: $method");
        
        // Build headers per documentation
        $headers = array(
            'Content-Type' => 'application/json',
            'LP-ACCESS-KEY' => $this->app_key,
            'LP-ACCESS-TIMESTAMP' => (string)$timestamp,
            'LP-ACCESS-SIGN' => $signature,
        );
        
        $args = array(
            'method' => $method,
            'headers' => $headers,
            'timeout' => 45,
            'sslverify' => true,
        );
        
        if ($payload) {
            $args['body'] = $payload;
            $this->log("Request body: " . $payload);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            $error_msg = 'API Request Failed: ' . $response->get_error_message();
            $this->log("ERROR: " . $error_msg);
            throw new \Exception($error_msg);
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $this->log("Response code: $code");
        $this->log("Response body: " . substr($body, 0, 500));
        
        $result = json_decode($body, true);
        
        if ($code >= 400) {
            $error_msg = isset($result['message']) ? $result['message'] : $body;
            $full_error = "API Error ($code): " . $error_msg;
            $this->log("ERROR: " . $full_error);
            throw new \Exception($full_error);
        }
        
        $this->log("✅ Request successful!");
        return $result ? $result : array('success' => true, 'response' => $body);
    }
    
    /**
     * Check account balance
     */
    public function balance() {
        return $this->request('GET', '/v1/api/balance');
    }
    
    /**
     * KYC Check
     * Phone number should be 9 digits only (e.g., 681645585)
     * DO NOT include country code 237
     */
    public function kyc($phone_number, $service_code) {
        return $this->request('GET', "/v1/api/kyc/{$service_code}/{$phone_number}");
    }
    
    /**
     * Check transaction status
     */
    public function check_transaction($partner_id) {
        return $this->request('GET', "/v1/api/transaction/check/{$partner_id}");
    }
    
    /**
     * COLLECT PAYMENT from customer (WooCommerce use case)
     * Per Moala support: Use transactionType "deposit" with PAIEMENTMARCHAND_MTN_CM or PAIEMENTMARCHAND_ORANGE_CM
     * 
     * Uses:
     * - Endpoint: POST /v1/api/transaction/payment
     * - transactionType: "deposit"
     * - Service code: PAIEMENTMARCHAND_MTN_CM or PAIEMENTMARCHAND_ORANGE_CM
     */
    public function cashin($phone_number, $service_code, $amount, $partner_id) {
        $this->log("=== COLLECT PAYMENT FROM CUSTOMER ===");
        $this->log("Phone: $phone_number");
        $this->log("Service: $service_code");
        $this->log("Amount: $amount");
        $this->log("Partner ID: $partner_id");
        
        // Build request body EXACTLY as Moala support example
        $data = array(
            'transactionType' => 'deposit',  // Per Moala support confirmation
            'amount' => (string)$amount,     // String format as per example
            'phoneNumber' => $phone_number,
            'serviceCode' => $service_code,
            'partnerId' => $partner_id,
        );
        
        // Use the /payment endpoint
        return $this->request('POST', '/v1/api/transaction/payment', $data);
    }
    
    /**
     * SEND MONEY TO a phone number (admin withdrawal / cashout)
     * Per Moala "Deposit via API" docs (depot-argent-java):
     *   - Endpoint:        POST /v1/api/transaction/withdrawal   ← different from cashin
     *   - transactionType: "deposit"
     *   - Service codes:   CASHIN_MTN_CM  or  CASHIN_ORANGE_CM   ← different from cashin
     *
     * NOTE: Moala's naming is from the CUSTOMER perspective.
     *   "Deposit" to them = money deposited INTO the customer's wallet = YOU sending money out.
     *
     * @param string $phone_number  9 digits, e.g. 678817640
     * @param string $service_code  CASHIN_MTN_CM or CASHIN_ORANGE_CM
     * @param float  $amount        Amount to send
     * @param string $partner_id    Unique ID for this transaction (must never repeat)
     */
    public function cashout($phone_number, $service_code, $amount, $partner_id) {
        $this->log("=== SEND MONEY TO PHONE (CASHOUT) ===");
        $this->log("Phone: $phone_number");
        $this->log("Service: $service_code");
        $this->log("Amount: $amount");
        $this->log("Partner ID: $partner_id");
        
        $data = array(
            'transactionType' => 'deposit',      // Per Moala docs: "deposit" = money INTO customer wallet
            'amount' => (string)$amount,
            'phoneNumber' => $phone_number,
            'serviceCode' => $service_code,      // Caller must pass CASHIN_MTN_CM or CASHIN_ORANGE_CM
            'partnerId' => $partner_id,
        );
        
        // Endpoint is /withdrawal per Moala docs, NOT /payment
        return $this->request('POST', '/v1/api/transaction/withdrawal', $data);
    }
}