<?php
/*
Plugin Name: Payant WooCommerce Payment Gateway
Plugin URI: https://www.payant.ng
Description: Payant Payment gateway for woocommerce
Version: 2.0
Author: Payant Team
Author URI: https://www.payant.ng
*/
 
add_action('plugins_loaded', 'payant_woocommerce_init', 0);

function payant_woocommerce_init() {
	if(!class_exists('WC_Payment_Gateway')) return;

	class WC_Payant extends WC_Payment_Gateway {
		public function __construct() {
			$this->id = 'payant';
			$this->method_title = 'Payant';
			$this->method_description = sprintf( 'Payant is the easiest way to accept payment from customers anywhere in the world. <a href="%1$s" target="_blank">Sign up</a> for a Payant account, and <a href="%2$s" target="_blank">get your API keys</a>.', 'https://payant.ng/get-started', 'https://payant.ng/settings/developer' );
			
			$this->has_fields = true;

			// Load the form fields
			$this->init_form_fields();

			// Load the settings
			$this->init_settings();

			// Get setting values
			$this->title 				= $this->settings['title'];
			$this->description 			= $this->settings['description'];
			$this->demo_mode 			= $this->settings['demo_mode'];

			$this->payment_methods 		= $this->settings['payment_methods'];
			$this->fee_bearer 			= $this->settings['fee_bearer'];
			
			$this->demo_public_key 		= $this->settings['demo_public_key'];
			$this->demo_private_key 	= $this->settings['demo_private_key'];

			$this->live_public_key 		= $this->settings['live_public_key'];
			$this->live_private_key 	= $this->settings['live_private_key'];

			$this->demourl 				= $this->settings['demo_base_url'];
			$this->liveurl 				= $this->settings['live_base_url'];

			// Hooks
			add_action( 'admin_notices', array( $this, 'admin_notices' ) );
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

			add_action('init', array(&$this, 'check_payant_response'));

		    // Payment listener/API hook
			add_action( 'woocommerce_api_wc_' . $this->id, array(&$this, 'check_payant_response'));
			add_action('woocommerce_receipt_' . $this->id, array(&$this, 'receipt_page')); 

			// Check if the gateway can be used
			if (!$this->is_valid_for_use()) {
				$this->enabled = false;
			}
		}


		/**
		 * Check if this gateway is enabled and available in the user's country.
		 */
		public function is_valid_for_use() {
			$valid            = true;

			$message          = '';

			$valid_countries  = array( 'NG' );
			$valid_currencies = array( 'NGN' );

			$base_location    = wc_get_base_location();

			if ( ! in_array( get_woocommerce_currency(), apply_filters( 'woocommerce_rave_supported_currencies',  $valid_currencies ) ) ) {
				
				$currencies = '';

				foreach( $valid_currencies as $currency ) {
					$currencies .= $currency . ' ('. get_woocommerce_currency_symbol( $currency ) .'), ';
				}

				$currencies = rtrim( $currencies, ', ' );

				$message .= 'Payant does not support your store currency. Kindly set it to ' . $currencies . ' <a href="' . admin_url( 'admin.php?page=wc-settings&tab=general' ) . '">here</a><br>';

				$valid = false;
			}

			if ( ! in_array( $base_location['country'], $valid_countries ) ) {

				$message .= 'Payant does not support your store country. You need to set it to Nigeria <a href="' . admin_url( 'admin.php?page=wc-settings&tab=general' ) . '">here</a>';

				$valid = false;
			}

			if ( ! $valid ) {
				$this->msg = $message;			
			}

			return $valid;
		}

		/**
		 * Check if Payant merchant details is filled
		 */
		public function admin_notices() {
			// Check required fields
			if ( ! ( $this->live_public_key && $this->live_private_key ) && ! ( $this->demo_public_key && $this->demo_private_key ) ) {
				echo '<div class="error"><p>' . sprintf( 'Please enter your Payant merchant details <a href="%s">here</a> to be able to use the Payant WooCommerce plugin.', admin_url( 'admin.php?page=wc-settings&tab=checkout&section=payant' ) ) . '</p></div>';
				return;
			}
		}

		/**
		 * Display paystack payment icon
		 */
		public function get_icon() {
			$icon  = '<img src="' . plugins_url( 'assets/images/payant-cards.png' , __FILE__ ) . '" alt="cards" />';
			return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
		}

		/**
		 * Initialise Gateway Settings Form Fields
		 */
		function init_form_fields() {
 
			$this->form_fields = array(
				'enabled' 		=> array(
					'title' 		=> __('Enable/Disable', 'payant'),
					'label'       	=> 'Enable Payant',
					'type' 			=> 'checkbox',
					'description' 		=> __('Enable Payant as a payment option on the checkout page.', 'payant'),
					'default' 		=> 'no',
					'desc_tip'    	=> true
				),
				'title' 		=> array(
					'title' 		=> __('Title:', 'payant'),
					'type'			=> 'text',
					'description' 	=> __('This controls the title which the user sees during checkout.', 'payant'),
					'desc_tip'    	=> true,
					'default' 		=> __('Payant', 'payant') 
				),
				'description' 	=> array(
					'title' 		=> __('Description:', 'payant'),
					'type' 			=> 'textarea',
					'description' 	=> __('This controls the description which the user sees during checkout.', 'payant'),
					'desc_tip'    	=> true,
					'default' 		=> __('Make payment using your debit/credit card, bank account & phone number.', 'payant') 
				),
				'demo_mode' 		=> array(
					'title' 		=> __('Demo Mode', 'payant'),
					'type' 			=> 'checkbox',
					'label' 		=> __('Enable Demo Mode.', 'payant'),
					'description' 	=> __('Demo mode enables you to test payments before going live. <br />Once you are ready to move to your LIVE account, uncheck this.'),
					'desc_tip'    	=> true,
					'default' 		=> 'yes' 
				),
				'fee_bearer' => array(
					'title'         => 'Fee Bearer',
					'type'          => 'select',
					'class'         => 'wc-enhanced-select',
					'description'   => 'Who bears the transaction fees?',
					'desc_tip' 		=> true,
					'default'       => '',
					'options'       => array(
						'client'      	=> 'Client: My customer bears the fees',
						'account'      	=> 'Account: I will bear the fees'
					)
				),
				'demo_base_url' => array(
					'title' 		=> __('Demo API Base URL', 'payant'),
					'type' 			=> 'text',
					'desc_tip'    	=> true,
					'default' 		=> 'https://api.demo.payant.ng' 
				),
				'demo_public_key' => array(
					'title' 		=> __('Demo Public Key', 'payant'),
					'type' 			=> 'text',
					'description' 	=> __('Enter your Demo Public Key here.'),
					'desc_tip'    	=> true 
				),
				'demo_private_key' => array(
					'title' 		=> __('Demo Private Key', 'payant'),
					'type' 			=> 'text',
					'description' 	=> __('Enter your Demo Private Key here.'),
					'desc_tip'    	=> true 
				),
				'live_base_url' => array(
					'title' 		=> __('Live API Base URL', 'payant'),
					'type' 			=> 'text',
					'default' 		=> 'https://api.payant.ng',
					'desc_tip'    	=> true 
				),
				'live_public_key' => array(
					'title' 		=> __('Live Public Key', 'payant'),
					'type' 			=> 'text',
					'description' 	=> __('Enter your Live Private Key here.'),
					'desc_tip'    	=> true 
				),
				'live_private_key' => array(
					'title' 		=> __('Live Private Key', 'payant'),
					'type' 			=> 'text',
					'description' 	=> __('Enter your Live Private Key here.'),
					'desc_tip'    	=> true 
				),
				'payment_methods' => array(
					'title'         => 'Payment Methods',
					'type'          => 'multiselect',
					'class'         => 'wc-enhanced-select',
					'description'   => 'Select the payment methods you want for your customers.',
					'desc_tip' 		=> true,
					'default'       => '',
					'options'       => array(
						'card'      	=> 'Card',
						'bank'      	=> 'Bank',
						'phone'     	=> 'Phone',
						'qr'        	=> 'QR'
					),
					'custom_attributes' => array(
						'data-placeholder' => 'Select payment methods',
					),
				),
			);
		}

		/**
	     * Admin Panel Options
	    */
		public function admin_options() {
	        echo '<h3>'.__('Payant Payment Gateway', 'payant').'</h3>';
	        echo '<p>'.__('Payant is the easiest way to accept payment from customers anywhere in the world.').'</p>';
	        echo '<table class="form-table">';
	        // Generate the HTML For the settings form.
	        $this->generate_settings_html();
	        echo '</table>';
	    }

	    /**
	     * Receipt Page
	     **/
	    function receipt_page($order) {
	        echo '<p>'.__('Thank you for your order, please click the button below to pay with your Credit/Debit Card.', 'payant').'</p>';
	        echo $this->generate_payant_form($order);
	    }

	    /**
	     * Generate Payant button link
	     **/
	    public function generate_payant_form($order_id) {
	 
	        global $woocommerce;
	 
	        $order = new WC_Order($order_id);
	        $order_items = $order->get_items();

	        $txnid = $order_id.'_'.date("ymds");

	        $items = array();

	        foreach ($order_items as $key => $item) {
	        	array_push($items, array(
	        		'item' => $item['name'],
	        		'description' => $item['name'],
	        		'unit_cost' => $item['line_subtotal']/$item['qty'],
	        		'quantity' => $item['qty']
	        		));
	        }

	        if($order->get_total_shipping() > 0) {
	        	array_push($items, array(
	        		'item' => 'Shipping',
	        		'description' => 'Total Shipping Cost',
	        		'unit_cost' => $order->get_total_shipping(),
	        		'quantity' => 1
	        		));
	        }
	 
	        $redirect_url = ($this->redirect_page_id == "" || $this->redirect_page_id == 0) ? get_site_url()."/":get_permalink($this->redirect_page_id);
	
	        return '
	        	<form action="'.WC()->api_request_url( 'WC_Payant' ).'" method="POST" id="payantForm">
	        	  <input type="hidden" name="txnid" value="'.$txnid.'" />
	        	  <input type="hidden" name="reference_code" id="reference_code" value="" />

				  <script src="'.($this->demo_mode == "yes" ? $this->demourl : $this->liveurl).'/assets/js/inline.min.js"></script>
				  <button type="button" onclick="payWithPayant()"> Pay </button> 
				</form>

				<script>
				  function payWithPayant() {
				    var handler = Payant.invoice({
				      "key": "'.($this->demo_mode == "yes" ? $this->demo_public_key : $this->live_public_key).'",
				      "client": {
				      	"first_name": "'.$order->billing_first_name.'",
				      	"last_name": "'.$order->billing_last_name.'",
				      	"phone": "'.(substr($order->billing_phone, 0, 1) == '+' ? $order->billing_phone : ((substr($order->billing_phone, 0, 1) == "0") ? "+234".substr($order->billing_phone, 1) : $order->billing_phone)).'",
				      	"email": "'.$order->billing_email.'"
				      },
				      "due_date": "31/12/2018",
				      "fee_bearer": "'.$this->fee_bearer.'",
				      "items": '.json_encode($items).',
				      "payment_methods": '.json_encode($this->payment_methods).',
				      callback: function(response) {
				      	document.getElementById("reference_code").value = response.reference_code;
				      	document.getElementById("payantForm").submit();
				      },
				      onClose: function() {
				        alert("Payment Window Closed.");
				      }
				    });

				    handler.openIframe();
				  }
				</script>';
	    }

	    /**
	     * Process the payment and return the result
	     **/
	    function process_payment($order_id) {
	        $order = new WC_Order($order_id);

	        if (version_compare('2.7', WOOCOMMERCE_VERSION, '>')) {
	            return array(
	                'result' => 'success',
	                'redirect' => add_query_arg(
	                    'key',
	                    $order->order_key,
	                    add_query_arg(
	                        'order',
	                        $order->id,
	                        $order->get_checkout_payment_url(true)
	                    )
	                )
	            );
	        } else {
	            return array(
	                'result' => 'success',
	                'redirect' => add_query_arg(
	                    'key',
	                    $order->get_order_key(),
	                    $order->get_checkout_payment_url(true)
	                )
	            );
	        }

	    }

	    /**
	     * Check for valid payant server callback
	     **/
	    function check_payant_response() {
	        global $woocommerce;
	        if(isset($_REQUEST['txnid']) && isset($_REQUEST['reference_code'])){
	            $order_id_time = $_REQUEST['txnid'];
	            $order_id = explode('_', $_REQUEST['txnid']);
	            $order_id = (int)$order_id[0];

	            if($order_id != ''){
	                try {	                	
	                    $order = new WC_Order($order_id);
	                    $payant_ref = trim(htmlentities($_REQUEST['reference_code']));

	                    $headers = array(
							'Content-Type'	=> 'application/json',
							'Authorization' => 'Bearer ' . ($this->demo_mode == "yes" ? $this->demo_private_key : $this->live_private_key)
						);

						$body = array();

						$args = array(
							'body'		=> json_encode( $body ),
							'headers'	=> $headers,
							'timeout'	=> 60
						);

						$request = wp_remote_get(($this->demo_mode == "yes" ? $this->demourl : $this->liveurl).'/payments/'.$payant_ref, $args);

						if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request)) {
							$response = json_decode(wp_remote_retrieve_body($request));

							if('success' == $response->status) {
								$order_total	= $order->get_total();

	        					$amount_paid	= $response->data->amount;

	        					if ( in_array( $order->get_status(), array( 'processing', 'completed', 'on-hold' ) ) ) {

						        	wp_redirect( $this->get_return_url( $order ) );

									exit;

						        }

	        					if($amount_paid != $order_total) {
	        						$order->update_status( 'on-hold', '' );

									add_post_meta( $order_id, '_transaction_id', $payant_ref, true );

									$notice = 'Thank you for shopping with us.<br />Your payment transaction was successful, but the amount paid is not the same as the total order amount.<br />Your order is currently on-hold.<br />Kindly contact us for more information regarding your order and payment status.';
									$notice_type = 'notice';

									// Add Customer Order Note
				                    $order->add_order_note( $notice, 1 );

				                    // Add Admin Order Note
				                    $order->add_order_note('<strong>Look into this order</strong><br />This order is currently on hold.<br />Reason: Amount paid is less than or greater than the total order amount.<br />Amount Paid was <strong>&#8358;'.$amount_paid.'</strong> while the total order amount is <strong>&#8358;'.$order_total.'</strong><br />Payant Transaction Reference: '.$payant_ref );

				                    wc_reduce_stock_levels( $order_id );

									wc_add_notice( $notice, $notice_type );
	        					}else {
	        						$order->payment_complete( $payant_ref );

									$order->add_order_note( sprintf( 'Payment via Payant was successful (Transaction Reference: %s)', $payant_ref ) );

									$this->msg['message'] = "Payment via Payant successful (Transaction Reference: %s)";
	                    			$this->msg['class'] = 'woocommerce_message';
	        					}

	        					wc_empty_cart();
							}else {
								$order = wc_get_order($order_id);

								$order->update_status('failed', 'Payment was declined by Payant.');

								wc_add_notice('Payment Failed. Try again.', 'error');

								$this->msg['class'] = 'woocommerce_error';
	                                $this->msg['message'] = "Thank you for shopping with us. However, Your payment was declined by Payant.";
							}

							wp_redirect( $this->get_return_url( $order ) );

							exit;
						}

						wc_add_notice( 'Payment failed. Try again.', 'error');

						wp_redirect( wc_get_page_permalink( 'checkout' ) );

						exit;
					}catch(Exception $e){
                        wc_add_notice( 'Payment failed. Try again.', 'error');

						wp_redirect( wc_get_page_permalink( 'checkout' ) );

						exit;
                    }
	            }
	        }

	        wc_add_notice( 'Payment failed. Try again.', 'error');

			wp_redirect( wc_get_page_permalink( 'checkout' ) );

			exit;
	    }

        /**
         * Get all pages
         */
	    function get_pages($title = false, $indent = true) {
	        $wp_pages = get_pages('sort_column=menu_order');
	        $page_list = array();
	        if ($title) $page_list[] = $title;
	        foreach ($wp_pages as $page) {
	            $prefix = '';
	            // show indented child pages?
	            if ($indent) {
	                $has_parent = $page->post_parent;
	                while($has_parent) {
	                    $prefix .=  ' - ';
	                    $next_page = get_page($has_parent);
	                    $has_parent = $next_page->post_parent;
	                }
	            }
	            // add to page list array array
	            $page_list[$page->ID] = $prefix . $page->post_title;
	        }
	        return $page_list;
	    }
	}

	/**
	* Add the Gateway to WooCommerce
	**/
	function woocommerce_add_payant_gateway($methods) {
		$methods[] = 'WC_Payant';
		return $methods;
	}

	add_filter('woocommerce_payment_gateways', 'woocommerce_add_payant_gateway');
}












