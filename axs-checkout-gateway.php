<?php
/*
 * Plugin Name: AXS Checkout for WooCommerce​
 * Plugin URI: https://axs.com.sg/business/axs-checkout/
 * Description: Accepts cards and QR payments using AXS Checkout. Supports local and foreign payment schemes.​
 * Author: AXS Pte Ltd​
 * Author URI: https://axs.com.sg/business/axs-checkout/
 * Developer: AXS Pte Ltd​
 * Developer URI: https://axs.com.sg/business/axs-checkout/
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Include the PaymentLinkGenerator class
require_once plugin_dir_path(__FILE__) . 'payment_link_generator.php';

/**
 * WooCommerce fallback notice.
 *
 * @since 1.0.0
 */
function woocommerce_extension_template_missing_wc_notice()
{
    /* translators: %s WC download URL link. */
    echo '<div class="error"><p><strong>' . sprintf(esc_html__('WooCommerce AXS Checkout Payment Gateway requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-extension-template'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
}

/*
 * This action hook registers our PHP class as a WooCommerce payment gateway
 */
add_filter('woocommerce_payment_gateways', 'axs_checkout_add_gateway_class');
function axs_checkout_add_gateway_class($gateways)
{
    $gateways[] = 'WC_AXS_Checkout_Gateway'; // your class name is here
    return $gateways;
}

/*
 * The class itself, please note that it is inside plugins_loaded action hook
 */
add_action('plugins_loaded', 'axs_checkout_init_gateway_class');

/**
 * Returns the class instance when plugins are
 * loaded.
 *
 * @since 1.0.0
 */
function axs_checkout_init_gateway_class()
{

    if (! class_exists('WooCommerce')) {
        add_action('admin_notices', 'woocommerce_extension_template_missing_wc_notice');
        return;
    }

    class WC_AXS_Checkout_Gateway extends WC_Payment_Gateway
    {

        /**
         * Class constructor
         *
         * @since 1.0.0
         */
        public function __construct()
        {

            $this->id = 'axs_checkout'; // payment gateway plugin ID
            $this->icon = plugin_dir_url(__FILE__) . 'assets/images/axs.svg'; // URL of the icon that will be displayed on checkout page near your gateway name
            $this->has_fields = true; // in case you need a custom credit card form
            $this->method_title = 'AXS Checkout for WooCommerce​';
            $this->method_description = 'Enables seamless and secure payments for credit/debit cards, QR (local and foreign wallets), Apple Pay and more.'; // will be displayed on the options page

            // gateways can support subscriptions, refunds, saved payment methods,
            // but in this tutorial we begin with simple payments
            $this->supports = array(
                'products'
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = "";
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->testmode = 'yes' === $this->get_option('testmode');
            $this->private_key = $this->testmode ? $this->get_option('test_private_key') : $this->get_option('private_key');
            $this->publishable_key = $this->testmode ? $this->get_option('test_publishable_key') : $this->get_option('publishable_key');

            // This action hook saves the settings
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // custom JavaScript to obtain a token
            // add_action('wp_enqueue_scripts', array($this, 'payment_scripts'));

            // You can also register a webhook here
            add_action('woocommerce_api_axs_checkout', array($this, 'webhook'));
        }

        /**
         * Plugin options
         *
         * @since 1.0.0
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable AXS Checkout for WooCommerce​',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no'
                ),
                'testmode' => array(
                    'title'       => 'Test mode',
                    'label'       => 'Enable Test Mode',
                    'type'        => 'checkbox',
                    'description' => 'Enable test mode to process transactions using test API keys.',
                    'default'     => 'no',
                    'desc_tip'    => true,
                ),
                'test_merchant_link' => array(
                    'title'       => 'Test Payment Link',
                    'description' => '',
                    'type'        => 'text',
                ),
                'test_client_key' => array(
                    'title'       => 'Test Client ID',
                    'description' => '',
                    'type'        => 'text'
                ),
                'test_Secret_key' => array(
                    'title'       => 'Test Secret',
                    'description' => '',
                    'type'        => 'password'
                ),
                'merchant_link' => array(
                    'title'       => 'Live Payment Link',
                    'description' => '',
                    'type'        => 'text',
                ),
                'client_key' => array(
                    'title'       => 'Live Client ID',
                    'description' => '',
                    'type'        => 'text'
                ),
                'Secret_key' => array(
                    'title'       => 'Live Secret',
                    'description' => '',
                    'type'        => 'password'
                ),
            );
        }

        /**
         * Show content inside the payment fields page
         *
         * @since 1.0.0
         */
        public function payment_fields()
        {
            if ($this->enabled !== 'yes') {
                echo '<div class="woocommerce-error">AXS Checkout is currently unavailable. Please try again later.</div>';
                return;
            }

            // Display the payment gateway description
            echo wpautop(wp_kses_post('Secure and seamless online payments'));

            // Display test mode notice if in test mode
            if ($this->testmode) {
                echo '<div class="woocommerce-info">AXS Checkout is currently in test mode. You can use test credentials to make test payments.</div>';
            }
        }

        /**
         * Fields validation
         *
         * @since 1.0.0
         */
        public function validate_fields()
        {
            // Check if we're in test mode
            $is_test_mode = 'yes' === $this->get_option('testmode');

            // Get the appropriate keys based on test mode
            $merchant_link = $is_test_mode ? $this->get_option('test_merchant_link') : $this->get_option('merchant_link');
            $client_key = $is_test_mode ? $this->get_option('test_client_key') : $this->get_option('client_key');
            $secret_key = $is_test_mode ? $this->get_option('test_Secret_key') : $this->get_option('Secret_key');

            // Validate merchant link
            if (empty($merchant_link)) {
                wc_add_notice('Sandbox/Production Merchant link is not set up! Please set up in the payment option page.', 'error');
                return false;
            }

            // Validate client key
            if (empty($client_key)) {
                wc_add_notice('Sandbox/Production Client key is not set up! Please set up in the payment option page.', 'error');
                return false;
            }

            // Validate secret key
            if (empty($secret_key)) {
                wc_add_notice('Sandbox/Production Secret key is not set up! Please set up in the payment option page.', 'error');
                return false;
            }

            return true;
        }

        /**
         * Log data to a file with timestamp
         * 
         * @param string $prefix The prefix for the log file name
         * @param mixed $data The data to log
         * @param string $suffix Optional suffix for the log file name
         * @return string The path to the created log file
         */
        private function log_data($prefix, $data, $suffix = '')
        {
            // Create a directory for logs if it doesn't exist
            $log_dir = WP_CONTENT_DIR . '/axs-logs';
            if (!file_exists($log_dir)) {
                mkdir($log_dir, 0755, true);
            }

            // Create log file with timestamp
            $filename = $prefix . '-' . date('Y-m-d-H-i-s');
            if (!empty($suffix)) {
                $filename .= '-' . $suffix;
            }
            $log_file = $log_dir . '/' . $filename . '.txt';

            // Write data to file
            file_put_contents($log_file, print_r($data, true));

            return $log_file;
        }

        /**
         * Processing the payments here
         *
         * @since 1.0.0
         */
        public function process_payment($order_id)
        {
            // we need it to get any order detailes
            $order = wc_get_order($order_id);

            // Get the appropriate keys based on test mode
            $is_test_mode = 'yes' === $this->get_option('testmode');
            $merchant_link = $is_test_mode ? $this->get_option('test_merchant_link') : $this->get_option('merchant_link');
            $client_key = $is_test_mode ? $this->get_option('test_client_key') : $this->get_option('client_key');
            $secret_key = $is_test_mode ? $this->get_option('test_Secret_key') : $this->get_option('Secret_key');

            // Initialize PaymentLinkGenerator
            $generator = new PaymentLinkGenerator();


            // Prepare payment parameters
            $params = [
                'clientId' => $client_key,
                'amount' => (int)($order->get_total() * 100), // Convert to cents by multiplying by 100
                'currency' => $order->get_currency(),
                'merchantRef' => $order->get_id(),
                'successUrl' => $order->get_checkout_order_received_url(),
                'failUrl' => $order->get_checkout_order_received_url(),
                'webhookUrl' => home_url('/wc-api/axs_checkout'),
                'expiry' => 300 // 5 minutes expiry
            ];


            // Generate payment link
            $payment_link = $generator->generatePaymentLink($merchant_link, $client_key, $secret_key, $params);

            // Store payment link in order meta for reference
            $order->update_meta_data('_axs_payment_link', $payment_link);
            // Ensure order shows a readable payment method title in details/emails
            $order->set_payment_method_title('AXS Checkout');
            $order->save();

            // Log order data using the new function
            // $this->log_data('order', $params, $order_id);

            // Return success and redirect to payment link
            return array(
                'result' => 'success',
                'redirect' => $payment_link
            );
        }

        /**
         * Webhook
         *
         * @since 1.0.0
         */
        public function webhook()
        {
            // Prepare webhook request data for logging
            $request_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
            $webhook_data = [
                'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
                'request_url' => $request_url,
                'content_type' => $_SERVER['CONTENT_TYPE'] ?? 'N/A',
                'get_data' => $_GET ?? [],
                'post_data' => $_POST ?? [],
                'raw_input' => file_get_contents('php://input')
            ];

            // Log webhook request data using the new function
            // $this->log_data('webhook', $webhook_data);

            // Initialize PaymentLinkGenerator
            $generator = new PaymentLinkGenerator();

            // Get the appropriate secret key based on test mode
            $is_test_mode = 'yes' === $this->get_option('testmode');
            $secret_key = $is_test_mode ? $this->get_option('test_Secret_key') : $this->get_option('Secret_key');

            // First extract JWE token from the payment link URL
            $jweToken = $generator->extractJWEFromPaymentLink($request_url);

            // $this->log_data('secret_key', $secret_key);

            if ($jweToken) {
                // Now decrypt the extracted JWE token
                $decrypted_data = $generator->decryptJWE($jweToken, $secret_key);

                // Log decrypted data
                // $this->log_data('webhook-decrypted', $decrypted_data);

                if ($decrypted_data['success']) {
                    // Verify the webhook data
                    if (!isset($decrypted_data['payload']['merchantRef'])) {
                        status_header(400);
                        exit('Invalid webhook data');
                    }

                    // Get the order
                    $order = wc_get_order($decrypted_data['payload']['merchantRef']);
                    if (!$order) {
                        status_header(404);
                        exit('Order not found');
                    }

                    // Verify the payment status
                    if (isset($decrypted_data['payload']['status'])) {
                        switch ($decrypted_data['payload']['status']) {
                            case 'SUCCESS':
                                // Payment successful
                                $order->payment_complete($decrypted_data['payload']['transactionRef'] ?? '');
                                $order->add_order_note(sprintf(
                                    'AXS Checkout payment completed successfully. Amount: %s %s. Transaction ID: %s',
                                    $decrypted_data['payload']['amount'] ?? 'N/A',
                                    $decrypted_data['payload']['currency'] ?? 'N/A',
                                    $decrypted_data['payload']['transactionRef'] ?? 'N/A'
                                ));
                                break;
                            case 'DECLINED':
                                // Payment failed
                                $order->update_status('failed', 'AXS Checkout payment failed.');
                                $order->add_order_note(sprintf(
                                    'AXS Checkout payment failed. Amount: %s %s. Transaction ID: %s',
                                    $decrypted_data['payload']['amount'] ?? 'N/A',
                                    $decrypted_data['payload']['currency'] ?? 'N/A',
                                    $decrypted_data['payload']['transactionRef'] ?? 'N/A'
                                ));
                                break;
                            case 'EXPIRED':
                                // Payment cancelled
                                //$order->update_status('cancelled', 'AXS Checkout payment was expired.');
                                //$order->add_order_note(sprintf(
                                //    'AXS Checkout payment was expired by customer. Amount: %s %s. Transaction ID: %s',
                                //    $decrypted_data['payload']['amount'] ?? 'N/A',
                                //    $decrypted_data['payload']['currency'] ?? 'N/A',
                                //    $decrypted_data['payload']['transactionRef'] ?? 'N/A'
                                //));
                                break;
                            default:
                                // Unknown status
                                $order->add_order_note('AXS Checkout payment status: ' . $decrypted_data['payload']['status']);
                                break;
                        }

                        // Just return success response for webhook
                        status_header(200);
                        exit('Webhook processed successfully');
                    }
                } else {
                    // Log decryption error
                    // $this->log_data('webhook-error', ['error' => 'Decryption failed', 'details' => $decrypted_data]);
                }
            } else {
                // Log extraction error
                $this->log_data('webhook-error', ['error' => 'Could not extract JWE token from payment link']);
            }
        }
    }
}
