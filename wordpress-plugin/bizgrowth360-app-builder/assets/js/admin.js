/**
 * Bizgrowth360 App Builder Admin JavaScript
 *
 * @package Bizgrowth360\AppBuilder
 */

(function ($) {
	'use strict';

	var pollInterval = null;
	var pollSeconds = 0;
	var timerInterval = null;

	$(document).ready(function () {
		initTabs();
		initColorPickers();
		initLivePreview();
		initMediaSelectors();
		initTokenVisibility();
		initTestConnection();
		initBuildTrigger();
		initHistory();

		// Check initial history on page load
		loadHistory();
	});

	/**
	 * Tab Navigation
	 */
	function initTabs() {
		$('.bizgrowth360-nav-tabs a').on('click', function (e) {
			e.preventDefault();
			var target = $(this).attr('data-tab');

			$('.bizgrowth360-nav-tabs a').removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');

			$('.bizgrowth360-tab-content').removeClass('active');
			$('#' + target).addClass('active');

			if (target === 'tab-monitor') {
				loadHistory();
			}
		});
	}

	function switchTab(tabId) {
		$('.bizgrowth360-nav-tabs a[data-tab="' + tabId + '"]').trigger('click');
	}

	/**
	 * WordPress Color Pickers & Live Preview Sync
	 */
	function initColorPickers() {
		if ($.fn.wpColorPicker) {
			$('#bg360_primary_color').wpColorPicker({
				change: function (event, ui) {
					var color = ui.color.toString();
					$('#preview-header').css('background-color', color);
					$('#preview-mock-hero').css('background-color', color + '20');
					$('.bg360-mock-badge').css('background-color', color);
				}
			});

			$('#bg360_status_bar_color').wpColorPicker({
				change: function (event, ui) {
					$('#preview-statusbar').css('background-color', ui.color.toString());
				}
			});

			$('#bg360_navigation_bar_color').wpColorPicker({
				change: function (event, ui) {
					$('#preview-navbar').css('background-color', ui.color.toString());
				}
			});

			$('#bg360_background_color').wpColorPicker();
		}
	}

	/**
	 * Live Phone Preview Text & Icon Synchronization
	 */
	function initLivePreview() {
		$('#bg360_app_name').on('input', function () {
			var val = $(this).val() || 'Bizgrowth360 App';
			$('#preview-title').text(val);
			$('#preview-mock-hero h4').text(val);
		});

		$('#bg360_website_url').on('input', function () {
			var val = $(this).val() || 'https://example.com';
			$('#preview-url-text').text(val);
		});

		$('#bg360_app_icon').on('input change', function () {
			var val = $(this).val();
			if (val) {
				$('#preview-icon-wrapper').html('<img src="' + val + '" alt="Icon" />');
			}
		});
	}

	/**
	 * WordPress Media Frame for App Icon and Splash Screen
	 */
	function initMediaSelectors() {
		$('.bg360-upload-btn').on('click', function (e) {
			e.preventDefault();
			var targetInput = $($(this).data('target'));
			var isIcon = targetInput.attr('id') === 'bg360_app_icon';

			var frame = wp.media({
				title: isIcon ? bizgrowth360AppBuilder.strings.select_icon : bizgrowth360AppBuilder.strings.select_splash,
				button: { text: bizgrowth360AppBuilder.strings.use_image },
				multiple: false,
				library: { type: 'image' }
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				targetInput.val(attachment.url).trigger('change');
			});

			frame.open();
		});
	}

	/**
	 * GitHub Token Visibility Toggle
	 */
	function initTokenVisibility() {
		$('#bg360-toggle-token-visibility').on('click', function () {
			var input = $('#bg360_github_token');
			var type = input.attr('type') === 'password' ? 'text' : 'password';
			input.attr('type', type);
			$(this).find('.dashicons').toggleClass('dashicons-visibility dashicons-hidden');
		});
	}

	/**
	 * Notice Display
	 */
	function showNotice(message, type) {
		type = type || 'info'; // info, success, warning, error
		var notice = $(
			'<div class="notice notice-' + type + ' is-dismissible" style="margin-top:16px;">' +
			'<p>' + message + '</p>' +
			'<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss</span></button>' +
			'</div>'
		);
		$('#bg360-notice-container').empty().append(notice);
		notice.find('.notice-dismiss').on('click', function () {
			notice.fadeOut();
		});
	}

	/**
	 * Test GitHub Connection
	 */
	function initTestConnection() {
		$('#bg360-test-conn-btn').on('click', function () {
			var btn = $(this);
			var status = $('#bg360-test-conn-result');

			btn.prop('disabled', true);
			status.html('<span class="spinner is-active" style="float:none;margin:0 6px 0 0;"></span> ' + bizgrowth360AppBuilder.strings.testing_connection);

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_test_connection',
				nonce: bizgrowth360AppBuilder.nonce
			}).done(function (res) {
				btn.prop('disabled', false);
				if (res.success) {
					status.html('<span style="color:#059669;font-weight:600;"><span class="dashicons dashicons-yes"></span> ' + res.data.message + ' (' + res.data.repo_name + ')</span>');
				} else {
					status.html('<span style="color:#DC2626;font-weight:600;"><span class="dashicons dashicons-no"></span> ' + (res.data.message || bizgrowth360AppBuilder.strings.connection_failed) + '</span>');
				}
			}).fail(function () {
				btn.prop('disabled', false);
				status.html('<span style="color:#DC2626;font-weight:600;"><span class="dashicons dashicons-no"></span> Network error testing connection.</span>');
			});
		});
	}

	/**
	 * Build Trigger & Monitor
	 */
	function initBuildTrigger() {
		$('#bg360-trigger-build-btn, .bg360-trigger-action').on('click', function (e) {
			e.preventDefault();

			if (!confirm(bizgrowth360AppBuilder.strings.confirm_build)) {
				return;
			}

			triggerBuild();
		});
	}

	function triggerBuild() {
		switchTab('tab-monitor');

		// UI update to queued state
		updateBuildStatus('queued', 'Build Dispatched');
		$('#bg360-run-subtitle').text(bizgrowth360AppBuilder.strings.triggering_build);
		$('#bg360-progress-bar-wrap').show();
		$('#bg360-progress-bar').css('width', '15%');
		$('#bg360-run-meta').show();
		$('#bg360-downloads-zone').hide();
		resetStages();
		$('#stage-init').addClass('active');

		startTimer();

		var postData = {
			action: 'bizgrowth360_trigger_build',
			nonce: bizgrowth360AppBuilder.nonce,
			app_name: $('#bg360_app_name').val(),
			package_id: $('#bg360_package_id').val(),
			website_url: $('#bg360_website_url').val(),
			version_name: $('#bg360_version_name').val(),
			version_code: $('#bg360_version_code').val(),
			build_type: $('#bg360_build_type').val(),
			build_target: $('#bg360_build_target').val()
		};

		$.post(bizgrowth360AppBuilder.ajax_url, postData).done(function (res) {
			if (res.success) {
				showNotice(res.data.message, 'success');
				// Begin polling for run execution
				setTimeout(startPolling, 3000);
			} else {
				stopTimer();
				updateBuildStatus('failure', 'Dispatch Failed');
				showNotice(res.data.message || 'Build trigger failed.', 'error');
			}
		}).fail(function () {
			stopTimer();
			updateBuildStatus('failure', 'Network Error');
			showNotice('Network error dispatching build to GitHub.', 'error');
		});
	}

	function startTimer() {
		pollSeconds = 0;
		if (timerInterval) clearInterval(timerInterval);
		timerInterval = setInterval(function () {
			pollSeconds++;
			$('#bg360-meta-time').text(pollSeconds + 's');
		}, 1000);
	}

	function stopTimer() {
		if (timerInterval) {
			clearInterval(timerInterval);
			timerInterval = null;
		}
	}

	function resetStages() {
		$('.bg360-stage').removeClass('active completed');
	}

	function updateBuildStatus(status, label) {
		var badge = $('#bg360-run-badge');
		var globalBadge = $('#bg360-status-badge');

		badge.removeClass('bg360-badge-idle bg360-badge-queued bg360-badge-progress bg360-badge-success bg360-badge-failure');
		globalBadge.removeClass('bg360-badge-idle bg360-badge-queued bg360-badge-progress bg360-badge-success bg360-badge-failure');

		var badgeClass = 'bg360-badge-idle';
		if (status === 'queued') badgeClass = 'bg360-badge-queued';
		else if (status === 'in_progress') badgeClass = 'bg360-badge-progress';
		else if (status === 'success') badgeClass = 'bg360-badge-success';
		else if (status === 'failure') badgeClass = 'bg360-badge-failure';

		badge.addClass(badgeClass).html('<span class="bg360-dot"></span> ' + label);
		globalBadge.addClass(badgeClass).html('<span class="bg360-dot"></span> ' + label);
	}

	function startPolling() {
		if (pollInterval) clearInterval(pollInterval);
		checkStatus();
		pollInterval = setInterval(checkStatus, 6000);
	}

	function stopPolling() {
		if (pollInterval) {
			clearInterval(pollInterval);
			pollInterval = null;
		}
	}

	function checkStatus() {
		$.post(bizgrowth360AppBuilder.ajax_url, {
			action: 'bizgrowth360_check_status',
			nonce: bizgrowth360AppBuilder.nonce
		}).done(function (res) {
			if (!res.success || !res.data.run) {
				return;
			}

			var run = res.data.run;
			var artifacts = res.data.artifacts || [];

			$('#bg360-meta-run-id').text('#' + run.run_number + ' (' + run.id + ')');
			$('#bg360-meta-event').text(run.event);
			$('#bg360-meta-github-link').attr('href', run.html_url);

			if (run.status === 'queued') {
				updateBuildStatus('queued', 'Runner Queued');
				$('#bg360-progress-bar').css('width', '25%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('active');
			} else if (run.status === 'in_progress') {
				updateBuildStatus('in_progress', 'Building Android App');
				$('#bg360-run-subtitle').text(bizgrowth360AppBuilder.strings.build_in_progress);
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('completed');
				$('#stage-compile').addClass('active');
				$('#bg360-progress-bar').css('width', '65%');
			} else if (run.status === 'completed') {
				stopPolling();
				stopTimer();

				if (run.conclusion === 'success') {
					updateBuildStatus('success', 'Build Succeeded');
					$('#bg360-run-subtitle').text(bizgrowth360AppBuilder.strings.build_success);
					$('#bg360-progress-bar').css('width', '100%');
					$('.bg360-stage').removeClass('active').addClass('completed');

					// Render artifacts
					renderArtifacts(artifacts);
					loadHistory();
				} else {
					updateBuildStatus('failure', 'Build ' + (run.conclusion || 'Failed'));
					$('#bg360-run-subtitle').text(bizgrowth360AppBuilder.strings.build_failed);
					$('#stage-compile').removeClass('active');
					loadHistory();
				}
			}
		});
	}

	function renderArtifacts(artifacts) {
		var container = $('#bg360-artifact-cards');
		container.empty();

		if (!artifacts || artifacts.length === 0) {
			container.html('<p>Artifacts are being indexed by GitHub. Check back in a moment or view run on GitHub.</p>');
			$('#bg360-downloads-zone').show();
			return;
		}

		artifacts.forEach(function (art) {
			var isApk = art.name.toLowerCase().indexOf('apk') !== -1;
			var isAab = art.name.toLowerCase().indexOf('aab') !== -1;
			var typeClass = isApk ? 'apk' : (isAab ? 'aab' : 'zip');
			var typeLabel = isApk ? 'APK' : (isAab ? 'AAB' : 'FILE');

			var downloadLink = bizgrowth360AppBuilder.download_base_url +
				'&artifact_id=' + encodeURIComponent(art.id) +
				'&_wpnonce=' + encodeURIComponent(bizgrowth360AppBuilder.download_nonce);

			var card = $(
				'<div class="bg360-artifact-card">' +
				'  <div class="bg360-artifact-info">' +
				'    <div class="bg360-artifact-icon ' + typeClass + '">' + typeLabel + '</div>' +
				'    <div>' +
				'      <h5 class="bg360-artifact-title">' + art.name + '</h5>' +
				'      <p class="bg360-artifact-size">' + art.size_mb + ' • ' + (isApk ? 'Direct Android Install' : 'Google Play Console') + '</p>' +
				'    </div>' +
				'  </div>' +
				'  <a href="' + downloadLink + '" class="button button-primary bg360-download-btn">' +
				'    <span class="dashicons dashicons-download"></span> Download' +
				'  </a>' +
				'</div>'
			);
			container.append(card);
		});

		$('#bg360-downloads-zone').slideDown();
	}

	/**
	 * Recent Build History
	 */
	function initHistory() {
		$('#bg360-refresh-history-btn').on('click', function () {
			loadHistory();
		});
	}

	function loadHistory() {
		var tbody = $('#bg360-history-body');
		tbody.html('<tr><td colspan="7" class="text-center"><span class="spinner is-active" style="float:none;"></span> Loading history...</td></tr>');

		$.post(bizgrowth360AppBuilder.ajax_url, {
			action: 'bizgrowth360_get_history',
			nonce: bizgrowth360AppBuilder.nonce
		}).done(function (res) {
			if (!res.success || !res.data.runs || res.data.runs.length === 0) {
				tbody.html('<tr><td colspan="7" class="text-center">No build records found yet.</td></tr>');
				return;
			}

			tbody.empty();
			res.data.runs.forEach(function (run) {
				var statusClass = 'bg360-badge-idle';
				if (run.conclusion === 'success') statusClass = 'bg360-badge-success';
				else if (run.conclusion === 'failure') statusClass = 'bg360-badge-failure';
				else if (run.status === 'in_progress') statusClass = 'bg360-badge-progress';

				var row = $(
					'<tr>' +
					'  <td><strong>#' + run.run_number + '</strong></td>' +
					'  <td>' + run.name + '</td>' +
					'  <td><code>' + run.event + '</code></td>' +
					'  <td>' + run.status + '</td>' +
					'  <td><span class="bg360-badge ' + statusClass + '">' + (run.conclusion || run.status) + '</span></td>' +
					'  <td>' + new Date(run.created_at).toLocaleString() + '</td>' +
					'  <td>' +
					'    <a href="' + run.html_url + '" target="_blank" rel="noopener noreferrer" class="button button-small">' +
					'      <span class="dashicons dashicons-external" style="vertical-align:middle;font-size:14px;"></span> Logs' +
					'    </a>' +
					'  </td>' +
					'</tr>'
				);
				tbody.append(row);
			});
		}).fail(function () {
			tbody.html('<tr><td colspan="7" class="text-center text-error">Failed loading build history from GitHub.</td></tr>');
		});
	}

})(jQuery);
