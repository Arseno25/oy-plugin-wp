<?php
/**
 * Plugin Name: OY! Payment Gateway for WooCommerce
 * Plugin URI: https://example.com
 * Author: Your Name
 * Description: OY! Indonesia Payment Gateway integration for WooCommerce.
 * Version: 1.0.0
 * Text Domain: oy-payment-woo
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'oy_payment_init', 11);

function oy_payment_init() {
    if (class_exists('WC_Payment_Gateway')) {
        class WC_Gateway_OY extends WC_Payment_Gateway {
            public function __construct() {
                $this->id = 'oy_gateway';
                $this->icon = apply_filters('woocommerce_oy_icon', plugins_url('/assets/icon.png', __FILE__));
                $this->has_fields = true;
                $this->method_title = __('OY! Payment Gateway', 'oy-payment-woo');
                $this->method_description = __('OY! Indonesia Payment Gateway for WooCommerce.', 'oy-payment-woo');

                $this->title = $this->get_option('title');
                $this->description = $this->get_option('description');
                $this->instructions = $this->get_option('instructions', $this->description);

                $this->init_form_fields();
                $this->init_settings();

                add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
                add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            }

            public function init_form_fields() {
                $this->form_fields = apply_filters('woo_oy_payment_fields', array(
                    'enabled' => array(
                        'title' => __('Enable/Disable', 'oy-payment-woo'),
                        'type' => 'checkbox',
                        'label' => __('Enable or Disable OY! Payments', 'oy-payment-woo'),
                        'default' => 'no'
                    ),
                    'title' => array(
                        'title' => __('OY! Payments Gateway', 'oy-payment-woo'),
                        'type' => 'text',
                        'default' => __('OY! Payments Gateway', 'oy-payment-woo'),
                        'desc_tip' => true,
                        'description' => __('Add a new title for the OY! Payments Gateway that customers will see when they are in the checkout page.', 'oy-payment-woo')
                    ),
                    'description' => array(
                        'title' => __('OY! Payments Gateway Description', 'oy-payment-woo'),
                        'type' => 'textarea',
                        'default' => __('Please remit your payment to the shop to allow for the delivery to be made', 'oy-payment-woo'),
                        'desc_tip' => true,
                        'description' => __('Add a new title for the OY! Payments Gateway that customers will see when they are in the checkout page.', 'oy-payment-woo')
                    ),
                    'instructions' => array(
                        'title' => __('Instructions', 'oy-payment-woo'),
                        'type' => 'textarea',
                        'default' => __('Default instructions', 'oy-payment-woo'),
                        'desc_tip' => true,
                        'description' => __('Instructions that will be added to the thank you page and order email', 'oy-payment-woo')
                    ),
                    'oy_username' => array(
                        'title' => __('OY! Username', 'oy-payment-woo'),
                        'type' => 'text',
                        'default' => '',
                        'desc_tip' => true,
                        'description' => __('Enter your OY! API username.', 'oy-payment-woo')
                    ),
                    'oy_api_key' => array(
                        'title' => __('OY! API Key', 'oy-payment-woo'),
                        'type' => 'text',
                        'default' => '',
                        'desc_tip' => true,
                        'description' => __('Enter your OY! API key.', 'oy-payment-woo')
                    ),
                    'oy_api_url' => array(
                        'title' => __('OY! API URL', 'oy-payment-woo'),
                        'type' => 'text',
                        'default' => 'https://api-stg.oyindonesia.com/api/payment-checkout/create-v2',
                        'desc_tip' => true,
                        'description' => __('Enter the OY! API endpoint URL.', 'oy-payment-woo')
                    ),
                ));
            }

            public function process_payment($order_id) {
                $order = wc_get_order($order_id);

                $order->update_status('on-hold', __('Awaiting OY! Payment', 'oy-payment-woo'));

                $order->reduce_order_stock();

                WC()->cart->empty_cart();

                return array(
                    'result' => 'success',
                    'redirect' => $this->get_return_url($order),
                );
            }

            public function receipt_page($order_id) {
                $order = wc_get_order($order_id);

                // Mengambil data pembeli dari objek pesanan
                $billing_first_name = $order->get_billing_first_name();
                $billing_last_name = $order->get_billing_last_name();
                $billing_email = $order->get_billing_email();
                $billing_phone = $order->get_billing_phone();

                // Mengambil nilai-nilai dari setelan
                $oy_username = $this->get_option('oy_username');
                $oy_api_key = $this->get_option('oy_api_key');
                $oy_api_url = $this->get_option('oy_api_url');

                // Inisialisasi permintaan HTTP
                $request = new HTTP_Request2();
                $request->setUrl($oy_api_url);
                $request->setMethod(HTTP_Request2::METHOD_POST);
                $request->setConfig(array(
                    'follow_redirects' => TRUE
                ));
                $request->setHeader(array(
                    'Content-Type' => 'application/json',
                    'x-oy-username' => $oy_username,
                    'x-api-key' => $oy_api_key
                ));

                // Setel body permintaan dengan data dari objek pesanan
                $request->setBody('{
                    "description": "Prod Test API",
                    "partner_tx_id": "",
                    "notes": "",
                    "sender_name" : "' . $billing_first_name . ' ' . $billing_last_name . '",
                    "amount" : ' . $order->get_total() * 100 . ',
                    "email": "' . $billing_email . '",
                    "phone_number": "' . $billing_phone . '",
                    "is_open" : true,
                    "include_admin_fee" : true,
                    "list_disabled_payment_methods": "",
                    "list_enabled_banks": "002, 008, 009, 013, 022",
                    "list_enabled_ewallet": "shopeepay_ewallet",
                    "expiration": "' . date('Y-m-d H:i:s', strtotime('+1 week')) . '"
                }');

                // Kirim permintaan dan tanggapi
                try {
                    $response = $request->send();
                    if ($response->getStatus() == 000) {
                        // Proses tanggapan dan redirect ke halaman pembayaran OY!
                        $response_data = json_decode($response->getBody(), true);
                        $redirect_url = $response_data['redirect_url'];
                        wp_redirect($redirect_url);
                        exit;
                    } else {
                        wc_add_notice('Terjadi kesalahan saat menghubungi OY! Indonesia. Silakan coba lagi.', 'error');
                        return;
                    }
                } catch (HTTP_Request2_Exception $e) {
                    wc_add_notice('Error: ' . $e->getMessage(), 'error');
                    return;
                }
            }

            public function thank_you_page() {
                if ($this->instructions) {
                    echo wpautop($this->instructions);
                }
            }
        }
    }
}

add_filter('woocommerce_payment_gateways', 'add_oy_gateway');

function add_oy_gateway($gateways) {
    $gateways[] = 'WC_Gateway_OY';
    return $gateways;
}