<?php if (!defined('ABSPATH')) die;


define('BIE_REST_NS', 'bie/v1');


function bie_rest_permission() {

	if (current_user_can('import')) {
		return true;
	}

	return new WP_Error(
		'bie_forbidden',
		__('You are not allowed to run the importer.', 'blogger-importer-extended'),
		array('status' => rest_authorization_required_code())
	);

}


function bie_rest_permission_upload() {

	$can = bie_rest_permission();

	if (is_wp_error($can)) {
		return $can;
	}

	if (!current_user_can('upload_files')) {
		return new WP_Error(
			'bie_forbidden',
			__('You are not allowed to upload files.', 'blogger-importer-extended'),
			array('status' => rest_authorization_required_code())
		);
	}

	return true;

}


add_action('rest_api_init', function() {

	$common = array('permission_callback' => 'bie_rest_permission');

	register_rest_route(BIE_REST_NS, '/ping', array_merge($common, array(
		'methods'  => WP_REST_Server::READABLE,
		'callback' => function() {
			return rest_ensure_response(array('ok' => true, 'version' => BIE_VER));
		},
	)));

	register_rest_route(BIE_REST_NS, '/batch', array_merge($common, array(
		'methods'  => WP_REST_Server::CREATABLE,
		'callback' => 'bie_rest_batch',
		'args'     => array(
			'blog_id'    => array('required' => true),
			'type'       => array('default' => 'posts'),
			'page_token' => array('default' => ''),
		),
	)));

	register_rest_route(BIE_REST_NS, '/post', array_merge($common, array(
		'methods'  => WP_REST_Server::CREATABLE,
		'callback' => 'bie_rest_post',
		'args'     => array(
			'blog_id' => array('required' => true),
			'type'    => array('default' => 'posts'),
			'item'    => array('required' => true),
		),
	)));

	register_rest_route(BIE_REST_NS, '/comments', array_merge($common, array(
		'methods'  => WP_REST_Server::CREATABLE,
		'callback' => 'bie_rest_comments',
		'args'     => array(
			'blog_id'         => array('required' => true),
			'post_id'         => array('required' => true),
			'blogger_post_id' => array('required' => true),
			'page_token'      => array('default' => ''),
			'items'           => array('default' => null),
		),
	)));

	register_rest_route(BIE_REST_NS, '/image', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => 'bie_rest_permission_upload',
		'callback'            => 'bie_rest_image',
		'args'                => array(
			'post_id' => array('required' => true),
			'src'     => array('required' => true),
		),
	));

	register_rest_route(BIE_REST_NS, '/finalize', array_merge($common, array(
		'methods'  => WP_REST_Server::CREATABLE,
		'callback' => 'bie_rest_finalize',
		'args'     => array(
			'post_id'            => array('required' => true),
			'abandon_remaining'  => array('default' => false),
		),
	)));

	register_rest_route(BIE_REST_NS, '/cursor', array_merge($common, array(
		'methods'  => WP_REST_Server::CREATABLE,
		'callback' => 'bie_rest_cursor',
		'args'     => array(
			'blog_id'    => array('required' => true),
			'page_token' => array('default' => ''),
		),
	)));

	register_rest_route(BIE_REST_NS, '/pending', array_merge($common, array(
		'methods'  => WP_REST_Server::READABLE,
		'callback' => 'bie_rest_pending',
		'args'     => array(
			'after' => array('default' => 0),
			'limit' => array('default' => 100),
		),
	)));

	register_rest_route(BIE_REST_NS, '/complete', array_merge($common, array(
		'methods'  => WP_REST_Server::CREATABLE,
		'callback' => 'bie_rest_complete',
	)));

});


function bie_rest_cursor(WP_REST_Request $request) {

	$blog_id = sanitize_text_field($request['blog_id']);

	if (!is_numeric($blog_id)) {
		return new WP_Error('bie_bad_blog_id', __('Blog ID should be a number.', 'blogger-importer-extended'), array('status' => 400));
	}

	$token = (string) $request['page_token'];

	if ($token !== '') {
		update_option('bie_page_token_'.$blog_id, $token, false);
	} else {
		delete_option('bie_page_token_'.$blog_id);
	}

	return rest_ensure_response(array('ok' => true));

}


