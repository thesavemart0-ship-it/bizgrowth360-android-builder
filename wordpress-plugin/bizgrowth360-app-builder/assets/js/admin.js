/**
 * Bizgrowth360 App Builder Admin JavaScript
 *
 * Phase 4: Full GitHub Actions Build Engine Integration
 *
 * @package Bizgrowth360\AppBuilder
 */

(function ($) {
	'use strict';

	var pollInterval = null;
	var pollSeconds = 0;
	var timerInterval = null;
	var activeBuildId = null;
	var pendingBuildTarget = 'both';
	var currentPage = 1;
	var totalPages = 1;

	$(document).ready(function () {
		initTabs();
		initColorPickers();
		initLivePreview();
		initMediaSelectors();
		initModals();
		initBuildButtons();
		initBuildCenter();

		// Initial check on load
		loadActiveBuildOrHistory();
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
				loadHistory(1);
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
	 * WordPress Media Frame
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
	 * Modals Management
	 */
	function initModals() {
		// Close modal button handlers
		$(document).on('click', '.bg360-modal-close, [data-close]', function (e) {
			e.preventDefault();
			var target = $(this).data('close') || $(this).closest('.bg360-modal-overlay');
			$(target).fadeOut(150);
		});

		// Close modal on escape key
		$(document).on('keydown', function (e) {
			if (e.key === 'Escape') {
				$('.bg360-modal-overlay:visible').fadeOut(150);
			}
		});

		// Copy logs button
		$('#bg360-copy-logs-btn').on('click', function () {
			var text = $('#bg360-logs-content').text();
			navigator.clipboard.writeText(text).then(function () {
				var btn = $('#bg360-copy-logs-btn');
				btn.text('Copied!');
				setTimeout(function () {
					btn.html('<span class="dashicons dashicons-admin-page"></span> ' + 'Copy Logs');
				}, 2000);
			});
		});
	}

	/**
	 * Notice Display
	 */
	function showNotice(message, type) {
		type = type || 'info';
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
	 * Build Buttons & Pre-Build Confirmation Modal
	 */
	function initBuildButtons() {
		$('.bg360-open-confirm-btn').on('click', function (e) {
			e.preventDefault();

			if (!bizgrowth360AppBuilder.has_token) {
				showNotice(bizgrowth360AppBuilder.strings.service_not_configured, 'warning');
				switchTab('tab-monitor');
				return;
			}

			pendingBuildTarget = $(this).data('build-target') || 'both';

			// Validate inputs before showing modal
			var postData = collectFormData();
			postData.action = 'bizgrowth360_validate_build';
			postData.build_type = pendingBuildTarget;

			$('#modal-summary-error-wrap').hide();

			$.post(bizgrowth360AppBuilder.ajax_url, postData).done(function (res) {
				if (res.success && res.data.valid) {
					var s = res.data.summary;
					$('#modal-summary-app-name strong').text(s.app_name);
					$('#modal-summary-package-id code').text(s.package_id);
					$('#modal-summary-url-link').attr('href', s.website_url).text(s.website_url);
					$('#modal-summary-version').text(s.version_name + ' (Build: ' + s.version_code + ')');
					$('#modal-summary-target strong').text(formatBuildTarget(pendingBuildTarget));
					$('#modal-summary-variant').text(s.build_variant.toUpperCase());
					$('#modal-summary-orientation').text(s.orientation);
					$('#modal-summary-color-box').css('background-color', s.primary_color);
					$('#modal-summary-color').text(s.primary_color);
					$('#modal-summary-icon').text(s.icon_url ? 'Custom Icon Provided' : 'Default Auto-Generated Vector');

					$('#bg360-confirm-modal').fadeIn(150);
				} else {
					var msg = res.data && res.data.message ? res.data.message : 'Please check your configuration values.';
					showNotice(msg, 'error');
				}
			}).fail(function () {
				showNotice('Error validating app configuration.', 'error');
			});
		});

		// Confirm & Start Dispatch
		$('#bg360-confirm-start-btn').on('click', function (e) {
			e.preventDefault();
			$('#bg360-confirm-modal').fadeOut(150);
			startBuild(pendingBuildTarget);
		});

		// Cancel Active Build
		$('#bg360-cancel-build-btn').on('click', function (e) {
			e.preventDefault();
			if (!activeBuildId) return;

			if (!confirm(bizgrowth360AppBuilder.strings.confirm_cancel)) {
				return;
			}

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_cancel_build',
				nonce: bizgrowth360AppBuilder.nonce,
				build_id: activeBuildId
			}).done(function (res) {
				if (res.success) {
					showNotice(res.data.message, 'info');
					stopPolling();
					stopTimer();
					updateBuildStatus('cancelled', 'Build Cancelled');
					$('#bg360-cancel-build-btn').hide();
					loadHistory(1);
				} else {
					showNotice(res.data.message || 'Could not cancel build.', 'error');
				}
			});
		});
	}

	function collectFormData() {
		return {
			nonce: bizgrowth360AppBuilder.nonce,
			app_name: $('#bg360_app_name').val(),
			package_id: $('#bg360_package_id').val(),
			website_url: $('#bg360_website_url').val(),
			version_name: $('#bg360_version_name').val(),
			version_code: $('#bg360_version_code').val(),
			build_target: $('#bg360_build_target').val(),
			build_type: pendingBuildTarget || $('#bg360_build_target').val(),
			build_variant: $('#bg360_build_type').val(),
			orientation: $('#bg360_orientation').val(),
			primary_color: $('#bg360_primary_color').val(),
			background_color: $('#bg360_background_color').val(),
			status_bar_color: $('#bg360_status_bar_color').val(),
			navigation_bar_color: $('#bg360_navigation_bar_color').val(),
			icon_url: $('#bg360_app_icon').val(),
			splash_url: $('#bg360_splash_image').val(),
			allow_external_links: $('#bg360_allow_external_links').is(':checked') ? 1 : 0
		};
	}

	function formatBuildTarget(target) {
		if (target === 'apk') return 'APK Only (Direct Install)';
		if (target === 'aab') return 'AAB Only (Play Store)';
		return 'Both APK & AAB Packages';
	}

	/**
	 * Trigger Build
	 */
	function startBuild(target) {
		switchTab('tab-monitor');

		updateBuildStatus('dispatching', 'Dispatching Build');
		$('#bg360-run-subtitle').text(bizgrowth360AppBuilder.strings.triggering_build);
		$('#bg360-progress-bar-wrap').show();
		$('#bg360-progress-bar').css('width', '10%');
		$('#bg360-run-meta').show();
		$('#bg360-downloads-zone').hide();
		$('#bg360-cancel-build-btn').show();
		resetStages();
		$('#stage-init').addClass('active');

		startTimer();

		var saveDesignFirst = Promise.resolve(true);
		if (window.bg360DesignStudio && typeof window.bg360DesignStudio.save === 'function') {
			saveDesignFirst = window.bg360DesignStudio.save();
		}

		saveDesignFirst.then(function (saved) {
			if (saved === false) {
				stopTimer();
				updateBuildStatus('failed', 'Design Not Saved');
				$('#bg360-cancel-build-btn').hide();
				showNotice('Design changes could not be saved. Please retry before starting the build.', 'error');
				return;
			}

			var postData = collectFormData();
			postData.action = 'bizgrowth360_start_build';
			postData.build_type = target;

			$.post(bizgrowth360AppBuilder.ajax_url, postData).done(function (res) {
				if (res.success) {
					activeBuildId = res.data.build_id;
					$('#bg360-meta-run-id').text(activeBuildId);
					$('#bg360-meta-event').text(target.toUpperCase());
					showNotice(res.data.message, 'success');
					setTimeout(startPolling, 3000);
				} else {
					stopTimer();
					updateBuildStatus('failed', 'Dispatch Failed');
					$('#bg360-cancel-build-btn').hide();
					showNotice(res.data.message || 'Build trigger failed.', 'error');
				}
			}).fail(function () {
				stopTimer();
				updateBuildStatus('failed', 'Network Error');
				$('#bg360-cancel-build-btn').hide();
				showNotice('Network error dispatching build request.', 'error');
			});
		});
	}

	function startTimer(initialSeconds) {
		pollSeconds = initialSeconds || 0;
		if (timerInterval) clearInterval(timerInterval);
		timerInterval = setInterval(function () {
			pollSeconds++;
			var mins = Math.floor(pollSeconds / 60);
			var secs = pollSeconds % 60;
			$('#bg360-meta-time').text((mins > 0 ? mins + 'm ' : '') + secs + 's');
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
		if (status === 'queued' || status === 'dispatching') badgeClass = 'bg360-badge-queued';
		else if (status === 'in_progress' || status === 'starting' || status === 'preparing' || status === 'building' || status === 'signing') badgeClass = 'bg360-badge-progress';
		else if (status === 'completed' || status === 'success') badgeClass = 'bg360-badge-success';
		else if (status === 'failed' || status === 'cancelled') badgeClass = 'bg360-badge-failure';

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
			action: 'bizgrowth360_get_build_status',
			nonce: bizgrowth360AppBuilder.nonce,
			build_id: activeBuildId
		}).done(function (res) {
			if (!res.success || !res.data.build) {
				return;
			}

			var b = res.data.build;
			activeBuildId = b.build_id;

			$('#bg360-meta-run-id').text(b.run_number ? '#' + b.run_number + ' (' + b.build_id + ')' : b.build_id);
			$('#bg360-meta-event').text(b.build_type ? b.build_type.toUpperCase() + ' (' + (b.build_variant || 'release') + ')' : 'BOTH');
			$('#bg360-meta-status-text').text(b.status.toUpperCase());

			// Granular stages
			if (b.status === 'queued' || b.status === 'dispatching') {
				updateBuildStatus('queued', 'Runner Queued');
				$('#bg360-progress-bar').css('width', '20%');
				resetStages();
				$('#stage-init').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'preparing' || b.stage === 'setup') {
				updateBuildStatus('in_progress', 'Preparing SDK & Tools');
				$('#bg360-progress-bar').css('width', '40%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'building' || b.stage === 'building') {
				updateBuildStatus('in_progress', 'Gradle Compiling');
				$('#bg360-progress-bar').css('width', '65%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('completed');
				$('#stage-compile').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'signing' || b.stage === 'signing') {
				updateBuildStatus('in_progress', 'Keystore Signing');
				$('#bg360-progress-bar').css('width', '85%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('completed');
				$('#stage-compile').addClass('completed');
				$('#stage-signing').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'completed') {
				stopPolling();
				stopTimer();
				$('#bg360-cancel-build-btn').hide();
				updateBuildStatus('completed', 'Build Succeeded');
				$('#bg360-run-subtitle').text(bizgrowth360AppBuilder.strings.build_success);
				$('#bg360-progress-bar').css('width', '100%');
				$('.bg360-stage').removeClass('active').addClass('completed');

				renderArtifacts(b.artifacts, b.build_id);
				loadHistory(1);
			} else if (b.status === 'failed' || b.status === 'cancelled') {
				stopPolling();
				stopTimer();
				$('#bg360-cancel-build-btn').hide();
				updateBuildStatus(b.status, b.status === 'cancelled' ? 'Build Cancelled' : 'Build Failed');
				$('#bg360-run-subtitle').text(b.status === 'cancelled' ? bizgrowth360AppBuilder.strings.build_cancelled : bizgrowth360AppBuilder.strings.build_failed);
				$('.bg360-stage').removeClass('active');
				loadHistory(1);
			}
		});
	}

	function renderArtifacts(artifacts, buildId) {
		var container = $('#bg360-artifact-cards');
		container.empty();

		if (!artifacts || artifacts.length === 0) {
			container.html('<p>Artifacts are being processed. Click Refresh History or check back shortly.</p>');
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
				'&build_id=' + encodeURIComponent(buildId || '') +
				'&type=' + (isApk ? 'apk' : (isAab ? 'aab' : '')) +
				'&_wpnonce=' + encodeURIComponent(bizgrowth360AppBuilder.download_nonce);

			var card = $(
				'<div class="bg360-artifact-card">' +
				'  <div class="bg360-artifact-info">' +
				'    <div class="bg360-artifact-icon ' + typeClass + '">' + typeLabel + '</div>' +
				'    <div>' +
				'      <h5 class="bg360-artifact-title">' + art.name + '</h5>' +
				'      <p class="bg360-artifact-size">' + (art.size_mb || '') + ' • ' + (isApk ? 'Direct Android Install' : 'Google Play Console') + '</p>' +
				'    </div>' +
				'  </div>' +
				'  <a href="' + downloadLink + '" class="button button-primary bg360-download-btn">' +
				'    <span class="dashicons dashicons-download"></span> Download ' + typeLabel +
				'  </a>' +
				'</div>'
			);
			container.append(card);
		});

		$('#bg360-downloads-zone').slideDown();
	}

	/**
	 * Build Center History, Filters, Pagination
	 */
	function initBuildCenter() {
		$('#bg360-refresh-history-btn').on('click', function () {
			loadHistory(currentPage);
		});

		$('#bg360-filter-search').on('input', debounce(function () {
			loadHistory(1);
		}, 400));

		$('#bg360-filter-status, #bg360-filter-target').on('change', function () {
			loadHistory(1);
		});

		$('#bg360-page-prev').on('click', function () {
			if (currentPage > 1) loadHistory(currentPage - 1);
		});

		$('#bg360-page-next').on('click', function () {
			if (currentPage < totalPages) loadHistory(currentPage + 1);
		});

		// Retry Build button handler
		$(document).on('click', '.bg360-retry-btn', function (e) {
			e.preventDefault();
			var bId = $(this).data('build-id');
			if (!confirm('Retry this build using its original configuration snapshot?')) return;

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_retry_build',
				nonce: bizgrowth360AppBuilder.nonce,
				build_id: bId
			}).done(function (res) {
				if (res.success) {
					activeBuildId = res.data.build_id;
					switchTab('tab-monitor');
					showNotice(res.data.message, 'success');
					startPolling();
				} else {
					showNotice(res.data.message || 'Retry failed.', 'error');
				}
			});
		});

		// View Logs button handler
		$(document).on('click', '.bg360-view-logs-btn', function (e) {
			e.preventDefault();
			var runId = $(this).data('run-id');
			var buildId = $(this).data('build-id');

			$('#logs-modal-title-id').text(buildId ? '(' + buildId + ')' : '');
			$('#bg360-logs-content').text(bizgrowth360AppBuilder.strings.loading_logs);
			$('#logs-modal-status-text').text('Connecting...');
			$('#bg360-logs-modal').fadeIn(150);

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_get_build_logs',
				nonce: bizgrowth360AppBuilder.nonce,
				run_id: runId,
				build_id: buildId
			}).done(function (res) {
				if (res.success && res.data.logs) {
					$('#bg360-logs-content').text(res.data.logs);
					$('#logs-modal-status-text').removeClass('bg360-badge-idle bg360-badge-failure').addClass('bg360-badge-success').text('Logs Loaded');
				} else {
					$('#bg360-logs-content').text(res.data && res.data.message ? res.data.message : 'No logs available for this build yet.');
					$('#logs-modal-status-text').removeClass('bg360-badge-idle bg360-badge-success').addClass('bg360-badge-failure').text('Unavailable');
				}
			}).fail(function () {
				$('#bg360-logs-content').text('Network error retrieving logs.');
			});
		});

		// View Details / Snapshot handler
		$(document).on('click', '.bg360-view-details-btn', function (e) {
			e.preventDefault();
			var snapshotJson = $(this).attr('data-snapshot');
			var buildId = $(this).data('build-id');

			$('#details-modal-title-id').text('(' + buildId + ')');
			try {
				var parsed = JSON.parse(snapshotJson);
				$('#bg360-details-content').text(JSON.stringify(parsed, null, 2));
			} catch (err) {
				$('#bg360-details-content').text(snapshotJson || 'No snapshot available.');
			}
			$('#bg360-details-modal').fadeIn(150);
		});
	}

	function loadHistory(page) {
		currentPage = page || 1;
		var tbody = $('#bg360-history-body');
		tbody.html('<tr><td colspan="7" class="text-center"><span class="spinner is-active" style="float:none;"></span> Loading build history...</td></tr>');

		$.post(bizgrowth360AppBuilder.ajax_url, {
			action: 'bizgrowth360_get_builds',
			nonce: bizgrowth360AppBuilder.nonce,
			search: $('#bg360-filter-search').val(),
			status: $('#bg360-filter-status').val(),
			build_type: $('#bg360-filter-target').val(),
			page: currentPage,
			per_page: 10
		}).done(function (res) {
			if (!res.success) {
				tbody.html('<tr><td colspan="7" class="text-center text-error">Failed loading build records.</td></tr>');
				return;
			}

			var items = res.data.items || [];
			var runs = res.data.runs || [];

			// Handle custom DB records first
			if (items.length > 0) {
				renderDbHistory(items, res.data.total, res.data.page, res.data.pages);
				return;
			}

			// Fallback to GitHub runs if DB is empty
			if (runs.length > 0) {
				renderGhRunsHistory(runs);
				return;
			}

			tbody.html('<tr><td colspan="7" class="text-center">No build records found yet. Trigger your first build to see history.</td></tr>');
			$('#bg360-history-pagination').hide();
		}).fail(function () {
			tbody.html('<tr><td colspan="7" class="text-center text-error">Failed to communicate with build server.</td></tr>');
		});
	}

	function renderDbHistory(items, total, page, pages) {
		var tbody = $('#bg360-history-body');
		tbody.empty();

		currentPage = page;
		totalPages = pages;

		items.forEach(function (b) {
			var statusClass = 'bg360-badge-idle';
			if (b.status === 'completed') statusClass = 'bg360-badge-success';
			else if (b.status === 'failed') statusClass = 'bg360-badge-failure';
			else if (b.status === 'cancelled') statusClass = 'bg360-badge-failure';
			else statusClass = 'bg360-badge-progress';

			var durationStr = b.duration_seconds ? Math.floor(b.duration_seconds / 60) + 'm ' + (b.duration_seconds % 60) + 's' : '-';

			var actionsHtml = '<div style="display:flex; gap:6px; flex-wrap:wrap;">';

			// Downloads
			if (b.status === 'completed' && b.artifacts && b.artifacts.length > 0) {
				b.artifacts.forEach(function (art) {
					var isApk = art.name.toLowerCase().indexOf('apk') !== -1;
					var isAab = art.name.toLowerCase().indexOf('aab') !== -1;
					if (isApk || isAab) {
						var dl = bizgrowth360AppBuilder.download_base_url +
							'&artifact_id=' + encodeURIComponent(art.id) +
							'&build_id=' + encodeURIComponent(b.build_id) +
							'&type=' + (isApk ? 'apk' : 'aab') +
							'&_wpnonce=' + encodeURIComponent(bizgrowth360AppBuilder.download_nonce);
						actionsHtml += '<a href="' + dl + '" class="button button-small button-primary" title="Download ' + (isApk ? 'APK' : 'AAB') + '"><span class="dashicons dashicons-download"></span> ' + (isApk ? 'APK' : 'AAB') + '</a>';
					}
				});
			}

			// Details button
			actionsHtml += '<button type="button" class="button button-small bg360-view-details-btn" data-build-id="' + b.build_id + '" data-snapshot="' + escapeHtml(JSON.stringify(b.config_snapshot)) + '" title="View Configuration Snapshot"><span class="dashicons dashicons-info"></span></button>';

			// Logs button
			if (b.run_id) {
				actionsHtml += '<button type="button" class="button button-small bg360-view-logs-btn" data-run-id="' + b.run_id + '" data-build-id="' + b.build_id + '" title="View Logs"><span class="dashicons dashicons-media-text"></span></button>';
			}

			// Retry button
			if (b.status === 'failed' || b.status === 'cancelled') {
				actionsHtml += '<button type="button" class="button button-small bg360-retry-btn" data-build-id="' + b.build_id + '" title="Retry Build"><span class="dashicons dashicons-image-rotate"></span></button>';
			}

			actionsHtml += '</div>';

			var row = $(
				'<tr>' +
				'  <td><code>' + b.build_id + '</code></td>' +
				'  <td><strong>' + escapeHtml(b.app_name) + '</strong> <span style="color:#64748B;">v' + escapeHtml(b.version_name) + '</span></td>' +
				'  <td><code>' + (b.build_type || 'both').toUpperCase() + '</code></td>' +
				'  <td><span class="bg360-badge ' + statusClass + '">' + b.status + '</span></td>' +
				'  <td>' + durationStr + '</td>' +
				'  <td>' + b.created_at + '</td>' +
				'  <td>' + actionsHtml + '</td>' +
				'</tr>'
			);
			tbody.append(row);
		});

		// Pagination controls
		$('#bg360-pagination-count').text(total + ' builds');
		$('#bg360-current-page').text(page);
		$('#bg360-total-pages').text(pages);
		$('#bg360-history-pagination').show();
		$('#bg360-page-prev').prop('disabled', page <= 1);
		$('#bg360-page-next').prop('disabled', page >= pages);
	}

	function renderGhRunsHistory(runs) {
		var tbody = $('#bg360-history-body');
		tbody.empty();

		runs.forEach(function (run) {
			var statusClass = 'bg360-badge-idle';
			if (run.conclusion === 'success') statusClass = 'bg360-badge-success';
			else if (run.conclusion === 'failure') statusClass = 'bg360-badge-failure';
			else if (run.status === 'in_progress') statusClass = 'bg360-badge-progress';

			var actionsHtml = '<button type="button" class="button button-small bg360-view-logs-btn" data-run-id="' + run.id + '"><span class="dashicons dashicons-media-text"></span> Logs</button>';

			var row = $(
				'<tr>' +
				'  <td><strong>#' + run.run_number + '</strong></td>' +
				'  <td>' + escapeHtml(run.name) + '</td>' +
				'  <td><code>' + escapeHtml(run.event) + '</code></td>' +
				'  <td><span class="bg360-badge ' + statusClass + '">' + (run.conclusion || run.status) + '</span></td>' +
				'  <td>' + (run.duration_sec ? Math.floor(run.duration_sec / 60) + 'm ' + (run.duration_sec % 60) + 's' : '-') + '</td>' +
				'  <td>' + new Date(run.created_at).toLocaleString() + '</td>' +
				'  <td>' + actionsHtml + '</td>' +
				'</tr>'
			);
			tbody.append(row);
		});
		$('#bg360-history-pagination').hide();
	}

	function loadActiveBuildOrHistory() {
		$.post(bizgrowth360AppBuilder.ajax_url, {
			action: 'bizgrowth360_get_build_status',
			nonce: bizgrowth360AppBuilder.nonce
		}).done(function (res) {
			if (res.success && res.data.build) {
				var b = res.data.build;
				if (b.status !== 'completed' && b.status !== 'failed' && b.status !== 'cancelled') {
					activeBuildId = b.build_id;
					startPolling();
					startTimer(b.duration_seconds || 0);
				} else if (b.status === 'completed' && b.artifacts && b.artifacts.length > 0) {
					renderArtifacts(b.artifacts, b.build_id);
				}
			}
			loadHistory(1);
		}).fail(function () {
			loadHistory(1);
		});
	}

	function debounce(func, wait) {
		var timeout;
		return function () {
			var context = this, args = arguments;
			clearTimeout(timeout);
			timeout = setTimeout(function () {
				func.apply(context, args);
			}, wait);
		};
	}

	function escapeHtml(text) {
		if (!text) return '';
		return String(text)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#039;');
	}

})(jQuery);
