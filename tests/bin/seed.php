<?php
/**
 * Seed data for the Open Export test site. Run with `wp eval-file`.
 * Uses a fixed random seed so every rebuild gives the same shape of data.
 */
mt_srand( 42 );
wp_set_current_user( 1 );
global $wpdb;

// Every date is derived from one fixed base, so a rebuild gives identical rows.
$base = strtotime( '2026-06-30 12:00:00 UTC' );
$at   = function ( int $days_ago ) use ( $base ) { return gmdate( 'Y-m-d H:i:s', $base - $days_ago * DAY_IN_SECONDS ); };

// Remove the install-time sample content (dates and ids depend on install time).
wp_delete_post( 1, true );
wp_delete_post( 2, true );
wp_delete_post( 3, true );
wp_delete_comment( 1, true );
$pick = function ( array $a ) { return $a[ mt_rand( 0, count( $a ) - 1 ) ]; };

// Categories and tags.
$cats = array();
foreach ( array( 'News', 'Guides' ) as $n ) {
	$cats[] = (int) wp_insert_term( $n, 'category' )['term_id'];
}
$tags = array( 'alpha', 'beta', 'gamma', 'delta', 'omega' );

// 60 posts, with meta (one serialized array).
for ( $i = 1; $i <= 60; $i++ ) {
	$id = wp_insert_post( array(
		'post_title'    => "Test post $i",
		'post_content'  => "Body of test post $i.\n\nSecond paragraph.",
		'post_status'   => 'publish',
		'post_date'     => $at( $i ),
		'post_date_gmt' => $at( $i ),
		'post_category' => array( $cats[ $i % 2 ] ),
		'tags_input'    => array( $tags[ $i % 5 ], $tags[ ( $i + 2 ) % 5 ] ),
	) );
	update_post_meta( $id, 'opex_rating', $i % 6 );
	update_post_meta( $id, 'opex_note', "note $i" );
	update_post_meta( $id, 'opex_settings', array( 'color' => 'blue', 'sizes' => array( 1, 2, $i ), 'on' => true ) );
	$post_ids[] = $id;
}

// 10 pages.
for ( $i = 1; $i <= 10; $i++ ) {
	wp_insert_post( array( 'post_type' => 'page', 'post_title' => "Test page $i",
		'post_content' => "Page $i content.", 'post_status' => 'publish' ) );
}

// 25 users across roles, with user meta.
$roles = array( 'subscriber', 'editor', 'author', 'contributor', 'customer' );
for ( $i = 1; $i <= 25; $i++ ) {
	$uid = wp_insert_user( array(
		'user_login' => "user$i", 'user_pass' => wp_generate_password(),
		'user_email' => "user$i@example.test", 'role' => $roles[ $i % 5 ],
		'first_name' => "First$i", 'last_name' => "Last$i",
		'user_registered' => $at( 100 + $i ),
	) );
	update_user_meta( $uid, 'opex_plan', $pick( array( 'free', 'pro', 'team' ) ) );
	update_user_meta( $uid, 'opex_prefs', array( 'newsletter' => ( $i % 2 === 0 ), 'lang' => 'en' ) );
	$user_ids[] = $uid;
}

// 80 comments.
for ( $i = 1; $i <= 80; $i++ ) {
	wp_insert_comment( array(
		'comment_post_ID'      => $post_ids[ $i % count( $post_ids ) ],
		'comment_author'       => "Commenter $i",
		'comment_author_email' => "commenter$i@example.test",
		'comment_date'         => $at( $i ),
		'comment_date_gmt'     => $at( $i ),
		'comment_content'      => "Comment number $i.",
		'comment_approved'     => ( $i % 10 === 0 ) ? 0 : 1,
	) );
}

// WooCommerce.
if ( ! class_exists( 'WooCommerce' ) ) {
	echo "WooCommerce not active, skipping store data.\n";
	return;
}