function bie_rest_pending(WP_REST_Request $request) {

	global $wpdb;

	$after = absint($request['after']);
	$limit = min(500, max(1, absint($request['limit'])));

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta}
		 WHERE meta_key = '_bie_pending' AND post_id > %d
		 ORDER BY post_id ASC LIMIT %d",
		$after, $limit
	) );

	$items = array();

	foreach ((array) $rows as $row) {

		$data = json_decode($row->meta_value, true);

		if (!is_array($data)) {
			continue;
		}

		$items[] = array(
			'post_id' => (int) $row->post_id,
			'todo'    => isset($data['todo']) ? array_values((array) $data['todo']) : array(),
		);

	}

	return rest_ensure_response(array(
		'ok'    => true,
		'items' => $items,
		'more'  => count($rows) === $limit,
	));

}


function bie_prepare_import_env() {

	static $done = false;

	if (!defined('WP_IMPORTING')) {
		define('WP_IMPORTING', true);
	}

	add_filter('intermediate_image_sizes_advanced', '__return_empty_array');
	add_filter('big_image_size_threshold', '__return_false');

	if ($done) {
		return;
	}

	$done = true;

	wp_defer_term_counting(true);
	wp_defer_comment_counting(true);

	add_action('shutdown', function() {
		wp_defer_term_counting(false);
		wp_defer_comment_counting(false);
	}, 1);

}


function bie_rest_batch(WP_REST_Request $request) {

	$blog_id = sanitize_text_field($request['blog_id']);
	$type    = ($request['type'] === 'pages') ? 'pages' : 'posts';

	if (!is_numeric($blog_id)) {
		return new WP_Error('bie_bad_blog_id', __('Blog ID should be a number.', 'blogger-importer-extended'), array('status' => 400));
	}

	$query_args = array(
		'blog_id'          => $blog_id,
		'query'            => $type,
		'blogspot_domain'  => (string) get_option('bie_blogspot_domain_'.$blog_id, ''),
	);

	$page_token = (string) $request['page_token'];

	if ($page_token !== '') {
		$query_args['page_token'] = $page_token;
	}

	foreach (array('skip_comments', 'skip_images', 'convert_formatting') as $flag) {
		if (!empty($request[$flag])) {
			$query_args[$flag] = '1';
		}
	}

	$response = bie_api_get($query_args);

	if (is_wp_error($response)) {
		return $response;
	}

	$next = !empty($response->nextPageToken) ? (string) $response->nextPageToken : '';

	if ($type === 'posts') {
		if ($next !== '') {
			update_option('bie_page_token_'.$blog_id, $next, false);
		} else {
			delete_option('bie_page_token_'.$blog_id);
		}
	}

	return rest_ensure_response(array(
		'ok'              => true,
		'type'            => $type,
		'items'           => isset($response->items) && is_array($response->items) ? $response->items : array(),
		'next_page_token' => $next,
	));

}


function bie_find_existing_post($blogger_item_id, $type) {

	global $wpdb;

	if ($type === 'pages') {
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %s AND meta_key = 'blogger_post_id' LIMIT 1",
			$blogger_item_id
		) );
	}

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT post_id FROM {$wpdb->prefix}bie_redirects WHERE blogger_post_id = %s LIMIT 1",
		$blogger_item_id
	) );

}


