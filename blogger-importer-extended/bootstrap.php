<?php
/*
Plugin Name: Blogger Importer Extended
Plugin URI: https://wordpress.org/plugins/blogger-importer-extended/
Description: The only plugin you need to move from Blogger to WordPress. Import all content and setup 301 redirects automatically.
Author: pipdig
Version: 3.4.0
Author URI: https://www.pipdig.co/
License: GPLv2 or later
Text Domain: blogger-importer-extended
*/

if (!defined('ABSPATH')) die;

define('BIE_VER', '3.4.0');
define('BIE_DOMAIN', 'bie.ppdg.eco');
define('BIE_DIR', plugin_dir_path(__FILE__));
define('BIE_PATH', plugin_dir_url(__FILE__));

if (!defined('BIE_CONCURRENCY')) {
	define('BIE_CONCURRENCY', 3);
}

if (!defined('BIE_IMAGE_DOWNLOAD_TIMEOUT')) {
	define('BIE_IMAGE_DOWNLOAD_TIMEOUT', 20);
}

include(BIE_DIR.'settings.php');
include(BIE_DIR.'redirects.php');
include(BIE_DIR.'rest.php');

add_action('admin_init', function() {
	register_importer('bie-importer', 'Blogger Importer Extended', __('Import posts, pages, comments and labels from Blogger to WordPress.', 'blogger-importer-extended'), 'bie_page_render');
});


add_action('admin_menu', function() {
	add_submenu_page('tools.php', __('Blogger Importer', 'blogger-importer-extended'), __('Blogger Importer', 'blogger-importer-extended'), 'edit_theme_options', 'bie-importer', 'bie_page_render');
}, 999999);


add_filter('plugin_action_links_'.plugin_basename(__FILE__), function($links) {
	$links[] = '<a href="'.esc_url(admin_url('options-general.php?page=bie-settings')).'">'.__('Run Importer', 'blogger-importer-extended').'</a>';
	$links[] = '<a href="'.esc_url(admin_url('options-general.php?page=bie-settings#redirectsCard')).'">301 Redirects</a>';
	return $links;
});


register_activation_hook(__FILE__, function() {
	
	bie_create_database_tables();
	
	update_option('default_pingback_flag', '');
	update_option('default_ping_status', 'closed');
	
	bie_remove_helper_mu_plugin();
	
});

register_deactivation_hook(__FILE__, function() {
	
	delete_option('bie_license');
	
	global $wpdb;
	$results = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bie_page_token_%'");
	foreach ($results as $result) {
		delete_option($result->option_name);
	}
	
	bie_remove_helper_mu_plugin();
	
});


function bie_remove_helper_mu_plugin() {
	
	if (is_multisite()) {
		return;
	}
	
	$mu_file = WP_CONTENT_DIR.'/mu-plugins/blogger-importer-extended-helper.php';
	
	if (!file_exists($mu_file) || !is_writable($mu_file)) {
		return;
	}
	
	@unlink($mu_file);
	
}

add_action('admin_init', function() {
	
	if (get_option('bie_mu_removed') === BIE_VER) {
		return;
	}
	
	bie_remove_helper_mu_plugin();
	
	update_option('bie_mu_removed', BIE_VER, false);
	
});


function bie_create_database_tables() {
	
	global $wpdb;
	
	$charset_collate = $wpdb->get_charset_collate();
	$table_name = $wpdb->prefix.'bie_redirects';
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
		post_id bigint(20) PRIMARY KEY,
		blogger_permalink tinytext NOT NULL,
		blogger_post_id tinytext NOT NULL
	) $charset_collate;";
	
	require_once(ABSPATH.'wp-admin/includes/upgrade.php');
	dbDelta($sql);

}


add_action('before_delete_post', function($post_id) {
	
	global $wpdb;
	
	$table_name = $wpdb->prefix.'bie_redirects';
	$wpdb->delete($table_name, array('post_id' => $post_id));
	
});