// 12 products: 8 simple, 4 variable (3 variations each).
$product_ids = array();
for ( $i = 1; $i <= 8; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( "Simple product $i" );
	$p->set_regular_price( (string) ( 5 + $i * 3 ) . '.50' );
	$p->set_sku( "SIMPLE-$i" );
	$p->set_status( 'publish' );
	$product_ids[] = $p->save();
}
for ( $i = 1; $i <= 4; $i++ ) {
	$p = new WC_Product_Variable();
	$p->set_name( "Variable product $i" );
	$p->set_sku( "VAR-$i" );
	$p->set_status( 'publish' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( 'S', 'M', 'L' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p->set_attributes( array( $attr ) );
	$pid = $p->save();
	foreach ( array( 'S', 'M', 'L' ) as $k => $size ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $pid );
		$v->set_attributes( array( 'size' => $size ) );
		$v->set_regular_price( (string) ( 20 + $i * 2 + $k * 3 ) . '.00' );
		$v->set_sku( "VAR-$i-$size" );
		$v->save();
	}
	WC_Product_Variable::sync( $pid );
	$product_ids[] = $pid;
}

// 2 coupons.
foreach ( array( array( 'SAVE10', 'percent', '10' ), array( 'FIVEOFF', 'fixed_cart', '5' ) ) as $c ) {
	$cp = new WC_Coupon();
	$cp->set_code( $c[0] );
	$cp->set_discount_type( $c[1] );
	$cp->set_amount( $c[2] );
	$cp->save();
}

// 40 orders, 1 to 4 line items each, mixed statuses.
$statuses = array( 'completed', 'completed', 'processing', 'on-hold', 'pending', 'cancelled', 'refunded', 'failed' );
$customers = array_slice( $user_ids, 0, 10 );
$refund_targets = array();
for ( $i = 1; $i <= 40; $i++ ) {
	$o = wc_create_order( array( 'customer_id' => $customers[ $i % 10 ] ) );
	$n = mt_rand( 1, 4 );
	for ( $j = 0; $j < $n; $j++ ) {
		$prod = wc_get_product( $product_ids[ mt_rand( 0, count( $product_ids ) - 1 ) ] );
		if ( $prod->is_type( 'variable' ) ) {
			$kids = $prod->get_children();
			$prod = wc_get_product( $kids[ mt_rand( 0, count( $kids ) - 1 ) ] );
		}
		$o->add_product( $prod, mt_rand( 1, 3 ) );
	}
	$o->set_billing_first_name( "First" . ( $i % 10 + 1 ) );
	$o->set_billing_last_name( "Buyer$i" );
	$o->set_billing_email( "buyer$i@example.test" );
	$o->set_billing_country( 'US' );
	$o->set_date_created( $base - $i * DAY_IN_SECONDS );
	if ( $i % 8 === 0 ) {
		$o->apply_coupon( 'SAVE10' );
	}
	$o->calculate_totals();
	$o->set_status( $statuses[ $i % count( $statuses ) ] );
	$o->save();
	if ( 'completed' === $o->get_status() ) {
		$refund_targets[] = $o;
	}
}

// A few partial refunds on completed orders.
foreach ( array_slice( $refund_targets, 0, 4 ) as $o ) {
	wc_create_refund( array( 'order_id' => $o->get_id(), 'amount' => '2.00', 'reason' => 'Test refund' ) );
}

// Pin everything that was stamped with the build time.
$fixed = $at( 1 );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->posts} SET post_date = %s, post_date_gmt = %s, post_modified = %s, post_modified_gmt = %s WHERE post_date > %s", $fixed, $fixed, $fixed, $fixed, '2026-07-01 00:00:00' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->users} SET user_registered = %s WHERE user_registered > %s", $at( 200 ), '2026-07-01 00:00:00' ) );
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->comments} SET comment_date = %s, comment_date_gmt = %s WHERE comment_date > %s", $fixed, $fixed, '2026-07-01 00:00:00' ) );
$orders = $wpdb->prefix . 'wc_orders';
$ops    = $wpdb->prefix . 'wc_order_operational_data';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$orders'" ) ) {
	$wpdb->query( "UPDATE $orders SET date_updated_gmt = date_created_gmt" );
	$wpdb->query( "UPDATE $ops SET date_paid_gmt = (SELECT date_created_gmt FROM $orders WHERE $orders.id = $ops.order_id) WHERE date_paid_gmt IS NOT NULL" );
	$wpdb->query( "UPDATE $ops SET date_completed_gmt = (SELECT date_created_gmt FROM $orders WHERE $orders.id = $ops.order_id) WHERE date_completed_gmt IS NOT NULL" );
	$wpdb->query( "UPDATE $ops SET order_key = CONCAT('wc_order_fixed', order_id), cart_hash = ''" );
}
wp_cache_flush();

echo "Seeded: " . count( $post_ids ) . " posts, " . count( $user_ids ) . " users, "
	. count( $product_ids ) . " products, 40 orders.\n";
