<?php if (!defined('ABSPATH')) die;

add_action('template_redirect', function() {
	
	if ( (defined('DOING_CRON') && DOING_CRON) || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) || (defined('DOING_AJAX') && DOING_AJAX) ) return;
	
	if (is_admin()) return;
	
	if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'sitemap.xml') !== false) return;
	
	// No pretty permalinks? No redirects
	if (!get_option('permalink_structure')) {
		return;
	}
	
	// Redirects enabled?
	$options = get_option('bie_settings');
	if (!isset($options['enabled_redirects']) || !$options['enabled_redirects']) {
		return;
	}
	
	global $wp_query;
	if ($wp_query->is_404 === false) return;
	
	$redirect = false;
	$new_url = home_url('/');
	
	global $wp;
	$split = explode('/', $wp->request);
	
	// m=1 is stripped along with all other queries
	
	if (isset($split[0]) && isset($split[1])) {
		
		if ($split[0] == 'feeds' && $split[1] == 'posts') { // feeds
			
			$new_url .= 'feed';
			$redirect = true;
			
		} elseif ($split[0] == 'search' && $split[1] == 'label' && !empty($split[2])) { // labels
			
			$label = str_replace('%20', '-', $split[2]);
			
			// check if it's a category/tag
			if (term_exists($label, 'category')) {
				$base = get_option('category_base', 'category');
				$new_url .= $base.'/'.strtolower($label);
				$redirect = true;
			} elseif (term_exists($label, 'tag')) {
				$base = get_option('tag_base', 'tag');
				$new_url .= $base.'/'.strtolower($label);
				$redirect = true;
			}
			
		} elseif ( (count($split) === 2 && $split[0] == 'p' && strpos($split[1], '.html') !== false) || (is_numeric($split[0]) && !empty($split[2]) && strpos($split[2], '.html') !== false) ) { // posts/pages
		
			$url = $wp->request;
			$path = parse_url($url, PHP_URL_PATH);
			$path = ltrim($path, '/');
			
			global $wpdb;
			
			$post_id = (int) $wpdb->get_var( $wpdb->prepare("SELECT post_id FROM {$wpdb->prefix}bie_redirects WHERE blogger_permalink = %s", $path) );
			
			if ($post_id) {
				
				$new_url = get_permalink($post_id);
				$redirect = true;
				
			} else {
				
				$post_id = (int) $wpdb->get_var( $wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'blogger_permalink' AND meta_value = %s", $path) );
				
				if ($post_id) {
					
					$new_url = get_permalink($post_id);
					$redirect = true;
					
				} else {
					
					$slug = str_replace('.html', '', basename($url));
					
					if (!empty($slug)) {
						
						$post_id = $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_name = '".esc_sql($slug)."'");
						
						if ($post_id) {
							$new_url = get_permalink($post_id);
							$redirect = true;
						}
						
					}
					
				}
			}
			
		}
		
	}
		
	if (!$redirect) {
		if (isset($options['redirect_404s']) && $options['redirect_404s']) {
			$redirect = true;
		}
	}
	
	if ($redirect) {
		wp_redirect($new_url, 301);
		die;
	}

}, 99999);

add_action('admin_footer-plugins.php', function() {
	
	$options = get_option('bie_settings');
	
	?>
	<script>
	jQuery(document).ready(function($) {
		
		$('tr[data-slug="blogger-importer-extended"] .deactivate a').click(function(e){
			if (!confirm("This plugin must be enabled for the 301 Redirects to work from Blogger/Blogspot. Are you sure you want to deactivate it?")) {
				e.preventDefault();
			}
		});
		
		<?php if (isset($options['enabled_redirects']) && $options['enabled_redirects']) { ?>
			$('tr[data-slug="blogger-importer-extended"]').find('.plugin-title').find('strong').append(' - Redirects enabled');
		<?php } ?>
	});
	</script>
	<?php
}, 99999);


add_action('admin_notices', function() {
	
	$active_plugins = array();
	
	if (function_exists('br_plugin_settings_link')) {
		$active_plugins[] = '"Blogger 301 Redirect"';
	}
	
	if (function_exists('rt_blogger_to_wordpress_add_option')) {
		$active_plugins[] = '"Blogger To WordPress"';
	}
	
	if (!$active_plugins) {
		return;
	}
	
	global $pagenow;
	if ($pagenow != 'plugins.php') {
		return;
	}
	
	if (current_user_can('manage_options')) {
		if (!empty($_POST['bie_hide_redirects_plugin_notice']) && wp_verify_nonce($_POST['bie_hide_redirects_plugin_nonce'], 'sec')) {
			update_option('bie_hide_redirects_plugin_notice', 1);
			return;
		}
	} else {
		return;
	}
	
	if (!get_option('bie_hide_setup_notice')) {
		return;
	}
	
	if (get_option('bie_hide_redirects_plugin_notice')) {
		return;
	}
	
	?>
	<div class="notice notice-warning">
		<h2>Plugin conflict</h2>
		<p>The <?php echo implode($active_plugins, ' and '); ?> plugin is currently active. Please note this may cause problems with Blogger Importer Extended. We recommend removing those plugins and activating the 301 redirect options on <a href="<?php echo admin_url('options-general.php?page=bie-settings'); ?>">this page</a> instead.</p>
		<p id="showDeactivateBlogger301RedirectText" style="display:none"> <a href="#" id="deactivateBlogger301RedirectPlugin">Click here</a> to deactivate the "Blogger 301 Redirect" plugin automatically.</p>
		<p id="showDeactivateBloggerToWordPressText" style="display:none"> <a href="#" id="deactivateBloggerToWordPress">Click here</a> to deactivate the "Blogger To WordPress" plugin automatically.</p>
		<form action="<?php echo admin_url('plugins.php') ?>" method="post">
			<input type="hidden" value="1" name="bie_hide_redirects_plugin_notice" />
			<?php wp_nonce_field('sec', 'bie_hide_redirects_plugin_nonce'); ?>
			<p class="submit" style="margin-top: 5px; padding-top: 5px;">
				<input name="submit" class="button" value="Remove this notice" type="submit" />
			</p>
		</form>
	</div>
	<style>
	tr[data-slug="blogger-301-redirect"] th, tr[data-slug="blogger-to-wordpress-redirection"] th {
		border-left-color: red !important;
	}
	</style>
	<script>
	jQuery(document).ready(function($) {
		
		var hrefBlogger301Redirect = $('tr[data-slug="blogger-301-redirect"] .deactivate a').attr('href');
		if (hrefBlogger301Redirect) {
			$('#showDeactivateBlogger301RedirectText').show();
		}
		$('#deactivateBlogger301RedirectPlugin').attr('href', hrefBlogger301Redirect);
		
		var hrefBloggerToWordPress = $('tr[data-slug="blogger-to-wordpress-redirection"] .deactivate a').attr('href');
		if (hrefBloggerToWordPress) {
			$('#showDeactivateBloggerToWordPressText').show();
		}
		$('#deactivateBloggerToWordPress').attr('href', hrefBloggerToWordPress);
	});
	</script>
	<?php
});