add_action('admin_notices', function() {
	
	global $pagenow;
	if ($pagenow != 'plugins.php') {
		return;
	}
	
	if (current_user_can('manage_options')) {
		if (!empty($_POST['bie_hide_setup_notice']) && wp_verify_nonce($_POST['bie_hide_setup_notice_nonce'], 'sec')) {
			update_option('bie_hide_setup_notice', 1);
			return;
		}
	} else {
		return;
	}

	if (get_option('bie_hide_setup_notice')) {
		return;
	}
	
	?>
	<div class="notice notice-success">
		<h2>Blogger Importer</h2>
		<p>Thank you for installing Blogger Importer Extended! Please go to <a href="<?php echo esc_url(admin_url('options-general.php?page=bie-settings')); ?>">this page</a> to get started.</p>
		<form action="" method="post">
			<input type="hidden" value="1" name="bie_hide_setup_notice" />
			<?php wp_nonce_field('sec', 'bie_hide_setup_notice_nonce'); ?>
			<p class="submit" style="margin-top: 5px; padding-top: 5px;">
				<a href="<?php echo esc_url(admin_url('options-general.php?page=bie-settings')); ?>" class="button button-primary" style="margin-right: 5px;">Get Started</a> <input name="submit" class="button" value="Remove this notice" type="submit" />
			</p>
		</form>
	</div>
	<?php
});


add_action('admin_enqueue_scripts', function($hook) {

	$is_importer = ($hook === 'tools_page_bie-importer')
		|| (isset($_GET['import']) && $_GET['import'] === 'bie-importer');

	if (!$is_importer) {
		return;
	}

	$ver = (defined('WP_DEBUG') && WP_DEBUG) ? (string) filemtime(BIE_DIR.'js/importer.js') : BIE_VER;

	wp_enqueue_style('bie-importer', BIE_PATH.'css/importer.css', array('dashicons'), $ver);
	wp_enqueue_script('bie-importer', BIE_PATH.'js/importer.js', array(), $ver, true);
	wp_enqueue_script('bie-importer-ui', BIE_PATH.'js/importer-ui.js', array('jquery', 'bie-importer'), $ver, true);

	wp_add_inline_script('bie-importer', 'window.BIE_BOOT = '.wp_json_encode(array(
		'root'      => esc_url_raw(rest_url(BIE_REST_NS)),
		'nonce'     => wp_create_nonce('wp_rest'),
		'ajaxUrl'   => admin_url('admin-ajax.php'),
		'ajaxNonce' => wp_create_nonce('bie_ajax_nonce'),
		'adminUrl'  => admin_url(),
		'settings'  => esc_url_raw(admin_url('options-general.php?page=bie-settings')),
		'restart'   => esc_url_raw(admin_url('tools.php?page=bie-importer')),
		'postsUrl'  => esc_url_raw(admin_url('edit.php')),
		'imgPath'   => BIE_PATH.'img/',
		'limits'    => array('concurrency' => (int) BIE_CONCURRENCY),

		'apiBase'   => esc_url_raw(bie_api_base()),
		'apiArgs'   => array(
			'plugin_v' => BIE_VER,
			'home_url' => home_url(),
			'license'  => !empty(get_option('bie_license')) ? get_option('bie_license') : 'free',
		),
		'pageSize'  => 25,
	)).';', 'before');

}, 20);