function bie_rest_post(WP_REST_Request $request) {

	global $wpdb;

	bie_prepare_import_env();

	$blog_id = sanitize_text_field($request['blog_id']);
	$type    = ($request['type'] === 'pages') ? 'pages' : 'posts';
	$item    = $request['item'];

	if (is_array($item)) {
		$item = json_decode(wp_json_encode($item));
	}

	if (!is_object($item) || empty($item->id)) {
		return new WP_Error('bie_bad_item', __('Malformed item.', 'blogger-importer-extended'), array('status' => 400));
	}

	$blogger_item_id = (string) $item->id;

	$existing = bie_find_existing_post($blogger_item_id, $type);

	if ($existing) {
		return rest_ensure_response(array(
			'ok'      => true,
			'post_id' => $existing,
			'created' => false,
			'images'  => array(),
		));
	}

	$raw_content  = isset($item->content) ? $item->content : '';
	$post_content = pipdig_blogger_decode_content($raw_content);

	if ($post_content === '' && trim($raw_content) !== '') {
		return new WP_Error('bie_decode_failed', __('Post content could not be decoded.', 'blogger-importer-extended'), array('status' => 422));
	}

	$insert_post = array(
		'post_type'     => ($type === 'pages') ? 'page' : 'post',
		'post_date_gmt' => isset($item->published) ? $item->published : '',
		'post_content'  => $post_content,
		'post_title'    => isset($item->title) ? $item->title : '',
		'post_status'   => 'publish',
		'ping_status'   => 'closed',
		'post_name'     => isset($item->slug) ? $item->slug : '',
	);

	if ($type === 'posts') {
		$insert_post['tags_input'] = property_exists($item, 'labels') ? $item->labels : '';
	} else {
		$insert_post['meta_input'] = array('blogger_post_id' => $blogger_item_id);
	}

	$author_id = '';

	if (empty($request['skip_authors']) && isset($item->author) && $item->author !== 'Unknown') {
		$author_id = pipdig_blogger_process_author(sanitize_user($item->author_id), $item->author);
		$insert_post['post_author'] = $author_id;
	}

	$post_id = wp_insert_post($insert_post, true);

	if (is_wp_error($post_id) || !$post_id) {
		return new WP_Error(
			'bie_insert_failed',
			is_wp_error($post_id) ? $post_id->get_error_message() : __('Could not create the post.', 'blogger-importer-extended'),
			array('status' => 500)
		);
	}

	if ($type === 'posts') {
		$wpdb->insert(
			$wpdb->prefix.'bie_redirects',
			array(
				'post_id'           => $post_id,
				'blogger_permalink' => isset($item->permalink) ? $item->permalink : '',
				'blogger_post_id'   => $blogger_item_id,
			),
			array('%d', '%s', '%s')
		);
	}

	$images = array();

	if (empty($request['skip_images'])) {
		$images = bie_extract_image_urls($post_content);
	}

	if ($images) {
		update_post_meta($post_id, '_bie_pending', wp_slash(wp_json_encode(array(
			'todo'     => $images,
			'done'     => array(),
			'featured' => $images[0],
			'date'     => isset($item->published) ? $item->published : '',
			'author'   => $author_id,
		))));
	}

	return rest_ensure_response(array(
		'ok'            => true,
		'post_id'       => (int) $post_id,
		'created'       => true,
		'images'        => $images,
		'needs_finalize' => !empty($images),
	));

}


function bie_rest_comments(WP_REST_Request $request) {

	bie_prepare_import_env();

	$post_id = absint($request['post_id']);

	if (!$post_id || !get_post($post_id)) {
		return new WP_Error('bie_missing_post', __('Post no longer exists.', 'blogger-importer-extended'), array('status' => 404));
	}

	$items = $request['items'];

	if (is_array($items)) {
		$result = bie_insert_comment_page($post_id, json_decode(wp_json_encode($items)));
	} else {
		$result = bie_import_comment_page(
			$post_id,
			sanitize_text_field($request['blog_id']),
			sanitize_text_field($request['blogger_post_id']),
			(string) $request['page_token']
		);
	}

	if (is_wp_error($result)) {
		return $result;
	}

	return rest_ensure_response(array_merge(array('ok' => true), $result));

}


