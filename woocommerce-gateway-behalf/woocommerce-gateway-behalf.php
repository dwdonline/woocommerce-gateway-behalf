<?php
/**
 * Plugin Name: WooCommerce Behalf Gateway
 * Plugin URI: https://dwd.tech
 * Description: Allows you to offer Behalf.com signup on your checkout
 * Author: Philip N. Deatherage
 * Author URI: https://dwd.tech
 * Version: 1.0.1
 * Text Domain: wc-gateway-behalf
 *
 *
 * @package   wc-gateway-behalf
 * @author    Deatheage Web Development
 * @category  Admin
 *
 */

defined('ABSPATH') or exit;


// Make sure WooCommerce ion-holds active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

// add_filter('woocommerce_get_order_item_totals', function ($total_rows, $order, $tax_display) {
//     if ($order->get_payment_method() === 'behalf_gateway' && $order->get_transaction_id()) {
//         $total_rows['payment_method']['value'] .= '<p><b>Transaction ID:</b> ' . $order->get_transaction_id() . '</p>';
//     }
//     return $total_rows;
// }, 10, 3);

/**
 * Add the gateway to WC Available Gateways
 *
 * @param array $gateways all available WC gateways
 * @return array $gateways all WC gateways + behalf gateway
 * @since 1.0.0
 */
function wc_behalf_add_to_gateways($gateways)
{
    $gateways[] = 'WC_Gateway_Behalf';
    return $gateways;
}

add_filter('woocommerce_payment_gateways', 'wc_behalf_add_to_gateways');


/**
 * Adds plugin page links
 *
 * @param array $links all plugin links
 * @return array $links all plugin links + our custom links (i.e., "Settings")
 * @since 1.0.0
 */
function wc_behalf_gateway_plugin_links($links)
{

    $plugin_links = array(
        '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=behalf_gateway') . '">' . __('Configure', 'wc-gateway-behalf') . '</a>'
    );

    return array_merge($plugin_links, $links);
}

add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wc_behalf_gateway_plugin_links');


/**
 * Behalf Payment Gateway
 *
 * We load this later to ensure WC is loaded first since we're extending it.
 *
 * @class        WC_Gateway_Behalf
 * @extends        WC_Payment_Gateway
 * @version        1.0.0
 * @package        WooCommerce/Classes/Payment
 * @author
 */
add_action('plugins_loaded', 'wc_behalf_gateway_init', 11);