function bie_page_render() {

	$license = get_option('bie_license');
	$prefill = ($license && $license !== 'free') ? $license : '';
	$resume  = !empty($_GET['bid']) ? sanitize_text_field($_GET['bid']) : '';

	?>
	<div id="pipdigBloggerImporter">

		<a id="pipdigBloggerClose" href="<?php echo esc_url(admin_url('options-general.php?page=bie-settings')); ?>" title="<?php esc_attr_e('Return to dashboard', 'blogger-importer-extended'); ?>">
			<span class="dashicons dashicons-no-alt"></span>
		</a>

		<div id="pipdigBloggerImporterContent">

			<img src="<?php echo esc_url(BIE_PATH.'img/boxes.svg'); ?>" alt="" class="fade_out" style="width:150px" />
			<h2 class="fade_out"><?php esc_html_e('Welcome to the Blogger Importer!', 'blogger-importer-extended'); ?></h2>

			<div id="bieLicenseChoices"<?php echo $resume ? ' style="display:none"' : ''; ?>>
				<p><?php esc_html_e('The free version of this plugin can import up to 20 blog posts and pages.', 'blogger-importer-extended'); ?></p>
				<p><?php printf(
					wp_kses(__('Alternatively you can purchase an <a href="%s" target="_blank" rel="noopener">unlimited license</a> for unlimited posts, pages, comments and images.', 'blogger-importer-extended'), array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))),
					'https://go.pipdig.co/open.php?id=bie-pro'
				); ?></p>
				<div style="margin-top:20px">
					<div class="button" id="bieFreeBtn"><?php esc_html_e('20 posts for free', 'blogger-importer-extended'); ?></div>
					<div class="button button-primary" id="bieProBtn"><?php esc_html_e('Unlimited license', 'blogger-importer-extended'); ?></div>
				</div>
			</div>

			<div id="blogLicenseStep" class="fade_out" style="display:none">
				<p class="fade_out" style="margin-bottom: 20px;"><?php printf(
					wp_kses(__('What is your license key? License keys can be purchased <a href="%s" target="_blank" rel="noopener">here</a>.', 'blogger-importer-extended'), array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))),
					'https://go.pipdig.co/open.php?id=bie-pro'
				); ?></p>
				<input type="text" value="<?php echo esc_attr($prefill); ?>" class="wide-fat fade_out" style="width:320px;max-width:100%;" id="bieLicenseField">
				<input type="button" value="<?php esc_attr_e('Submit', 'blogger-importer-extended'); ?>" class="button button-primary fade_out" id="bieLicenseSubmit">
				<div id="bieCheckingLicense" style="display: none; margin-top: 10px;">
					<span class="dashicons dashicons-update spin"></span> <?php esc_html_e('Checking License...', 'blogger-importer-extended'); ?>
				</div>
				<div id="bieCheckingLicenseResult" style="margin-top: 10px;"></div>
			</div>

			<div id="blogIdStep" class="fade_out" style="display:none">
				<p>
					<span id="bieLicenseSuccessMsg"></span>
					<?php printf(
						wp_kses(__('Please enter your Blog\'s ID in the option below. You can find your Blog ID like <a href="%s" target="_blank" rel="noopener">this example</a>.', 'blogger-importer-extended'), array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))),
						esc_url(BIE_PATH.'img/find_blog_id.png')
					); ?>
				</p>
				<p style="margin-bottom: 20px;">
					<span class="dashicons dashicons-warning"></span>
					<?php printf(
						wp_kses(__('Please note that Blogger settings must be <a href="%s" target="_blank" rel="noopener">Public</a> during the import.', 'blogger-importer-extended'), array('a' => array('href' => array(), 'target' => array(), 'rel' => array()))),
						esc_url(BIE_PATH.'img/blogger_public.png')
					); ?>
				</p>
				<input type="text" value="<?php echo esc_attr($resume); ?>" class="wide-fat fade_out" style="width:320px;max-width:100%;" id="BlogggerBlogIdField" placeholder="<?php esc_attr_e('Blog ID should be a number', 'blogger-importer-extended'); ?>">
				<input type="button" value="<?php esc_attr_e('Submit', 'blogger-importer-extended'); ?>" class="button button-primary fade_out" id="submitBlogId">
			</div>

			<div id="pipdigBloggerImpoterMsg1"></div>
			<div id="bieCounts" style="display:none"></div>
			<div id="bieWarnings" style="display:none"></div>

		</div>
	</div>

	<?php if ($resume) : ?>
	<script>window.BIE_AUTOSTART = <?php echo wp_json_encode($resume); ?>;</script>
	<?php endif; ?>
	<?php

}


add_action('wp_ajax_bie_free_license_ajax', function() {
	check_ajax_referer('bie_ajax_nonce', 'sec');
	update_option('bie_license', 'free', false);
	wp_die();
});

add_action('wp_ajax_bie_complete_ajax', function() {
	
	check_ajax_referer('bie_ajax_nonce', 'sec');
	
	delete_option('bie_license');
	
	global $wpdb;
	$results = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bie_page_token_%'");
	foreach ($results as $result) {
		delete_option($result->option_name);
	}
	
	update_option('bie_hide_setup_notice', 1);
	
	echo 'BIE import complete.';
	wp_die();
	
});


