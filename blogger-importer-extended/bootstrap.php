<?php
/*
Plugin Name: Blogger Importer Extended
Plugin URI: https://wordpress.org/plugins/blogger-importer-extended/
Description: The only plugin you need to move from Blogger to WordPress. Import all your content and setup 301 redirects automatically.
Author: pipdig
Version: 3.2.5
Author URI: https://www.pipdig.co/
License: GPLv2 or later
Text Domain: blogger-importer-extended
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

if (!defined('ABSPATH')) die;

define('BIE_VER', '3.2.5');
define('BIE_DOMAIN', 'bie.pdg.center');
define('BIE_DIR', plugin_dir_path(__FILE__));
define('BIE_PATH', plugin_dir_url(__FILE__));

 // Wait time between import batches
if (!defined('BIE_WAIT_TIME')) {
	define('BIE_WAIT_TIME', 1750);
}

include(BIE_DIR.'settings.php');
include(BIE_DIR.'redirects.php');

// create entry in 'Tools > Import'
add_action('admin_init', function() {
	register_importer('bie-importer', 'Blogger Importer Extended', __('Import posts, pages, comments and labels from Blogger to WordPress.', 'bie-importer'), 'bie_page_render');
});


// Add menu item under Tools
add_action('admin_menu', function() {
	add_submenu_page('tools.php', __('Blogger Importer'), __('Blogger Importer'), 'edit_theme_options', 'bie-importer', 'bie_page_render');
}, 999999);


// Plugin page links
add_filter('plugin_action_links_'.plugin_basename(__FILE__), function($links) {
	$links[] = '<a href="'.admin_url('options-general.php?page=bie-settings').'">'.__('Run Importer').'</a>';
	$links[] = '<a href="'.admin_url('options-general.php?page=bie-settings#redirectsCard').'">301 Redirects</a>';
	return $links;
});


// Setup some things on activation
register_activation_hook(__FILE__, function() {
	
	bie_create_database_tables();
	
	if (!get_option('bie_installed_date')) {
		add_option('bie_installed_date', date('Y-m-d'));
	}
	
	update_option('default_pingback_flag', '');
	update_option('default_ping_status', 'closed');
	
});

// Clear some things on deactivation
register_deactivation_hook(__FILE__, function() {
	
	delete_option('bie_license');
	
	// clear any import tokens
	global $wpdb;
	$results = $wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'bie_page_token_%'");
	foreach ($results as $result) {
		delete_option($result->option_name);
	}
	
});


// Create table used to store redirects from Blogger traffic
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


// Remove a redirect from the database when a post is deleted
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
		<p>Thank you for installing Blogger Importer Extended! Please go to <a href="<?php echo admin_url('options-general.php?page=bie-settings'); ?>">this page</a> to get started.</p>
		<form action="" method="post">
			<input type="hidden" value="1" name="bie_hide_setup_notice" />
			<?php wp_nonce_field('sec', 'bie_hide_setup_notice_nonce'); ?>
			<p class="submit" style="margin-top: 5px; padding-top: 5px;">
				<a href="<?php echo admin_url('options-general.php?page=bie-settings'); ?>" class="button button-primary" style="margin-right: 5px;">Get Started</a> <input name="submit" class="button" value="Remove this notice" type="submit" />
			</p>
		</form>
	</div>
	<?php
});


function bie_page_render() {
	?>
	<style>
	html.wp-toolbar {
		padding-top: 0;
	}
	#wpwrap {
		display: none;
	}

	body {
		margin: 65px auto 24px;
		box-shadow: none;
		background: #f1f1f1;
		padding: 0;
		max-width: 600px;
	}
	
	#pipdigBloggerImporter {
		text-align: center;
		position: relative;
	}
	
	#pipdigBloggerClose {
		position: absolute;
		top: 0;
		right: 0;
		text-decoration: none;
	}
	#pipdigBloggerClose .dashicons {
		font-size: 40px;
		width: 40px;
		height: 40px;
	}
	
	#pipdigBloggerImporterContent {
		box-shadow: 0 1px 3px rgba(0, 0, 0, .13);
		padding: 2em;
		margin: 0 0 20px;
		background: #fff;
		overflow: hidden;
		zoom: 1;
		text-align: center;
		border-radius: 2px;
	}

	#pipdigBloggerImpoterMsg1 {
		margin-top: 35px;
	}

	input.fade_out, .fade_out {
		-moz-transition: opacity 0.25s ease-out; -webkit-transition: opacity 0.25s ease-out; transition: opacity 0.25s ease-out;
	}
	.pipdig_hide {
		opacity: 0.25;
		pointer-events: none;
	}

	.dashicons.spin {
		animation: dashicons-spin 1.3s infinite;
		animation-timing-function: linear;
	}
	@keyframes dashicons-spin {
		0% {
			transform: rotate( 0deg );
		}
		100% {
			transform: rotate( 360deg );
		}
	}
	.bie_info_icon {
		text-decoration: none;
	}
	#postImportProgress {
		margin-top: 25px;
	}
	</style>
	
	<script>
	jQuery(document).ready(function($) {
		
		$('#wpwrap').before('<div id="pipdigBloggerImporter"><a id="pipdigBloggerClose" href="<?php echo admin_url('options-general.php?page=bie-settings'); ?>" title="Return to dashboard"><span class="dashicons dashicons-no-alt"></span></a><div id="pipdigBloggerImporterContent"><img src="<?php echo BIE_PATH; ?>img/boxes.svg" alt="" class="fade_out" style="width:150px" /><h2 class="fade_out">Welcome to the Blogger Importer!</h2><div id="bieLicenseChoices"><p>The free version of this plugin can import up to 20 blog posts and pages.</p><p>Alternatively you can purchase an <a href="https://go.pipdig.co/open.php?id=bie-pro" target="_blank" rel="noopener">unlimited license</a> for unlimited posts, pages, comments and images.</p><p>Read more about the differences <a href="https://go.pipdig.co/open.php?id=bie-pro" target="_blank" rel="noopener">here</a>.</p><div style="margin-top:20px"><div class="button" id="bieFreeBtn">20 posts for free</div> <div class="button button-primary" id="bieProBtn">Unlimited license</div></div></div><div id="blogLicenseStep" class="fade_out" style="display:none"><p class="fade_out" style="margin-bottom: 20px;">What is your license key? License keys an be purchased <a href="https://go.pipdig.co/open.php?id=bie-pro" target="_blank" rel="noopener">here</a>.</p><input type="text" value="" class="wide-fat fade_out" style="width:320px;max-width:100%;" id="bieLicenseField"> <input type="button" value="<?php echo esc_attr(__('Submit')); ?>" class="button button-primary fade_out" id="bieLicenseSubmit"><div id="bieCheckingLicense" style="display: none; margin-top: 10px;"><span class="dashicons dashicons-update spin"></span> Checking License...</div><div id="bieCheckingLicenseResult" style="margin-top: 10px;"></div></div><div id="blogIdStep" class="fade_out" style="display:none"><p><span id="bieLicenseSuccessMsg"></span>Please enter your Blog\'s ID in the option below. You can find your Blog ID like <a href="<?php echo BIE_PATH; ?>img/find_blog_id.png" target="_blank" rel="noopener">this example</a>.</p><p style="margin-bottom: 20px;"><span class="dashicons dashicons-warning"></span> Please note that Blogger settings must be <a href="<?php echo BIE_PATH; ?>img/blogger_public.png" target="_blank" rel="noopener">Public</a> during the import.</p><input type="text" value="" class="wide-fat fade_out" style="width:320px;max-width:100%;" id="BlogggerBlogIdField" placeholder="Blog ID should be a number"> <input type="button" value="<?php echo esc_attr(__('Submit')); ?>" class="button button-primary fade_out" id="submitBlogId"></div><div id="pipdigBloggerImpoterMsg1"></div><p id="postImportProgress"></p></div></div><div id="totalPostCount" style="display:none"></div><div id="lastUpdateCountdown" style="display:none"></div>');
		
		var bieLicenseField = $('#bieLicenseField');
		
		<?php
		if (get_option('bie_license') && get_option('bie_license') != 'free') {
			echo 'bieLicenseField.val("'.sanitize_text_field(get_option('bie_license')).'");';
		}
		?>
		
		// Select Free button
		$('#pipdigBloggerImporter').on('click', '#bieFreeBtn', function() {
			
			$('#bieLicenseChoices').slideUp(300);
			$('#blogIdStep').slideDown(300);
			
			var data = {
				'action': 'bie_free_license_ajax',
				'sec': '<?php echo wp_create_nonce('bie_ajax_nonce'); ?>',
			};
			
			$.post(ajaxurl, data, function(response) {
				
			});
			
		});
		
		// Select Pro button
		$('#pipdigBloggerImporter').on('click', '#bieProBtn', function() {
			$('#bieLicenseChoices').slideUp(300);
			$('#blogLicenseStep').slideDown(300);
		});
		
		// Submit license button
		$('#pipdigBloggerImporter').on('click', '#bieLicenseSubmit', function() {
			checkLicenseStep();
		});
		// or on Return key
		bieLicenseField.bind("enterKey", function() {
			checkLicenseStep();
		});
		bieLicenseField.keyup(function(e) {
		    if (e.keyCode == 13) {
		        $(this).trigger("enterKey");
		    }
		});
		
		function checkLicenseStep() {
			
			if (!bieLicenseField.val()) {
				return;
			}
			
			$('#bieCheckingLicenseResult').text('');
			$('#bieCheckingLicense').show();
			
			var data = {
				'action': 'bie_check_license_ajax',
				'sec': '<?php echo wp_create_nonce('bie_ajax_nonce'); ?>',
				'bie_license': bieLicenseField.val()
			};
			
			$.post(ajaxurl, data, function(response) {
				
				$('#bieCheckingLicense').hide();
				
				if (response == 1) {
					//$('#bieLicenseSuccessMsg').text('License is valid, thanks! ');
					$('#blogLicenseStep').slideUp(300);
					$('#blogIdStep').slideDown(300);
					$('#BlogggerBlogIdField').focus();
				} else if (response == 2) {
					$('#bieCheckingLicenseResult').html('This license has expired. Would you like to <a href="https://go.pipdig.co/open.php?id=bie-pro" target="_blank" rel="noopener">purchase a new one</a>?');
				} else if (response == 3) {
					$('#bieCheckingLicenseResult').html('This license does not exist. Please check your email receipt for the license key or <a href="<?php echo admin_url('tools.php?page=bie-importer'); ?>">click here</a> to restart the import process.');
				} else {
					
				}
				
			});
			
		}
		
		var BlogggerBlogIdField = $('#BlogggerBlogIdField');
		var button = $('#submitBlogId');
		var message = $('#pipdigBloggerImpoterMsg1');
		
		<?php
		// if we're restarting importer, copy old ID from GET
		if (!empty($_GET['bid'])) {
			echo '$("#bieLicenseChoices").hide();';
			echo 'BlogggerBlogIdField.val("'.sanitize_text_field($_GET['bid']).'"); checkBlogggerBlogId()';
		}
		?>
		
		function checkBlogggerBlogId() {
			
			if (BlogggerBlogIdField.val()) {
				
				button.prop('disabled', true);
				button.removeClass('button-primary');
				$('.fade_out').addClass('pipdig_hide');
				message.html('<h2><span class="dashicons dashicons-update spin"></span> Loading...</h2>');
				
				var data = {
					'action': 'bie_get_blog_ajax',
					'sec': '<?php echo wp_create_nonce('bie_ajax_nonce'); ?>',
					'blogger_blog_id': BlogggerBlogIdField.val(),
				};
				
				$.post(ajaxurl, data, function(response) {
					message.html(response);
					//console.log(response);
					button.prop('disabled', false);
					$('.fade_out').removeClass('pipdig_hide');
				});
				
			} else {
				message.html('Please enter your Blogger blog ID in the field above. You can get your Blog ID from <a href="<?php echo BIE_PATH; ?>img/find_blog_id.png" rel="nofollow" target="_blank">here</a> when logged in to Blogger.');
			}
		}

		// Search blog ID on submit button
		button.on('click', function() {
			checkBlogggerBlogId();		
		});
		// or on Enter key
		BlogggerBlogIdField.bind("enterKey", function() {
			checkLicenseStep();
		});
		BlogggerBlogIdField.keyup(function(e) {
			// numbers only
			if (/\D/g.test(this.value)) {
				this.value = this.value.replace(/\D/g, '');
			}
			// submit on enter key
		    if (e.keyCode == 13) {
		        $(this).trigger("enterKey");
		    }
		});
		
		$('#pipdigBloggerImporter').on('click', '#restartImport', function() {
			
			var BlogggerBlogIdToDelete = $(this).data('blog-id');
			
			var data = {
				'action': 'bie_delete_progress_ajax',
				'sec': '<?php echo wp_create_nonce('bie_ajax_nonce'); ?>',
				'blogger_blog_id': BlogggerBlogIdToDelete,
			};
			
			$.post(ajaxurl, data, function(response) {
				// start import by clicking button
				$('#startImport').trigger('click');
			});			
			
		});
		
		$('#pipdigBloggerImporter').on('click', '#startImport', function() {
			
			importPostsBtn = $(this);
			
			if (confirm("Are you sure you want to import this blog?")) {
				
				// Set "browse away" navigation prompt
				window.onbeforeunload = function() {
					return true;
				};
				
				$('#pipdigBloggerClose').hide();
				
				$('.fade_out').slideUp(550);
				message.css('margin-top', 0);
				
				var skipComments = 0;
				if ($('#skipComments').prop("checked") == true) {
					skipComments = 1;
				}
				
				var skipPages = 0;
				if ($('#skipPages').prop("checked") == true) {
					skipPages = 1;
				}
				
				var skipImages = 0;
				if ($('#skipImages').prop("checked") == true) {
					skipImages = 1;
				}
				
				var skipAuthors = 0;
				if ($('#skipAuthors').prop("checked") == true) {
					skipAuthors = 1;
				}
				
				var convertFormatting = 0;
				if ($('#convertFormatting').prop("checked") == true) {
					convertFormatting = 1;
				}
				
				var totalPosts = parseInt(importPostsBtn.data('total-posts'));
				$('#totalPostCount').text(totalPosts);
				
				var importingTimeNotice = 'The import process can take a long time for large blogs.';
				if ((totalPosts > 1000) && skipImages == 0) {
					importingTimeNotice = 'This import may take several hours. Please note that the importer may need to restart, however you will not lose any progress.';
				}
				
				button.prop('disabled', true);
				$('.fade_out').addClass('pipdig_hide');
				importPostsBtn.addClass('pipdig_hide');
				message.html('<img src="<?php echo BIE_PATH; ?>img/moving.svg" alt="" style="width: 150px;" /><h2><span class="dashicons dashicons-update spin"></span> Importing, Please wait...</h2><p>Please <strong>keep this window open</strong>.</p><p>'+importingTimeNotice+'</p><div style="margin-top: 25px"><a href="<?php echo admin_url('tools.php?page=bie-importer'); ?>&bid='+BlogggerBlogIdField.val()+'" class="button fade_out" id="stopImport"><span class="dashicons dashicons-no" style="margin-top: 4px;"></span> Stop the import!</a></div>');
				
				importPosts('', 0, skipComments, skipImages, skipPages, skipAuthors, convertFormatting);
				
			}
			
		});
		
		$('#pipdigBloggerImporter').on('change', '#includeDrafts', function() {
			if (this.checked) {
				$('#draftsFile').slideDown();
			} else {
				$('#draftsFile').slideUp();
			}
		});
		
		$('#pipdigBloggerImporter').on('change', '#skipPages', function() {
			if (this.checked) {
				$('#andPages').fadeOut();
			} else {
				$('#andPages').fadeIn();
			}
		});
		
		// check the response is a json object
		function checkIsJsonString(str) {
			try {
				JSON.parse(str);
			} catch (e) {
				return false;
			}
			return true;
		}
		
		function importPosts(pageToken, postsImported, skipComments, skipImages, skipPages, skipAuthors, convertFormatting) {
			
			var data = {
				'action': 'bie_progress_ajax',
				'sec': '<?php echo wp_create_nonce('bie_ajax_nonce'); ?>',
				'page_token': pageToken,
				'posts_imported': postsImported,
				'blogger_blog_id': BlogggerBlogIdField.val(),
				'skip_comments': skipComments,
				'skip_pages': skipPages,
				'skip_images': skipImages,
				'skip_authors': skipAuthors,
				'convert_formatting': convertFormatting,
			};
			
			$.post(ajaxurl, data, function(response) {
				
				//console.log(response);
				
				if (!checkIsJsonString(response)) {
					console.log(response);
					if (response.includes("https://go.pipdig.co/open.php?id=2")) {
						message.html('<img src="<?php echo BIE_PATH; ?>img/battery_low.svg" alt="" style="width: 150px;" />'+response+'<p style="margin-top: 20px"><a class="button" href="<?php echo admin_url('options-general.php?page=bie-settings'); ?>">Return to dashboard</a></p>');
					} else {
						message.html('<img src="<?php echo BIE_PATH; ?>img/broken.svg" alt="" style="width: 150px;" /><h2>Connection lost</h2><p>It looks like the importer has stopped working. Don\'t worry though, any progress was not lost! Click the button below to continue.</p><p>Are you seeing this message a lot? <a href="https://support.pipdig.co/articles/blogger-importer-extended-faq/" target="_blank" rel="noopener">Click here</a> for some tips for easier migrations.</p><p style="margin-top: 20px"><a class="button-primary" href="<?php echo admin_url('tools.php?page=bie-importer'); ?>&bid='+BlogggerBlogIdField.val()+'">Continue Importer</a></p>');
					}
					window.onbeforeunload = null; // Remove navigation prompt
					$('#postImportProgress').text('');
					$('#lastUpdateCountdown').text('');
					return;
				}
				
				var resp = JSON.parse(response);
				
				var postsImported = parseInt(resp.posts_imported);
				
				//console.log(postsImported);
				var totalPostCount = parseInt($('#totalPostCount').text());
				
				if (resp.next_page !== null && resp.next_page !== '') {
					
					$('#lastUpdateCountdown').text('180');
					
					/*
					if (resp.latest_imported_id != '' && resp.latest_imported_title != '' && resp.total_posts != 0) {
						$('#postImportProgress').html('<h2>Status Update:</h2>There are now <strong>'+resp.total_posts+'</strong> <a href="<?php echo admin_url('edit.php'); ?>" target="_blank" rel="noopener">blog posts</a> in WordPress.<br /><br />Last item imported:<br /><br /><a href="<?php echo trailingslashit(admin_url()); ?>post.php?post=' + resp.latest_imported_id + '&action=edit" target="_blank" rel="noopener" style="text-decoration:none">' + resp.latest_imported_title + '</a>');
					}
					*/
					
					if (typeof resp.total_posts !== 'undefined' && resp.total_posts != 0) {
						var time = new Date();
						var currentTime = time.toLocaleString('en-US', {hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true});
						$('#postImportProgress').html('<h2>Status Update:</h2><span style="font-style:italic">'+currentTime+': There are <strong>'+resp.total_posts+'</strong> <a href="<?php echo admin_url('edit.php'); ?>" target="_blank" rel="noopener">blog posts</a> in WordPress.</span>');
					}
					
					setTimeout(function() {
						importPosts(resp.next_page, postsImported, skipComments, skipImages, skipPages, skipAuthors, convertFormatting);
					}, <?php echo absint(BIE_WAIT_TIME); ?>);
					
				} else {
					
					// complete!
					document.title = 'Import Complete | <?php echo esc_attr(get_bloginfo("name")); ?>';
					window.onbeforeunload = null; // Remove navigation prompt
					$('#lastUpdateCountdown').text('');
					$('#postImportProgress').text('');
					message.html('<img src="<?php echo BIE_PATH; ?>img/success.svg" alt="" style="width: 150px;" /><h2>Success!</h2><p>All content was imported successfully.</p><p>What now? Don\'t forget to setup the <a href="<?php echo admin_url('options-general.php?page=bie-settings'); ?>">remaining steps</a>.</p><p style="margin-top: 20px"><a class="button" href="<?php echo admin_url('options-general.php?page=bie-settings'); ?>">Return to dashboard</a></p>');
					$('.fade_out').slideUp(550);
					
					$.post(ajaxurl, {'action': 'bie_complete_ajax', 'sec': '<?php echo wp_create_nonce('bie_ajax_nonce'); ?>'}, function(response) {
						console.log(response);
					});
					
				}
				
			});
				
		}
		
		
		// Every 1s, decrement our 60s counter. The counter is set back to 180 when a post import has completed via $('#lastUpdateCountdown').text('180'); above.
		setInterval(function() {
			
			var counter = parseInt($('#lastUpdateCountdown').text());
			
			if (!isNaN(counter)) {
				--counter; // decrement by 1
				
				$('#lastUpdateCountdown').text(counter);
				
				if (counter === 0) {
					window.onbeforeunload = null; // Remove navigation prompt
					message.html('<img src="<?php echo BIE_PATH; ?>img/broken.svg" alt="" style="width: 150px;" /><h2>Connection lost</h2><p>It looks like the importer has stopped unexpectedly. Don\'t worry though, any progress was not lost! Click the button below to continue the current import.</p><p>Are you seeing this message a lot? <a href="https://support.pipdig.co/articles/blogger-importer-extended-faq/" target="_blank" rel="noopener">Click here</a> for some tips for easier migrations.</p><p style="margin-top: 20px"><a class="button-primary" href="<?php echo admin_url('tools.php?page=bie-importer'); ?>&bid='+BlogggerBlogIdField.val()+'">Continue Importer</a></p>');
					$('#lastUpdateCountdown').text('');
					return;
				}
				
			}
			
		}, 1000);
		
	});
	</script>
	
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
		
		update_option('bie_blogspot_domain_'.$blogger_blog_id, $site_url, false); // save the old blogspot domain (not full url) so we can use it in search replace later
		
		echo '<h2 class="fade_out">'.esc_html($response->name).'</h2>';
		
		if (!$free_license) {
			echo '<p class="fade_out">Import '.absint($response->posts).' posts<span id="andPages"> and '.absint($response->pages).' pages</span> from <a href="'.esc_url($response->url).'" target="_blank" rel="noopener">'.esc_html($site_url).'</a>.</p>';
			echo '<p style="margin-bottom: 10px;"><em>Please note that Draft & Scheduled posts are not imported.</em></p>';
			//echo '<div style="margin-bottom: 10px;"><label><input type="checkbox" id="includeDrafts" name="includeDrafts" value="1"> Include Draft &amp; Schedule posts</label> <a href="https://support.pipdig.co/articles/blogger-importer-extended-faq/" target="_blank" rel="noopener" class="bie_info_icon"><span class="dashicons dashicons-editor-help"></span></a></div>';
			//echo '<div style="margin-bottom: 10px;" id="draftsFile">You can download your Blogger XML file <a href="https://www.blogger.com/feeds/'.$blogger_blog_id.'/archive?authuser=0" target="_blank" rel="noopener">here</a><br /><input type="file" id="draftsFileField" name="draftsFileField"></div>';
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
			// continue from previous
			echo '<p style="margin-top:20px;"><span class="dashicons dashicons-backup"></span> Some of this blog has already been imported.</p><p>Should we continue from last time? Or start again and import any new posts first?</p>';
			echo '<div class="button button-primary fade_out" id="startImport" style="margin-top: 10px" data-total-posts="'.absint($response->posts).'"><span class="dashicons dashicons-controls-play" style="margin-top: 4px;"></span> Continue import</div> &nbsp;<div class="button fade_out" id="restartImport" style="margin-top: 10px" data-blog-id="'.$blogger_blog_id.'">Import newest posts first</div>';
			echo '<p style="font-style:italic">(Already imported posts will be skipped with both options)</p>';
		} else {
			// no previous import found
			echo '<div class="button button-primary fade_out" id="startImport" style="margin-top: 10px" data-total-posts="'.absint($response->posts).'"><span class="dashicons dashicons-controls-play" style="margin-top: 4px;"></span> Start import!</div> &nbsp;<a href="'.admin_url('tools.php?page=bie-importer').'" class="button fade_out" style="margin-top: 10px">Cancel</a>';
		}
		
	}
	
	wp_die();
	
});


