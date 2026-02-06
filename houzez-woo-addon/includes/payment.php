<?php
if ( ! class_exists( 'Houzez_Woo_Payment' ) ) {

	class Houzez_Woo_Payment {

		public function __construct() {
	        
	        add_action( 'woocommerce_remove_cart_item',  array($this, 'woo_cart_updated'), 10, 2 );
	        add_action( 'wp_ajax_houzez_perlist_woo_pay',         array( $this, 'houzez_perlist_woo_pay') );
        	add_action( 'wp_ajax_mopriv_houzez_perlist_woo_pay',  array( $this, 'houzez_perlist_woo_pay') );
        	add_action( 'wp_ajax_houzez_woo_pay_package',         array( $this, 'houzez_woo_pay_package') );
        	add_action( 'wp_ajax_mopriv_houzez_woo_pay_package',  array( $this, 'houzez_woo_pay_package') );
        	add_action( 'houzez_per_listing_woo_payment', array( $this, 'per_listing_while_submission' ), 10, 1 );
        	add_action( 'woocommerce_order_status_completed',       array( $this, 'woo_payment_complete') );
        	add_action( 'woocommerce_order_status_processing',      array( $this, 'woo_payment_complete') );
        	add_action( 'woocommerce_order_status_refunded',        array( $this, 'woo_payment_refunded') );
        	add_action( 'woocommerce_order_status_cancelled',       array( $this, 'woo_payment_cancelled') );
        	add_action( 'woocommerce_order_status_failed',          array( $this, 'woo_payment_failed') );
        	add_action( 'woocommerce_order_fully_refunded',         array( $this, 'woo_payment_refunded'), 10, 2 );
        	add_action( 'woocommerce_order_partially_refunded',     array( $this, 'woo_payment_refunded'), 10, 2 );
        	add_filter( 'woocommerce_cart_item_permalink','__return_false');
        	add_action( 'woocommerce_before_single_product',        array( $this, 'product_redirect') );
        	add_action( 'woocommerce_product_query',                array( $this, 'custom_pre_get_posts_query' ) );
		}

		public function houzezWooCommerce() {

	        if( houzez_option('houzez_payment_gateways', 'houzez_custom_gw') == 'gw_woocommerce' ) {
	            return true;
	        } else {
	            return false;
	        }
	    }

	    function woo_cart_updated( $cart_item_key, $cart ) {

		    $product_id = $cart->cart_contents[ $cart_item_key ]['product_id']; 
		    $is_houzez_woocommerce = intval( get_post_meta( $product_id, '_is_houzez_woocommerce', true ) );

		    if( $is_houzez_woocommerce == 1 ) {
		    	wp_delete_post( $product_id );
		    }
		  
		}

	    function per_listing_while_submission( $listing_id ) {

	    	$listing_id   = intval($listing_id);
	    	$is_featured   = 0;

	    	$product_id  = $this->checkIfAlreadyInCart($listing_id);

	    	if( $product_id == 0 ) {
	    		$product_id = $this->houzez_per_listing_payment($listing_id, $is_featured);
	    	}

	    	$cart = WC()->cart->add_to_cart( $product_id, 1, '', [], [ '__booking_data' => '' ] );
	       	
	       	$woo_checkout_url = wc_get_checkout_url();
	    	wp_redirect($woo_checkout_url);
	    	exit();
	    }

	    function houzez_perlist_woo_pay() {

	    	$listing_id   = intval($_POST['listing_id']);
	    	$is_featured   = intval($_POST['is_featured']);

	    	$product_id  = $this->checkIfAlreadyInCart($listing_id);

	    	if( $product_id == 0 ) {
	    		$product_id = $this->houzez_per_listing_payment($listing_id, $is_featured);
	    	}

	    	$cart = WC()->cart->add_to_cart( $product_id, 1, '', [], [ '__booking_data' => '' ] );
	       
	    	return $cart;
	    }

	    function houzez_woo_pay_package() {

	    	$package_id   = intval($_POST['package_id']);

	    	$product_id  = $this->checkIfAlreadyInCart($package_id);

	    	if( $product_id == 0 ) {
	    		$product_id = $this->houzez_package_payment($package_id);
	    	}

	    	$cart = WC()->cart->add_to_cart( $product_id, 1, '', [], [ '__booking_data' => '' ] );
	       
	    	return $cart;
	    }


	    function woo_payment_complete( $order_id ) {
	        $order    = wc_get_order( $order_id );
	        $items = $order->get_items();

	        foreach( $items as $item ) {

	            $product_id = $item['product_id'];
	            $order_title = $item['name'];

	            $is_woocommerce = intval( get_post_meta( $product_id, '_is_houzez_woocommerce', true ) );
	            $payment_mode 	= get_post_meta( $product_id, '_is_houzez_payment_mode', true );

	            if( $payment_mode == 'per_listing' ) {

	            	$this->per_listing_payment_completed( $product_id, $order, $order_title );

	            } else if( $payment_mode == 'package' ) {
	            	$this->package_payment_completed( $product_id, $order, $order_title );
	            }

	            // Save metadata to order item BEFORE deleting product for refund support
	            if( $is_woocommerce == 1 ) {
	            	$this->save_houzez_order_item_meta( $item, $product_id );
			    	wp_delete_post( $product_id );
			    }

	        }

	    }

	    function houzez_per_listing_payment($listing_id, $is_featured ) {

	    	$current_user = wp_get_current_user();
			$userID       = get_current_user_id();
			$user_email   = $current_user->user_email;
	        
	        if( $is_featured == 1 ) {

	        	$listing_price = houzez_option('price_featured_listing_submission');
	        	$product_title = sprintf( esc_html__('Upgrade to "Featured" for Listing "%s" with id %s', 'houzez-woo-addon'), get_the_title($listing_id),$listing_id);

	        } else {

	        	$listing_price = houzez_option('price_listing_submission');
	        	$product_title = sprintf( esc_html__('Payment for Listing "%s" with id %s', 'houzez-woo-addon'), get_the_title($listing_id),$listing_id);
	        }
	    	
	        $args = array(
                'post_content'   => '',
                'post_status'    => "publish",
                'post_title'     => $product_title,
                'post_parent'    => '',
                'post_type'      => "product",
                'comment_status' => 'closed'
            );

	        $product_id = wp_insert_post( $args );
	        
	        
	        update_post_meta( $product_id, '_is_houzez_woocommerce', true );
	        update_post_meta( $product_id, '_is_houzez_payment_mode', 'per_listing' );
	        update_post_meta( $product_id, '_virtual', 'yes' );  //no
	        update_post_meta( $product_id, '_sold_individually', 'yes' ); //no
	        update_post_meta( $product_id, '_manage_stock', 'no' ); //no
	        update_post_meta( $product_id, '_featured', 'no' );
	        update_post_meta( $product_id, '_stock_status', 'instock' ); //instock
	        update_post_meta( $product_id, '_visibility', 'visible' );
	        update_post_meta( $product_id, '_downloadable', 'no' ); //no
	        update_post_meta( $product_id, '_invoice_id', $listing_id );
	        update_post_meta( $product_id, '_backorders', 'no' ); //no
	        update_post_meta( $product_id, '_price', $listing_price ); //''
	        update_post_meta( $product_id, '_houzez_listing_id', $listing_id );
	        update_post_meta( $product_id, '_houzez_is_featured', $is_featured );
	        update_post_meta( $product_id, '_houzez_featured_listing_date', current_time( 'mysql' ) );
	        update_post_meta( $product_id, 'houzez_featured_listing_date', current_time( 'mysql' ) );
	        update_post_meta( $product_id, '_houzez_user_id', $userID );
	        update_post_meta( $product_id, '_houzez_user_email', $user_email );
	        
	        update_post_meta( $product_id, '_wc_min_qty_product', 1 );
	        update_post_meta( $product_id, '_wc_max_qty_product', 1 );
	        $data_variation = [
	            'types' => [
	                'name'         => 'types',
	                'value'        => 'service',
	                'position'     => 0,
	                'is_visible'   => 1,
	                'is_variation' => 1,
	                'is_taxonomy'  => 1
	            ]
	        ];
	        update_post_meta( $product_id, '_product_attributes', $data_variation );
	        update_post_meta( $product_id, '_product_version', '4.2.0' );
	        
	        return $product_id;
	        
	    }

	    function houzez_package_payment( $package_id ) {

	    	$current_user = wp_get_current_user();
			$userID       = get_current_user_id();
			$user_email   = $current_user->user_email;

			$pack_price = get_post_meta( $package_id, 'fave_package_price', true );
	        
	        $product_title = sprintf( esc_html__('Payment for package "%s"', 'houzez-woo-addon'), get_the_title($package_id));
	    	
	        $args = array(
                'post_content'   => '',
                'post_status'    => "publish",
                'post_title'     => $product_title,
                'post_parent'    => '',
                'post_type'      => "product",
                'comment_status' => 'closed'
            );

	        $product_id = wp_insert_post( $args );
	        
	        
	        update_post_meta( $product_id, '_is_houzez_woocommerce', true );
	        update_post_meta( $product_id, '_is_houzez_payment_mode', 'package' );
	        update_post_meta( $product_id, '_virtual', 'yes' );  //no
	        update_post_meta( $product_id, '_sold_individually', 'yes' ); //no
	        update_post_meta( $product_id, '_manage_stock', 'no' ); //no
	        update_post_meta( $product_id, '_featured', 'no' );
	        update_post_meta( $product_id, '_stock_status', 'instock' ); //instock
	        update_post_meta( $product_id, '_visibility', 'visible' );
	        update_post_meta( $product_id, '_downloadable', 'no' ); //no
	        update_post_meta( $product_id, '_invoice_id', $package_id );
	        update_post_meta( $product_id, '_backorders', 'no' ); //no
	        update_post_meta( $product_id, '_price', $pack_price ); //''
	        update_post_meta( $product_id, '_houzez_package_id', $package_id );
	        update_post_meta( $product_id, '_houzez_user_id', $userID );
	        update_post_meta( $product_id, '_houzez_user_email', $user_email );
	        
	        update_post_meta( $product_id, '_wc_min_qty_product', 1 );
	        update_post_meta( $product_id, '_wc_max_qty_product', 1 );
	        $data_variation = [
	            'types' => [
	                'name'         => 'types',
	                'value'        => 'service',
	                'position'     => 0,
	                'is_visible'   => 1,
	                'is_variation' => 1,
	                'is_taxonomy'  => 1
	            ]
	        ];
	        update_post_meta( $product_id, '_product_attributes', $data_variation );
	        update_post_meta( $product_id, '_product_version', '4.2.0' );
	        
	        return $product_id;
	        
	    }


	    function checkIfAlreadyInCart($invoice_no) {
           
	       $product_id = 0;

           $args = array(
                'post_type'      => 'product',
                'meta_key'       => '_invoice_id',
                'meta_value'     => $invoice_no,
                'posts_per_page' => 1
            );
          
            $qry = new WP_Query( $args );

            if ( $qry->have_posts() ):
                while ( $qry->have_posts() ): $qry->the_post();
                    $product_id =  get_the_ID();
                endwhile;
            endif;

            return $product_id;
     	}

     	function per_listing_payment_completed( $product_id, $woo_order, $order_title ) {

     		$admin_email  =  get_bloginfo('admin_email');
			$payment_method_title = $woo_order->get_payment_method_title();

			$time = time();
			$date = date('Y-m-d H:i:s',$time);

			$is_featured = get_post_meta( $product_id, '_houzez_is_featured', true );
	        $listing_id = intval( get_post_meta( $product_id, '_houzez_listing_id', true ) );
	        $userID = intval( get_post_meta( $product_id, '_houzez_user_id', true ) );
	        $user_email = get_post_meta( $product_id, '_houzez_user_email', true );

			if( $is_featured == 1 ) {

	            update_post_meta( $listing_id, 'fave_featured', 1 );
	            update_post_meta( $listing_id, 'houzez_featured_listing_date', current_time( 'mysql' ) );
	            $invoice_id = houzez_generate_invoice( $order_title, 'one_time', $listing_id, $date, $userID, 0, 1, '', $payment_method_title );
	            update_post_meta( $invoice_id, 'invoice_payment_status', 1 );

	            $args = array(
	                'listing_title'  =>  get_the_title($listing_id),
	                'listing_id'     =>  $listing_id,
	                'invoice_no' =>  $invoice_id,
	            );

	            /*
	             * Send email
	             * */
	            houzez_email_type( $user_email, 'featured_submission_listing', $args);
	            houzez_email_type( $admin_email, 'admin_featured_submission_listing', $args);

	        } else {
	            update_post_meta( $listing_id, 'fave_payment_status', 'paid' );

	            $paid_submission_status    = houzez_option('enable_paid_submission');
	            $listings_admin_approved = houzez_option('listings_admin_approved');

	            if( $listings_admin_approved != 'yes'  && $paid_submission_status == 'per_listing' ){
	                $post = array(
	                    'ID'            => $listing_id,
	                    'post_status'   => 'publish',
	                    'post_date'     => current_time( 'mysql' )
	                );
	                $post_id =  wp_update_post($post );
	            } 

	            $invoice_id = houzez_generate_invoice( $order_title, 'one_time', $listing_id, $date, $userID, 0, 0, '', $payment_method_title );
	            update_post_meta( $invoice_id, 'invoice_payment_status', 1 );

	            $args = array(
	                'listing_title'  =>  get_the_title($listing_id),
	                'listing_id'     =>  $listing_id,
	                'invoice_no' =>  $invoice_id,
	            );

	            /*
	             * Send email
	             * */
	            houzez_email_type( $user_email, 'paid_submission_listing', $args);
	            houzez_email_type( $admin_email, 'admin_paid_submission_listing', $args);
	        }
     	}

     	function package_payment_completed( $product_id, $woo_order, $order_title ) {

     		$admin_email  =  get_bloginfo('admin_email');
			$payment_method_title = $woo_order->get_payment_method_title();

			$time = time();
			$date = date('Y-m-d H:i:s',$time);

	        $package_id = intval( get_post_meta( $product_id, '_houzez_package_id', true ) );
	        $userID = intval( get_post_meta( $product_id, '_houzez_user_id', true ) );
	        $user_email = get_post_meta( $product_id, '_houzez_user_email', true );

	        houzez_save_user_packages_record($userID);
	        if( houzez_check_user_existing_package_status($userID, $package_id) ){
	            houzez_downgrade_package( $userID, $package_id );
	            houzez_update_membership_package($userID, $package_id);
	        }else{
	            houzez_update_membership_package($userID, $package_id);
	        }

	        $invoiceID = houzez_generate_invoice( $order_title, 'one_time', $package_id, $date, $userID, 0, 0, '', $payment_method_title, 1 );
	        update_post_meta( $invoiceID, 'invoice_payment_status', 1 );

	        update_user_meta( $userID, 'houzez_has_stripe_recurring', 0 );

	        $args = array();
	        houzez_email_type( $user_email,'purchase_activated_pack', $args );

     	}

     	/**
     	 * Save Houzez metadata to order item for later retrieval during refunds
     	 * This must be called BEFORE deleting the product.
     	 *
     	 * @param WC_Order_Item_Product $item The order item
     	 * @param int $product_id The product ID
     	 */
     	private function save_houzez_order_item_meta( $item, $product_id ) {
     		$payment_mode = get_post_meta( $product_id, '_is_houzez_payment_mode', true );
     		$item->add_meta_data( '_houzez_payment_mode', $payment_mode, true );

     		$user_id = intval( get_post_meta( $product_id, '_houzez_user_id', true ) );
     		$user_email = get_post_meta( $product_id, '_houzez_user_email', true );
     		$item->add_meta_data( '_houzez_user_id', $user_id, true );
     		$item->add_meta_data( '_houzez_user_email', $user_email, true );

     		if ( $payment_mode == 'per_listing' ) {
     			$listing_id = intval( get_post_meta( $product_id, '_houzez_listing_id', true ) );
     			$is_featured = intval( get_post_meta( $product_id, '_houzez_is_featured', true ) );
     			$item->add_meta_data( '_houzez_listing_id', $listing_id, true );
     			$item->add_meta_data( '_houzez_is_featured', $is_featured, true );
     		} elseif ( $payment_mode == 'package' ) {
     			$package_id = intval( get_post_meta( $product_id, '_houzez_package_id', true ) );
     			$item->add_meta_data( '_houzez_package_id', $package_id, true );
     		}

     		$item->save();
     	}

     	/**
     	 * Handle WooCommerce order refund - revoke membership and revert listings
     	 *
     	 * @param int $order_id The order ID
     	 * @param int $refund_id Optional refund ID (from WooCommerce hooks)
     	 */
     	public function woo_payment_refunded( $order_id, $refund_id = null ) {
     		$order = wc_get_order( $order_id );

     		if ( ! $order ) {
     			return;
     		}

     		$items = $order->get_items();

     		foreach ( $items as $item ) {
     			// Try order item meta first (persisted data)
     			$payment_mode = $item->get_meta( '_houzez_payment_mode' );

     			// Fallback to product meta for backwards compatibility
     			if ( empty( $payment_mode ) ) {
     				$product_id = $item['product_id'];
     				$payment_mode = get_post_meta( $product_id, '_is_houzez_payment_mode', true );
     			}

     			if ( $payment_mode == 'package' ) {
     				$this->handle_package_refund( $item, $order_id );
     			} elseif ( $payment_mode == 'per_listing' ) {
     				$this->handle_per_listing_refund( $item, $order_id );
     			}
     		}
     	}

     	/**
     	 * Handle package refund - revoke membership and expire properties
     	 *
     	 * @param WC_Order_Item_Product $item The order item
     	 * @param int $order_id The order ID
     	 */
     	private function handle_package_refund( $item, $order_id ) {
     		// Try order item meta first (persisted data)
     		$package_id = intval( $item->get_meta( '_houzez_package_id' ) );
     		$userID = intval( $item->get_meta( '_houzez_user_id' ) );

     		// Fallback to product meta for backwards compatibility
     		if ( ! $package_id || ! $userID ) {
     			$product_id = $item['product_id'];
     			if ( ! $package_id ) {
     				$package_id = intval( get_post_meta( $product_id, '_houzez_package_id', true ) );
     			}
     			if ( ! $userID ) {
     				$userID = intval( get_post_meta( $product_id, '_houzez_user_id', true ) );
     			}
     		}

     		if ( ! $userID || ! $package_id ) {
     			return;
     		}

     		// Cancel membership - inline logic from houzez_cancel_user_membership()
     		// We can't call that function because it ends with wp_die() which breaks WooCommerce admin
     		$current_package_id = get_user_meta( $userID, 'package_id', true );
     		$current_package_id = intval( $current_package_id );

     		if ( $current_package_id === $package_id ) {
     			// Fire before action
     			do_action( 'houzez_before_delete_user_membership', $userID, $package_id );

     			// Delete user meta
     			delete_user_meta( $userID, 'package_id' );
     			delete_user_meta( $userID, 'package_listings' );
     			delete_user_meta( $userID, 'package_activation' );
     			delete_user_meta( $userID, 'package_featured_listings' );

     			// Update subscription status
     			update_user_meta( $userID, 'houzez_subscription_detail_status', 'expired' );
     			delete_user_meta( $userID, 'fave_stripe_user_profile' );
     			delete_user_meta( $userID, 'houzez_stripe_subscription_id' );
     			delete_user_meta( $userID, 'houzez_stripe_subscription_start' );
     			delete_user_meta( $userID, 'houzez_stripe_subscription_due' );
     			update_user_meta( $userID, 'houzez_has_stripe_recurring', 0 );
     			update_user_meta( $userID, 'houzez_is_recurring_membership', 0 );

     			delete_user_meta( $userID, 'houzez_subscription_order_number' );
     			delete_user_meta( $userID, 'houzez_subscription_session_id' );
     			delete_user_meta( $userID, 'houzez_subscription_plan_id' );
     			delete_user_meta( $userID, 'houzez_membership_id' );
     			delete_user_meta( $userID, 'houzez_payment_method' );

     			// Fire after action
     			do_action( 'houzez_after_user_membership_cancelled', $userID, $package_id );

     			// Send email notification
     			$user = get_user_by( 'id', $userID );
     			if ( $user && function_exists( 'houzez_email_type' ) ) {
     				houzez_email_type( $user->user_email, 'membership_cancelled', array() );
     			}
     			// NOTE: We skip wp_die() here - that was causing the blank screen issue
     		}

     		// Delete the user_packages record
     		$this->delete_user_package_record( $userID, $package_id );

     		// Mark properties created with this package as expired
     		$this->expire_package_properties( $userID, $package_id );

     		// Update invoice status to unpaid
     		$this->update_invoice_status( $userID, $package_id, 0 );

     		// Add order note for tracking
     		$order = wc_get_order( $order_id );
     		if ( $order ) {
     			$order->add_order_note(
     				sprintf(
     					__( 'Membership package (ID: %d) cancelled for user (ID: %d). Properties marked as expired.', 'houzez-woo-addon' ),
     					$package_id,
     					$userID
     				)
     			);
     		}
     	}

     	/**
     	 * Handle per-listing refund - unpublish listing and update invoice
     	 *
     	 * @param WC_Order_Item_Product $item The order item
     	 * @param int $order_id The order ID
     	 */
     	private function handle_per_listing_refund( $item, $order_id ) {
     		// Try order item meta first (persisted data)
     		$listing_id = intval( $item->get_meta( '_houzez_listing_id' ) );
     		$userID = intval( $item->get_meta( '_houzez_user_id' ) );
     		$is_featured = intval( $item->get_meta( '_houzez_is_featured' ) );

     		// Fallback to product meta for backwards compatibility
     		if ( ! $listing_id || ! $userID ) {
     			$product_id = $item['product_id'];
     			if ( ! $listing_id ) {
     				$listing_id = intval( get_post_meta( $product_id, '_houzez_listing_id', true ) );
     			}
     			if ( ! $userID ) {
     				$userID = intval( get_post_meta( $product_id, '_houzez_user_id', true ) );
     			}
     			if ( ! $is_featured ) {
     				$is_featured = intval( get_post_meta( $product_id, '_houzez_is_featured', true ) );
     			}
     		}

     		if ( ! $listing_id ) {
     			return;
     		}

     		if ( $is_featured == 1 ) {
     			// Refund for featured upgrade - remove featured status
     			update_post_meta( $listing_id, 'fave_featured', 0 );
     			delete_post_meta( $listing_id, 'houzez_featured_listing_date' );

     			$note_message = sprintf(
     				__( 'Featured status removed from listing (ID: %d) due to refund.', 'houzez-woo-addon' ),
     				$listing_id
     			);

     		} else {
     			// Refund for listing payment - unpublish listing
     			wp_update_post( array(
     				'ID' => $listing_id,
     				'post_status' => 'draft'
     			) );

     			// Update payment status
     			delete_post_meta( $listing_id, 'fave_payment_status' );

     			$note_message = sprintf(
     				__( 'Listing (ID: %d) set to draft due to refund.', 'houzez-woo-addon' ),
     				$listing_id
     			);
     		}

     		// Update associated invoice to unpaid
     		if ( $userID && $listing_id ) {
     			$this->update_listing_invoice_status( $userID, $listing_id, 0 );
     		}

     		// Add order note
     		$order = wc_get_order( $order_id );
     		if ( $order ) {
     			$order->add_order_note( $note_message );
     		}
     	}

     	/**
     	 * Delete user_packages custom post type record
     	 *
     	 * @param int $userID User ID
     	 * @param int $package_id Package ID
     	 */
     	private function delete_user_package_record( $userID, $package_id ) {
     		$args = array(
     			'post_type' => 'user_packages',
     			'author' => $userID,
     			'meta_query' => array(
     				array(
     					'key' => 'user_packages_id',
     					'value' => $package_id,
     					'compare' => '='
     				)
     			),
     			'posts_per_page' => -1,
     			'fields' => 'ids'
     		);

     		$user_packages = get_posts( $args );

     		foreach ( $user_packages as $post_id ) {
     			wp_delete_post( $post_id, true );
     		}
     	}

     	/**
     	 * Mark properties created with package as expired/pending
     	 *
     	 * @param int $userID User ID
     	 * @param int $package_id Package ID
     	 */
     	private function expire_package_properties( $userID, $package_id ) {
     		// Get all properties by this user
     		$args = array(
     			'post_type' => 'property',
     			'author' => $userID,
     			'post_status' => 'publish',
     			'posts_per_page' => -1,
     			'fields' => 'ids'
     		);

     		$properties = get_posts( $args );

     		foreach ( $properties as $property_id ) {
     			// Check if property was created with this package
     			// We'll mark all published properties as pending since package is revoked
     			wp_update_post( array(
     				'ID' => $property_id,
     				'post_status' => 'pending'
     			) );

     			// Add a note about why it's expired
     			update_post_meta( $property_id, 'fave_property_expired_reason', 'package_refunded' );
     		}
     	}

     	/**
     	 * Update listing invoice payment status
     	 *
     	 * @param int $userID User ID
     	 * @param int $listing_id Listing ID
     	 * @param int $status Payment status (0 = unpaid, 1 = paid)
     	 */
     	private function update_listing_invoice_status( $userID, $listing_id, $status ) {
     		$invoices = get_posts( array(
     			'post_type' => 'houzez_invoice',
     			'meta_query' => array(
     				array(
     					'key' => 'invoice_listing_id',
     					'value' => $listing_id,
     					'compare' => '='
     				),
     				array(
     					'key' => 'invoice_user_id',
     					'value' => $userID,
     					'compare' => '='
     				)
     			),
     			'posts_per_page' => -1
     		) );

     		foreach ( $invoices as $invoice ) {
     			update_post_meta( $invoice->ID, 'invoice_payment_status', $status );
     		}
     	}

     	/**
     	 * Handle WooCommerce order cancellation - revoke membership
     	 *
     	 * @param int $order_id The order ID
     	 */
     	public function woo_payment_cancelled( $order_id ) {
     		// Same logic as refunded
     		$this->woo_payment_refunded( $order_id );
     	}

     	/**
     	 * Handle WooCommerce order failure - revoke membership
     	 *
     	 * @param int $order_id The order ID
     	 */
     	public function woo_payment_failed( $order_id ) {
     		// Same logic as refunded
     		$this->woo_payment_refunded( $order_id );
     	}

     	/**
     	 * Update invoice payment status
     	 *
     	 * @param int $userID User ID
     	 * @param int $package_id Package ID
     	 * @param int $status Payment status (0 = unpaid, 1 = paid)
     	 */
     	private function update_invoice_status( $userID, $package_id, $status ) {
     		$invoices = get_posts( array(
     			'post_type' => 'houzez_invoice',
     			'meta_query' => array(
     				array(
     					'key' => 'invoice_package_id',
     					'value' => $package_id,
     					'compare' => '='
     				),
     				array(
     					'key' => 'invoice_user_id',
     					'value' => $userID,
     					'compare' => '='
     				)
     			),
     			'posts_per_page' => -1
     		) );

     		foreach ( $invoices as $invoice ) {
     			update_post_meta( $invoice->ID, 'invoice_payment_status', $status );
     		}
     	}

     	function custom_pre_get_posts_query( $query ) {
	        $meta_query = (array) $query->get( 'meta_query' );
	        $meta_query[] = array(
	                'meta_key'      => '_is_houzez_woocommerce',
	                'meta_compare'  => 'NOT EXISTS',
	                'value'         => ''
	               );
	        $query->set( 'meta_query', $meta_query );
	    }

	    function product_redirect() {

	        $is_houzez_custom = get_post_meta( get_the_ID(), '_is_houzez_woocommerce', true );
	        
	        if( $is_houzez_custom == 1 ) {
	            wp_redirect( home_url(), 301 );
	            exit();
	        }
	    }
		
	}
	new Houzez_Woo_Payment();
}