add_action('wp_ajax_bie_check_license_ajax', function() {

	check_ajax_referer('bie_ajax_nonce', 'sec');
	
	if (!isset($_POST['bie_license'])) {
		echo 0;
		wp_die();
	}
	
	$email = get_option('admin_email');
	if (empty($email)) {
		$email = 0;
	}
	
	$bh = 0;
	if (defined('WP_CONTENT_DIR') && file_exists(WP_CONTENT_DIR.'/mu-plugins/sso.php')) {
		$bh = 1;
	}
	
	$url = rawurlencode(home_url());
	if (empty($url)) {
		$url = 0;
	}
	
	$license = sanitize_text_field($_POST['bie_license']);
	
	$request = add_query_arg(array(
		'license' => $license,
		'email' => $email,
		'bh' => $bh,
		'url' => $url
	), 'https://'.BIE_DOMAIN.'/check_license.php');
	
	$body = wp_remote_retrieve_body(wp_remote_get($request, array('timeout' => 15)));
	
	if ($body == 1) {
		update_option('bie_license', $license, false);
	}
	
	echo absint($body);
	
	wp_die();
	
});


add_action('wp_ajax_bie_get_blog_ajax', function() {

	check_ajax_referer('bie_ajax_nonce', 'sec');
	
	if (!isset($_POST['blogger_blog_id'])) {
		echo '<p>Error: Please enter your Blogger blog ID.</p>';
		wp_die();
	}
	
	$blogger_blog_id = sanitize_text_field($_POST['blogger_blog_id']);

	if (!is_numeric($blogger_blog_id)) {
		echo '<p>Error: Your blog ID should be a number.</p>';
		wp_die();
	}
	
	$free_license = true;
	if (get_option('bie_license') && get_option('bie_license') != 'free') {
		$free_license = false;
	}
	
	$query_args = array(
		'blog_id' => $blogger_blog_id,
		'query' => 'blog',
	);
	
	$response = pipdig_blogger_get_response($query_args, true);
	
	if (isset($response->name)) {
		
		$site_url = rtrim($response->url, '/');
		$site_url = parse_url($site_url, PHP_URL_HOST);
		
		update_option('bie_blogspot_domain_'.$blogger_blog_id, $site_url, false);

		$direct_ok = (isset($response->api_v) && (int) $response->api_v >= 2) ? '1' : '0';

		echo '<h2 class="fade_out">'.esc_html($response->name).'</h2>';
		
		if (!$free_license) {
			echo '<p class="fade_out">Import '.absint($response->posts).' posts<span id="andPages"> and '.absint($response->pages).' pages</span> from <a href="'.esc_url($response->url).'" target="_blank" rel="noopener">'.esc_html($site_url).'</a>.</p>';
			echo '<p style="margin-bottom: 10px;"><em>Please note that Draft & Scheduled posts are not imported.</em></p>';
			echo '<div style="margin-bottom: 10px;"><label><input type="checkbox" id="skipPages" name="skipPages" value="1" title="If you only want to import blog posts and not pages, select this option"> Don\'t import pages.</label></div>';
			echo '<div style="margin-bottom: 10px;"><label><input type="checkbox" id="skipComments" name="skipComments" value="1"> Don\'t import post comments</label> <a href="https://support.pipdig.co/articles/blogger-importer-extended-faq/" target="_blank" rel="noopener" class="bie_info_icon" title="If you do not wish to keep old comments, select this option to speed up the import"><span class="dashicons dashicons-editor-help"></span></a></div>';
			echo '<div style="margin-bottom: 10px;"><label><input type="checkbox" id="skipImages" name="skipImages" value="1"> Don\'t import images</label> <a href="https://support.pipdig.co/articles/blogger-importer-extended-faq/" target="_blank" rel="noopener" class="bie_info_icon" title="This option will greatly speed up the import. Please note the images will remain on your Google/Blogspot account and not be imported to WordPress, but will be visible in post and page content as normal"><span class="dashicons dashicons-editor-help"></span></a></div>';
			echo '<div style="margin-bottom: 10px;"><label><input type="checkbox" id="skipAuthors" name="skipAuthors" value="1"> Don\'t import authors</label> <a href="https://support.pipdig.co/articles/blogger-importer-extended-faq/" target="_blank" rel="noopener" class="bie_info_icon" title="If you are the only author of this blog, we recommend enabling this option"><span class="dashicons dashicons-editor-help"></span></a></div>';
			echo '<div style="margin-bottom: 10px;"><label><input type="checkbox" id="convertFormatting" name="convertFormatting" value="1"> Try to clean-up post content</label> <a href="https://support.pipdig.co/articles/blogger-importer-extended-faq/" target="_blank" rel="noopener" class="bie_info_icon" title="Click here for more information"><span class="dashicons dashicons-editor-help"></span></a></div>';
		} else {
			echo '<p class="fade_out">Import up to 20 posts and pages</span> from <a href="'.esc_url($response->url).'" target="_blank" rel="noopener">'.esc_html($site_url).'</a>.</p>';
			echo '<p style="margin-bottom: 10px;">Want to import more content? Purchase an <a href="https://go.pipdig.co/open.php?id=bie-pro" target="_blank" rel="noopener">unlimited license</a> for unlimited inports.</p>';
			echo '<p style="margin-bottom: 10px;"><em>Please note that Draft & Scheduled posts are not imported.</em></p>';
		}
		
		if (get_option('bie_page_token_'.$blogger_blog_id)) {
			echo '<p style="margin-top:20px;"><span class="dashicons dashicons-backup"></span> Some of this blog has already been imported.</p><p>Should we continue from last time? Or start again and import any new posts first?</p>';
			echo '<div class="button button-primary fade_out" id="startImport" style="margin-top: 10px" data-total-posts="'.absint($response->posts).'" data-blogspot-domain="'.esc_attr($site_url).'" data-direct="'.absint($direct_ok).'"><span class="dashicons dashicons-controls-play"></span> Continue import</div> &nbsp;<div class="button fade_out" id="restartImport" style="margin-top: 10px" data-blog-id="'.esc_attr($blogger_blog_id).'">Import newest posts first</div>';
			echo '<p style="font-style:italic">(Already imported posts will be skipped with both options)</p>';
		} else {
			echo '<div class="button button-primary fade_out" id="startImport" style="margin-top: 10px" data-total-posts="'.absint($response->posts).'" data-blogspot-domain="'.esc_attr($site_url).'" data-direct="'.absint($direct_ok).'"><span class="dashicons dashicons-controls-play"></span> Start import!</div> &nbsp;<a href="'.esc_url(admin_url('tools.php?page=bie-importer')).'" class="button fade_out" style="margin-top: 10px">Cancel</a>';
		}
		
	}
	
	wp_die();
	
});



