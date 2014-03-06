<?php
/**
 * Plugin Name: UPS Shipping Labels
 * Description: Automatically create UPS shipping labels and insert a tracking number
 * Version: 1.0
 * Author: Everlook Studios
 * Author URI: http://everlookstudios.com
 */

use \Awsp\Ship as Ship;

// This plugin only works with WooCommerce, so we must make sure that it is active
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {

	if ( ! class_exists( 'ES_Shipping_Labels' ) ) {

		class ES_Shipping_Labels {

			var $trackingURL;
            var $shippingLabels;

			// Constructor
			function __construct() {
				$this->trackingURL = 'http://wwwapps.ups.com/WebTracking/track?track=yes&trackNums=%1$s';

				// Setup basic plugin stuff
				add_action( 'add_meta_boxes', array( &$this, 'add_meta_box' ) );
				add_action( 'woocommerce_process_shop_order_meta', array( &$this, 'save_meta_box' ), 0, 2 );

				// View Order Page
				add_action( 'woocommerce_view_order', array( &$this, 'display_tracking_info' ) );
				add_action( 'woocommerce_email_before_order_table', array( &$this, 'email_display' ) );
                
                if ( is_admin() ) {
                    // Bulk edit
                    add_action( 'admin_footer', array( $this, 'bulk_admin_footer' ), 10 );
                    add_action( 'load-edit.php', array( $this, 'bulk_action' ) );
                    add_action( 'admin_notices', array( $this, 'bulk_admin_notices' ) );
                }
			}
            
            function bulk_admin_footer() {
                global $post_type;
                
                if ( $post_type == 'shop_order' ) {
                    ?>
                    <script type="text/javascript">
                        jQuery(document).ready(function() {
                            jQuery('<option>').val('generate_labels').text('<?php _e( 'Generate Labels' ); ?>').appendTo("select[name='action']");
                            jQuery('<option>').val('generate_labels').text('<?php _e( 'Generate Labels' ); ?>').appendTo("select[name='action2']");
                            jQuery('<option>').val('print_labels').text('<?php _e( 'Print Labels' ); ?>').appendTo("select[name='action']");
                            jQuery('<option>').val('print_labels').text('<?php _e( 'Print Labels' ); ?>').appendTo("select[name='action2']");
                        });
                    </script>
                    <?php
                }
            }
            
            function bulk_action() {
                $wp_list_table = _get_list_table( 'WP_Posts_List_Table' );
                $action = $wp_list_table->current_action();
                
                switch ( $action ) {
                    case 'generate_labels':
                        $report_action = 'generated_labels';
                        $changed = 0;
                    
                        $post_ids = array_map( 'absint', (array)$_REQUEST['post'] );
                        
                        foreach ( $post_ids as $post_id ) {
                            
                            // Generate label for each order
                            $this->bulk_process( $post_id );
                            
                            $changed++;
                        }
                
                
                
                        $sendback = add_query_arg( array( 'post_type' => 'shop_order', $report_action => true, 'changed' => $changed, 'ids' => join( ',', $post_ids ) ), '' );
                        wp_redirect( $sendback );
                        
                        exit();
                    break;
                    case 'print_labels':
                        $report_action = 'printed_labels';
                        $changed = 0;
                        
                        $post_ids = array_map( 'absint', (array)$_REQUEST['post'] );
                        
                        require('fpdf-alpha.php');
                        $pdf = new PDF_ImageAlpha('P', 'in', array( 4,6 ));
                    
                        foreach ( $post_ids as $post_id ) {
                            $pdf->AddPage();
                            
                            // Convert byte array to image and put it on the page
                            $image = get_post_meta( $post_id, '_label_image', true );
                            $file = 'label.gif';
                            $image = base64_decode( $image );
                            $im = imagecreatefromstring( $image );
                            
                            $file = "label.png";
                            $newImg = imagerotate( $im, -90, 0 );
                            imagepng($newImg, $file);
                            
                            $pdf->ImagePngWithAlpha( $file, 0, 0, 4, 7 );
                            
                            $changed++;
                        }
                        $pdf->Output();
                    
                        exit();
                    break;
                    default:
                        return;
                }
            }
            
            function bulk_process( $post_id ) {
                $shipmentData = array();

                // Require config file and autoloader file
                require 'ship-master/vendor/autoload.php';
                require 'ship-master/includes/config.php';
                require_once( 'ship-master/includes/autoloader.php' );

                // Configure receiver informaiton
                $shipmentData['receiver_name'] = get_post_meta( $post_id, '_shipping_first_name', true ) . " " . get_post_meta( $post_id, '_shipping_last_name', true );
                $shipmentData['receiver_email'] = get_post_meta( $post_id, '_billing_email', true );
                $shipmentData['receiver_address1'] = get_post_meta( $post_id, '_shipping_address_1', true );
                $shipmentData['receiver_address2'] = get_post_meta( $post_id, '_shipping_address_2', true );
                $shipmentData['receiver_city'] = get_post_meta( $post_id, '_shipping_city', true );
                $shipmentData['receiver_state'] = get_post_meta( $post_id, '_shipping_state', true );
                $shipmentData['receiver_postal_code'] = get_post_meta( $post_id, '_shipping_postcode', true );
                $shipmentData['receiver_phone'] = get_post_meta( $post_id, '_billing_phone', true );
                $shipmentData['receiver_country_code'] = 'US';
                $shipmentData['receiver_is_residential'] = true;

                // Grab the total weight of the order
                $order = new WC_Order( $post_id );
                $weight = 0;

                if ( sizeof( $order->get_items() ) > 0 ) {
                    foreach ( $order->get_items() as $item ) {
                        if ( $item['product_id'] > 0 ) {
                            $_product = $order->get_product_from_item( $item );
                            if ( !$_product->is_virtual() ) {
                                $weight += $_product->get_weight() * $item['qty'];
                            }
                        }
                    }
                }

                // Create a Shipment Object
                $Shipment = new Ship\Shipment( $shipmentData );

                // Create a package object and add it to the shipment
                $package = new \Awsp\Ship\Package(
                    $weight, // the weight
                    array( 15, 12, 1 ) // the dimensions
                );
                $Shipment->addPackage( $package );

                // Create the shipper object for the appropriate shipping vendor and pass it tthe shipment and config data
                // using UPS
                $shipperObj = new \Awsp\Ship\Ups( $Shipment, $config );

                // Send request for a shipping label
                $params = array();
                //$shipping_type = explode( ":", get_post_meta( $post_id, '_shipping_method', true ) );
                $shipping_type = get_post_meta( $post_id, '_shipping_method', true );
                $service_code = "";
                if ( $shipping_type == "free_shipping") {
                    $service_code = "03";
                } else if ( strpos( $shipping_type, 'ups' ) !== FALSE ) {
                    $tempVar = explode( ":", $shipping_type );
                    $service_code = $tempVar[1];
                } else if ( $shipping_type == "table_rate_9 : 98" ) {
                    $service_code = "03";
                } else if ( $shipping_type == "table_rate_10 : 99" ) {
                    $service_code = "02";
                } else if ( $shipping_type == "table_rate_11 : 100" ) {
                    $service_code = "01";
                } else {
                    $service_code = "03";
                }
                
                $params['service_code'] = $service_code;
                $response = $shipperObj->createLabel($params);

                // Now that the label has been created, we enter the tracking number and show the label
                foreach ( $response->labels as $label ) {
                    update_post_meta( $post_id, '_tracking_number', $label['tracking_number'] );
                    update_post_meta( $post_id, '_label_image', $label['label_image'] );
                }
            }
            
            function bulk_admin_notices() {
                global $post_type, $pagenow;
                
                if ( isset( $_REQUEST['generated_labels'] ) ) {
                    $number = isset( $_REQUEST['changed'] ) ? absint( $_REQUEST['changed'] ) : 0;
                    
                    if ( 'edit.php' == $pagenow && 'shop_order' == $post_type ) {
                        $message = sprintf( _n( 'Labels generated.', '%s labels generated.', $number ), number_format_i18n( $number ) );
                        echo '<div class="updated"><p>' . $message . '</p></div>';
                    }
                } else if ( isset( $_REQUEST['printed_labels'] ) ) {
                    $number = isset( $_REQUEST['changed'] ) ? absint( $_REQUEST['changed'] ) : 0;
                    
                    if ( 'edit.php' == $pagenow && 'shop_order' == $post_type ) {
                        $message = sprintf( _n( 'Labels printed.', '%s labels printed.', $number ), number_format_i18n( $number ) );
                        echo '<div class="updated"><p>' . $message . '</p></div>';
                    }
                }
            }

			/**
			 * Add the meta box for shipping info on the order page
			 *
			 * @access public
      		*/
			function add_meta_box() {
				add_meta_box( 'woocommerce-shipping-labels', __( 'Shipping Info', 'wc_shipping_labels' ), array( &$this, 'meta_box'), 'shop_order', 'side', 'high' );
			}

			/**
			 * Show the meta box for shipping info on the order page
			 * 
			 * @access public
			*/
			function meta_box() {
				global $woocommerce, $post;

				// Add tracking number input
				woocommerce_wp_text_input( array(
					'id'			=> 'tracking_number',
					'label' 		=> __( 'Tracking number:', 'wc_shipping_labels' ),
					'placeholder' 	=> '',
					'description' 	=> '',
					'value' 		=> get_post_meta( $post->ID, '_tracking_number', true )
				) );

				woocommerce_wp_checkbox( array( 
					'id' 			=> 'generate_shipping_label',
					'label' 		=> __( 'Generate shipping label', 'wc_shipping_labels' ),
					'value'			=> 'generate_shipping_label_yes',
				) );

				// Live preview
				$tracking_number = get_post_meta( $post->ID, '_tracking_number', true );
				$label_image = get_post_meta( $post->ID, '_label_image', true );

				if ( $tracking_number ) {
					$tracking_url = sprintf( $this->trackingURL, $tracking_number );

					echo '<p class="preview_tracking_link">' . __( 'Preview:', 'wc_shipping_labels' ) . ' <a href="' . $tracking_url . '" target="_blank">' . __( 'Click here to track your shipment', 'wc_shipping_labels' ) . '</a></p>';
				}

				if ( $label_image ) { 
					echo '<p class="shipping_label">' . '<a download="label.gif" href="data:image/gif;base64, ' . $label_image . '">Click here to download shipping label</a>';
				}
			}

			/**
			 * Function to save all tracking data into the database
			 *
			*/ 
			function save_meta_box( $post_id, $post ) {
				if ( isset( $_POST['tracking_number'] ) && $_POST['tracking_number'] != '' ) {

					// Download data
					$tracking_number = woocommerce_clean( $_POST['tracking_number'] );

					// Update order data
					update_post_meta( $post_id, '_tracking_number', $tracking_number );
				}

				// The user has selected to generate a shipping label, this means that no data in the tracking number should be saved
				// as this data will be pulled from UPS servers. The user will be presented with a PDF containing the shipping label
				// generated by UPS
				if ( isset( $_POST['generate_shipping_label'] ) ) {

					$shipmentData = array();

					// Require config file and autoloader file
					require 'ship-master/vendor/autoload.php';
					require_once( 'ship-master/includes/config.php' );
					require_once( 'ship-master/includes/autoloader.php' );

					// Configure receiver informaiton
					$shipmentData['receiver_name'] = get_post_meta( $post->ID, '_shipping_first_name', true ) . " " . get_post_meta( $post->ID, '_shipping_last_name', true );
					$shipmentData['receiver_email'] = get_post_meta( $post->ID, '_billing_email', true );
					$shipmentData['receiver_address1'] = get_post_meta( $post->ID, '_shipping_address_1', true );
					$shipmentData['receiver_address2'] = get_post_meta( $post->ID, '_shipping_address_2', true );
					$shipmentData['receiver_city'] = get_post_meta( $post->ID, '_shipping_city', true );
					$shipmentData['receiver_state'] = get_post_meta( $post->ID, '_shipping_state', true );
					$shipmentData['receiver_postal_code'] = get_post_meta( $post->ID, '_shipping_postcode', true );
					$shipmentData['receiver_phone'] = get_post_meta( $post->ID, '_billing_phone', true );
					$shipmentData['receiver_country_code'] = 'US';
					$shipmentData['receiver_is_residential'] = true;

					// Grab the total weight of the order
					$order = new WC_Order( $post->ID );
					$weight = 0;

					if ( sizeof( $order->get_items() ) > 0 ) {
						foreach ( $order->get_items() as $item ) {
							if ( $item['product_id'] > 0 ) {
								$_product = $order->get_product_from_item( $item );
								if ( !$_product->is_virtual() ) {
									$weight += $_product->get_weight() * $item['qty'];
								}
							}
						}
					}

					// Create a Shipment Object
					$Shipment = new Ship\Shipment( $shipmentData );

					// Create a package object and add it to the shipment
					$package = new \Awsp\Ship\Package(
						$weight, // the weight
						array( 15, 12, 1 ) // the dimensions
					);
					$Shipment->addPackage( $package );

					// Create the shipper object for the appropriate shipping vendor and pass it tthe shipment and config data
					// using UPS
					$shipperObj = new \Awsp\Ship\Ups( $Shipment, $config );

					// Send request for a shipping label
					$params = array();
					//$shipping_type = explode( ":", get_post_meta( $post->ID, '_shipping_method', true ) );
					$shipping_type = get_post_meta( $post_id, '_shipping_method', true );
                    $service_code = "";
                    if ( $shipping_type == "free_shipping") {
                        $service_code = "03";
                    } else if ( strpos( $shipping_type, 'ups' ) !== FALSE ) {
                        $tempVar = explode( ":", $shipping_type );
                        $service_code = $tempVar[1];
                    } else if ( $shipping_type == "table_rate_9 : 98" ) {
                        $service_code = "03";
                    } else if ( $shipping_type == "table_rate_10 : 99" ) {
                        $service_code = "02";
                    } else if ( $shipping_type == "table_rate_11 : 100" ) {
                        $service_code = "01";
                    } else {
                        $service_code = "03";
                    }
                    
                    $params['service_code'] = $service_code;
                    $response = $shipperObj->createLabel($params);

					// Now that the label has been created, we enter the tracking number and show the label
					foreach ( $response->labels as $label ) {
						update_post_meta( $post_id, '_tracking_number', $label['tracking_number'] );
						update_post_meta( $post_id, '_label_image', $label['label_image'] );
					}
				}
			}

			/**
			 * Display Shipment info in the frontend (order view/tracking page).
			 *
			 * @access public
			*/
			function display_tracking_info( $order_id, $for_email = false ) {
				$tracking_number = get_post_meta( $order_id, '_tracking_number', true );

				// Make sure a tracking number is present before generating a tracking link
				if ( $tracking_number ) {
					$tracking_link = '';
					$link_format = $this->trackingURL;

					if ( $link_format ) {
						$link = sprintf( $link_format, $tracking_number );

						if ( $for_email ) {
							$tracking_link = sprintf( '<a href="%s">Click here to track your shipment</a>', $link );
						} else {
							$tracking_link = sprintf( '<a target="_blank" href="%s">' . __('Click here to track your shipment', 'wc_shipping_labels') . '.</a>', $link );
						}
					}

					echo wpautop( sprintf( __("Your order is being shipped under the tracking number below. Please allow 3 business days to ship your order. Tracking number: %s %s", 'wc_shipping_labels' ), $tracking_number, $tracking_link ) );
				}
			}

			/**
			 * Display shipment info in customer emails
			 *
			 * @access public
			 * @return void
			*/
			function email_display( $order ) {
				$this->display_tracking_info( $order->id, true );
			}
		}
	}

	/** 
	 * Register this class globally
	*/
	$GLOBALS['ES_Shipping_Labels'] = new ES_Shipping_Labels();
}