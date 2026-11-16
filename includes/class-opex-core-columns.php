<?php
/**
 * The core columns each built-in source can export, in one place.
 *
 * The query engine checks field ids against these lists, and the picker and the
 * capability document list the same columns, so the three can never disagree.
 *
 * @package Open_Export
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Core column lists per source type.
 */
class OPEX_Core_Columns {

	/** Post columns. */
	const POSTS = array(
		'ID', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name',
		'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'post_author',
		'post_parent', 'menu_order', 'comment_status', 'ping_status', 'comment_count',
		'guid', 'post_mime_type', 'post_type',
	);

	/** User columns. */
	const USERS = array(
		'ID', 'user_login', 'user_email', 'user_nicename', 'user_url', 'user_registered', 'display_name',
	);

	/** Comment columns. */
	const COMMENTS = array(
		'comment_ID', 'comment_post_ID', 'comment_author', 'comment_author_email',
		'comment_author_url', 'comment_author_IP', 'comment_date', 'comment_date_gmt',
		'comment_content', 'comment_approved', 'comment_type', 'comment_parent', 'user_id',
		'comment_agent', 'comment_karma',
	);

	/** Term columns. */
	const TERMS = array( 'term_id', 'name', 'slug', 'description', 'parent', 'count' );

	/**
	 * The list for a source type, or an empty list for a type with no core columns.
	 *
	 * @param string $type Source type: posts, users, comments or terms.
	 * @return string[]
	 */
	public static function for_type( string $type ): array {
		$lists = array(
			'posts'    => self::POSTS,
			'users'    => self::USERS,
			'comments' => self::COMMENTS,
			'terms'    => self::TERMS,
		);
		return $lists[ $type ] ?? array();
	}
}