add_action('wp_ajax_bie_progress_ajax', function() {

	check_ajax_referer('bie_ajax_nonce', 'sec');
	
	global $wpdb;
	
	if (empty($_POST['blogger_blog_id'])) {
		echo '<p>Error: Please enter your Blogger blog ID.</p>';
		wp_die();
	}
	
	$blogger_blog_id = sanitize_text_field($_POST['blogger_blog_id']);
	
	$query_args = array(
		'blog_id' => $blogger_blog_id,
		'query' => 'posts',
		'blogspot_domain' => !empty(get_option('bie_blogspot_domain_'.$blogger_blog_id)) ? get_option('bie_blogspot_domain_'.$blogger_blog_id) : '',
	);
	
	if (!empty($_POST['page_token'])) {
		$query_args['page_token'] = sanitize_text_field($_POST['page_token']);
	} elseif (get_option('bie_page_token_'.$blogger_blog_id)) {
		$query_args['page_token'] = get_option('bie_page_token_'.$blogger_blog_id);
	}
	
	$skip_comments = false;
	if (isset($_POST['skip_comments']) && absint($_POST['skip_comments']) === 1) {
		$query_args['skip_comments'] = '1';
		$skip_comments = true;
	}
	
	$skip_images = false;
	if (isset($_POST['skip_images']) && absint($_POST['skip_images']) === 1) {
		$query_args['skip_images'] = '1';
		$skip_images = true;
	}
	
	if (isset($_POST['convert_formatting']) && absint($_POST['convert_formatting']) === 1) {
		$query_args['convert_formatting'] = '1';
	}
	
	$skip_authors = false;
	if (isset($_POST['skip_authors']) && absint($_POST['skip_authors']) === 1) {
		$skip_authors = true;
	}
	
	$response = pipdig_blogger_get_response($query_args);
	
	if (!empty($_POST['posts_imported'])) {
		$x = absint($_POST['posts_imported']);
	} else {
		$x = 0;
	}
	
	wp_suspend_cache_invalidation(true);
	wp_defer_term_counting(true);
	wp_defer_comment_counting(true);
	remove_action('post_updated', 'wp_save_post_revision');
	add_filter('intermediate_image_sizes_advanced', 'pipdig_blogger_skip_image_sizes'); // disable image sizes from generating, temporarily whilst uploading
	
	if (!defined('WP_IMPORTING')) define('WP_IMPORTING', true);
	
	if (isset($response->items) && is_array($response->items)) {
		
		foreach ($response->items as $item) {
			
			$exists = (int) $wpdb->get_var( $wpdb->prepare('SELECT post_id FROM '.$wpdb->prefix.'bie_redirects WHERE blogger_post_id = %s', $item->id) );
			
			if ($exists !== 0) {
				$x++;
				continue;
			}
			
			$insert_post = array(
				'post_type' => 'post',
				'post_date_gmt' => $item->published,
				'post_content' => '',
				'post_title' => $item->title,
				'post_status' => 'publish',
				'ping_status' => 'closed',
				'post_name' => $item->slug,
				'tags_input' => property_exists($item, 'labels') ? $item->labels : '',
			);
			
			$author_id = '';
			
			if (!$skip_authors) {
				if ($item->author != 'Unknown') {
					$author_id = pipdig_blogger_process_author(sanitize_user($item->author_id), $item->author);
					$insert_post['post_author'] = $author_id;
				}
			}
			
			$post_id = wp_insert_post($insert_post);
			
			if (!is_wp_error($post_id) && $post_id) {
				
				// returns post content and also featured image ID
				$content = pipdig_blogger_process_content($post_id, $item->content, $item->published, $skip_images, $author_id);
				
				$update = array(
					'ID' => $post_id,
					'post_content' => $content['content'],
				);
				
				$post_id = wp_update_post($update);
				
				if (!is_wp_error($post_id) && $post_id) {
					
					$x++;
					
					if (!$skip_comments && isset($item->comments)) {
						pipdig_bloggger_process_comments($post_id, $blogger_blog_id, $item->id, $item->comments);
					}
					
					// set the featured image
					if (!empty($content['featured_image_id'])) {
						update_post_meta($post_id, '_thumbnail_id', $content['featured_image_id']);
					}
					
					// store redirect
					$row = array(
						'post_id' => $post_id,
						'blogger_permalink' => $item->permalink,
						'blogger_post_id' => sanitize_text_field($item->id),
					);
					$formats = array(
						'%d',
						'%s',
						'%s',
					);
					$wpdb->insert($wpdb->prefix.'bie_redirects', $row, $formats);
					
					/*
					$title = get_the_title($post_id);
					$latest_imported_title = html_entity_decode($title, ENT_QUOTES, 'UTF-8'); // convert chars like & https://stackoverflow.com/a/6684000
					$latest_imported_id = $post_id;
					*/
					
				} else {
					wp_delete_post($post_id, true);
				}
				
			}
			
		}
		
	}
	
	
	if (!empty($response->nextPageToken)) {
		$next_page_token = $response->nextPageToken;
		update_option('bie_page_token_'.$blogger_blog_id, $next_page_token, false);
	} else {
		$next_page_token = '';
		
		delete_option('bie_page_token_'.$blogger_blog_id);
		
		if (absint($_POST['skip_pages']) !== 1) {
			
			$query_args['query'] = 'pages'; // change our request from posts to pages, keep other args
			
			$response = pipdig_blogger_get_response($query_args);
			
			if (isset($response->items) && is_array($response->items)) {
				
				foreach ($response->items as $item) {
					
					$exists = (int) $wpdb->get_var( $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_value = %s AND meta_key = 'blogger_post_id'", $item->id) );
					
					if ($exists !== 0) {
						$x++;
						continue;
					}
					
					$insert_post = array(
						'post_type' => 'page',
						'post_date_gmt' => $item->published,
						'post_content' => '',
						'post_title' => $item->title,
						'post_status' => 'publish',
						'ping_status' => 'closed',
						'post_name' => $item->slug,
						'meta_input' => array(
							'blogger_post_id' => $item->id,
						),
					);
					
					$author_id = '';
			
					if (!$skip_authors) {
						if ($item->author != 'Unknown') {
							$author_id = pipdig_blogger_process_author(sanitize_user($item->author_id), $item->author);
							$insert_post['post_author'] = $author_id;
						}
					}
					
					$page_id = wp_insert_post($insert_post);
					
					if ($page_id) {
						
						// returns post content and also featured image ID
						$content = pipdig_blogger_process_content($page_id, $item->content, $item->published, $skip_images, $author_id);
						
						$update = array(
							'ID' => $page_id,
							'post_content' => $content['content'],
						);
		 
						$post_id = wp_update_post($update);
						
						if (is_wp_error($post_id)) {
							wp_delete_post($post_id, true);
						}
						
					}
					
				}
				
			}
			
		}
		
	}
	
	wp_suspend_cache_invalidation(false);
	wp_defer_term_counting(false);
	wp_defer_comment_counting(false);
	add_action('post_updated', 'wp_save_post_revision');
	remove_filter('intermediate_image_sizes_advanced', 'pipdig_blogger_skip_image_sizes'); // return image sizes to normal after
	
	$total_posts = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post'");
	
	$output = array(
		'next_page' => $next_page_token,
		'posts_imported' => $x,
		//'latest_imported_title' => !empty($latest_imported_title) ? strip_tags($latest_imported_title) : '',
		//'latest_imported_id' => !empty($latest_imported_id) ? strip_tags($latest_imported_id) : '',
		'total_posts' => $total_posts,
	);
	
	echo json_encode($output);
	
	wp_die();

});


// Delete progress if reset button clicked
add_action('wp_ajax_bie_delete_progress_ajax', function() {

	check_ajax_referer('bie_ajax_nonce', 'sec');
	
	if (!isset($_POST['blogger_blog_id'])) {
		wp_die();
	}
	
	$blogger_blog_id = sanitize_text_field($_POST['blogger_blog_id']);
	
	delete_option('bie_page_token_'.$blogger_blog_id);
	
	wp_die();
	
});


function pipdig_bloggger_process_comments($post_id, $blogger_blog_id, $blogger_post_id, $total_comments) {
	
	$query_args = array(
		'blog_id' => $blogger_blog_id,
		'query' => 'comments',
		'post_id' => $blogger_post_id,
	);
	
	$response = pipdig_blogger_get_response($query_args);
	
	$comments_with_parent = array();
	
	if (isset($response->items) && is_array($response->items)) {
		
		foreach ($response->items as $item) {
			
			$content = htmlspecialchars_decode($item->content);
			
			$comment_id = wp_insert_comment(array(
				'comment_post_ID' => $post_id,
				'comment_author' => !empty($item->author) ? esc_html($item->author) : 'Anonymous',
				'comment_date' => $item->published,
				'comment_content' => strip_tags($content, '<a><abbr><acronym><b><blockquote><br><cite><code><del><em><q><strike><strong><ul>'),
				'comment_meta' => array(
					'blogger_id' => $item->id
				),
			));
			
			// check if comment is a reply, then assign to parent comment
			if (isset($item->inReplyTo->id)) {
				$comments_with_parent[] = array(
					'wp_id' => $comment_id,
					'blogger_id' => $item->id,
					'parent_blogger_id' => $item->inReplyTo->id,
				);
			}
			
		}
		
	}
	
	// next page if supplied. Two sweeps of 500 comments should be enough?
	if (!empty($response->nextPageToken)) {
		
		$query_args['page_query'] = $response->nextPageToken; // request next page, keep other args
				
		$response = pipdig_blogger_get_response($query_args);
		
		if (isset($response->items) && is_array($response->items)) {
			
			$comments = array_reverse($response->items);
			
			foreach ($comments as $item) {
				
				$content = htmlspecialchars_decode($item->content);
				
				$comment_id = wp_insert_comment(array(
					'comment_post_ID' => $post_id,
					'comment_author' => !empty($item->author) ? esc_html($item->author) : 'Anonymous',
					'comment_date' => $item->published,
					'comment_content' => strip_tags($content, '<abbr><acronym><b><blockquote><br><cite><code><del><em><q><strike><strong><ul>'),
					'comment_meta' => array(
						'blogger_id' => $item->id
					),
				));
				
				// check if comment is a reply, then assign to parent comment
				if (isset($item->inReplyTo->id)) {
					$comments_with_parent[] = array(
						'wp_id' => $comment_id,
						'parent_blogger_id' => $item->inReplyTo->id,
					);
				}
				
			}
			
		}
		
	}
	
	// Now that the comments are imported, let's assign any parent-children
	if ($comments_with_parent) {
		foreach ($comments_with_parent as $comment) {
			
			$comment_query = new WP_Comment_Query();
			
			$comments = $comment_query->query(array(
				'meta_key' => 'blogger_id',
				'meta_value' => $comment['parent_blogger_id'],
			));
			
			if (isset($comments[0]->comment_ID)) {
				$parent_id = $comments[0]->comment_ID;
			} else {
				continue;
			}
			
			wp_update_comment(array(
				'comment_ID' => $comment['wp_id'],
				'comment_parent' => $parent_id,
			));
			
		}
	}
	
}

function pipdig_blogger_skip_image_sizes($sizes) {
	return array();
}

function pipdig_blogger_process_content($post_id, $content, $post_date, $skip_images, $author_id = '') {
	
	if (!$author_id) {
		$author_id = get_current_user_id();
	}
	
	$content = trim($content);
	
	// if string has spaces, it won't be base64
	if (strpos($content, ' ') !== false) {
		$content = htmlspecialchars_decode($content);
	} else {
		$content = base64_decode($content);
	}
	
	// download images to media library
	if (!$skip_images) {
		
		$featured_image_id = false;
		
		$images = array();
		
		preg_match_all('/<img [^>]*src="([^"]+blogspot\.com\/[^"]+)"[^>]*>/', $content, $found_images);
		if (!empty($found_images)) {
			foreach ($found_images[1] as $found_image) {
				$images[] = $found_image;
			}
		}
		
		preg_match_all('/<img [^>]*src="([^"]+googleusercontent\.com\/[^"]+)"[^>]*>/', $content, $found_images);
		if (!empty($found_images)) {
			foreach ($found_images[1] as $found_image) {
				$images[] = $found_image;
			}
		}
		
		if (!empty($images)) {
			
			$x = 0;
			
			foreach ($images as $found_image) {
				
				// skip if returns 404
				$headers = get_headers($found_image, 1);
				if (isset($headers[0]) && strpos($headers[0], '404') !== false) {
					continue;
				}
				
				$found_image_original = $found_image; // keep original for later, we need it for str_replace in content
				
				/*
				preg_match('/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $found_image, $matches);
				
				if (empty($matches[0])) {
					continue;
				}
				*/
				
				// check mime
				$file_size = getimagesize($found_image);
				
				$lets_go = false;
				
				if (!empty($file_size['mime'])) {
					
					$mime = strtolower($file_size['mime']);
					
					$allowed_types = ['jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp'];
					
					foreach ($allowed_types as $allowed_type) {
						
						if (strpos($mime, $allowed_type) !== false) {
							$file_ext = '.'.$allowed_type;
							$lets_go = true;
							break;
						}
						
					}
					
				}
				
				if (!$lets_go) {
					continue;
				}
				
				// urldecode twice to better rename imported Chinese characters. See support ticket #47809 for more info
				$name = urldecode(urldecode(wp_basename($found_image)));
				
				// Are Chinese characters in $name?
				if (preg_match("/\p{Han}+/u", $name)) {
					
					// Chinese chars found, so urldecode twice for better filename
					$found_image = urldecode(urldecode($found_image)); // needed to overcome long filenames.
					
				} else {
					// No Chinese characters found, so revert back to standard filename.
					// TODO - This might not be necessary if it is safe to double urldecode any filenames. Needs more testing to confirm.
					$name = wp_basename($found_image);
				}
				
				if (empty($name)) {
					continue;
				}
				
				// Some filesystems can't handle long filenames. So fallback to post slug.
				if (strlen($name) > 150) {
					
					// First 150 chars of post slug
					$short_slug = substr(get_post_field('post_name', $post_id), 0, 150);
					
					// add a dash on the end if slug is available
					if ($short_slug) {
						$short_slug = $short_slug.'-';
					}
					
					// Reconstruct as post slug, random number, file ext
					$name = $short_slug.rand().$file_ext;
				}
				
				$file = array(
					'name' => $name,
					'tmp_name' => download_url($found_image),
				);
				
				if (is_wp_error($file['tmp_name'])) {
					@unlink($file['tmp_name']);
					continue;
				}
				
				// remove extension if it's ther. E.g. rtim .jpeg first, then re-add it after. Needed in case there isn't an extension already set
				$filename = rtrim($file['name'], $file_ext).$file_ext;
				
				$image_id = media_handle_sideload($file, $post_id, $filename, array('post_date' => $post_date, 'post_author' => $author_id));
				
				if (!is_wp_error($image_id)) {
					
					$attachment = wp_get_attachment_image_src($image_id, 'large');
					
					if (empty($attachment[0])) {
						@unlink($file['tmp_name']);
						break;
					}
					
					// Add wp-image-$id class to img tag. Covers <img src="" and src=''
					$content = str_replace('src="'.$found_image_original.'"', 'src="'.$attachment[0].'" class="wp-image-'.absint($image_id).'"', $content);
					$content = str_replace("src='".$found_image_original."'", "src='".$attachment[0]."' class='wp-image-".absint($image_id)."'", $content);
					
					// Replace instances of just the url itself
					$content = str_replace($found_image_original, $attachment[0], $content);
					
					// if this is the first image, include it in the return as the featured_image_id
					if ($x === 0) {
						
						$featured_image_id = $image_id;
						
						if (!get_option('bie_license') || get_option('bie_license') == 'free') { // skip after first image
							@unlink($file['tmp_name']);
							break;
						}
						
					}
					
					$x++;
					
				}
				
				@unlink($file['tmp_name']);
				
			}
		}
	}
	
	// Add lazy load and srcset if supported
	/*
	if (function_exists('wp_filter_content_tags')) {
		$content = wp_filter_content_tags($content);
	}
	*/
	
	return array(
		'content' => $content,
		'featured_image_id' => $featured_image_id,
	);
	
}

function pipdig_blogger_process_author($username, $name) {
	
	// does user already exist?
	$user = get_user_by('login', $username);
	if ($user) {
		return $user->ID;
	}
	
	// Create new user
	$user_id = wp_insert_user(array(
		'user_login' => $username,
		'display_name' => $name,
		'nickname' => $name,
		'role' => 'author',
	));
	
	if (!is_wp_error($user_id)) {
		return $user_id;
	} else {
		// default to current user if error
		return get_current_user_id();
	}
	
}


function pipdig_blogger_get_response($query_args, $cb = false) {
	
	$default_args = array(
		'plugin_v' => BIE_VER,
		'home_url' => home_url(),
		'license' => !empty(get_option('bie_license')) ? get_option('bie_license') : 'free',
	);
	
	if ($cb) {
		$query_args['cb'] = time();
	}
	
	$query_args = wp_parse_args($query_args, $default_args);
	
	$url = add_query_arg($query_args, 'https://bie.pdg.center/');
	
	$body = wp_remote_retrieve_body(wp_remote_get($url, array('timeout' => 20)));
	
	if (!$body) {
		echo '<p>Error: Could not connect to Blogger. Please try again later.</p>';
		wp_die();
	}
	
	$response = json_decode($body);
	
	if (isset($response->message)) {
		echo '<p>'.strip_tags($response->message, '<a>').'</p>';
		wp_die();
	}
	
	if (isset($response->error->message)) {
		echo '<h3>Error message from Blogger: '.esc_html($response->error->message).'</p>';
		wp_die();
	}
	
	return $response;
	
}