function bie_rest_image(WP_REST_Request $request) {

	bie_prepare_import_env();

	$post_id = absint($request['post_id']);
	$src     = (string) $request['src'];

	$pending = bie_get_pending($post_id);

	if (!$pending || !in_array($src, $pending['todo'], true)) {
		return rest_ensure_response(array('ok' => true, 'skipped' => 'not_pending'));
	}

	$result = bie_sideload_image($post_id, $src, $pending['date'], $pending['author']);

	if (is_wp_error($result)) {

		$data = $result->get_error_data();
		$status = isset($data['status']) ? (int) $data['status'] : 500;

		if ($status === 422) {
			bie_pending_drop($post_id, $src);
			return rest_ensure_response(array(
				'ok'      => true,
				'skipped' => 'failed',
				'warning' => $result->get_error_message(),
			));
		}

		return $result;

	}

	bie_pending_complete($post_id, $src, $result);

	$pending = bie_get_pending($post_id);

	return rest_ensure_response(array(
		'ok'            => true,
		'attachment_id' => $result['attachment_id'],
		'url'           => $result['url'],
		'remaining'     => $pending ? count($pending['todo']) : 0,
	));

}


function bie_rest_finalize(WP_REST_Request $request) {

	$post_id = absint($request['post_id']);
	$pending = bie_get_pending($post_id);

	if (!$pending) {
		return rest_ensure_response(array('ok' => true, 'skipped' => 'nothing_pending'));
	}

	if ($pending['todo'] && empty($request['abandon_remaining'])) {
		return new WP_Error(
			'bie_not_ready',
			__('Images are still being imported for this post.', 'blogger-importer-extended'),
			array('status' => 409, 'remaining' => count($pending['todo']))
		);
	}

	$abandoned = count($pending['todo']);

	$post = get_post($post_id);

	if (!$post) {
		delete_post_meta($post_id, '_bie_pending');
		return new WP_Error('bie_missing_post', __('Post no longer exists.', 'blogger-importer-extended'), array('status' => 404));
	}

	$content = bie_rewrite_content_images($post->post_content, $pending['done']);

	if ($content !== $post->post_content) {
		wp_update_post(array(
			'ID'           => $post_id,
			'post_content' => $content,
		));
	}

	if (!empty($pending['featured']) && isset($pending['done'][$pending['featured']])) {
		update_post_meta($post_id, '_thumbnail_id', $pending['done'][$pending['featured']]['attachment_id']);
	}

	wp_update_comment_count($post_id);

	delete_post_meta($post_id, '_bie_pending');

	return rest_ensure_response(array('ok' => true, 'abandoned' => $abandoned));

}


function bie_rest_complete(WP_REST_Request $request) {

	global $wpdb;

	delete_option('bie_license');

	$results = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bie_page_token_%'");

	foreach ($results as $result) {
		delete_option($result->option_name);
	}

	update_option('bie_hide_setup_notice', 1);

	wp_cache_flush();

	return rest_ensure_response(array('ok' => true));

}



function bie_get_pending($post_id) {

	$raw = get_post_meta($post_id, '_bie_pending', true);

	if (!$raw) {
		return null;
	}

	$data = json_decode($raw, true);

	if (!is_array($data)) {
		return null;
	}

	return wp_parse_args($data, array(
		'todo'     => array(),
		'done'     => array(),
		'featured' => '',
		'date'     => '',
		'author'   => 0,
	));

}


function bie_save_pending($post_id, $data) {

	update_post_meta($post_id, '_bie_pending', wp_slash(wp_json_encode($data)));

}


function bie_pending_drop($post_id, $src) {

	$pending = bie_get_pending($post_id);

	if (!$pending) {
		return;
	}

	$pending['todo'] = array_values(array_diff($pending['todo'], array($src)));

	bie_save_pending($post_id, $pending);

}


function bie_pending_complete($post_id, $src, $result) {

	$pending = bie_get_pending($post_id);

	if (!$pending) {
		return;
	}

	$pending['todo'] = array_values(array_diff($pending['todo'], array($src)));
	$pending['done'][$src] = $result;

	bie_save_pending($post_id, $pending);

}
