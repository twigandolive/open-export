<?php
/**
 * Seed a multisite sub-site for the Open Export test site. Run with
 * `wp --url=<sub-site url> eval-file seed-subsite.php <slug>`.
 * Gives each sub-site a different number of posts and one extra user, so an export
 * returns different rows per site.
 */

declare(strict_types=1);

$slug  = isset( $args[0] ) ? (string) $args[0] : 'second';
$count = ( 'second' === $slug ) ? 3 : 5;
wp_set_current_user( 1 );

// Remove the install-time sample content, as seed.php does on the main site.
wp_delete_post( 1, true );
wp_delete_post( 2, true );
wp_delete_post( 3, true );
wp_delete_comment( 1, true );

$base = strtotime( '2026-06-30 12:00:00 UTC' );
for ( $i = 1; $i <= $count; $i++ ) {
	$when = gmdate( 'Y-m-d H:i:s', $base - $i * DAY_IN_SECONDS );
	wp_insert_post( array(
		'post_title'    => "Sub-site $slug post $i",
		'post_content'  => "Body of $slug post $i.",
		'post_status'   => 'publish',
		'post_date'     => $when,
		'post_date_gmt' => $when,
	) );
}

$uid = wp_insert_user( array(
	'user_login'      => "{$slug}user",
	'user_pass'       => wp_generate_password(),
	'user_email'      => "{$slug}user@example.test",
	'role'            => 'editor',
	'user_registered' => gmdate( 'Y-m-d H:i:s', $base - 150 * DAY_IN_SECONDS ),
) );
if ( ! is_wp_error( $uid ) ) {
	add_user_to_blog( get_current_blog_id(), $uid, 'editor' );
}
echo "Seeded $slug: $count posts, 1 user.\n";