add_action('wp_ajax_bie_delete_progress_ajax', function() {

	check_ajax_referer('bie_ajax_nonce', 'sec');
	
	if (!isset($_POST['blogger_blog_id'])) {
		wp_die();
	}
	
	$blogger_blog_id = sanitize_text_field($_POST['blogger_blog_id']);
	
	delete_option('bie_page_token_'.$blogger_blog_id);
	
	wp_die();
	
});


function bie_import_comment_page($post_id, $blogger_blog_id, $blogger_post_id, $page_token = '') {

	global $wpdb;

	$query_args = array(
		'blog_id' => $blogger_blog_id,
		'query'   => 'comments',
		'post_id' => $blogger_post_id,
	);

	if ($page_token !== '') {
		$query_args['page_token'] = $page_token;
	}

	$response = bie_api_get($query_args);

	if (is_wp_error($response)) {
		return $response;
	}

	$existing = array();

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT cm.meta_value AS blogger_id, cm.comment_id AS comment_id
		 FROM {$wpdb->commentmeta} cm
		 INNER JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
		 WHERE c.comment_post_ID = %d AND cm.meta_key = 'blogger_id'",
		$post_id
	) );

	foreach ((array) $rows as $row) {
		$existing[(string) $row->blogger_id] = (int) $row->comment_id;
	}

	$inserted = 0;
	$skipped  = 0;
	$replies  = array();

	if (isset($response->items) && is_array($response->items)) {

		foreach ($response->items as $item) {

			if (!isset($item->id)) {
				continue;
			}

			$blogger_id = (string) $item->id;

			if (isset($existing[$blogger_id])) {
				$skipped++;
				continue;
			}

			$content = htmlspecialchars_decode(isset($item->content) ? $item->content : '');

			$comment_id = wp_insert_comment(array(
				'comment_post_ID' => $post_id,
				'comment_author'  => !empty($item->author) ? esc_html($item->author) : 'Anonymous',
				'comment_date'    => $item->published,
				'comment_content' => strip_tags($content, '<a><abbr><acronym><b><blockquote><br><cite><code><del><em><q><strike><strong><ul>'),
				'comment_meta'    => array(
					'blogger_id' => $blogger_id,
				),
			));

			if (!$comment_id) {
				continue;
			}

			$inserted++;
			$existing[$blogger_id] = (int) $comment_id;

			if (isset($item->inReplyTo->id)) {
				$replies[(int) $comment_id] = (string) $item->inReplyTo->id;
			}

		}

	}

	foreach ($replies as $child_id => $parent_blogger_id) {

		if (!isset($existing[$parent_blogger_id])) {
			continue;
		}

		wp_update_comment(array(
			'comment_ID'     => $child_id,
			'comment_parent' => $existing[$parent_blogger_id],
		));

	}

	return array(
		'inserted'        => $inserted,
		'skipped'         => $skipped,
		'next_page_token' => !empty($response->nextPageToken) ? (string) $response->nextPageToken : '',
	);

}

