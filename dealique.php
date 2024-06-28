<?php
/**
* Plugin Name: Dealique Escrow Payment Gateway
* Plugin URI: https://usedealique.com
* Description: Integrates WooCommerce with Dealique escrow for payment processing.
* Version: 1.0
* Author: Chukwudi Onyekwere
* Author URI: https://fabugit.com
* License: GPLv3
* License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

// Add settings link on plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'dealique_settings_link');
function dealique_settings_link($links) {
    $settings_link = '<a href="admin.php?page=wc-settings&tab=checkout&section=dealique">' . __('Settings') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}

// Add Dealique payment gateway to WooCommerce
add_filter('woocommerce_payment_gateways', 'add_dealique_gateway');
function add_dealique_gateway($gateways) {
    $gateways[] = 'WC_Gateway_Dealique';
    return $gateways;
}

// Change paragraph description directly
add_filter('woocommerce_gateway_description', 'custom_dealique_description', 20, 2);
function custom_dealique_description($description, $payment_id) {
    if ($payment_id == 'dealique') {
        $description = '<p>Pay with Dealique Escrow payment gateway.</p>';
    }
    return $description;
}

// Change the title of the Dealique payment gateway directly
add_action('admin_init', 'modify_payment_gateway_output');

function modify_payment_gateway_output() {
    if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'checkout') {
        // Verify nonce before processing
        if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dealique_modify_payment_gateway_output')) {
            ob_start('add_escrow_payment_to_dealique');
        }
    }
}

function add_escrow_payment_to_dealique($content) {
    // Regex to find the specific row and add the new span
    $pattern = '/(<tr data-gateway_id="dealique">.*?<td class="name" width="">.*?<a.*?class="wc-payment-gateway-method-title">Dealique<\/a>)(<\/td>)/s';
    $replacement = '$1<span class="wc-payment-gateway-method-name">&nbsp;–&nbsp;Escrow Payment</span>$2';
    return preg_replace($pattern, $replacement, $content);
}

// Dealique Payment Gateway Class
add_action('plugins_loaded', 'init_dealique_gateway');
function init_dealique_gateway() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Dealique extends WC_Payment_Gateway {
        public function __construct() {
            $this->id = 'dealique';
            $this->icon = plugins_url('assets/images/dealique-wc.png', __FILE__); // Path to the Dealique logo
            $this->has_fields = false;
            $this->method_title = 'Dealique';
            $this->method_description = 'Dealique offers an escrow payment gateway for secure transactions where buyers and sellers can confidently make and receive online payments, with funds held in escrow until transactions are successfully completed. <a href="https://app.usedealique.com/register" target="_blank">Sign up</a> to copy your <a href="https://app.usedealique.com/settings/#payments" target="_blank">API key, set up your payment receiving account</a> and integrate escrow payments seamlessly into your platform.';

            // Load the settings
            $this->init_form_fields();
            $this->init_settings();

            // Define user settings
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->api_key = $this->get_option('api_key');

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

            // Add webhook endpoint
            add_action('rest_api_init', function () {
                register_rest_route('dealique/v1', '/webhook', array(
                    'methods' => 'POST',
                    'callback' => array($this, 'webhook_handler'),
                ));
            });

            // Add the span to the title if on the WooCommerce settings page
            add_filter('woocommerce_gateway_description', array($this, 'add_span_to_title'), 20, 2);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'       => 'Enable/Disable',
                    'label'       => 'Enable Dealique Payment',
                    'type'        => 'checkbox',
                    'description' => '',
                    'default'     => 'no',
                ),
                'title' => array(
                    'title'       => 'Title',
                    'type'        => 'text',
                    'description' => 'Title of the payment method displayed to the customer during checkout.',
                    'default'     => 'Dealique',
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => 'Description',
                    'type'        => 'textarea',
                    'description' => 'Description of the payment method displayed to the customer during checkout.',
                    'default'     => 'Pay with Dealique payment gateway.',
                ),
                'api_key' => array(
                    'title'       => 'API Key',
                    'type'        => 'text',
                    'description' => 'Enter your Dealique API Key.',
                    'default'     => '',
                ),
            );
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            // Mark as on-hold (we're awaiting the payment)
            $order->update_status('on-hold', 'Awaiting Dealique payment');

            // Reduce stock levels
            wc_reduce_stock_levels($order_id);

            // Remove cart
            WC()->cart->empty_cart();

            // Get the Dealique API key from the settings
            $api_key = $this->api_key;

            // Extract item names
            $items = $order->get_items();
            $item_names = array();

            foreach ($items as $item) {
                $item_names[] = $item->get_name();
            }

            // Prepare data for the Dealique payment request
            $data = array(
                'api_key' => $api_key,
                'order_id' => $order_id,
                'amount' => $order->get_total(),
                'email' => $order->get_billing_email(),
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'item_names' => $item_names,
                'origin_wordpress_url' => get_site_url(), // Add this line to include the origin URL
            );

            // Make an AJAX call to your Django backend to initiate the Dealique payment
            $response = wp_remote_post('https://app.usedealique.com/transactions/create_wp_transaction/', array(
                'method'    => 'POST',
                'body'      => wp_json_encode($data),
                'headers'   => array(
                    'Content-Type' => 'application/json',
                ),
            ));

            if (is_wp_error($response)) {
                wc_add_notice('Connection error! Please ensure your Dealique API key is correct and try again.', 'error');
                return;
            }

            $response_body = wp_remote_retrieve_body($response);
            $result = json_decode($response_body, true);

            if ($result['authorization_url']) {
                return array(
                    'result'   => 'success',
                    'redirect' => $result['authorization_url'],
                );
            } else {
                wc_add_notice('Payment error: ' . $result['message'], 'error');
                return;
            }
        }

        public function receipt_page($order) {
            echo '<p>Thank you for your order, please click the button below to pay with Dealique.</p>';
            echo '<button type="button" class="button alt" id="pay_with_dealique_button">Pay with Dealique</button>';
        }

        public function webhook_handler(WP_REST_Request $request) {
            $data = $request->get_json_params();
            $order_id = $data['order_id'];
            $status = $data['status'];

            $order = wc_get_order($order_id);
            if ($order) {
                if ($status === 'completed') {
                    $order->payment_complete();
                    $order->add_order_note('Dealique payment completed.');
                } else {
                    $order->update_status('failed', 'Dealique payment failed.');
                }

                // Return the order key in the response
                return new WP_REST_Response(array('order_key' => $order->get_order_key()), 200);
            } else {
                return new WP_REST_Response('Order not found', 404);
            }
        }

        public function add_span_to_title($description, $gateway_id) {
            if ($gateway_id === 'dealique' && is_admin() && isset($_GET['page']) && $_GET['page'] === 'wc-settings' && isset($_GET['tab']) && $_GET['tab'] === 'checkout') {
                // Verify nonce before processing
                if (isset($_GET['_wpnonce']) && wp_verify_nonce($_GET['_wpnonce'], 'dealique_add_span_to_title')) {
                    $description .= '<span class="wc-payment-gateway-method-name">&nbsp;–&nbsp;Escrow Payment</span>';
                }
            }
            return $description;
        }
    }
}
?>
