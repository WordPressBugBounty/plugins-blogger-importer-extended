<?php if (!defined('ABSPATH')) die;

// Add menu item under Tools
add_action('admin_menu', function() {
	add_submenu_page('options-general.php', 'Blogger Importer', 'Blogger Importer', 'manage_options', 'bie-settings', 'bie_settings_page_render');
}, 999999);


// Highlight the permalink setting to choose
add_action('admin_footer-options-permalink.php', function() {
	if (get_option('permalink_structure')) {
		return;
	}
	if (!isset($_GET['bie-highlight-permalink'])) {
		return;
	}
	?>
	<script>
	jQuery('input[value="/%postname%/"]').closest('tr').addClass('highlight');
	</script>
	<?php
}, 999999);


function bie_settings_page_render() {
	?>
	<style>
	.notice {
		display: none !important;
	}
	#bieNoticeOk {
		display: block !important;
		/* max-width: 691px; */
	}
	.card {
		max-width: 720px;
	}
	.description {
		font-size: 90% !important;
	}
	
	.switch {
		position: relative;
		display: inline-block;
		width: 60px;
		height: 34px;
	}

	.switch input {display:none;}

	.slider_checkbox {
		position: absolute;
		cursor: pointer;
		top: 0;
		left: 0;
		right: 0;
		bottom: 0;
		background-color: #ccc;
		-webkit-transition: .4s;
		transition: .4s;
		border-radius: 34px;
	}

	.slider_checkbox:before {
		position: absolute;
		content: "";
		height: 26px;
		width: 26px;
		left: 4px;
		bottom: 4px;
		background-color: white;
		-webkit-transition: .4s;
		transition: .4s;
		border-radius: 50%;
	}

	input:checked + .slider_checkbox {
		background-color: #0085BA;
	}

	input:focus + .slider_checkbox {
		box-shadow: 0 0 1px #0085BA;
	}

	input:checked + .slider_checkbox:before {
		-webkit-transform: translateX(26px);
		-ms-transform: translateX(26px);
		transform: translateX(26px);
	}
	
	#wpfooter {
		display: none;
	}
	</style>
	<div class="wrap">
		<h1 style="display:none"></h1>
		<div class="card">
		
			<h2>1. Import published posts</h2>
			
			<p>Ready to import content from Blogger? Click the button below to get started:</p>
			<?php
			$check_resp = wp_remote_retrieve_body(wp_remote_get('https://'.BIE_DOMAIN.'/check_connection.php', array('timeout' => 10)));
			if ($check_resp != 1) {
			?>
				<p><span class="dashicons dashicons-warning"></span> Hmmmm we can't connect to the import system. This might just be temporary, so please try again later.</p>
				<p>This error usually occurs if your host has disabled "cURL" on the server. It can also be caused by an expired/invalid SSL certificate on your server. Please contact your host for assistance with this.</p>
				<p>If you are still experiencing the same issue and your host is unable to help, please contact support@pipdig.zendesk.com.</p>
				<p>Error details:</p>
				<?php
				echo '<pre>';
					print_r(esc_html($check_resp));
				echo '<pre>';
				?>
			<?php } else { ?>
				<p><a href="<?php echo admin_url('tools.php?page=bie-importer'); ?>" class="button" target="_blank">Run importer</a></p>
				<p>After the import is finished, you can return to this page to complete any remaining steps.</p>
			<?php } ?>
		</div>
		
		<div class="card" id="redirectsCard">
			
			<h2>2. Redirect old links</h2>
			
			<p>Blogger uses a different url/link structure compared to WordPress. The options below will make sure any old links redirect to the correct place.</p>
			
			<?php if (get_option('permalink_structure')) { ?>
				<p>We recommend enabling both options if you are not using any other redirection plugins/methods. If you're not sure what that means, it should be safe to enable both options anyway.</p>
			<?php } else { ?>
				<p><span class="dashicons dashicons-warning"></span> This site is not currently using "pretty permalinks". The redirection options <strong>will not work</strong>.<br />Please go to <a href="<?php echo admin_url('options-permalink.php?bie-highlight-permalink=1'); ?>">this page</a> and select the "Post name" option <a href="<?php echo BIE_PATH; ?>img/pretty_permalinks.png" target="_blank">shown here</a>.</p>
				<style>
				#blogger_redirects_settings {
					pointer-events: none;
					opacity: .25;
				}
				</style>
			<?php } ?>
			<form method="post" action="options.php" id="blogger_redirects_settings">
				<?php
				settings_fields('bie_redirects_section');
				do_settings_sections('bie_settings');
				submit_button();
				?>
			</form>
		
		</div>
		
		<div class="card">
			
			<h2>3. Redirect traffic from Blogger</h2>
			
			<p>After transferring to WordPress, the last step is to install a new template on your old Blogger blog. This will redirect all your old blogspot links to this new site. <a href="https://go.pipdig.co/open.php?id=bie-blogger-template" target="_blank" rel="noopener">Click here</a> for instructions on how to install the template.</p>
			
			<p style="margin-top: 25px;"><span class="button" id="downloadTemplateBtn">Download Template</span></p>
			