function pipdig_blogger_decode_content($content) {

	$content = trim($content);

	return htmlspecialchars_decode($content);

}


function bie_image_limit_per_post() {

	return (int) apply_filters('bie_image_limit_per_post', 0);

}


function bie_extract_image_urls($content) {

	$images = array();

	preg_match_all('/<img [^>]*src="([^"]+blogspot\.com\/[^"]+)"[^>]*>/', $content, $found_images);
	if (!empty($found_images[1])) {
		foreach ($found_images[1] as $found_image) {
			$images[] = $found_image;
		}
	}

	preg_match_all('/<img [^>]*src="([^"]+googleusercontent\.com\/[^"]+)"[^>]*>/', $content, $found_images);
	if (!empty($found_images[1])) {
		foreach ($found_images[1] as $found_image) {
			$images[] = $found_image;
		}
	}

	$images = array_values(array_unique($images));

	$limit = bie_image_limit_per_post();

	if ($limit > 0 && count($images) > $limit) {
		$images = array_slice($images, 0, $limit);
	}

	return $images;

}


function bie_sideload_image($post_id, $src, $post_date = '', $author_id = 0) {

	require_once(ABSPATH.'wp-admin/includes/file.php');
	require_once(ABSPATH.'wp-admin/includes/media.php');
	require_once(ABSPATH.'wp-admin/includes/image.php');

	if (!$author_id) {
		$author_id = get_current_user_id();
	}

	$name    = wp_basename(urldecode(urldecode($src)));
	$decoded = urldecode(urldecode($src));

	if (empty($name)) {
		return new WP_Error('bie_image_failed', __('Image URL has no filename.', 'blogger-importer-extended'), array('status' => 422));
	}

	$tmp = download_url($decoded, BIE_IMAGE_DOWNLOAD_TIMEOUT);

	if (is_wp_error($tmp)) {
		return new WP_Error(
			'bie_image_failed',
			$tmp->get_error_message(),
			array('status' => 503, 'src' => $src)
		);
	}

	$info = getimagesize($tmp);

	if (!$info || empty($info['mime'])) {
		@unlink($tmp);
		return new WP_Error('bie_image_failed', __('Downloaded file is not an image.', 'blogger-importer-extended'), array('status' => 422, 'src' => $src));
	}

	$ext_map = array(
		'image/jpeg' => '.jpg',
		'image/png'  => '.png',
		'image/gif'  => '.gif',
		'image/webp' => '.webp',
	);

	$file_ext = isset($ext_map[$info['mime']]) ? $ext_map[$info['mime']] : '.jpg';

	if (strlen($name) > 150) {

		$short_slug = substr(get_post_field('post_name', $post_id), 0, 150);

		if ($short_slug) {
			$short_slug = $short_slug.'-';
		}

		$name = $short_slug.rand().$file_ext;
	}

	$file = array(
		'name'     => $name,
		'tmp_name' => $tmp,
	);

	$filename = pathinfo($file['name'], PATHINFO_FILENAME).$file_ext;

	$image_id = media_handle_sideload($file, $post_id, $filename, array('post_date' => $post_date, 'post_author' => $author_id));

	if (is_wp_error($image_id)) {
		@unlink($tmp);
		return new WP_Error('bie_image_failed', $image_id->get_error_message(), array('status' => 422, 'src' => $src));
	}

	@unlink($tmp);

	$attachment = wp_get_attachment_image_src($image_id, 'large');

	if (empty($attachment[0])) {
		return new WP_Error('bie_image_failed', __('Attachment created but has no URL.', 'blogger-importer-extended'), array('status' => 500, 'src' => $src));
	}

	return array(
		'attachment_id' => (int) $image_id,
		'url'           => $attachment[0],
	);

}


