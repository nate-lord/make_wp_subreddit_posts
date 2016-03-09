<?php
/**
 * @package make_wp_subreddit_posts
 * @version 1.0
 */
/*
Plugin Name: Make WP Subreddit Posts
Description: Creates posts from the self.wordpress articles on the wordpress subreddit.
Author: Nate Lord
Version: 1.0
Author URI: http:/natelord.org/
*/

function create_posts( $feeds ) {
	/**
	* publishes the posts
	* amends the (wp_)read_reddit_posts containing all posts already published to prevent duplicates
	*/
	
	global $table_prefix;
	global $wpdb;
	
	$i = 0;
	$l = count( $feeds );
	
	for ( $i; $i < $l; $i = $i + 1 ) {
		$feed = $feeds[ $i ]->data;
		$post = array(
			'post_title' => $feed->title,
			'post_content' => $feed->selftext,
			'post_date' => date( 'Y-m-d H:i:s', $feed->created ),
			'post_date_gmt' => date( 'Y-m-d H:i:s', $feed->created_utc ),
			'post_status' => 'publish',
			'meta_input' => array(
				'reddit_author' => $feed->author,
				'score' => $feed->score,
				'over_18' => $feed->over_18,
				'num_comments' => $feed->num_comments,
				'downs' => $feed->downs,
				'src_url' => $feed->url,
				'ups' => $feed->ups
			)
		);
		
		$post_state = wp_insert_post( $post );
		
		if ( $post_state ) {
			$table_name = $table_prefix . 'read_reddit_posts';
			
			$wpdb->insert( 
				$table_name, 
				array( 
					'reddit_post_id' => $feed->id
				) 
			);
		}
	}
}

function filter_feeds( $feeds ) {
	/**
	* filters out non self.wordpress posts
	* compares each potentially new post against the (wp_)read_reddit_posts table to prevent duplicates
	* returns the filtered object
	*/
	
	global $table_prefix;
	global $wpdb;
	
	$feeds = json_decode( $feeds );
	$feeds = $feeds->data->children;
	$filtered = array();
	$table_name = $table_prefix . 'read_reddit_posts';
	$read_reddit_posts = $wpdb->get_col( "SELECT reddit_post_id FROM $table_name" );
	
	$i = 0;
	$l = count( $feeds );
	for ( $i; $i < $l; $i = $i + 1 ) {
		$domain = $feeds[ $i ]->data->domain;
		$reddit_post_id = $feeds[ $i ]->data->id;
		if ( $domain === 'self.Wordpress' && !in_array( $reddit_post_id, $read_reddit_posts ) ) {
			$filtered[] = $feeds[ $i ];
		}
	}
	
	return $filtered;
}

add_action( 'hourly_get_feeds', 'get_feeds' );
function get_feeds() {
	/**
	* grabs the JSON feed from reddit using cURL
	* filters out non self.wordpress & pre-downloaded reddit posts
	* sends the feed to be made into published posts
	*/
	
	$ch = curl_init(); 
	
	curl_setopt( $ch, CURLOPT_URL, 'https://www.reddit.com/r/wordpress.json' );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
	$raw_feeds = curl_exec( $ch );
	curl_close( $ch );
	
	$feeds_for_posts = filter_feeds( $raw_feeds );
	
	create_posts( $feeds_for_posts );
}

function make_plugin_table( $table_name ) {
	/**
	* the table (wp_)read_reddit_posts stores each of the ids grabbed from the JSON feed
	* note that these are the ids reddit populates into the id field
	* new posts are checked against these to prevent copies
	*/
	
	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
	  id mediumint(9) NOT NULL AUTO_INCREMENT,
	  reddit_post_id tinytext NOT NULL,
	  UNIQUE KEY id (id)
	) $charset_collate;";

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	dbDelta( $sql );
}

function check_for_plugin_table() {
	/**
	* checks to see if the table storing the read / posted reddit ids exists and makes it if it doesn't
	*/
	
	global $table_prefix;
	global $wpdb;
	$table_name = $table_prefix . 'read_reddit_posts';
	
	if( $wpdb->get_var( "SHOW TABLES LIKE '$table_name'" ) !== $table_name ) {
		make_plugin_table( $table_name );
	}
}

function make_wp_subreddit_posts_activate() {
	/**
	* on activation of the plugin:
	* checks to see if the table storing the read / posted reddit ids exists and makes it if it doesn't
	* runs get_feeds initially to populate the blog
	* schedules the cron event to check for new enteries hourly
	*/
	
	check_for_plugin_table();
	get_feeds();
	wp_schedule_event( time(), 'hourly', 'hourly_get_feeds' );
}
register_activation_hook( __FILE__, 'make_wp_subreddit_posts_activate' );

function make_wp_subreddit_posts_deactivate() {
	/**
	* clears the cron task if the user deactivates the plugin
	*/
	
	wp_clear_scheduled_hook( 'hourly_get_feeds' );
}
register_deactivation_hook( __FILE__, 'make_wp_subreddit_posts_deactivate' );
?>