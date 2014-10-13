<?php
/*
Plugin Name: Commons In A Box Demo Site Cleaner
Version: 0.1-alpha
Description: Cleanup routines for demo.commonsinabox.org
Author: Boone Gorges
Text Domain: cbox-demo-site-cleaner
Domain Path: /languages
*/

function cbox_dsc_catch_clean_request() {
	/*
	if ( empty( $_GET['clean_site'] ) ) {
		return;
	}

	if ( empty( $_GET['key'] ) ) {
		return;
	}

	$key = urldecode( $_GET['key'] );

	if ( ! defined( 'CBOX_DSC_KEY' ) || $key != CBOX_DSC_KEY ) {
		return;
	}
	*/

	// Only one chunk can run at a time.
	$chunk_in_progress = get_site_option( 'cbox_dsc_chunk_in_progress' );
	if ( ! empty( $chunk_in_progress ) ) {
		return;
	}

	// If a clean routine is in progress, skip the last_clean check.
	$clean_in_progress = get_site_option( 'cbox_dsc_clean_in_progress' );
	if ( empty( $clean_in_progress ) ) {
		$last_clean = get_site_option( 'cbox_dsc_last_clean' );
		if ( empty( $last_clean ) ) {
			$last_clean = 0;
		}

		// Weekly.
		$interval = 60 * 60 * 24 * 7;

		if ( time() - $last_clean < $interval ) {
			return;
		}

		update_site_option( 'cbox_dsc_clean_in_progress', '1' );
	}

	update_site_option( 'cbox_dsc_chunk_in_progress', '1' );

	if ( cbox_dsc_clean() ) {
		update_site_option( 'cbox_dsc_last_clean', time() );
		delete_site_option( 'cbox_dsc_chunk_in_progress' );
	}

	delete_site_option( 'cbox_dsc_chunk_in_progress', '1' );
}
add_action( 'bp_init', 'cbox_dsc_catch_clean_request', 20 );

/**
 * Primary function for running the clean routine.
 */
function cbox_dsc_clean() {
	$types = array(
		'user',
		'group',
		'activity',
		'topic', // We keep the forums around
		'docs',
	);

	$last = get_site_option( 'cbox_dsc_last' );
	if ( $last ) {
		$last = explode( ':', $last );
		$last_type = $last[0];
		$last_item = $last[1];
	} else {
		$last_type = 'user';
		$last_item = 0;
	}

	$increment = 100;

	error_log( sprintf(
		'Cleaning %d through %d of type %s',
		$last_item,
		$last_item + $increment,
		$last_type
	) );

	$type_done = cbox_dsc_clean_type( $last_type, $last_item, $increment );

	error_log( sprintf(
		'Cleaned %d through %d of type %s',
		$last_item,
		$last_item + $increment,
		$last_type
	) );

	$all_done = false;
	if ( $type_done ) {
		$type_done_key = array_search( $last_type, $types );
		$new_last_type_key = $type_done_key + 1;

		if ( isset( $types[ $new_last_type_key ] ) ) {
			$last_type = $types[ $new_last_type_key ];
			$last_item = 0;
		} else {
			// We are done
			$all_done = true;
		}
	} else {
		$last_item += $increment;
	}

	if ( $all_done ) {
		delete_site_option( 'cbox_dsc_last' );
		return true;
	} else {
		$new_last = $last_type . ':' . $last_item;
		update_site_option( 'cbox_dsc_last', $new_last );
		return false;
	}
}

/**
 * Clean by type. The behemoth.
 *
 * @param string $type
 * @param int $start
 * @param int $increment
 */
function cbox_dsc_clean_type( $type, $start, $increment ) {
	global $wpdb;

	$type_done = false;
	$bp = buddypress();

	switch ( $type ) {
		case 'user' :
			if ( ! function_exists( 'wp_delete_user' ) ) {
				include_once( ABSPATH . '/wp-admin/includes/user.php' );
			}

			$uid_query = $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID != 1 LIMIT %d, %d", $start, $increment );
			$user_ids = $wpdb->get_col( $uid_query );

			foreach ( $user_ids as $user_id ) {
				wp_delete_user( $user_id );
			}

			// Are there any left?
			$remaining = $wpdb->get_col( $uid_query );
			$type_done = empty( $remaining );

			break;

		case 'group' :
			$gid_query = $wpdb->prepare( "SELECT id FROM {$bp->groups->table_name} LIMIT %d, %d", $start, $increment );
			$group_ids = $wpdb->get_col( $gid_query );

			foreach ( $group_ids as $group_id ) {
				groups_delete_group( $group_id );
			}

			// Are there any left?
			$remaining = $wpdb->get_col( $gid_query );
			$type_done = empty( $remaining );

			break;

		case 'activity' :
			$aid_query = $wpdb->prepare( "SELECT id FROM {$bp->activity->table_name} LIMIT %d, %d", $start, $increment );
			$activity_ids = $wpdb->get_col( $aid_query );

			foreach ( $activity_ids as $activity_id ) {
				bp_activity_delete( array(
					'id' => $activity_id,
				) );
			}

			// Are there any left?
			$remaining = $wpdb->get_col( $aid_query );
			$type_done = empty( $remaining );

			break;

		case 'topic' :
			$tid_query = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'topic' LIMIT %d, %d", $start, $increment );
			$topic_ids = $wpdb->get_col( $tid_query );

			foreach ( $topic_ids as $topic_id ) {
				wp_delete_post( $topic_id, true );
			}

			// Are there any left?
			$remaining = $wpdb->get_col( $tid_query );
			$type_done = empty( $remaining );

			break;

		case 'docs' :
			$did_query = $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'bp_doc' LIMIT %d, %d", $start, $increment );
			$doc_ids = $wpdb->get_col( $did_query );

			foreach ( $doc_ids as $doc_id ) {
				wp_delete_post( $doc_id, true );
			}

			// Are there any left?
			$remaining = $wpdb->get_col( $did_query );
			$type_done = empty( $remaining );

			break;
	}

	return $type_done;
}