<p><textarea style="width: 100%; height: 100px; margin-top: 10px; display: none;" id="templateContent" class="code" readonly>
<?php echo htmlspecialchars('<?xml version="1.0" encoding="UTF-8" ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html expr:dir=\'data:blog.languageDirection\' lang=\'en\' xml:lang=\'en\' xmlns=\'http://www.w3.org/1999/xhtml\' xmlns:b=\'http://www.google.com/2005/gml/b\' xmlns:data=\'http://www.google.com/2005/gml/data\' xmlns:expr=\'http://www.google.com/2005/gml/expr\' xmlns:fb=\'http://ogp.me/ns/fb#\' xmlns:og=\'http://ogp.me/ns#\'>
<head>
<b:if cond=\'data:blog.pageType == "index"\'>
<link rel=\'canonical\' href=\''.home_url('/').'\' />
<meta content=\'0;url='.home_url('/').'\' http-equiv=\'refresh\'/>
<script>
//<![CDATA[
window.location.href = "'.home_url('/').'";
//]]>
</script>
<b:else/>
<link rel=\'canonical\' expr:href=\'"'.home_url('/').'" + data:blog.url\' />
<meta expr:content=\'"0;url='.home_url('/').'" + data:blog.url\' http-equiv=\'refresh\'/>
<script>
//<![CDATA[
window.location.href = "'.home_url().'" + window.location.pathname;
//]]>
</script>
</b:if>
<b:skin><![CDATA[/*
-----------------------------------------------
Name: Blogger Redirect
Designer: pipdig
URL: https://www.pipdig.co
----------------------------------------------- */
/*
]]></b:skin>
</head>
<body>
<b:section id=\'header\' showaddelement=\'no\'>
<b:widget id=\'Header1\' locked=\'true\' title=\'techxt (Header)\' type=\'Header\'/>
</b:section>
</body>
</html>');?></textarea></p>
		</div>
		
		<div class="card">
			<p>Have you found this <?php if (!get_option('bie_license') || get_option('bie_license') == 'free') echo 'free '; ?>plugin useful? Please consider <a href="https://go.pipdig.co/open.php?id=bie-rev" target="_blank" rel="noopener" style="text-decoration:none">leaving a &#9733;&#9733;&#9733;&#9733;&#9733; review</a>.</p>
			<p>Not happy with the plugin? Please contact support@pipdig.zendesk.com and we may be able to help.</p>
		</div>
		
		<?php if (!function_exists('is_cart')) { // don't want to risk ecommerce sites losing data from misunderstanding the reset function ?>
			<p id="resetBieStatus" style="margin-top: 30px;"><span style="font-size: 10px;">Need to reset this site's content and start a fresh import? <a href="#" id="resetBieBtn">Click here</a></span></p>
		<?php } ?>
		
	</div>
	
	<script>
	jQuery(document).ready(function($) {
		
		$("#downloadTemplateBtn").click(function(e) {
			
			e.preventDefault();
			
			var textToWrite = $("#templateContent").val();
			var textFileAsBlob = new Blob([textToWrite], {type: 'Application/xml'});
			var fileNameToSaveAs = "blogger-redirect-template.xml";
			var downloadLink = document.createElement("a");
			downloadLink.download = fileNameToSaveAs;
			downloadLink.innerHTML = "link text";
			window.URL = window.URL || window.webkitURL;
			downloadLink.href = window.URL.createObjectURL(textFileAsBlob);
			downloadLink.style.display = "none";
			document.body.appendChild(downloadLink);
			downloadLink.click();
			
		});
		
		$("#resetBieBtn").click(function(e) {
			
			e.preventDefault();
			
			if (!confirm("Please note that this will delete ALL posts from WordPress. This includes things like blog posts, pages and custom post types from other plugins. Are you sure?")) {
				return;
			}
			
			if (!confirm("Seriously, it will delete ALL the posts and pages from this WordPress site. Are you sure?")) {
				return;
			}
			
			var data = {
				'action': 'bie_reset',
				'sec': '<?php echo wp_create_nonce('bie_reset_nonce'); ?>',
			};
			
			$.post(ajaxurl, data, function(response) {
				if (response == 1) {
					$('#resetBieStatus').html('Reset complete! You can now <a href="<?php echo admin_url('tools.php?page=bie-importer'); ?>">start a new import</a>.');
				} else {
					$('#resetBieStatus').text('Reset failed! Please reload this page to try again.');
				}
			});
			
		});
		
	});
	</script>
	
	<?php
}


add_action('admin_init', function() {
	
	add_settings_section('bie_redirects_section', '', null, 'bie_settings');
	
	// Enable redirects
	add_settings_field('enabled_redirects', 'Redirect old Blogger links', 'bie_settings_field_enable_redirects', 'bie_settings', 'bie_redirects_section');
	
	// 404 to homepage
	add_settings_field('redirect_404s', 'Redirect 404s to homepage', 'bie_settings_field_redirect_404s', 'bie_settings', 'bie_redirects_section');
	
	register_setting('bie_redirects_section', 'bie_settings');
	
});


function bie_settings_field_enable_redirects() {
	$value = 0;
	$options = get_option('bie_settings');
	if (isset($options['enabled_redirects'])) {
		$value = absint($options['enabled_redirects']);
	}
	$postname = 'blog-post-title';
	$year = date('Y');
	$month = date('m');
	$permalink_structure = get_option('permalink_structure');
	if ($permalink_structure) {
		$permalink = str_replace('%postname%', $postname, $permalink_structure);
		$permalink = str_replace('%year%', $year, $permalink);
		$permalink = str_replace('%monthnum%', $month, $permalink);
	}
	$show_example = false;
	if ($permalink_structure && ($permalink_structure == '/%postname%/' || $permalink_structure == '/%year%/%monthnum%/%postname%/')) {
		$show_example = true;
	}
	?>
	<label class="switch">
		<input type="checkbox" id="enabled_redirects" name="bie_settings[enabled_redirects]" value="1" <?php checked(1, $value, true); ?>>
		<span class="slider_checkbox"></span>
	</label>
	<p class="description">Any old Blogger post, page, label or RSS feed links will be 301 redirected, keeping any SEO value.<?php if ($show_example) { ?> For example:</p>
	<p class="description"><?php echo home_url().'/'.$year.'/'.$month.'/'.$postname.'.html'; ?><br />
	will redirect to<br />
	<?php echo home_url().$permalink; ?></p><?php } ?>
	<?php
}


function bie_settings_field_redirect_404s() {
	$value = 0;
	$options = get_option('bie_settings');
	if (isset($options['redirect_404s'])) {
		$value = absint($options['redirect_404s']);
	}
	?>
	<label class="switch">
		<input type="checkbox" id="redirect_404s" name="bie_settings[redirect_404s]" value="1" <?php checked(1, $value, true); ?>>
		<span class="slider_checkbox"></span>
	</label>
	<p class="description">If a post/page can't be found, it will be 301 redirected to the homepage instead of showing the normal 404 error page.</p>
	<?php
}



add_action('wp_ajax_bie_reset', function() {

	check_ajax_referer('bie_reset_nonce', 'sec');
	
	if (!is_super_admin()) {
		die;
	}
	
	global $wpdb;
	
	$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix."posts");
	$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix."postmeta");
	$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix."comments");
	$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix."commentmeta");
	$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix."term_relationships");
	$wpdb->query("TRUNCATE TABLE ".$wpdb->prefix."bie_redirects");
	
	$results = $wpdb->get_results("SELECT option_name FROM $wpdb->options WHERE option_name LIKE 'bie_page_token_%'");
	foreach ($results as $result) {
		delete_option($result->option_name);
	}
	
	wp_cache_flush();
	
	echo 1;
	
	die;
});