<?php
/*
 * Plugin name: Simple WP Crossposting – Terms
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost terms with all the data along with the post.
 * Plugin URI: https://rudrastyh.com/support/categories-tags-and-attributes-are-not-added
 * Version: 2.0
 */

if( ! class_exists( 'Rudr_Simple_WP_Crosspost_Terms' ) ) {

	class Rudr_Simple_WP_Crosspost_Terms{

		public function __construct() {
			// terms
			add_filter( 'rudr_swc_get_synced_term_ids', array( $this, 'sync_terms' ), 25, 5 );
			add_filter( 'rudr_swc_register_meta_term_keys', array( $this, 'register_meta_keys' ) );
			// attributes
			add_filter( 'rudr_swc_get_synced_attribute_id', array( $this, 'sync_attribute' ), 25, 3 );
			// attribute terms
			add_filter( 'rudr_swc_add_attribute_terms', array( $this, 'sync_attribute_terms' ), 25, 3 );
		}

		public function register_meta_keys() {
			global $wpdb;

			$term_keys = $wpdb->get_col(
				"
				SELECT DISTINCT meta_key
				FROM $wpdb->termmeta
				ORDER BY meta_key
				"
			);

			if( $term_keys ) {
				return $term_keys;
			} else {
				return array();
			}
		}

		public function sync_terms( $crossposted_term_ids, $crossposted_terms, $post_terms, $taxonomy, $blog ) {

			// taxonomy can be a string for product categories and tags for example
			if( ! is_object( $taxonomy ) ) {
				$taxonomy = get_taxonomy( $taxonomy );
			}

			// we're going to recalculate it from scratch
			$crossposted_term_ids = array();

			if( ! $taxonomy || is_wp_error( $taxonomy ) ) {
				return $crossposted_term_ids;
			}

			// sort crossposted terms terms as Array( slug => id )
			$crossposted_terms = wp_list_pluck( $crossposted_terms, 'id', 'slug' );

			if( $post_terms ) {
				// create or update all terms one by one
				foreach( $post_terms as $post_term ) {
					// basic term data
					$term_id = $post_term->term_id;
					$term_data = array(
						'name' => $post_term->name,
						'slug' => $post_term->slug,
						'description' => $post_term->description,
						'meta' => array()
					);
					// add an appropriate parent element
					if( $post_term->parent && ( $parent = get_term_by( 'id', $post_term->parent, $taxonomy->name ) ) && ! empty( $crossposted_terms[ $parent->slug ] ) ) {
						$term_data[ 'parent' ] = $crossposted_terms[ $parent->slug ];
					}
					// add term meta data
					$term_meta = get_term_meta( $term_id );
					if( $term_meta ) {
						foreach( $term_meta as $meta_key => $meta_values ){
							$term_data[ 'meta' ][ $meta_key ] = apply_filters( 'rudr_swc_pre_crosspost_termmeta', $meta_values[0], $meta_key, $term_id, $blog );
						}
					}
					// add product image for WooCommerce
					if( class_exists( 'woocommerce' ) && 'product_cat' === $taxonomy->name && ( $product_cat_image_id = get_term_meta( $term_id, 'thumbnail_id', true ) ) ) {
						$product_cat_image = Rudr_Simple_WP_Crosspost::maybe_crosspost_image( $product_cat_image_id, $blog );
						if( isset( $product_cat_image[ 'id' ] ) && $product_cat_image[ 'id' ] ) {
							$term_data[ 'image' ] = array(
								'id' => $product_cat_image[ 'id' ],
							);
						}
					}

					// endpoint selection
					$rest_base = isset( $taxonomy->rest_base ) && $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;

					if( empty( $crossposted_terms[ $post_term->slug ] ) ) {
						$method = 'POST';
						if( class_exists( 'woocommerce' ) && 'product_cat' === $taxonomy->name ) {
							$endpoint = "{$blog[ 'url' ]}/wp-json/wc/v3/products/categories";
						} elseif( class_exists( 'woocommerce' ) && 'product_tag' === $taxonomy->name ) {
							$endpoint = "{$blog[ 'url' ]}/wp-json/wc/v3/products/tags";
						} else {
							$endpoint = "{$blog[ 'url' ]}/wp-json/wp/v2/{$rest_base}";
						}
					} else {
						$method = 'PUT';
						if( class_exists( 'woocommerce' ) && 'product_cat' === $taxonomy->name ) {
							$endpoint = "{$blog[ 'url' ]}/wp-json/wc/v3/products/categories/{$crossposted_terms[ $post_term->slug ]}";
						} elseif( class_exists( 'woocommerce' ) && 'product_tag' === $taxonomy->name ) {
							$endpoint = "{$blog[ 'url' ]}/wp-json/wc/v3/products/tags/{$crossposted_terms[ $post_term->slug ]}";
						} else {
							$endpoint = "{$blog[ 'url' ]}/wp-json/wp/v2/{$rest_base}/{$crossposted_terms[ $post_term->slug ]}";
						}
					}

					$request = wp_remote_request(
						$endpoint,
						array(
							'method' => $method,
							'timeout' => 30,
							'headers' => array(
								'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
							),
							'body' => apply_filters( 'rudr_swc_pre_crosspost_term_data', $term_data, $blog, $post_term ),
						)
					);
//echo '<pre>'; print_r( $request ); exit;
					if( in_array( wp_remote_retrieve_response_message( $request ), array( 'Created', 'OK' ) ) ) {
						$term = json_decode( wp_remote_retrieve_body( $request ) );
						if( ! empty( $term->id ) ) {
							$crossposted_term_ids[] = $term->id;
						}
					}

				}
			}

			return $crossposted_term_ids;

		}

		public function sync_attribute( $crossposted_attribute_id, $attribute_name, $blog ) {

			// we don't update, only create new attributes
			if( false !== $crossposted_attribute_id ) {
				return $crossposted_attribute_id;
			}

			// the ONLY way to collect all atrribute information
			$attributes = wc_get_attribute_taxonomies();

			if( $attributes ) {
				foreach( $attributes as $attribute ) {
					// skip all other attributes
					if( $attribute_name !== "pa_{$attribute->attribute_name}" ) {
						continue;
					}

					$request = wp_remote_post(
						"{$blog[ 'url' ]}/wp-json/wc/v3/products/attributes",
						array(
							'timeout' => 30,
							'headers' => array(
								'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
							),
							'body' => apply_filters(
								'rudr_swc_pre_crosspost_attribute_data',
								array(
									'name' => $attribute->attribute_label,
									'slug' => $attribute->attribute_name,
									'type' => $attribute->attribute_type,
									'order_by' => $attribute->attribute_orderby,
									'has_archives' => $attribute->attribute_public,
								)
							)
						)
					);

					if( 'Created' === wp_remote_retrieve_response_message( $request ) ) {
						$body = json_decode( wp_remote_retrieve_body( $request ), true );
						if( ! empty( $body[ 'id' ] ) ) {
							$crossposted_attribute_id = $body[ 'id' ];
							set_transient( Rudr_Simple_WP_Crosspost::META_KEY . Rudr_Simple_WP_Crosspost::get_blog_id( $blog ) . '_attribute_' . $attribute_name, $crossposted_attribute_id, WEEK_IN_SECONDS );
						}
					}
				}
			}

			return $crossposted_attribute_id;

		}

		public function get_synced_attribute_terms( $attribute_id, $blog ) {

			$object_cache_key = Rudr_Simple_WP_Crosspost::META_KEY . Rudr_Simple_WP_Crosspost::get_blog_id( $blog ) . '_attribute_terms_' . $attribute_id;
			$crossposted_attribute_terms = wp_cache_get( $object_cache_key );

			if( false === $crossposted_attribute_terms ) {
				$request = wp_remote_request(
					add_query_arg(
						array(
							'per_page' => 100, // maximum
						),
						"{$blog[ 'url' ]}/wp-json/wc/v3/products/attributes/{$attribute_id}/terms"
					),
					array(
						'method' => 'GET',
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
						)
					)
				);

				if( 'OK' === wp_remote_retrieve_response_message( $request ) ) {
					$body = json_decode( wp_remote_retrieve_body( $request ), true );
					$crossposted_attribute_terms = wp_list_pluck( $body, 'id', 'name' );
					wp_cache_set( $object_cache_key, $crossposted_attribute_terms );
				}
			}

			return $crossposted_attribute_terms;

		}

		public function sync_attribute_terms( $attribute_terms, $crossposted_attribute_id, $blog ) {

			$crossposted_attribute_terms = $this->get_synced_attribute_terms( $crossposted_attribute_id, $blog );

			foreach( $attribute_terms as $attribute_term_id => $attribute_term ) {

				$attribute_term_data = array(
					'name' => $attribute_term->name,
					'slug' => $attribute_term->slug,
					'description' => $attribute_term->description,
					'menu_order' => $attribute_term->menu_order,
				);

				if( empty( $crossposted_attribute_terms[ $attribute_term->name ] ) ) {
					// create a new attribute option
					$method = 'POST';
					$endpoint = "{$blog[ 'url' ]}/wp-json/wc/v3/products/attributes/{$crossposted_attribute_id}/terms";
				} else {
					// update attribute option
					$method = 'PUT';
					$endpoint = "{$blog[ 'url' ]}/wp-json/wc/v3/products/attributes/{$crossposted_attribute_id}/terms/{$crossposted_attribute_terms[ $attribute_term->name ]}";
				}

				wp_remote_request(
					$endpoint,
					array(
						'method' => $method,
						'timeout' => 30,
						'headers' => array(
							'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
						),
						'body' => apply_filters( 'rudr_swc_pre_crosspost_attribute_term_data', $attribute_term_data, $blog, $attribute_term ),
					)
				);

			}

		}




	}
	new Rudr_Simple_WP_Crosspost_Terms;
}