function wc_behalf_gateway_init()
{

    class WC_Gateway_Behalf extends WC_Payment_Gateway
    {

        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {

            $this->id = 'behalf_gateway';
            $this->icon = '/wp-content/plugins/woocommerce-gateway-behalf/assets/images/behalf_logo_gateway.svg';
            $this->has_fields = true;
            $this->method_title = __('Net Terms and Financing by Behalf.com', 'wc-gateway-behalf');
            $this->method_description = __('Allows customers to signup for Behalf financing. Orders are marked as "Pending payment" when received.', 'wc-gateway-behalf');

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = '<p class="behalf-description">' . $this->get_option('description') . '</p>';
            $this->behalf_instructions = '<p class="behalf-instructions">' . $this->get_option('behalf_instructions') . '</p>';
            $this->instructions = $this->get_option('instructions', $this->description);
            $this->behalf_token = $this->get_option('behalf_token');
            $this->behalf_order_status = $this->get_option('behalf_order_status');

            // Actions
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
            add_filter('woocommerce_payment_complete_order_status', [$this, 'set_on_hold'], 10, 3);

            // Customer Emails
            add_action('woocommerce_email_before_order_table', [$this, 'email_instructions'], 10, 3);
            add_action('wp_enqueue_scripts', [$this, 'wp_enqueue_scripts']);
        }


        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $this->form_fields = apply_filters('wc_behalf_form_fields', array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'wc-gateway-behalf'),
                    'type' => 'checkbox',
                    'label' => __('Enable Behalf Payment Gateway', 'wc-gateway-behalf'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'wc-gateway-behalf'),
                    'type' => 'text',
                    'description' => __('This controls the title for the payment method the customer sees during checkout.', 'wc-gateway-behalf'),
                    'default' => __('Net 30/60/90 and Financing by Behalf', 'wc-gateway-behalf'),
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => __('Description', 'wc-gateway-behalf'),
                    'type' => 'textarea',
                    'description' => __('Payment method description that the customer will see on your checkout.', 'wc-gateway-behalf'),
                    'default' => __('With Behalf, you can apply for Net Terms and Financing right here and get a decision. Or if you have a Behalf account, login here.</br>Once you have logged in, click continue to open Behalf in a new window or tab and complete the payment. Then return here, and finish the checkout.', 'wc-gateway-behalf'),
                    'desc_tip' => true,
                ),
                'behalf_instructions' => array(
                    'title' => __('Gateway Instructions', 'wc-gateway-behalf'),
                    'type' => 'textarea',
                    'description' => __('Instructions that the customer will see on your checkout under the Behalf widget.', 'wc-gateway-behalf'),
                    'default' => __('You can apply right here and get a decision, or log in if you already have a Behalf account.</br>Once you have logged in, click continue to open Behalf in a new window or tab and complete the payment. Then return here, and finish the checkout.', 'wc-gateway-behalf'),
                    'desc_tip' => true,
                ),
                'instructions' => array(
                    'title' => __('Order email text', 'wc-gateway-behalf'),
                    'type' => 'textarea',
                    'description' => __('Instructions/Message that will be added to the thank you page and emails.', 'wc-gateway-behalf'),
                    'default' => '<b>You selected Behalf.com as your payment method. If you have already completed the payment with Behalf, we will verify it shortly. If you have not already completed your payment, please <a href="https://app.behalf.com/users/signin">Login to your behalf.com account</a> and complete the Payment, and then let us know.</b>',
                    'desc_tip' => true,
                ),
                'behalf_token' => array(
                    'title' => __('Behalf Client Token', 'wc-gateway-behalf'),
                    'type' => 'text',
                    'description' => __('This is the client token received from Behalf.', 'wc-gateway-behalf'),
                    'desc_tip' => true,
                ),
                'behalf_order_status' => array(
                    'title'       => __( 'Order Status', 'wc-gateway-behalf' ),
                    'type'        => 'select',
                    'description' => __( 'Choose what order status you wish orders to be marked as after checkout.', 'wc-gateway-behalf' ),
                    'default'     => 'wc-pending',
                    'desc_tip'    => true,
                    'class'       => 'wc-enhanced-select',
                    'options'     => wc_get_order_statuses()
                ),
            ));
        }


        /**
         * Output for the order received page.
         */
        public function thankyou_page()
        {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }


        /**
         * Add content to the WC emails.
         *
         * @access public
         * @param WC_Order $order
         * @param bool $sent_to_admin
         * @param bool $plain_text
         */
        public function email_instructions($order, $sent_to_admin, $plain_text = false)
        {

            if ($this->instructions && !$sent_to_admin && $this->id === $order->payment_method && $order->has_status('pending-payment')) {
                echo wpautop(wptexturize($this->instructions)) . PHP_EOL;
            }
        }

        public function payment_fields()
        {
            parent::payment_fields();
            // if (!empty($this->behalf_code)) {
                // echo '<img class="qr-code-img" src="' . $this->behalf_code . '">';
                // echo '<div class="behalf-widget-code"' . $this->behalf_code . '"</div>';
				$behalfToken = $this->behalf_token;
				?>				
				<div id="behalf-payment-element"> </div>
				<style>
				 #behalf-payment-element {
				 min-width: 320px;
				 }
				</style>
				<script>
				 window.behalfPaymentReady = function() {
				 var config = {
				 "clientToken" : "<?php echo $behalfToken; ?>",
				 "showPromo" : true,
				 "callToAction" : {
				 "workflow" : "redirect"
				 }
				 }
				 BehalfPayment.init(config);
				 BehalfPayment.load("#behalf-payment-element");
				 };
				</script>
				<script src="https://sdk.behalf.com/sdk/v4/behalf_payment_sdk.js" async></script>
				<?php
                if (!empty($this->behalf_instructions)) {
                    echo '<p class="behalf-instructions">' . $this->behalf_instructions . '</p>';
                }
                // woocommerce_form_field('transaction-id', [
                //     'label' => 'Transaction ID',
                //     'required' => true
                // ]);
            // }
        }

        // public function validate_fields()
        // {
        //     if (empty($_POST['transaction-id'])) {
        //         wc_add_notice(__('Transaction ID is missing.', 'wc-gateway-behalf'), 'error');
        //         return false;
        //     }
        //     return true;
        // }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            // $order->payment_complete($_POST['transaction-id']);
            $order->add_order_note(__('Transaction to be checked.', 'wc-gateway-behalf'));
            // Remove cart
            WC()->cart->empty_cart();

            // Return thankyou redirect
            return array(
                'result' => 'success',
                'redirect' => $this->get_return_url($order)
            );
        }

        public function set_on_hold($status, $order_id, $order)
        {
            if ($order->get_payment_method() === 'behalf_gateway') {
                $order->add_order_note(__('Transaction to be checked.', 'wc-gateway-behalf'));
                // $status = 'hold';
				$status = $this->behalf_order_status;
            }
            return $status;
        }

        public function wp_enqueue_scripts(){
            wp_enqueue_style('behalf-pay', plugin_dir_url(__FILE__) . 'assets/css/behalf-pay.css');
        }

    } // end \WC_Gateway_Behalf class
}