function bie_rewrite_content_images($content, $map) {

	foreach ($map as $src => $image) {

		$url = $image['url'];
		$id  = absint($image['attachment_id']);

		$content = str_replace('src="'.$src.'"', 'src="'.$url.'" class="wp-image-'.$id.'"', $content);
		$content = str_replace("src='".$src."'", "src='".$url."' class='wp-image-".$id."'", $content);

		$content = str_replace($src, $url, $content);

	}

	return $content;

}

function pipdig_blogger_process_author($username, $name) {
	
	$user = get_user_by('login', $username);
	if ($user) {
		return $user->ID;
	}
	
	$user_id = wp_insert_user(array(
		'user_login' => $username,
		'user_pass' => wp_generate_password(24, true, true),
		'display_name' => $name,
		'nickname' => $name,
		'role' => 'author',
	));
	
	if (!is_wp_error($user_id)) {
		return $user_id;
	} else {
		return get_current_user_id();
	}
	
}


function bie_api_base() {

	if (defined('BIE_API_BASE')) {
		return trailingslashit(BIE_API_BASE);
	}

	return 'https://'.BIE_DOMAIN.'/';

}


function bie_api_get($query_args, $cb = false) {

	$default_args = array(
		'plugin_v' => BIE_VER,
		'home_url' => home_url(),
		'license' => !empty(get_option('bie_license')) ? get_option('bie_license') : 'free',
	);

	if ($cb) {
		$query_args['cb'] = time();
	}

	$query_args = wp_parse_args($query_args, $default_args);

	$url = add_query_arg($query_args, bie_api_base());

	$response = wp_remote_get($url, array('timeout' => 20));

	if (is_wp_error($response)) {
		return new WP_Error(
			'bie_upstream_unavailable',
			__('Could not reach the import service. This is usually temporary.', 'blogger-importer-extended'),
			array('status' => 503, 'detail' => $response->get_error_message())
		);
	}

	$body      = wp_remote_retrieve_body($response);
	$http_code = (int) wp_remote_retrieve_response_code($response);

	if ($body === '') {
		return new WP_Error(
			'bie_upstream_unavailable',
			__('The import service returned an empty response.', 'blogger-importer-extended'),
			array('status' => 503, 'http_code' => $http_code)
		);
	}

	$decoded = json_decode($body);

	if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
		return new WP_Error(
			'bie_bad_gateway',
			__('The import service returned an unreadable response.', 'blogger-importer-extended'),
			array('status' => 502, 'snippet' => substr(wp_strip_all_tags($body), 0, 300))
		);
	}

	if (isset($decoded->message)) {

		$message = wp_kses($decoded->message, array('a' => array('href' => array(), 'target' => array(), 'rel' => array())));

		if ($http_code >= 500) {
			return new WP_Error('bie_upstream_unavailable', $message, array('status' => 503));
		}

		return new WP_Error('bie_license_limit', $message, array('status' => 403));

	}

	if (isset($decoded->error->message)) {
		return new WP_Error(
			'bie_upstream_error',
			sprintf(__('Blogger returned an error: %s', 'blogger-importer-extended'), $decoded->error->message),
			array('status' => 502)
		);
	}

	return $decoded;

}


function pipdig_blogger_get_response($query_args, $cb = false) {

	$response = bie_api_get($query_args, $cb);

	if (!is_wp_error($response)) {
		return $response;
	}

	if (defined('REST_REQUEST') && REST_REQUEST) {
		return $response;
	}

	echo '<p>'.esc_html($response->get_error_message()).'</p>';
	wp_die();

}
