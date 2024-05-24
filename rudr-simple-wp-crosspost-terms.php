<?php
/*
 * Plugin name: Simple WP Crossposting – Terms
 * Author: Misha Rudrastyh
 * Author URI: https://rudrastyh.com
 * Description: Allows to crosspost terms with all the data along with the post.
 * Plugin URI: https://rudrastyh.com/support/categories-tags-and-attributes-are-not-added
 * Version: 1.5
 */
add_filter( 'rudr_swc_terms', function( $remote_terms, $post_id, $taxonomy, $blog ) {
	// taxonomy could be both name and object
	if( ! is_object( $taxonomy ) ) {
		$taxonomy = get_taxonomy( $taxonomy );
	}

	if( ! $taxonomy || is_wp_error( $taxonomy ) ) {
		return $remote_terms;
	}

	// new terms, we gonna add new ones here once remotes are synced
	$new_terms = $remote_terms;
	// sort remote terms as Array( slug => id )
	$remote_terms = wp_list_pluck( $remote_terms, 'id', 'slug' );
	// get post taxonomy term
	$post_terms = wp_get_object_terms( $post_id, $taxonomy->name );

	if( $post_terms ) {

		foreach( $post_terms as $post_term ) {

			$term_data = array(
				'id' => $post_term->term_id,
				'name' => $post_term->name,
				'slug' => $post_term->slug,
				//'parent' => $post_term->parent,
				'description' => $post_term->description,
				'taxonomy' => $post_term->taxonomy,
				'meta' => array()
			);

			// term parent calculation
			if(
				$post_term->parent
				&& ( $parent_term = get_term_by( 'id', $post_term->parent, $taxonomy->name ) )
			 	&& isset( $remote_terms[ $parent_term->slug ] )
				&& $remote_terms[ $parent_term->slug ]
			) {
				$term_data[ 'parent' ] = $remote_terms[ $parent_term->slug ];
			}

			// add meta as well
			$term_meta = get_term_meta( $post_term->term_id );
			foreach( $term_meta as $meta_key => $meta_values ){
				$term_data[ 'meta' ][ $meta_key ] = apply_filters( 'rudr_swc_pre_crosspost_termmeta', $meta_values[0], $meta_key, $post_term->term_id, $blog );
			}

			// taxonomy endpoint
			$rest_base = isset( $taxonomy->rest_base ) && $taxonomy->rest_base ? $taxonomy->rest_base : $taxonomy->name;
			// we need to add an image for WooCommerce product categories
			if(
				'product_cat' === $taxonomy->name
				&& ( $product_cat_thumbnail_id = get_term_meta( $post_term->term_id, 'thumbnail_id', true ) )
			) {
				$thumbnail = Rudr_Simple_WP_Crosspost::maybe_crosspost_image( $product_cat_thumbnail_id, $blog );
				if( isset( $thumbnail[ 'id' ] ) && $thumbnail[ 'id' ] ) {
					$term_data[ 'image' ] = array(
						'id' => $thumbnail[ 'id' ],
					);
				}
			}
			// term data filter
			$term_data = apply_filters( 'rudr_swc_pre_crosspost_term_data', $term_data, $blog, 'term' );
			// we don't need local term ID or taxonomy name in the body of the request further
			unset( $term_data[ 'id' ] );
			unset( $term_data[ 'taxonomy' ] );
			if( ! array_key_exists( $post_term->slug, $remote_terms ) ) {
				// in case we're working with WooCommerce product categories taxonomy, we need to do some changes
				if( 'product_cat' === $taxonomy->name ) {
					$request = wp_remote_post(
						"{$blog[ 'url' ]}/wp-json/wc/v3/products/categories",
						array(
							'timeout' => 30,
							'headers' => array(
								'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
							),
							'body' => $term_data,
						)
					);
				} else {
					$request = wp_remote_post(
						"{$blog[ 'url' ]}/wp-json/wp/v2/{$rest_base}",
						array(
							'timeout' => 30,
							'headers' => array(
								'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
							),
							'body' => $term_data,
						)
					);
				}
//echo '<pre>';print_r( $request );exit;
				if( 'Created' === wp_remote_retrieve_response_message( $request ) ) {
					$term = json_decode( wp_remote_retrieve_body( $request ) );
					if( isset( $term->id ) ) {
						$new_terms[] = array( 'id' => $term->id );
					}
				}

			} else {
				if( 'product_cat' === $taxonomy->name ) {
					wp_remote_request(
						"{$blog[ 'url' ]}/wp-json/wc/v3/products/categories/{$remote_terms[ $post_term->slug ]}",
						array(
							'method' => 'PUT',
							'timeout' => 30,
							'headers' => array(
								'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
							),
							'body' => $term_data,
						)
					);
				} else {
					wp_remote_post(
						"{$blog[ 'url' ]}/wp-json/wp/v2/{$rest_base}/{$remote_terms[ $post_term->slug ]}",
						array(
							'timeout' => 30,
							'headers' => array(
								'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
							),
							'body' => $term_data,
						)
					);
				}

			}

		} // endforeach

	}

	return $new_terms;

}, 10, 4 );

add_filter( 'rudr_swc_register_meta_term_keys', function() {

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

} );

// WooCommerce
add_filter( 'rudr_swc_wc_attribute', function( $crossposted_attribute, $attribute_name, $blog ) {

	// we need to collect all the attribute information
	$attributes = wc_get_attribute_taxonomies();
	if( ! $attributes ) {
		return $crossposted_attribute;
	}

	$request_body = array();

	foreach( $attributes as $attribute ) {
		if( $attribute_name !== "pa_{$attribute->attribute_name}" ) {
			continue;
		}

		$request_body = array(
			'name' => $attribute->attribute_label,
			'slug' => $attribute_name,
			'type' => $attribute->attribute_type,
			'order_by' => $attribute->attribute_orderby,
			'has_archives' => $attribute->attribute_public,
		);
	}

	$request = wp_remote_request(
		"{$blog[ 'url' ]}/wp-json/wc/v3/products/attributes",
		array(
			'method' => 'POST',
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( "{$blog[ 'login' ]}:{$blog[ 'pwd' ]}" )
			),
			'body' => $request_body
		)
	);

	if( 'Created' === wp_remote_retrieve_response_message( $request ) ) {
		$body = json_decode( wp_remote_retrieve_body( $request ), true );
		$crossposted_attribute = $body[ 'id' ];
	}

	return $crossposted_attribute;

}, 10, 3 );
