<?php
/**
 * Plugin Name: WooCommerce Mobile Money Payment Gateway (Cameroon)
 * Plugin URI: https://teejayacademics.com
 * Description: Accept MTN Mobile Money and Orange Money payments in WooCommerce using Moala API for Cameroon
 * Version: 2.0.1
 * Author: Big Stunnz Tech
 * Author URI: https://teejayacademics.com
 * Text Domain: wc-mobile-money
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 3.0
 * WC tested up to: 8.5
 * License: GPL-3.0+
 * License URI: http://www.gnu.org/licenses/gpl-3.0.txt
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

define('WC_MOBILE_MONEY_VERSION', '2.0.1');
define('WC_MOBILE_MONEY_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WC_MOBILE_MONEY_PLUGIN_URL', plugin_dir_url(__FILE__));

if (file_exists(WC_MOBILE_MONEY_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once WC_MOBILE_MONEY_PLUGIN_DIR . 'vendor/autoload.php';
}

if (file_exists(WC_MOBILE_MONEY_PLUGIN_DIR . 'includes/class-custom-moala-client.php')) {
    require_once WC_MOBILE_MONEY_PLUGIN_DIR . 'includes/class-custom-moala-client.php';
}

add_action('plugins_loaded', 'wc_mobile_money_init_gateways');

function wc_mobile_money_init_gateways() {
    
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    // ============================================
    // BASE CLASS - Shared functionality
    // ============================================
    class WC_Mobile_Money_Gateway_Base extends WC_Payment_Gateway {

        protected $testmode;
        protected $app_key;
        protected $secret_key;
        protected $api_url;
        protected $service_code;
        protected $use_custom_client;
        protected $skip_kyc;
        protected $auto_complete;
        protected $debug;
        protected $provider_name; // "MTN" or "Orange"

        protected function init_gateway_settings($gateway_id, $provider_name, $default_service_code) {
            $this->id = $gateway_id;
            $this->provider_name = $provider_name;
            $this->has_fields = true;
            $this->supports = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->app_key = $this->testmode ? $this->get_option('test_app_key') : $this->get_option('live_app_key');
            $this->secret_key = $this->testmode ? $this->get_option('test_secret_key') : $this->get_option('live_secret_key');
            $this->api_url = $this->get_option('api_url', 'https://api.moala.africa');
            $this->service_code = $this->get_option('service_code', $default_service_code);
            $this->use_custom_client = 'yes' === $this->get_option('use_custom_client', 'yes');
            $this->skip_kyc = 'yes' === $this->get_option('skip_kyc');
            $this->auto_complete = 'yes' === $this->get_option('auto_complete');
            $this->debug = 'yes' === $this->get_option('debug');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-mobile-money'),
                    'type' => 'checkbox',
                    'label' => sprintf(__('Enable %s Mobile Money Payment', 'wc-mobile-money'), $this->provider_name),
                    'default' => 'no'
                ),
                'title' => array(
                    'title' => __('Title', 'wc-mobile-money'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'wc-mobile-money'),
                    'default' => sprintf(__('%s Mobile Money', 'wc-mobile-money'), $this->provider_name),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'wc-mobile-money'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see during checkout.', 'wc-mobile-money'),
                    'default' => sprintf(__('Pay securely using %s Mobile Money.', 'wc-mobile-money'), $this->provider_name),
                    'desc_tip' => true,
                ),
                'testmode' => array(
                    'title' => __('Test mode', 'wc-mobile-money'),
                    'type' => 'checkbox',
                    'label' => __('Enable Test Mode (use test credentials)', 'wc-mobile-money'),
                    'default' => 'no',
                ),
                'api_url' => array(
                    'title' => __('API URL', 'wc-mobile-money'),
                    'type' => 'text',
                    'default' => 'https://api.moala.africa',
                    'desc_tip' => true,
                ),
                'test_app_key' => array(
                    'title' => __('Test App Key', 'wc-mobile-money'),
                    'type' => 'text',
                    'default' => '',
                ),
                'test_secret_key' => array(
                    'title' => __('Test Secret Key', 'wc-mobile-money'),
                    'type' => 'password',
                    'default' => '',
                ),
                'live_app_key' => array(
                    'title' => __('Live App Key', 'wc-mobile-money'),
                    'type' => 'text',
                    'default' => '',
                ),
                'live_secret_key' => array(
                    'title' => __('Live Secret Key', 'wc-mobile-money'),
                    'type' => 'password',
                    'default' => '',
                ),
                'service_code' => array(
                    'title' => __('Service Code', 'wc-mobile-money'),
                    'type' => 'text',
                    'default' => $this->id === 'mtn_momo' ? 'PAIEMENTMARCHAND_MTN_CM' : 'PAIEMENTMARCHAND_ORANGE_CM',
                    'custom_attributes' => array('readonly' => 'readonly'),
                ),
                'use_custom_client' => array(
                    'title' => __('Use Custom API Client', 'wc-mobile-money'),
                    'type' => 'checkbox',
                    'label' => __('Use custom Moala API client (REQUIRED)', 'wc-mobile-money'),
                    'default' => 'yes',
                ),
                'skip_kyc' => array(
                    'title' => __('Skip KYC Check', 'wc-mobile-money'),
                    'type' => 'checkbox',
                    'label' => __('Skip KYC verification', 'wc-mobile-money'),
                    'default' => 'yes',
                ),
                'auto_complete' => array(
                    'title' => __('Auto Complete Orders', 'wc-mobile-money'),
                    'type' => 'checkbox',
                    'label' => __('Auto-complete orders after payment', 'wc-mobile-money'),
                    'default' => 'yes',
                ),
                'webhook_url_info' => array(
                    'title' => __('Webhook URL', 'wc-mobile-money'),
                    'type' => 'title',
                    'description' => '<strong>' . home_url('/wc-api/wc_mobile_money_webhook') . '</strong><br><em style="color:#666;">Note: Same webhook URL is used for both MTN and Orange Money payments.</em>',
                ),
                'debug' => array(
                    'title' => __('Debug Log', 'wc-mobile-money'),
                    'type' => 'checkbox',
                    'label' => __('Enable logging', 'wc-mobile-money'),
                    'default' => 'yes',
                    'description' => sprintf(__('Log events to %s', 'wc-mobile-money'), '<code>WooCommerce > Status > Logs</code>'),
                ),
            );
        }

        public function payment_fields() {
            if ($this->description) {
                echo wpautop(wp_kses_post($this->description));
            }
            ?>
            <fieldset id="wc-<?php echo esc_attr($this->id); ?>-form" class="wc-payment-form">
                <p class="form-row form-row-wide">
                    <label for="<?php echo esc_attr($this->id); ?>_phone">
                        <?php printf(__('%s Phone Number', 'wc-mobile-money'), $this->provider_name); ?> 
                        <span class="required">*</span>
                    </label>
                    <input 
                        id="<?php echo esc_attr($this->id); ?>_phone" 
                        name="<?php echo esc_attr($this->id); ?>_phone" 
                        type="tel" 
                        placeholder="6XXXXXXXX (9 digits)" 
                        maxlength="9" 
                        pattern="[0-9]{9}" 
                        autocomplete="tel" 
                        required 
                        style="width:100%; padding:10px; font-size:16px;"
                    >
                    <small style="color:#666;"><?php _e('Enter 9 digits only (e.g., 678817640)', 'wc-mobile-money'); ?></small>
                </p>
            </fieldset>
            <?php
        }

        public function validate_fields() {
            $phone = isset($_POST[$this->id . '_phone']) ? sanitize_text_field($_POST[$this->id . '_phone']) : '';
            
            if (empty($phone)) {
                wc_add_notice(sprintf(__('%s phone number is required.', 'wc-mobile-money'), $this->provider_name), 'error');
                return false;
            }
            
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            if (strlen($phone) !== 9) {
                wc_add_notice(__('Phone number must be exactly 9 digits.', 'wc-mobile-money'), 'error');
                return false;
            }
            
            if (!preg_match('/^[0-9]{9}$/', $phone)) {
                wc_add_notice(__('Phone number must contain only digits.', 'wc-mobile-money'), 'error');
                return false;
            }
            
            return true;
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);
            $phone = isset($_POST[$this->id . '_phone']) ? sanitize_text_field($_POST[$this->id . '_phone']) : '';
            $phone = preg_replace('/[^0-9]/', '', $phone);
            
            $this->log("=== PROCESSING {$this->provider_name} PAYMENT ===");
            $this->log("Order ID: $order_id");
            $this->log("Phone: $phone");
            $this->log("Amount: " . $order->get_total());
            
            try {
                $client = $this->get_moala_client();
                
                if (!$this->skip_kyc) {
                    try {
                        $kyc = $client->kyc($phone, $this->service_code);
                        $this->log("KYC OK: " . print_r($kyc, true));
                    } catch (Exception $e) {
                        $this->log("KYC SKIPPED: " . $e->getMessage());
                    }
                }
                
                // CRITICAL: Partner ID includes gateway ID so webhook can identify which gateway to use
                $partner_id = $this->id . '_' . $order_id . '_' . time();
                
                $result = $client->cashin(
                    $phone,
                    $this->service_code,
                    $order->get_total(),
                    $partner_id
                );
                
                $this->log("Payment initiated: " . print_r($result, true));
                
                $order->update_status('pending', sprintf(__('Awaiting %s Mobile Money payment', 'wc-mobile-money'), $this->provider_name));
                
                // Store ALL partner IDs in a unified meta key for webhook lookup
                $order->update_meta_data('_mobile_money_partner_id', $partner_id); // Unified
                $order->update_meta_data('_mobile_money_gateway_id', $this->id);    // Track which gateway
                $order->update_meta_data('_mobile_money_phone_number', $phone);
                $order->update_meta_data('_mobile_money_service_code', $this->service_code);
                
                // Also store gateway-specific meta (for backward compatibility)
                $order->update_meta_data('_' . $this->id . '_partner_id', $partner_id);
                $order->update_meta_data('_' . $this->id . '_phone_number', $phone);
                $order->update_meta_data('_' . $this->id . '_service_code', $this->service_code);
                
                if (isset($result['transactionId'])) {
                    $order->update_meta_data('_' . $this->id . '_transaction_id', $result['transactionId']);
                }
                
                $order->save();
                
                WC()->cart->empty_cart();
                
                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order)
                );
                
            } catch (Exception $e) {
                $error_message = $e->getMessage();
                $this->log("ERROR: " . $error_message);
                wc_add_notice(sprintf(__('Payment error: %s', 'wc-mobile-money'), $error_message), 'error');
                return array('result' => 'fail');
            }
        }

        public function thankyou_page($order_id) {
            $order = wc_get_order($order_id);
            if (!$order || $order->get_payment_method() !== $this->id) return;

            // Force check DB directly - CRITICAL for cache bypass
            wp_cache_flush();
            global $wpdb;
            $db_status = $wpdb->get_var($wpdb->prepare("SELECT post_status FROM {$wpdb->posts} WHERE ID = %d", $order_id));
            
            $this->log('Thank You Page - Order #' . $order_id . ' | WC Status: ' . $order->get_status() . ' | DB Status: ' . $db_status);
            
            // If completed, show success with Tutor LMS integration
            if ($db_status === 'wc-completed' || $db_status === 'wc-processing' || $order->get_status() === 'completed' || $order->get_status() === 'processing') {
                ?>
                <style>.woocommerce-order-overview,.woocommerce-order-details,.woocommerce-thankyou-order-received,.woocommerce-info{display:none!important;}</style>
                <div style="max-width:600px;margin:40px auto;background:#fff;border-radius:10px;box-shadow:0 4px 12px rgba(0,0,0,0.15);">
                    <div style="background:linear-gradient(135deg,#28a745 0%,#20c997 100%);padding:40px;text-align:center;border-radius:10px 10px 0 0;">
                        <div style="font-size:64px;margin-bottom:10px;">✅</div>
                        <h1 style="color:white;margin:0;font-size:32px;font-weight:bold;">Payment Confirmed!</h1>
                    </div>
                    <div style="padding:40px;text-align:center;">
                        <p style="font-size:20px;color:#333;margin:0 0 15px 0;">Your <?php echo esc_html($this->provider_name); ?> Mobile Money payment has been successfully verified.</p>
                        <div style="background:#f8f9fa;padding:20px;border-radius:8px;margin:25px 0;">
                            <p style="font-size:16px;color:#666;margin:0 0 10px 0;"><strong>Order #<?php echo $order_id; ?></strong></p>
                            <p style="font-size:18px;color:#28a745;margin:0;font-weight:bold;">Amount: <?php echo $order->get_formatted_order_total(); ?></p>
                        </div>
                        <p style="font-size:18px;color:#28a745;margin:20px 0;font-weight:bold;">🎓 Your course has been unlocked!</p>
                        <p style="font-size:16px;color:#666;margin:0 0 30px 0;">Check your dashboard to access your course and start learning.</p>
                        <?php if (class_exists('TUTOR\\Tutor')) : ?>
                        <a href="<?php echo esc_url(get_permalink(tutor_utils()->get_option('student_my_dashboard'))); ?>" style="display:inline-block;background:#28a745;color:white;padding:16px 40px;font-size:18px;font-weight:bold;text-decoration:none;border-radius:8px;box-shadow:0 4px 8px rgba(40,167,69,0.3);">📚 Go to My Dashboard →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                return;
            }

            // If pending OR draft (draft happens when page loads before process_payment saves status), check API once then auto-refresh
            if ($order->get_status() === 'pending' || $order->get_status() === 'draft' || $db_status === 'draft') {
                try {
                    // Get partner ID from unified meta key
                    $partner_id = $order->get_meta('_mobile_money_partner_id');
                    
                    // Fallback to gateway-specific meta key (backward compatibility)
                    if (empty($partner_id)) {
                        $partner_id = $order->get_meta('_' . $this->id . '_partner_id');
                    }
                    
                    if ($partner_id) {
                        $this->log('Checking transaction status via API: ' . $partner_id);
                        $client = $this->get_moala_client();
                        $result = $client->check_transaction($partner_id);
                        
                        // Check both possible status fields
                        $status = isset($result['status']) ? strtoupper(trim($result['status'])) : (isset($result['transactionStatus']) ? strtoupper(trim($result['transactionStatus'])) : null);
                        
                        $this->log('API returned status: ' . $status);
                        
                        if ($status && in_array($status, array('SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'RECEIVED'))) {
                            $this->log('Payment confirmed via API check - completing order');
                            $this->complete_order($order, $result);
                            
                            // Redirect to refresh the page and show success message
                            wp_redirect(add_query_arg('r', time(), $this->get_return_url($order)));
                            exit;
                        }
                    }
                } catch (Exception $e) {
                    $this->log('API check failed: ' . $e->getMessage());
                }
                
                ?>
                <style>.woocommerce-order-overview,.woocommerce-order-details,.woocommerce-thankyou-order-received,.woocommerce-info{display:none!important;}</style>
                <div style="max-width:600px;margin:40px auto;background:#fff3cd;border:2px solid #ffc107;border-radius:10px;padding:40px;text-align:center;">
                    <div style="font-size:48px;margin-bottom:20px;">⏳</div>
                    <h2 style="color:#856404;margin:0 0 15px 0;">Waiting for Payment Confirmation</h2>
                    <p style="font-size:16px;color:#856404;margin:0 0 20px 0;">Please complete the payment on your <?php echo esc_html($this->provider_name); ?> Mobile Money phone.</p>
                    <p style="font-size:14px;color:#856404;margin:0;"><span id="timer">Refreshing in 5 seconds...</span></p>
                </div>
                <script>
                var sec=0;setInterval(function(){sec+=5;if(sec>=600){document.getElementById('timer').innerHTML='<span style="color:#dc3545">Timed out. Contact support if you paid. Order #<?php echo $order_id; ?></span>';return;}var m=Math.floor(sec/60),s=sec%60;document.getElementById('timer').textContent='Refreshing in 5s... ('+m+'m '+s+'s elapsed)';},5000);
                setTimeout(function(){location.reload();},5000);
                </script>
                <?php
                return;
            }

            // If failed
            if ($order->get_status() === 'failed') {
                ?>
                <style>.woocommerce-order-overview,.woocommerce-order-details,.woocommerce-thankyou-order-received,.woocommerce-info{display:none!important;}</style>
                <div style="max-width:600px;margin:40px auto;background:#f8d7da;border:2px solid #dc3545;border-radius:10px;padding:40px;text-align:center;">
                    <div style="font-size:48px;margin-bottom:20px;">❌</div>
                    <h2 style="color:#721c24;margin:0 0 15px 0;">Payment Failed</h2>
                    <p style="font-size:16px;color:#721c24;margin:0 0 25px 0;">The payment was not successful. Please try again.</p>
                    <a href="<?php echo esc_url($order->get_checkout_payment_url()); ?>" style="display:inline-block;background:#dc3545;color:white;padding:14px 30px;font-size:16px;font-weight:bold;text-decoration:none;border-radius:6px;">🔄 Try Again</a>
                </div>
                <?php
            }
        }

        protected function complete_order($order, $data = array()) {
            if ($order->has_status(array('processing', 'completed'))) return;
            
            $this->log('Completing order #' . $order->get_id());
            $order->payment_complete();
            $order->add_order_note('✅ Payment confirmed via ' . $this->provider_name . ' Mobile Money');
            
            if ($this->auto_complete) {
                $order->update_status('completed');
            }
            
            $order->save();
        }

        protected function get_moala_client() {
            if ($this->use_custom_client && class_exists('WC_MTN_MoMo\Custom_Moala_Client')) {
                return new \WC_MTN_MoMo\Custom_Moala_Client($this->api_url, $this->app_key, $this->secret_key, array($this, 'log'));
            }
            throw new Exception('Moala client not available');
        }

        public function log($message) {
            if ($this->debug) {
                if (empty($this->logger)) $this->logger = wc_get_logger();
                $this->logger->info($message, array('source' => 'mobile-money-' . $this->id));
            }
        }
    }

    // ============================================
    // MTN MOBILE MONEY GATEWAY
    // ============================================
    class WC_MTN_MoMo_Gateway extends WC_Mobile_Money_Gateway_Base {

        public function __construct() {
            $this->init_gateway_settings('mtn_momo', 'MTN', 'PAIEMENTMARCHAND_MTN_CM');
            $this->method_title = __('MTN Mobile Money', 'wc-mobile-money');
            $this->method_description = __('Accept payments via MTN Mobile Money using Moala API (Cameroon)', 'wc-mobile-money');
            $this->icon = apply_filters('wc_mtn_momo_icon', WC_MOBILE_MONEY_PLUGIN_URL . 'assets/mtn-momo-logo.png');
        }
    }

    // ============================================
    // ORANGE MONEY GATEWAY
    // ============================================
    class WC_Orange_Money_Gateway extends WC_Mobile_Money_Gateway_Base {

        public function __construct() {
            $this->init_gateway_settings('orange_money', 'Orange', 'PAIEMENTMARCHAND_ORANGE_CM');
            $this->method_title = __('Orange Money', 'wc-mobile-money');
            $this->method_description = __('Accept payments via Orange Money using Moala API (Cameroon)', 'wc-mobile-money');
            $this->icon = apply_filters('wc_orange_money_icon', WC_MOBILE_MONEY_PLUGIN_URL . 'assets/orange-money-logo.png');
        }
    }
}

// Register both payment gateways
add_filter('woocommerce_payment_gateways', function($methods) {
    $methods[] = 'WC_MTN_MoMo_Gateway';
    $methods[] = 'WC_Orange_Money_Gateway';
    return $methods;
});

// ============================================
// UNIFIED WEBHOOK HANDLER (ONE URL FOR BOTH)
// ============================================
add_action('woocommerce_api_wc_mobile_money_webhook', 'wc_mobile_money_unified_webhook_handler');

function wc_mobile_money_unified_webhook_handler() {
    $logger = wc_get_logger();
    $logger->info('=== UNIFIED MOBILE MONEY WEBHOOK RECEIVED ===', array('source' => 'mobile-money-webhook'));
    
    $raw = file_get_contents('php://input');
    $logger->info('Raw: ' . $raw, array('source' => 'mobile-money-webhook'));
    
    $data = json_decode($raw, true);
    if (!$data && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = $_GET;
    }
    
    $logger->info('Data: ' . print_r($data, true), array('source' => 'mobile-money-webhook'));
    
    if (empty($data)) {
        status_header(200);
        exit;
    }
    
    $partner_id = isset($data['partnerId']) ? $data['partnerId'] : (isset($data['partner_id']) ? $data['partner_id'] : null);
    
    if (!$partner_id) {
        $logger->info('No partnerId in webhook', array('source' => 'mobile-money-webhook'));
        status_header(400);
        exit;
    }
    
    // SMART LOOKUP: Search using unified meta key (works for both MTN and Orange)
    $orders = wc_get_orders(array(
        'meta_key' => '_mobile_money_partner_id',
        'meta_value' => $partner_id,
        'limit' => 1
    ));
    
    if (empty($orders)) {
        $logger->info('Order not found for partnerId: ' . $partner_id, array('source' => 'mobile-money-webhook'));
        status_header(404);
        exit;
    }
    
    $order = $orders[0];
    $gateway_id = $order->get_meta('_mobile_money_gateway_id', true);
    $status = isset($data['status']) ? strtoupper(trim($data['status'])) : null;
    $message = isset($data['message']) ? $data['message'] : '';
    
    $logger->info("Order: {$order->get_id()}, Gateway: {$gateway_id}, Status: {$status}", array('source' => 'mobile-money-webhook'));
    
    $order->add_order_note(sprintf('Webhook: Gateway=%s, Status=%s, Message=%s', $gateway_id, $status, $message));
    
    if (in_array($status, array('SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'PAID', 'OK', 'RECEIVED'))) {
        $logger->info('SUCCESS - Completing order', array('source' => 'mobile-money-webhook'));
        
        // Complete the order
        if (!$order->has_status(array('processing', 'completed'))) {
            $order->payment_complete();
            $provider_name = ($gateway_id === 'mtn_momo') ? 'MTN' : 'Orange';
            $order->add_order_note('✅ Payment confirmed via ' . $provider_name . ' Mobile Money');
            
            // Get auto_complete setting from the appropriate gateway
            $gateway = ($gateway_id === 'mtn_momo') ? new WC_MTN_MoMo_Gateway() : new WC_Orange_Money_Gateway();
            if ('yes' === $gateway->get_option('auto_complete')) {
                $order->update_status('completed');
            }
            
            $order->save();
        }
        
        status_header(200);
        echo json_encode(array('success' => true));
        exit;
        
    } elseif (in_array($status, array('FAIL', 'FAILED', 'ERROR', 'CANCELLED', 'REJECTED'))) {
        $logger->info('FAILED - Marking as failed', array('source' => 'mobile-money-webhook'));
        $order->update_status('failed', sprintf(__('Payment failed: %s', 'wc-mobile-money'), $message));
        status_header(200);
        echo json_encode(array('success' => true));
        exit;
    }
    
    status_header(200);
    echo json_encode(array('success' => true));
    exit;
}

// Cron job to verify pending transactions for BOTH gateways
add_action('wc_mobile_money_verify_transactions', function() {
    $logger = wc_get_logger();
    
    // Get all pending mobile money orders (both MTN and Orange)
    $orders = wc_get_orders(array(
        'status' => 'pending',
        'limit' => 50,
        'date_created' => '>' . (time() - DAY_IN_SECONDS),
        'meta_key' => '_mobile_money_partner_id', // Only orders with mobile money partner ID
        'meta_compare' => 'EXISTS'
    ));
    
    foreach ($orders as $order) {
        $partner_id = $order->get_meta('_mobile_money_partner_id', true);
        $gateway_id = $order->get_meta('_mobile_money_gateway_id', true);
        
        if (empty($partner_id) || empty($gateway_id)) continue;
        
        try {
            // Instantiate the appropriate gateway
            $gateway = ($gateway_id === 'mtn_momo') ? new WC_MTN_MoMo_Gateway() : new WC_Orange_Money_Gateway();
            
            $method = new ReflectionMethod($gateway, 'get_moala_client');
            $method->setAccessible(true);
            $client = $method->invoke($gateway);
            
            $result = $client->check_transaction($partner_id);
            
            if (isset($result['status'])) {
                $status = strtoupper(trim($result['status']));
                if (in_array($status, array('SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'RECEIVED'))) {
                    $complete = new ReflectionMethod($gateway, 'complete_order');
                    $complete->setAccessible(true);
                    $complete->invoke($gateway, $order, $result);
                } elseif (in_array($status, array('FAIL', 'FAILED', 'ERROR'))) {
                    $order->update_status('failed');
                }
            } elseif (isset($result['transactionStatus'])) {
                $status = strtoupper(trim($result['transactionStatus']));
                if (in_array($status, array('SUCCESS', 'SUCCESSFUL', 'COMPLETED', 'RECEIVED'))) {
                    $complete = new ReflectionMethod($gateway, 'complete_order');
                    $complete->setAccessible(true);
                    $complete->invoke($gateway, $order, $result);
                } elseif (in_array($status, array('FAIL', 'FAILED', 'ERROR'))) {
                    $order->update_status('failed');
                }
            }
        } catch (Exception $e) {
            $logger->error('Cron verification failed: ' . $e->getMessage(), array('source' => 'mobile-money-cron'));
        }
    }
});

register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('wc_mobile_money_verify_transactions')) {
        wp_schedule_event(time(), 'every_5_minutes', 'wc_mobile_money_verify_transactions');
    }
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('wc_mobile_money_verify_transactions');
});

add_filter('cron_schedules', function($schedules) {
    $schedules['every_5_minutes'] = array('interval' => 300, 'display' => 'Every 5 Minutes');
    return $schedules;
});


// ============================================
// ADMIN WITHDRAWAL SYSTEM
// Everything below is the admin-only cashout.
// Nothing above this line has been changed.
// ============================================

// --- 1. Create the DB table on activation ---
add_action('plugins_loaded', function() {
    global $wpdb;
    $table = $wpdb->prefix . 'moala_withdrawals';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$table} (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                partner_id    VARCHAR(100)    NOT NULL UNIQUE,
                provider      VARCHAR(10)     NOT NULL,
                phone         VARCHAR(20)     NOT NULL,
                amount        DECIMAL(12,2)   NOT NULL,
                status        VARCHAR(30)     NOT NULL DEFAULT 'pending',
                api_response  TEXT,
                initiated_by  BIGINT UNSIGNED NOT NULL,
                created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY   (id),
                KEY idx_status (status),
                KEY idx_partner (partner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");
    }
}, 999);

// --- 2. Register the admin page ---
add_action('admin_menu', function() {
    add_submenu_page(
        'woocommerce',                                          // parent slug
        'Mobile Money Withdrawal Form',                         // page title
        'Mobile Money Withdrawal Form',                         // menu title (breadcrumb)
        'manage_options',                                       // capability — Administrator only
        'wc_mobile_money_withdraw',                             // menu slug
        'wc_mobile_money_render_withdrawal_page'                // callback
    );
});

// --- 3. Enqueue nonce for AJAX ---
add_action('admin_enqueue_scripts', function($hook) {
    if ($hook !== 'woocommerce_page_wc_mobile_money_withdraw') return;
    wp_localize_script('jquery', 'wc_mm_withdraw', array(
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('wc_mm_do_withdrawal'),
    ));
});

// --- 4. Render the admin page ---
function wc_mobile_money_render_withdrawal_page() {
    if (!current_user_can('manage_options')) {
        wp_die('You do not have permission to access this page.');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'moala_withdrawals';
    
    // Check if user is already authenticated (session active)
    $is_authenticated = get_transient('wc_mm_authenticated_' . get_current_user_id());
    
    // CRITICAL: If page is loaded WITHOUT the 'stay_authenticated' parameter,
    // it means user clicked the menu link (fresh load) → Always show auth screen
    // This ensures clicking "Mobile Money Withdrawal Form" always goes back to security page
    if (!isset($_GET['stay_authenticated'])) {
        // Fresh page load → Clear session → Show auth screen
        delete_transient('wc_mm_authenticated_' . get_current_user_id());
        $is_authenticated = false;
    }
    
    // Auto-cleanup: keep only the last 5 records, delete everything older
    // Only run cleanup if table exists and we have more than 5 records
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    
    if ($table_exists) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 5) {
            $keep_ids = $wpdb->get_col("SELECT id FROM {$table} ORDER BY created_at DESC LIMIT 5");
            if (!empty($keep_ids)) {
                $ids_str = implode(',', array_map('intval', $keep_ids));
                $wpdb->query("DELETE FROM {$table} WHERE id NOT IN ({$ids_str})");
            }
        }
        
        // Get the last 5 withdrawal records for display
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 5");
    } else {
        // Table doesn't exist yet - will be created on first withdrawal
        $rows = array();
    }
    
    $show_auth_layer = !$is_authenticated; // Show auth only if NOT authenticated
    $show_withdrawal_layer = $is_authenticated; // Show withdrawal form if authenticated
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:12px;">
            💸 Mobile Money Withdrawal Form
        </h1>

        <!-- ===== SECURITY AUTHENTICATION LAYER (shown first if not authenticated) ===== -->
        <div id="wc-mm-auth-layer" style="display:<?php echo $show_auth_layer ? 'block' : 'none'; ?>;">
            <div class="postbox" style="max-width:600px;">
                <div class="postbox-header" style="position:relative;">
                    <h2 class="hnw" style="margin:0;padding:12px 16px;">🔒 Security Authentication Required</h2>
                    <a href="<?php echo admin_url('admin.php?page=wc-admin'); ?>" 
                       style="position:absolute;top:10px;right:10px;text-decoration:none;font-size:24px;color:#666;line-height:1;"
                       title="Close and return to dashboard">×</a>
                </div>
                <div style="padding:30px;">
                    
                    <!-- Status notice banner -->
                    <div id="wc-mm-auth-notice" style="display:none;padding:14px 18px;border-radius:6px;margin-bottom:24px;font-size:15px;font-weight:600;"></div>

                    <p style="font-size:15px;color:#555;margin:0 0 24px 0;">
                        For security, a 6-digit code will be sent to your authorized email. 
                        Please select your email and request a code to proceed.
                    </p>

                    <table class="form-table" style="width:100%;">
                        <tr>
                            <th style="width:140px;padding:10px 0;vertical-align:top;">
                                <label for="wc-mm-auth-email">Your Email</label>
                            </th>
                            <td style="padding:10px 0;">
                                <select id="wc-mm-auth-email" style="width:100%;max-width:420px;padding:10px;font-size:14px;">
                                    <option value="godsontabindok@gmail.com">godsontabindok@gmail.com</option>
                                    <option value="djouelahyvette@gmail.com">djouelahyvette@gmail.com</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th style="vertical-align:top;padding-top:10px;">
                                <label>Request Code</label>
                            </th>
                            <td style="padding:10px 0;">
                                <button id="wc-mm-send-code-btn" class="button button-secondary" style="padding:10px 24px;font-size:15px;">
                                    📧 Send Code
                                </button>
                                <span id="wc-mm-code-timer" style="margin-left:14px;color:#856404;font-weight:600;display:none;"></span>
                            </td>
                        </tr>
                        <tr id="wc-mm-code-input-row" style="display:none;">
                            <th style="vertical-align:top;padding-top:10px;">
                                <label for="wc-mm-code-input">Enter Code</label>
                            </th>
                            <td style="padding:10px 0;">
                                <input type="text"
                                       id="wc-mm-code-input"
                                       maxlength="6"
                                       placeholder="000000"
                                       style="width:160px;padding:10px;font-size:18px;letter-spacing:4px;text-align:center;font-weight:bold;">
                                <button id="wc-mm-verify-code-btn" class="button button-primary" style="padding:10px 24px;font-size:15px;margin-left:12px;">
                                    🔓 Verify Code
                                </button>
                            </td>
                        </tr>
                    </table>

                </div>
            </div>
        </div>

        <!-- ===== WITHDRAWAL FORM (shown if authenticated, or after auth success) ===== -->
        <div id="wc-mm-withdrawal-layer" style="display:<?php echo $show_withdrawal_layer ? 'block' : 'none'; ?>;">
            <div class="postbox" style="max-width:680px;">
                <div class="postbox-header" style="position:relative;">
                    <h2 class="hnw" style="margin:0;padding:12px 16px;">New Withdrawal</h2>
                    <a href="<?php echo admin_url('admin.php?page=wc-admin'); ?>" 
                       style="position:absolute;top:10px;right:10px;text-decoration:none;font-size:24px;color:#666;line-height:1;"
                       title="Close and return to dashboard">×</a>
                </div>
                <div style="padding:24px;">

                    <!-- live feedback banner, hidden by default -->
                    <div id="wc-mm-notice" style="display:none;padding:14px 18px;border-radius:6px;margin-bottom:20px;font-size:15px;"></div>

                    <table class="form-table" style="width:100%;">
                        <tr>
                            <th style="width:160px;padding:10px 0;vertical-align:top;">
                                <label for="wc-mm-provider">Provider</label>
                            </th>
                            <td style="padding:10px 0;">
                                <select id="wc-mm-provider" style="width:100%;max-width:340px;padding:8px;">
                                    <option value="mtn">MTN Mobile Money</option>
                                    <option value="orange">Orange Money</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wc-mm-phone">Phone Number</label></th>
                            <td>
                                <input type="tel"
                                       id="wc-mm-phone"
                                       placeholder="6XXXXXXXX"
                                       maxlength="9"
                                       pattern="[0-9]{9}"
                                       style="width:100%;max-width:340px;padding:8px;">
                                <p class="description">9 digits only, no country code (e.g. 678817640)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wc-mm-amount">Amount (FCFA)</label></th>
                            <td>
                                <input type="number"
                                       id="wc-mm-amount"
                                       min="1"
                                       step="1"
                                       placeholder="1000"
                                       style="width:100%;max-width:340px;padding:8px;">
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top:24px;">
                        <button id="wc-mm-submit-btn"
                                class="button button-primary"
                                style="padding:10px 28px;font-size:15px;">
                            💸 Send Withdrawal
                        </button>
                        <span id="wc-mm-spinner" class="spinner" style="float:none;margin-left:12px;"></span>
                    </div>
                </div>
            </div>

            <!-- ===== TRANSACTION LOG ===== -->
            <div class="postbox" style="max-width:960px;margin-top:28px;">
                <div class="postbox-header">
                    <h2 class="hnw" style="margin:0;padding:12px 16px;">Withdrawal History (Last 5)</h2>
                </div>
                <div class="wc-mm-history-container" style="padding:0;">
                    <?php if (empty($rows)): ?>
                        <p style="padding:20px;color:#666;">No withdrawals yet.</p>
                    <?php else: ?>
                    <table class="widefat striped" style="border-top:none;">
                        <thead>
                        <tr>
                            <th style="width:40px;">#</th>
                            <th>Transaction</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($rows as $i => $row):
                            // Format the message exactly like the success notice
                            $provider_display = ucfirst($row->provider);
                            $amount_display = number_format((float)$row->amount, 0, '.', ' ');
                            $message = "Withdrawal of {$amount_display} FCFA to {$row->phone} via {$provider_display} has been sent. Partner ID: {$row->partner_id}";
                            
                            // Color based on status
                            switch ($row->status) {
                                case 'completed': case 'success': case 'successful': case 'received':
                                    $color = '#155724'; $bg = '#d4edda'; break;
                                case 'failed': case 'error': case 'cancelled': case 'rejected':
                                    $color = '#721c24'; $bg = '#f8d7da'; break;
                                default:
                                    $color = '#856404'; $bg = '#fff3cd'; break;
                            }
                        ?>
                        <tr style="background:<?php echo $bg; ?>;">
                            <td style="font-weight:bold;color:<?php echo $color; ?>;padding:12px 8px;"><?php echo ($i + 1); ?>.</td>
                            <td style="color:<?php echo $color; ?>;padding:12px;"><?php echo esc_html($message); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== inline JS ===== -->
    <script>
    jQuery(function($) {
        var nonce   = wc_mm_withdraw.nonce;
        var url     = wc_mm_withdraw.ajax_url;
        
        // ========== AUTHENTICATION LAYER ==========
        var $authNotice      = $('#wc-mm-auth-notice');
        var $sendCodeBtn     = $('#wc-mm-send-code-btn');
        var $codeTimer       = $('#wc-mm-code-timer');
        var $codeInputRow    = $('#wc-mm-code-input-row');
        var $codeInput       = $('#wc-mm-code-input');
        var $verifyCodeBtn   = $('#wc-mm-verify-code-btn');
        var $authLayer       = $('#wc-mm-auth-layer');
        var $withdrawalLayer = $('#wc-mm-withdrawal-layer');
        
        var codeExpiry       = null;
        var timerInterval    = null;
        var inactivityTimer  = null;
        var lastActivity     = Date.now();

        function showAuthNotice(msg, type) {  // type: success | error
            var colors = {
                success: {bg:'#d4edda', fg:'#155724'},
                error:   {bg:'#f8d7da', fg:'#721c24'}
            };
            var c = colors[type] || colors.error;
            $authNotice.css({display:'block', background:c.bg, color:c.fg}).html(msg);
        }

        function startCountdown(seconds) {
            codeExpiry = Date.now() + (seconds * 1000);
            $sendCodeBtn.prop('disabled', true);
            
            timerInterval = setInterval(function() {
                var remaining = Math.floor((codeExpiry - Date.now()) / 1000);
                if (remaining <= 0) {
                    clearInterval(timerInterval);
                    $codeTimer.hide().text('');
                    $sendCodeBtn.prop('disabled', false);
                    showAuthNotice('⚠️ Code expired. Please request a new code.', 'error');
                    return;
                }
                var mins = Math.floor(remaining / 60);
                var secs = remaining % 60;
                $codeTimer.show().text('Code expires in ' + mins + 'm ' + secs + 's');
            }, 1000);
        }

        // Send Code button
        $sendCodeBtn.on('click', function() {
            var email = $('#wc-mm-auth-email').val();
            $authNotice.hide();
            $sendCodeBtn.prop('disabled', true);
            
            $.ajax({
                url: url,
                type: 'POST',
                data: {
                    action: 'wc_mm_send_auth_code',
                    nonce: nonce,
                    email: email
                },
                success: function(res) {
                    if (res.success) {
                        showAuthNotice('✅ ' + res.data.message, 'success');
                        $codeInputRow.show();
                        startCountdown(600); // 10 minutes
                    } else {
                        $sendCodeBtn.prop('disabled', false);
                        showAuthNotice('❌ ' + (res.data.message || 'Failed to send code'), 'error');
                    }
                },
                error: function() {
                    $sendCodeBtn.prop('disabled', false);
                    showAuthNotice('❌ Network error. Please try again.', 'error');
                }
            });
        });

        // Verify Code button
        $verifyCodeBtn.on('click', function() {
            var code = $codeInput.val().replace(/\D/g, '');
            if (code.length !== 6) {
                return showAuthNotice('⚠️ Please enter the 6-digit code.', 'error');
            }

            $verifyCodeBtn.prop('disabled', true);

            $.ajax({
                url: url,
                type: 'POST',
                data: {
                    action: 'wc_mm_verify_auth_code',
                    nonce: nonce,
                    code: code
                },
                success: function(res) {
                    $verifyCodeBtn.prop('disabled', false);
                    if (res.success) {
                        showAuthNotice('✅ Security Code Successful!', 'success');
                        clearInterval(timerInterval);
                        setTimeout(function() {
                            $authLayer.hide();
                            $withdrawalLayer.show();
                            // Add URL parameter so page refresh doesn't log user out
                            if (window.history && window.history.pushState) {
                                var newUrl = window.location.href.split('?')[0] + '?page=wc_mobile_money_withdraw&stay_authenticated=1';
                                window.history.pushState({}, '', newUrl);
                            }
                            startInactivityMonitor(); // Start monitoring for inactivity
                            // Load history immediately after auth
                            refreshWithdrawalHistory();
                        }, 1200);
                    } else {
                        showAuthNotice('❌ Security Code Failed, Insert Correct Code', 'error');
                    }
                },
                error: function() {
                    $verifyCodeBtn.prop('disabled', false);
                    showAuthNotice('❌ Network error. Please try again.', 'error');
                }
            });
        });

        // ========== INACTIVITY TIMEOUT (5 minutes) ==========
        function startInactivityMonitor() {
            // If already authenticated on page load, start monitoring
            if ($withdrawalLayer.is(':visible')) {
                resetInactivityTimer();
            }
        }

        function resetInactivityTimer() {
            lastActivity = Date.now();
            
            // Clear existing timer
            if (inactivityTimer) clearTimeout(inactivityTimer);
            
            // Set new 5-minute timeout
            inactivityTimer = setTimeout(function() {
                // Session expired due to inactivity
                alert('Session expired due to 5 minutes of inactivity. Please authenticate again.');
                location.reload(); // Reload page - will show auth layer
            }, 300000); // 5 minutes = 300,000 ms
        }

        // Update activity on any user interaction in the withdrawal form
        $withdrawalLayer.on('click keypress change', function() {
            resetInactivityTimer();
        });

        // Ping server to keep session alive on every withdrawal (optional, but good for reliability)
        function updateServerActivity() {
            $.ajax({
                url: url,
                type: 'POST',
                data: {
                    action: 'wc_mm_update_activity',
                    nonce: nonce
                }
            });
        }

        // Start inactivity monitor if already authenticated (page loaded with withdrawal form visible)
        <?php if ($show_withdrawal_layer): ?>
        startInactivityMonitor();
        // Load history immediately since we're already authenticated
        refreshWithdrawalHistory();
        <?php endif; ?>

        // ========== WITHDRAWAL FORM LAYER ==========
        var $notice = $('#wc-mm-notice');
        var $btn    = $('#wc-mm-submit-btn');
        var $spin   = $('#wc-mm-spinner');
        var currentWithdrawalPartnerId = null; // Track current withdrawal for status polling

        function showNotice(msg, type) {   // type: success | error | info
            var bg = { success:'#d4edda', error:'#f8d7da', info:'#d1ecf1' };
            var fg = { success:'#155724', error:'#721c24', info:'#0c5460' };
            $notice.css({ display:'block', background: bg[type]||bg.info, color: fg[type]||fg.info })
                   .html(msg);
        }

        function refreshWithdrawalHistory() {
            // Reload the withdrawal history table without reloading the entire page
            console.log('Refreshing withdrawal history...');
            $.ajax({
                url: url,
                type: 'POST',
                data: {
                    action: 'wc_mm_get_withdrawal_history',
                    nonce: nonce
                },
                success: function(res) {
                    console.log('History AJAX response:', res);
                    if (res.success && res.data.html) {
                        console.log('History refreshed successfully. Count:', res.data.count);
                        $('.wc-mm-history-container').html(res.data.html);
                    } else {
                        console.log('History refresh failed:', res);
                    }
                },
                error: function(xhr) {
                    console.log('History refresh error:', xhr.status, xhr.responseText);
                }
            });
        }

        function pollWithdrawalStatus(partnerId) {
            // Poll the withdrawal status every 5 seconds for up to 2 minutes
            var pollCount = 0;
            var maxPolls = 24; // 24 polls × 5 seconds = 2 minutes
            
            var pollInterval = setInterval(function() {
                pollCount++;
                
                $.ajax({
                    url: url,
                    type: 'POST',
                    data: {
                        action: 'wc_mm_check_withdrawal_status',
                        nonce: nonce,
                        partner_id: partnerId
                    },
                    success: function(res) {
                        if (res.success && res.data.status) {
                            var status = res.data.status.toLowerCase();
                            
                            if (status === 'completed' || status === 'success' || status === 'successful') {
                                clearInterval(pollInterval);
                                showNotice('✅ Withdrawal Successful! Money has been sent.', 'success');
                                refreshWithdrawalHistory();
                            } else if (status === 'failed' || status === 'error' || status === 'rejected') {
                                clearInterval(pollInterval);
                                showNotice('❌ Withdrawal Failed: ' + (res.data.message || 'Transaction rejected'), 'error');
                                refreshWithdrawalHistory();
                            }
                        }
                        
                        // Stop polling after 2 minutes
                        if (pollCount >= maxPolls) {
                            clearInterval(pollInterval);
                        }
                    }
                });
            }, 5000); // Poll every 5 seconds
        }

        $btn.on('click', function() {
            // --- client-side validation ---
            var provider = $('#wc-mm-provider').val();
            var phone    = $('#wc-mm-phone').val().replace(/\D/g, '');
            var amount   = $('#wc-mm-amount').val();

            if (!phone || phone.length !== 9) {
                return showNotice('⚠️ Phone number must be exactly 9 digits.', 'error');
            }
            if (!amount || isNaN(amount) || parseFloat(amount) < 1) {
                return showNotice('⚠️ Enter a valid amount (minimum 1 FCFA).', 'error');
            }

            // --- confirm before sending real money ---
            if (!confirm('You are about to send ' + Number(amount).toLocaleString() + ' FCFA\nto ' + phone + ' via ' + provider.toUpperCase() + '.\n\nProceed?')) {
                return;
            }

            // --- fire AJAX ---
            $btn.prop('disabled', true);
            $spin.addClass('is-active');
            showNotice('⏳ Sending withdrawal request…', 'info');
            
            // Reset inactivity timer (user is active)
            resetInactivityTimer();
            updateServerActivity(); // Ping server to extend session

            $.ajax({
                url     : url,
                type    : 'POST',
                data    : {
                    action   : 'wc_mm_do_withdrawal',
                    nonce    : nonce,
                    provider : provider,
                    phone    : phone,
                    amount   : amount
                },
                success : function(res) {
                    $btn.prop('disabled', false);
                    $spin.removeClass('is-active');
                    if (res.success) {
                        showNotice('✅ ' + res.data.message, 'success');
                        
                        // IMMEDIATELY add to history table (DOM manipulation - no AJAX delay)
                        var historyContainer = $('.wc-mm-history-container');
                        var currentHtml = historyContainer.html();
                        
                        // Check if "No withdrawals yet" message exists
                        if (currentHtml.indexOf('No withdrawals yet') !== -1) {
                            // Replace with new table
                            var newTable = '<table class="widefat striped" style="border-top:none;">' +
                                '<thead><tr><th style="width:40px;">#</th><th>Transaction</th></tr></thead>' +
                                '<tbody>' +
                                '<tr style="background:#fff3cd;"><td style="font-weight:bold;color:#856404;padding:12px 8px;">1.</td>' +
                                '<td style="color:#856404;padding:12px;">' + res.data.message + '</td></tr>' +
                                '</tbody></table>';
                            historyContainer.html(newTable);
                        } else {
                            // Table exists - prepend new row and renumber
                            var newRow = '<tr style="background:#fff3cd;"><td style="font-weight:bold;color:#856404;padding:12px 8px;">1.</td>' +
                                '<td style="color:#856404;padding:12px;">' + res.data.message + '</td></tr>';
                            
                            historyContainer.find('tbody').prepend(newRow);
                            
                            // Renumber all rows
                            historyContainer.find('tbody tr').each(function(index) {
                                $(this).find('td:first').text((index + 1) + '.');
                            });
                            
                            // Keep only last 5 rows
                            historyContainer.find('tbody tr:gt(4)').remove();
                        }
                        
                        // Start polling for this withdrawal's status
                        if (res.data.partner_id) {
                            currentWithdrawalPartnerId = res.data.partner_id;
                            pollWithdrawalStatus(currentWithdrawalPartnerId);
                        }
                        
                        // Also refresh via AJAX as backup (to sync with database)
                        refreshWithdrawalHistory();
                        
                        // Refresh again after 3 seconds
                        setTimeout(function() {
                            refreshWithdrawalHistory();
                            // Clear form for next withdrawal
                            $('#wc-mm-phone').val('');
                            $('#wc-mm-amount').val('');
                        }, 3000);
                    } else {
                        showNotice('❌ ' + (res.data.message || 'Unknown error'), 'error');
                    }
                },
                error : function(xhr) {
                    $btn.prop('disabled', false);
                    $spin.removeClass('is-active');
                    showNotice('❌ Request failed: ' + (xhr.responseText || 'server error'), 'error');
                }
            });
        });
    });
    </script>
    <?php
}

// --- 5a. AJAX: Send authentication code via email ---
add_action('wp_ajax_wc_mm_send_auth_code', function() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wc_mm_do_withdrawal')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    $email = sanitize_email($_POST['email'] ?? '');
    $allowed_emails = array('godsontabindok@gmail.com', 'djouelahyvette@gmail.com');

    if (!in_array($email, $allowed_emails)) {
        wp_send_json_error(array('message' => 'Invalid email address.'));
    }

    // Generate 6-digit code
    $code = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    
    // Store in transient for 10 minutes (600 seconds)
    set_transient('wc_mm_auth_code_' . get_current_user_id(), $code, 600);

    // Send email
    $subject = 'Mobile Money Withdrawal - Security Code';
    $message = "Your 6-digit security code for Mobile Money Withdrawal:\n\n";
    $message .= "Code: {$code}\n\n";
    $message .= "This code expires in 10 minutes.\n";
    $message .= "If you didn't request this code, please ignore this email.";

    $sent = wp_mail($email, $subject, $message);

    if ($sent) {
        wp_send_json_success(array(
            'message' => "6-digit code sent to {$email}. Check your inbox."
        ));
    } else {
        wp_send_json_error(array('message' => 'Failed to send email. Please try again.'));
    }
});

// --- 5b. AJAX: Verify authentication code ---
add_action('wp_ajax_wc_mm_verify_auth_code', function() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wc_mm_do_withdrawal')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    $input_code = sanitize_text_field($_POST['code'] ?? '');
    $stored_code = get_transient('wc_mm_auth_code_' . get_current_user_id());

    if (!$stored_code) {
        wp_send_json_error(array('message' => 'Code expired or not found. Request a new code.'));
    }

    if ($input_code === $stored_code) {
        // Code is correct - delete it so it can't be reused
        delete_transient('wc_mm_auth_code_' . get_current_user_id());
        
        // Set session flag: authenticated until 5 minutes of inactivity
        // This persists across page reloads and allows unlimited withdrawals
        set_transient('wc_mm_authenticated_' . get_current_user_id(), time(), 300); // 5 minutes
        
        wp_send_json_success(array('message' => 'Authentication successful!'));
    } else {
        wp_send_json_error(array('message' => 'Incorrect code.'));
    }
});

// --- 5ba. AJAX: Update last activity timestamp (keep session alive) ---
add_action('wp_ajax_wc_mm_update_activity', function() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wc_mm_do_withdrawal')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }
    
    // Refresh the authentication timestamp - extends it by another 5 minutes
    set_transient('wc_mm_authenticated_' . get_current_user_id(), time(), 300);
    wp_send_json_success(array('message' => 'Activity updated'));
});

// --- 5bb. AJAX: Get withdrawal history table HTML (for refreshing without page reload) ---
add_action('wp_ajax_wc_mm_get_withdrawal_history', function() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wc_mm_do_withdrawal')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'moala_withdrawals';
    
    // Check if table exists first
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table;
    
    if (!$table_exists) {
        // Table doesn't exist yet - return empty message
        wp_send_json_success(array('html' => '<p style="padding:20px;color:#666;">No withdrawals yet.</p>'));
        return;
    }
    
    // Get last 5 withdrawals from database
    $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 5");
    
    // Log for debugging
    error_log('Withdrawal history query returned ' . count($rows) . ' rows');
    
    ob_start();
    if (empty($rows)): ?>
        <p style="padding:20px;color:#666;">No withdrawals yet.</p>
    <?php else: ?>
    <table class="widefat striped" style="border-top:none;">
        <thead>
        <tr>
            <th style="width:40px;">#</th>
            <th>Transaction</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $i => $row):
            // Format the message exactly like the success notice
            $provider_display = ucfirst($row->provider);
            $amount_display = number_format((float)$row->amount, 0, '.', ' ');
            $message = "Withdrawal of {$amount_display} FCFA to {$row->phone} via {$provider_display} has been sent. Partner ID: {$row->partner_id}";
            
            // Color based on status
            switch ($row->status) {
                case 'completed': case 'success': case 'successful': case 'received':
                    $color = '#155724'; $bg = '#d4edda'; break;
                case 'failed': case 'error': case 'cancelled': case 'rejected':
                    $color = '#721c24'; $bg = '#f8d7da'; break;
                default:
                    $color = '#856404'; $bg = '#fff3cd'; break;
            }
        ?>
        <tr style="background:<?php echo $bg; ?>;">
            <td style="font-weight:bold;color:<?php echo $color; ?>;padding:12px 8px;"><?php echo ($i + 1); ?>.</td>
            <td style="color:<?php echo $color; ?>;padding:12px;"><?php echo esc_html($message); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif;
    $html = ob_get_clean();
    
    wp_send_json_success(array('html' => $html, 'count' => count($rows)));
});

// --- 5bc. AJAX: Check withdrawal status (for polling) ---
add_action('wp_ajax_wc_mm_check_withdrawal_status', function() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wc_mm_do_withdrawal')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }
    
    $partner_id = sanitize_text_field($_POST['partner_id'] ?? '');
    if (empty($partner_id)) {
        wp_send_json_error(array('message' => 'No partner ID provided'));
    }
    
    global $wpdb;
    $table = $wpdb->prefix . 'moala_withdrawals';
    $row = $wpdb->get_row($wpdb->prepare("SELECT status, api_response FROM {$table} WHERE partner_id = %s", $partner_id));
    
    if (!$row) {
        wp_send_json_error(array('message' => 'Withdrawal not found'));
    }
    
    $api_data = json_decode($row->api_response, true);
    $message = isset($api_data['message']) ? $api_data['message'] : '';
    
    wp_send_json_success(array(
        'status' => $row->status,
        'message' => $message
    ));
});

// --- 5c. AJAX handler (server-side) ---
add_action('wp_ajax_wc_mm_do_withdrawal', function() {
    // Resolve current user immediately so ->ID is populated for the DB insert later.
    $current_user = get_current_user();

    // Suppress wpdb error output — otherwise MySQL warnings print into the
    // response body and break the JSON that jQuery is trying to parse.
    global $wpdb;
    $wpdb->show_errors(false);

    // security — Administrator only
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'] ?? '', 'wc_mm_do_withdrawal')) {
        wp_send_json_error(array('message' => 'Security check failed.'));
    }

    $logger   = wc_get_logger();
    $provider = sanitize_text_field($_POST['provider'] ?? '');           // "mtn" or "orange"
    $phone    = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');     // 9 digits
    $amount   = floatval($_POST['amount'] ?? 0);

    // ---- validate ----
    if (!in_array($provider, array('mtn', 'orange'))) {
        wp_send_json_error(array('message' => 'Invalid provider.'));
    }
    if (strlen($phone) !== 9 || !preg_match('/^[0-9]{9}$/', $phone)) {
        wp_send_json_error(array('message' => 'Phone must be exactly 9 digits.'));
    }
    if ($amount < 1) {
        wp_send_json_error(array('message' => 'Amount must be at least 1 FCFA.'));
    }

    // ---- map provider → service code (per Moala docs) ----
    $service_codes = array(
        'mtn'    => 'CASHIN_MTN_CM',
        'orange' => 'CASHIN_ORANGE_CM',
    );
    $service_code = $service_codes[$provider];

    // ---- unique partnerId (never reuse) ----
    $partner_id = 'withdraw_' . $provider . '_' . time() . '_' . uniqid('', true);

    $logger->info("=== WITHDRAWAL INITIATED === Provider:{$provider} Phone:{$phone} Amount:{$amount} PartnerID:{$partner_id}", array('source' => 'mobile-money-withdraw'));

    // ---- grab API credentials from whichever gateway is enabled ----
    // Both MTN and Orange share the same Moala credentials, so either works.
    // We try MTN first; fall back to Orange if MTN is not enabled.
    $gateway = null;
    foreach (array('WC_MTN_MoMo_Gateway', 'WC_Orange_Money_Gateway') as $class) {
        if (class_exists($class)) {
            $gw = new $class();
            if ('yes' === $gw->get_option('enabled')) {
                $gateway = $gw;
                break;
            }
        }
    }
    if (!$gateway) {
        $logger->error('No enabled gateway found for credentials.', array('source' => 'mobile-money-withdraw'));
        wp_send_json_error(array('message' => 'No enabled payment gateway found. Enable MTN or Orange gateway first.'));
    }

    // ---- instantiate Moala client using those credentials ----
    try {
        $api_url    = $gateway->get_option('api_url', 'https://api.moala.africa');
        $testmode   = 'yes' === $gateway->get_option('testmode');
        $app_key    = $testmode ? $gateway->get_option('test_app_key')    : $gateway->get_option('live_app_key');
        $secret_key = $testmode ? $gateway->get_option('test_secret_key') : $gateway->get_option('live_secret_key');

        $client = new \WC_MTN_MoMo\Custom_Moala_Client(
            $api_url,
            $app_key,
            $secret_key,
            function($msg) use ($logger) { $logger->info($msg, array('source' => 'mobile-money-withdraw')); }
        );

        // ---- call the API ----
        $result = $client->cashout($phone, $service_code, $amount, $partner_id);

        $logger->info("API response: " . print_r($result, true), array('source' => 'mobile-money-withdraw'));

        // ---- persist to DB ----
        global $wpdb;
        $table = $wpdb->prefix . 'moala_withdrawals';
        $wpdb->insert($table, array(
            'partner_id'   => $partner_id,
            'provider'     => $provider,
            'phone'        => $phone,
            'amount'       => $amount,
            'status'       => 'pending',
            'api_response' => json_encode($result),
            'initiated_by' => $current_user->ID,
        ), array('%s','%s','%s','%f','%s','%s','%d'));

        wp_send_json_success(array(
            'message'    => "Withdrawal of " . number_format($amount, 0, '.', ' ') . " FCFA to {$phone} via " . strtoupper($provider) . " has been sent. Partner ID: {$partner_id}",
            'partner_id' => $partner_id,
        ));

    } catch (Exception $e) {
        $logger->error("Withdrawal failed: " . $e->getMessage(), array('source' => 'mobile-money-withdraw'));
        wp_send_json_error(array('message' => 'API error: ' . $e->getMessage()));
    }
});

// --- 6. Webhook: also handle withdrawal callbacks ---
// The unified webhook already fires for any partnerId.
// We just need to add a check: if partnerId starts with "withdraw_",
// update the withdrawals table instead of looking for a WooCommerce order.
// We hook BEFORE the existing handler by replacing the action registration.

remove_action('woocommerce_api_wc_mobile_money_webhook', 'wc_mobile_money_unified_webhook_handler', 10);
add_action('woocommerce_api_wc_mobile_money_webhook', function() {
    $logger = wc_get_logger();
    $raw    = file_get_contents('php://input');
    $data   = json_decode($raw, true);
    if (!$data && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $data = $_GET;
    }

    $logger->info('=== UNIFIED WEBHOOK (v2 — withdrawal-aware) === Raw: ' . $raw, array('source' => 'mobile-money-webhook'));

    if (empty($data)) { status_header(200); exit; }

    $partner_id = $data['partnerId'] ?? $data['partner_id'] ?? null;
    if (!$partner_id) { status_header(400); exit; }

    $status  = isset($data['status'])  ? strtoupper(trim($data['status']))  : null;
    $message = $data['message'] ?? '';

    // ============ BRANCH A: withdrawal record ============
    if (strpos($partner_id, 'withdraw_') === 0) {
        $logger->info("Webhook matched WITHDRAWAL partnerId: {$partner_id}, status: {$status}", array('source' => 'mobile-money-webhook'));

        global $wpdb;
        $table = $wpdb->prefix . 'moala_withdrawals';
        $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE partner_id = %s", $partner_id));

        if (!$row) {
            $logger->info("Withdrawal record not found for: {$partner_id}", array('source' => 'mobile-money-webhook'));
            status_header(404);
            exit;
        }

        // normalise status into one of: completed | failed | pending
        if (in_array($status, array('SUCCESS','SUCCESSFUL','COMPLETED','PAID','OK','RECEIVED'))) {
            $new_status = 'completed';
        } elseif (in_array($status, array('FAIL','FAILED','ERROR','CANCELLED','REJECTED'))) {
            $new_status = 'failed';
        } else {
            $new_status = 'pending';
        }

        $wpdb->update($table,
            array('status' => $new_status, 'api_response' => json_encode($data)),
            array('partner_id' => $partner_id),
            array('%s','%s'),
            array('%s')
        );

        $logger->info("Withdrawal {$partner_id} updated to: {$new_status}", array('source' => 'mobile-money-webhook'));
        status_header(200);
        echo json_encode(array('success' => true));
        exit;
    }

    // ============ BRANCH B: payment order (original logic, untouched) ============
    $orders = wc_get_orders(array(
        'meta_key'   => '_mobile_money_partner_id',
        'meta_value' => $partner_id,
        'limit'      => 1
    ));

    if (empty($orders)) {
        $logger->info('Order not found for partnerId: ' . $partner_id, array('source' => 'mobile-money-webhook'));
        status_header(404);
        exit;
    }

    $order      = $orders[0];
    $gateway_id = $order->get_meta('_mobile_money_gateway_id', true);

    $logger->info("Order: {$order->get_id()}, Gateway: {$gateway_id}, Status: {$status}", array('source' => 'mobile-money-webhook'));
    $order->add_order_note(sprintf('Webhook: Gateway=%s, Status=%s, Message=%s', $gateway_id, $status, $message));

    if (in_array($status, array('SUCCESS','SUCCESSFUL','COMPLETED','PAID','OK','RECEIVED'))) {
        if (!$order->has_status(array('processing','completed'))) {
            $order->payment_complete();
            $provider_name = ($gateway_id === 'mtn_momo') ? 'MTN' : 'Orange';
            $order->add_order_note('✅ Payment confirmed via ' . $provider_name . ' Mobile Money');

            $gateway = ($gateway_id === 'mtn_momo') ? new WC_MTN_MoMo_Gateway() : new WC_Orange_Money_Gateway();
            if ('yes' === $gateway->get_option('auto_complete')) {
                $order->update_status('completed');
            }
            $order->save();
        }
    } elseif (in_array($status, array('FAIL','FAILED','ERROR','CANCELLED','REJECTED'))) {
        $order->update_status('failed', sprintf(__('Payment failed: %s', 'wc-mobile-money'), $message));
    }

    status_header(200);
    echo json_encode(array('success' => true));
    exit;
}, 10);