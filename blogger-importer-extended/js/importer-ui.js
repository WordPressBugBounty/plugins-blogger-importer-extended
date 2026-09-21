jQuery(document).ready(function ($) {
	'use strict';

	var BOOT = window.BIE_BOOT || {};

	var $message = $('#pipdigBloggerImpoterMsg1');
	var $counts = $('#bieCounts');
	var $warnings = $('#bieWarnings');
	var $blogIdField = $('#BlogggerBlogIdField');
	var $licenseField = $('#bieLicenseField');

	var importer = null;

	function img(name) {
		return '<img src="' + BOOT.imgPath + name + '" alt="" style="width: 150px;" />';
	}

	function post(action, data) {
		return $.post(BOOT.ajaxUrl, $.extend({ action: action, sec: BOOT.ajaxNonce }, data || {}));
	}


	$('#pipdigBloggerImporter').on('click', '#bieFreeBtn', function () {
		$('#bieLicenseChoices').slideUp(300);
		$('#blogIdStep').slideDown(300);
		post('bie_free_license_ajax');
	});

	$('#pipdigBloggerImporter').on('click', '#bieProBtn', function () {
		$('#bieLicenseChoices').slideUp(300);
		$('#blogLicenseStep').slideDown(300);
	});

	function checkLicense() {
		if (!$licenseField.val()) { return; }

		$('#bieCheckingLicenseResult').text('');
		$('#bieCheckingLicense').show();

		post('bie_check_license_ajax', { bie_license: $licenseField.val() }).done(function (response) {
			$('#bieCheckingLicense').hide();

			if (response == 1) {
				$('#blogLicenseStep').slideUp(300);
				$('#blogIdStep').slideDown(300);
				$blogIdField.focus();
			} else if (response == 2) {
				$('#bieCheckingLicenseResult').html('This license has expired. Would you like to <a href="https://go.pipdig.co/open.php?id=bie-pro" target="_blank" rel="noopener">purchase a new one</a>?');
			} else if (response == 3) {
				$('#bieCheckingLicenseResult').html('This license does not exist. Please check your email receipt for the license key or <a href="' + BOOT.restart + '">click here</a> to restart the import process.');
			}
		});
	}

	$('#pipdigBloggerImporter').on('click', '#bieLicenseSubmit', checkLicense);

	$licenseField.on('keyup', function (e) {
		if (e.keyCode === 13) { checkLicense(); }
	});

	function lookUpBlog() {
		if (!$blogIdField.val()) {
			$message.html('Please enter your Blogger blog ID in the field above.');
			return;
		}

		var $button = $('#submitBlogId');
		$button.prop('disabled', true).removeClass('button-primary');
		$('.fade_out').addClass('pipdig_hide');
		$message.html('<h2><span class="dashicons dashicons-update spin"></span> Loading...</h2>');

		post('bie_get_blog_ajax', { blogger_blog_id: $blogIdField.val() }).done(function (response) {
			$message.html(response);
			$button.prop('disabled', false);
			$('.fade_out').removeClass('pipdig_hide');
		});
	}

	$('#submitBlogId').on('click', lookUpBlog);

	$blogIdField.on('keyup', function (e) {
		if (/\D/g.test(this.value)) {
			this.value = this.value.replace(/\D/g, '');
		}
		if (e.keyCode === 13) { lookUpBlog(); }
	});

	$('#pipdigBloggerImporter').on('change', '#skipPages', function () {
		if (this.checked) { $('#andPages').fadeOut(); } else { $('#andPages').fadeIn(); }
	});


	function readOptions() {
		return {
			skipComments: $('#skipComments').prop('checked'),
			skipPages: $('#skipPages').prop('checked'),
			skipImages: $('#skipImages').prop('checked'),
			skipAuthors: $('#skipAuthors').prop('checked'),
			convertFormatting: $('#convertFormatting').prop('checked')
		};
	}

	var ui = {
		started: function () {
			$('#pipdigBloggerClose').hide();
			$('.fade_out').slideUp(550);
			$message.css('margin-top', 0).html(
				img('moving.svg') +
				'<h2><span class="dashicons dashicons-update spin"></span> Importing, please wait...</h2>' +
				'<p>Please <strong>keep this window open</strong>.</p>' +
				'<div style="margin-top: 25px"><button type="button" class="button" id="bieStopImport">' +
				'<span class="dashicons dashicons-no"></span> Stop the import</button></div>'
			);
			$counts.show();
		},

		progress: function (counts) {
			$counts.html(
				'<strong>' + counts.posts + '</strong> posts &nbsp; ' +
				'<strong>' + counts.pages + '</strong> pages &nbsp; ' +
				'<strong>' + counts.comments + '</strong> comments &nbsp; ' +
				'<strong>' + counts.images + '</strong> images' +
				(counts.skipped ? ' &nbsp; <em>(' + counts.skipped + ' already imported)</em>' : '')
			);
		},

		warnings: function (list) {
			if (!list.length) { return; }
			var items = list.slice(-200).map(function (w) {
				return '<li><code>' + w.code + '</code> ' + $('<div>').text(w.message).html() +
					(w.context ? ' <em>' + $('<div>').text(w.context).html() + '</em>' : '') + '</li>';
			}).join('');
			$warnings.show().html(
				'<details><summary>' + list.length + ' notice' + (list.length === 1 ? '' : 's') +
				' (click to view)</summary><ul>' + items + '</ul></details>'
			);
		},

		done: function (counts, warnings) {
			window.onbeforeunload = null;
			document.title = 'Import Complete';
			$message.html(
				img('success.svg') +
				'<h2>Success!</h2>' +
				'<p>Imported <strong>' + counts.posts + '</strong> posts, <strong>' + counts.pages +
				'</strong> pages, <strong>' + counts.comments + '</strong> comments and <strong>' +
				counts.images + '</strong> images.</p>' +
				(warnings.length ? '<p>' + warnings.length + ' item(s) were skipped — see below.</p>' : '') +
				'<p>What now? Don\'t forget to set up the <a href="' + BOOT.settings + '">remaining steps</a>.</p>' +
				'<p style="margin-top: 20px"><a class="button" href="' + BOOT.settings + '">Return to dashboard</a></p>'
			);
		},

		stopped: function (counts) {
			window.onbeforeunload = null;
			document.title = 'Import Stopped';
			$message.html(
				img('broken.svg') +
				'<h2>Import stopped</h2>' +
				'<p>The import was stopped before it finished. Imported so far: <strong>' + counts.posts +
				'</strong> posts, <strong>' + counts.pages + '</strong> pages, <strong>' + counts.comments +
				'</strong> comments and <strong>' + counts.images + '</strong> images.</p>' +
				'<p>You can resume at any time. Anything already imported will be skipped.</p>' +
				'<p style="margin-top: 20px"><a class="button-primary" href="' + BOOT.restart +
				'&bid=' + encodeURIComponent($blogIdField.val()) + '">Resume import</a> &nbsp;' +
				'<a class="button" href="' + BOOT.settings + '">Return to dashboard</a></p>'
			);
		},

		failed: function (err, counts, warnings) {
			window.onbeforeunload = null;

			if (err.code === 'bie_license_limit') {
				$message.html(
					img('battery_low.svg') +
					'<p>' + err.message + '</p>' +
					'<p style="margin-top: 20px"><a class="button" href="' + BOOT.settings + '">Return to dashboard</a></p>'
				);
				return;
			}

			$message.html(
				img('broken.svg') +
				'<h2>Import paused</h2>' +
				'<p>' + $('<div>').text(err.message || 'Something went wrong.').html() + '</p>' +
				'<p>No progress was lost. Imported so far: <strong>' + counts.posts + '</strong> posts, <strong>' +
				counts.images + '</strong> images.</p>' +
				'<p style="margin-top: 20px"><a class="button-primary" href="' + BOOT.restart +
				'&bid=' + encodeURIComponent($blogIdField.val()) + '">Resume import</a></p>'
			);
		}
	};

	function startImport() {
		var options = readOptions();

		window.onbeforeunload = function () { return true; };

		importer = new window.BIEImporter({
			blogId: $blogIdField.val(),
			options: options,
			blogspotDomain: $('#startImport').data('blogspot-domain') || '',
			direct: String($('#startImport').data('direct')) === '1',
			ui: ui
		});

		importer.run();
	}

	$('#pipdigBloggerImporter').on('click', '#startImport', function () {
		if (!confirm('Are you sure you want to import this blog?')) { return; }
		startImport();
	});

	$('#pipdigBloggerImporter').on('click', '#restartImport', function () {
		post('bie_delete_progress_ajax', { blogger_blog_id: $(this).data('blog-id') }).done(function () {
			startImport();
		});
	});

	$('#pipdigBloggerImporter').on('click', '#bieStopImport', function () {
		if (importer) { importer.stop(); }
		window.onbeforeunload = null;
		$(this).prop('disabled', true).text('Stopping...');
	});

	if (window.BIE_AUTOSTART) {
		$blogIdField.val(window.BIE_AUTOSTART);
		lookUpBlog();
	}

});
