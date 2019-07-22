<?php

/*
 * Plugin Name: WooCommerce Dynamic Pricing Blocks
 * Plugin URI: https://github.com/lucasstark/dynamic-pricing-blocks/
 * Description: WooCommerce Dynamic Pricing Blocks let's you create custom pricing blocks for products.
 * Version: 1.0.0
 * Author: Lucas Stark
 * Author URI: https://elementstark.com
 * Requires at least: 3.3
 * Tested up to: 5.2.2
 * Text Domain: woocommerce-dynamic-pricing-blocks
 * Domain Path: /i18n/languages/
 * Copyright: © 2009-2018 Lucas Stark.
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 * WC requires at least: 3.0.0
 * WC tested up to: 3.7.0
 */


/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) ) {
	require_once( 'woo-includes/woo-functions.php' );
}

if ( is_woocommerce_active() ) {

	class Dynamic_Pricing_Blocks {

		/**
		 * @var ES_Dynamic_Pricing
		 */
		private static $instance;

		public static function register() {
			if ( self::$instance == null ) {
				self::$instance = new Dynamic_Pricing_Blocks();
			}
		}

		private $_cart_setup = false;

		private $_products_to_adjust;
		private $_categories_to_adjust;

		private $_price_blocks;

		private $_adjustment_type;

		protected function __construct() {

			add_filter( 'woocommerce_get_cart_item_from_session', array(
				$this,
				'on_woocommerce_get_cart_item_from_session'
			), 10, 3 );


			add_action( 'woocommerce_cart_loaded_from_session', array(
				$this,
				'on_cart_loaded_from_session'
			), 0, 1 );


			add_filter( 'woocommerce_product_get_price', array( $this, 'on_get_price' ), 10, 2 );
			add_filter( 'woocommerce_cart_item_price', array( $this, 'on_get_cart_item_price' ), 10, 2 );

			//$this->_products_to_adjust   = array();
			//$this->_products_to_adjust[] = 5035;

			$this->_categories_to_adjust   = array();
			$this->_categories_to_adjust[] = 43; //TODO:  Change this to your category you want to adjust.

			$this->_adjustment_type = 'percent';
			$this->_price_blocks    = array();
			$this->_price_blocks[2] = 25;
			$this->_price_blocks[3] = 50;
		}

		/**
		 * Records the cart item key on the product so we can reference it in the future.
		 *
		 * @param $cart_item
		 * @param $cart_item_values
		 * @param $cart_item_key
		 *
		 * @return mixed
		 */
		public function on_woocommerce_get_cart_item_from_session( $cart_item, $cart_item_values, $cart_item_key ) {
			$cart_item['data']->cart_item_key = $cart_item_key;

			return $cart_item;
		}

		/**
		 * Setup the adjustments on the cart.
		 *
		 * @param WC_Cart $cart
		 */
		public function on_cart_loaded_from_session( $cart ) {
			$this->setup_cart( $cart );
		}


		/**
		 * @param WC_Cart $cart
		 */
		public function setup_cart( $cart ) {
			if ( $this->_cart_setup ) {
				return;
			}

			ksort( $this->_price_blocks, SORT_NUMERIC );
			$price_blocks = array_reverse( $this->_price_blocks, true );
			if ( $cart && $cart->get_cart_contents_count() ) {

				$target_category_count = 0;
				foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
					unset( WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_quantities'] );
					$product_categories = $cart_item['data']->get_category_ids();
					if ( count( array_intersect( $product_categories, $this->_categories_to_adjust ) ) > 0 ) {
						$target_category_count += $cart_item['quantity'];
					}
				}


				$adjustment         = null;
				$current_block_size = - 1;
				if ( $target_category_count ) {
					foreach ( $price_blocks as $price_block_size => $price_block ) {
						if ( $target_category_count >= $price_block_size ) {
							$adjustment         = $price_block;
							$current_block_size = $price_block_size;
							break;
						}
					}
				}

				$full_priced_items = $target_category_count % $current_block_size;
				$quantity_remaining = $target_category_count - $full_priced_items;

				foreach ( $cart->get_cart() as $cart_item_key => &$cart_item ) {
					$product            = $cart_item['data'];
					$product_id         = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
					$the_product        = wc_get_product( $product_id );
					$product_base_price = $product->get_price( 'edit' );
					$category_ids       = $the_product->get_category_ids();

					if ( count( array_intersect( $category_ids, $this->_categories_to_adjust ) ) ) {
						WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_quantities'] = array_fill( 0, $cart_item['quantity'], $product_base_price );

						if ( $this->_adjustment_type == 'percent' && $adjustment > 1 ) {
							$adjustment = $adjustment / 100;
						}
						$adjusted_price = $product_base_price - ( $product_base_price * $adjustment );
						$c              = 0;

						while($quantity_remaining && $c < $cart_item['quantity']) {
							WC()->cart->cart_contents[ $cart_item_key ]['es_adjusted_quantities'][$c] = $adjusted_price;
							$quantity_remaining--;
							$c++;
						}

					}
				}
			}

			foreach ( WC()->cart->cart_contents as $cart_item_key => &$cart_item ) {

				if ( isset( $cart_item['es_adjusted_quantities'] ) ) {
					$grand_total = array_sum( $cart_item['es_adjusted_quantities'] );
					if ( $grand_total ) {
						$cart_item['es_adjusted_grand_total']   = $grand_total;
						$cart_item['es_adjusted_product_price'] = wc_cart_round_discount( $grand_total / $cart_item['quantity'], 4 );
					}
				}
			}
		}

		/**
		 * Finally everything is all set we can get the product price for cart items.
		 *
		 * @param $price
		 * @param $product
		 * @return float The products price.
		 */
		public function on_get_price( $price, $product ) {

			if ( isset( $product->cart_item_key ) ) {
				//We know this is for a product in the cart.

				$cart_item = WC()->cart->get_cart_item( $product->cart_item_key );

				if ( $cart_item && isset( $cart_item['es_adjusted_product_price'] ) ) {
					//found our cart item, and adjusted quantities.

					$price = $cart_item['es_adjusted_product_price'];

				}


			}

			return $price;

		}


		//Format the price as a sale price.
		public function on_get_cart_item_price( $html, $cart_item ) {
			$result_html = false;

			if ( isset( $cart_item['data']->cart_item_key ) ) {


				if ( isset( $cart_item['es_adjusted_quantities'] ) && ! empty( $cart_item['es_adjusted_quantities'] ) ) {
					$result_html = '';

					$block_html = '';
					$amounts    = array();
					foreach ( $cart_item['es_adjusted_quantities'] as $adjusted_price ) {
						if ( ! isset( $amounts[ $adjusted_price ] ) ) {
							$amounts[ $adjusted_price ] = 0;
						}
						$amounts[ $adjusted_price ] = $amounts[ $adjusted_price ] + 1;
					}

					foreach ( $amounts as $amount => $quantity ) {
						$result_html .= wc_price( $amount ) . ' x ' . $quantity;
						$result_html .= '<br />';
					}

					$remaining = $cart_item['quantity'] - count( $cart_item['es_adjusted_quantities'] );
					if ( $remaining ) {
						$result_html .= wc_price( $cart_item['data']->get_price( 'edit' ) ) . ' x ' . $remaining;
					}

				}


			}


			return $result_html ? $result_html : $html;

		}
	}

	Dynamic_Pricing_Blocks::register();
}
