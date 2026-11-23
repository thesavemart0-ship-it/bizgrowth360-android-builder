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
	var pendingBuildAction = 'both';
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
		initNativeFeatures();
		initIOSSettings();
		initSaasPlatform();

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
			} else if (target === 'tab-saas') {
				loadSaasPlatformData();
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

	function showToast(message, type) {
		type = type || 'success';
		var container = $('#bg360-toast-container');
		if (!container.length) {
			container = $('<div id="bg360-toast-container" class="bg360-toast-container"></div>');
			$('body').append(container);
		}
		var icon = type === 'success' ? 'yes-alt' : (type === 'error' ? 'warning' : 'info');
		var toast = $(
			'<div class="bg360-toast bg360-toast-' + type + '">' +
			'  <span class="dashicons dashicons-' + icon + '"></span>' +
			'  <span class="bg360-toast-msg">' + escapeHtml(message) + '</span>' +
			'</div>'
		);
		container.append(toast);
		setTimeout(function () { toast.addClass('show'); }, 10);
		setTimeout(function () {
			toast.removeClass('show');
			setTimeout(function () { toast.remove(); }, 400);
		}, 4000);
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

			var requested = $(this).data('build-action') || $(this).data('build-target') || 'auto';
			pendingBuildAction = resolveBuildAction(String(requested));

			// Validate inputs before showing modal
			var postData = collectFormData();
			postData.action = 'bizgrowth360_validate_build';
			postData.build_action = pendingBuildAction;
			postData.platform = actionPlatform(pendingBuildAction);

			$('#modal-summary-error-wrap').hide();

			$.post(bizgrowth360AppBuilder.ajax_url, postData).done(function (res) {
				if (res.success && res.data.valid) {
					var s = res.data.summary;
					var hasIOS = actionHasIOS(pendingBuildAction);
					var canTestflight = hasIOS &&
						bizgrowth360AppBuilder.ios_upload_testflight &&
						bizgrowth360AppBuilder.ios_export_method === 'app-store' &&
						bizgrowth360AppBuilder.ios_mode === 'ipa';

					$('#modal-summary-app-name strong').text(s.app_name);
					$('#modal-summary-platform strong').text(formatPlatform(pendingBuildAction));
					$('#modal-summary-package-id code').text(s.package_id);
					$('#modal-summary-ios-bundle code').text(s.ios_bundle_id || '-');
					$('#modal-summary-ios-bundle-row').toggle(hasIOS);
					$('#modal-summary-url-link').attr('href', s.website_url).text(s.website_url);
					$('#modal-summary-version').text(s.version_name + ' (Build: ' + s.version_code + ')');
					$('#modal-summary-target strong').text(formatBuildAction(pendingBuildAction));
					$('#modal-summary-variant').text((s.build_variant || 'release').toUpperCase());
					$('#modal-summary-orientation').text(s.orientation);
					$('#modal-summary-color-box').css('background-color', s.primary_color);
					$('#modal-summary-color').text(s.primary_color);
					$('#modal-summary-icon').text(s.icon_url ? 'Custom Icon Provided' : 'Default Auto-Generated Vector');

					$('#modal-summary-testflight-row').toggle(canTestflight);
					$('#bg360-confirm-testflight').prop('checked', false);
					$('#modal-summary-ios-warning').toggle(hasIOS);

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
			var testflight = actionHasIOS(pendingBuildAction) && $('#bg360-confirm-testflight').is(':checked');
			$('#bg360-confirm-modal').fadeOut(150);
			startBuild(pendingBuildAction, testflight);
			$('#bg360-confirm-testflight').prop('checked', false);
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

	function resolveBuildAction(requested) {
		if (requested && requested !== 'auto') return requested;
		var platform = $('#bg360_platform').val() || 'android';
		if (platform === 'ios') return 'ios';
		if (platform === 'both') return 'all';
		return $('#bg360_build_target').val() || 'both';
	}

	function actionPlatform(action) {
		if (action === 'ios') return 'ios';
		if (action === 'all') return 'both';
		return 'android';
	}

	function actionHasIOS(action) {
		return action === 'ios' || action === 'all';
	}

	function collectFormData() {
		return {
			nonce: bizgrowth360AppBuilder.nonce,
			app_name: $('#bg360_app_name').val(),
			package_id: $('#bg360_package_id').val(),
			website_url: $('#bg360_website_url').val(),
			version_name: $('#bg360_version_name').val(),
			version_code: $('#bg360_version_code').val(),
			platform: $('#bg360_platform').val(),
			build_target: $('#bg360_build_target').val(),
			build_type: $('#bg360_build_type').val(),
			build_variant: $('#bg360_build_type').val(),
			build_action: pendingBuildAction,
			orientation: $('#bg360_orientation').val(),
			primary_color: $('#bg360_primary_color').val(),
			background_color: $('#bg360_background_color').val(),
			status_bar_color: $('#bg360_status_bar_color').val(),
			navigation_bar_color: $('#bg360_navigation_bar_color').val(),
			icon_url: $('#bg360_app_icon').val(),
			splash_url: $('#bg360_splash_image').val(),
			allow_external_links: $('#bg360_allow_external_links').is(':checked') ? 1 : 0,
			ios_bundle_id: $('#bg360_ios_bundle_id').val(),
			ios_team_id: $('#bg360_ios_team_id').val(),
			ios_export_method: $('#bg360_ios_export_method').val(),
			ios_mode: $('#bg360_ios_mode').val(),
			macos_runner: $('#bg360_macos_runner').val(),
			ios_upload_testflight: $('#bg360_ios_upload_testflight').is(':checked') ? 1 : 0
		};
	}

	function formatBuildAction(action) {
		if (action === 'apk') return 'Android APK (Direct Install)';
		if (action === 'aab') return 'Android AAB (Play Store)';
		if (action === 'ios') return 'iOS IPA (Apple)';
		if (action === 'all') return 'Android APK + AAB and iOS IPA';
		return 'Android APK & AAB Packages';
	}

	function formatPlatform(action) {
		if (action === 'ios') return 'iOS (macOS runner)';
		if (action === 'all') return 'Android + iOS';
		return 'Android';
	}

	function initIOSSettings() {
		function sync() {
			var platform = $('#bg360_platform').val();
			$('#bg360-ios-settings').toggle(platform === 'ios' || platform === 'both');
			$('#modal-summary-ios-warning').toggle(platform === 'ios' || platform === 'both');
		}
		$('#bg360_platform').on('change', sync);
		sync();
	}

	/**
	 * Trigger Build
	 *
	 * @param {string} action apk|aab|both|ios|all
	 * @param {boolean} testflight Explicit per-build TestFlight approval
	 */
	function startBuild(action, testflight) {
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
			postData.build_action = action;
			postData.platform = actionPlatform(action);
			postData.confirm_app_store_upload = testflight && actionHasIOS(action) ? 1 : 0;
			postData.ios_upload_testflight = testflight && actionHasIOS(action) ? 1 : 0;

			$.post(bizgrowth360AppBuilder.ajax_url, postData).done(function (res) {
				if (res.success) {
					activeBuildId = res.data.build_id;
					$('#bg360-meta-run-id').text(activeBuildId);
					$('#bg360-meta-event').text(formatPlatform(action) + ' / ' + formatBuildAction(action));

					if (res.data.builds && res.data.builds.length > 1) {
						var ids = res.data.builds.map(function (b) { return b.build_id + ' (' + b.platform + ')'; }).join(', ');
						showNotice(res.data.message + ' Build records: ' + ids, res.data.partial ? 'warning' : 'success');
					} else {
						showNotice(res.data.message, res.data.partial ? 'warning' : 'success');
					}
					setTimeout(startPolling, 3000);
					loadHistory(1);
				} else {
					stopTimer();
					updateBuildStatus('failed', 'Dispatch Failed');
					$('#bg360-cancel-build-btn').hide();
					showNotice(res.data.message || 'Build trigger failed.', 'error');
					loadHistory(1);
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
		else if (status === 'in_progress' || status === 'starting' || status === 'preparing' || status === 'building' || status === 'signing' || status === 'exporting' || status === 'uploading') badgeClass = 'bg360-badge-progress';
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
			var isIOS = b.platform === 'ios';

			$('#bg360-meta-run-id').text(b.run_number ? '#' + b.run_number + ' (' + b.build_id + ')' : b.build_id);
			$('#bg360-meta-event').text((isIOS ? 'iOS' : 'Android') + ' / ' + (b.build_type ? b.build_type.toUpperCase() : 'BOTH') + ' (' + (b.build_variant || 'release') + ')');
			$('#bg360-meta-status-text').text(b.status.toUpperCase());

			// Granular stages
			if (b.status === 'queued' || b.status === 'dispatching') {
				updateBuildStatus('queued', 'Runner Queued');
				$('#bg360-progress-bar').css('width', '20%');
				resetStages();
				$('#stage-init').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'preparing' || b.stage === 'setup' || b.stage === 'preparing') {
				updateBuildStatus('in_progress', isIOS ? 'Preparing Xcode & CocoaPods' : 'Preparing SDK & Tools');
				$('#bg360-progress-bar').css('width', '40%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'building' || b.stage === 'building') {
				updateBuildStatus('in_progress', isIOS ? 'Xcode Compile / Archive' : 'Gradle Compiling');
				$('#bg360-progress-bar').css('width', '65%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('completed');
				$('#stage-compile').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'signing' || b.stage === 'signing') {
				updateBuildStatus('in_progress', isIOS ? 'Apple Code Signing' : 'Keystore Signing');
				$('#bg360-progress-bar').css('width', '82%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('completed');
				$('#stage-compile').addClass('completed');
				$('#stage-signing').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'exporting' || b.stage === 'exporting') {
				updateBuildStatus('in_progress', isIOS ? 'Exporting Signed IPA' : 'Packaging Artifacts');
				$('#bg360-progress-bar').css('width', '92%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('completed');
				$('#stage-compile').addClass('completed');
				$('#stage-signing').addClass('completed');
				$('#stage-artifacts').addClass('active');
				$('#bg360-cancel-build-btn').show();
			} else if (b.status === 'uploading' || b.stage === 'uploading') {
				updateBuildStatus('in_progress', 'Uploading to TestFlight');
				$('#bg360-progress-bar').css('width', '96%');
				$('#stage-init').addClass('completed');
				$('#stage-setup').addClass('completed');
				$('#stage-compile').addClass('completed');
				$('#stage-signing').addClass('completed');
				$('#stage-artifacts').addClass('active');
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
			var name = (art.name || '').toLowerCase();
			var isApk = name.indexOf('apk') !== -1;
			var isAab = name.indexOf('aab') !== -1;
			var isIpa = name.indexOf('ipa') !== -1;
			var typeClass = isApk ? 'apk' : (isAab ? 'aab' : (isIpa ? 'ipa' : 'zip'));
			var typeLabel = isApk ? 'APK' : (isAab ? 'AAB' : (isIpa ? 'IPA' : 'FILE'));
			var typeParam = isApk ? 'apk' : (isAab ? 'aab' : (isIpa ? 'ipa' : ''));
			var detail = isApk ? 'Direct Android Install' : (isAab ? 'Google Play Console' : (isIpa ? 'TestFlight / App Store' : 'Build package'));

			var downloadLink = bizgrowth360AppBuilder.download_base_url +
				'&artifact_id=' + encodeURIComponent(art.id) +
				'&build_id=' + encodeURIComponent(buildId || '') +
				'&type=' + typeParam +
				'&_wpnonce=' + encodeURIComponent(bizgrowth360AppBuilder.download_nonce);

			var card = $(
				'<div class="bg360-artifact-card">' +
				'  <div class="bg360-artifact-info">' +
				'    <div class="bg360-artifact-icon ' + typeClass + '">' + typeLabel + '</div>' +
				'    <div>' +
				'      <h5 class="bg360-artifact-title">' + escapeHtml(art.name) + '</h5>' +
				'      <p class="bg360-artifact-size">' + (art.size_mb || '') + ' • ' + detail + '</p>' +
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

		$('#bg360-filter-status, #bg360-filter-target, #bg360-filter-platform').on('change', function () {
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
		tbody.html('<tr><td colspan="8" class="text-center"><span class="spinner is-active" style="float:none;"></span> Loading build history...</td></tr>');

		$.post(bizgrowth360AppBuilder.ajax_url, {
			action: 'bizgrowth360_get_builds',
			nonce: bizgrowth360AppBuilder.nonce,
			search: $('#bg360-filter-search').val(),
			status: $('#bg360-filter-status').val(),
			build_type: $('#bg360-filter-target').val(),
			platform: $('#bg360-filter-platform').val(),
			page: currentPage,
			per_page: 10
		}).done(function (res) {
			if (!res.success) {
				tbody.html('<tr><td colspan="8" class="text-center text-error">Failed loading build records.</td></tr>');
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

			tbody.html('<tr><td colspan="8" class="text-center">No build records found yet. Trigger your first build to see history.</td></tr>');
			$('#bg360-history-pagination').hide();
		}).fail(function () {
			tbody.html('<tr><td colspan="8" class="text-center text-error">Failed to communicate with build server.</td></tr>');
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
					var name = (art.name || '').toLowerCase();
					var isApk = name.indexOf('apk') !== -1;
					var isAab = name.indexOf('aab') !== -1;
					var isIpa = name.indexOf('ipa') !== -1;
					if (isApk || isAab || isIpa) {
						var label = isApk ? 'APK' : (isAab ? 'AAB' : 'IPA');
						var type = isApk ? 'apk' : (isAab ? 'aab' : 'ipa');
						var dl = bizgrowth360AppBuilder.download_base_url +
							'&artifact_id=' + encodeURIComponent(art.id) +
							'&build_id=' + encodeURIComponent(b.build_id) +
							'&type=' + type +
							'&_wpnonce=' + encodeURIComponent(bizgrowth360AppBuilder.download_nonce);
						actionsHtml += '<a href="' + dl + '" class="button button-small button-primary" title="Download ' + label + '"><span class="dashicons dashicons-download"></span> ' + label + '</a>';
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

			var platformLabel = b.platform === 'ios' ? 'iOS' : 'Android';
			var platformClass = b.platform === 'ios' ? 'background:#111827;color:#F9FAFB;' : 'background:#DCFCE7;color:#166534;';

			var row = $(
				'<tr>' +
				'  <td><code>' + escapeHtml(b.build_id) + '</code></td>' +
				'  <td><strong>' + escapeHtml(b.app_name) + '</strong> <span style="color:#64748B;">v' + escapeHtml(b.version_name) + '</span></td>' +
				'  <td><span class="bg360-badge" style="' + platformClass + '">' + platformLabel + '</span></td>' +
				'  <td><code>' + escapeHtml((b.build_type || 'both').toUpperCase()) + '</code></td>' +
				'  <td><span class="bg360-badge ' + statusClass + '">' + escapeHtml(b.status) + '</span></td>' +
				'  <td>' + durationStr + '</td>' +
				'  <td>' + escapeHtml(b.created_at) + '</td>' +
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
				'  <td><span class="bg360-badge bg360-badge-idle">Workflow</span></td>' +
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

	/**
	 * Native Features Management
	 */
	function initNativeFeatures() {
		// Toggle password visibility
		$('.bg360-toggle-password').on('click', function () {
			var target = $($(this).data('target'));
			var isPassword = target.attr('type') === 'password';
			target.attr('type', isPassword ? 'text' : 'password');
			$(this).find('.dashicons').toggleClass('dashicons-visibility dashicons-hidden');
		});

		// Save Native Features
		$(document).on('click', '#bg360-save-features-btn', function (e) {
			e.preventDefault();
			var btn = $(this);
			var statusEl = $('#bg360-features-save-status');
			var originalHtml = '<span class="dashicons dashicons-saved"></span> Save Native Features Settings';

			// Immediate visual feedback
			btn.prop('disabled', true).html('<span class="spinner is-active" style="float:none;margin:0 8px 0 0;visibility:visible;"></span> Saving Features...').css({
				'background-color': '#2563EB',
				'border-color': '#1D4ED8',
				'color': '#ffffff'
			});
			statusEl.html('<span style="display:inline-flex;align-items:center;gap:6px;color:#2563EB;font-weight:600;font-size:13px;"><span class="dashicons dashicons-update spin"></span> Saving settings to database...</span>').show();

			var formArray = $('#bg360-native-features-form').serializeArray();
			var features = {
				push_notifications: { enabled: false },
				deep_linking: { enabled: false, auto_verify: false },
				native_share: { enabled: false },
				camera_upload: { camera_enabled: false, gallery_enabled: false },
				offline_engine: { enabled: false, auto_reconnect: false },
				permission_manager: { enabled: false, location_enabled: false },
				app_updates: { enabled: false }
			};

			$.each(formArray, function () {
				var name = this.name;
				var val = this.value;

				var match = name.match(/^([a-z_]+)\[([a-z_]+)\]$/);
				if (match) {
					var section = match[1];
					var field = match[2];
					if (!features[section]) features[section] = {};

					if (val === '1' && (field === 'enabled' || field === 'camera_enabled' || field === 'gallery_enabled' || field === 'auto_reconnect' || field === 'location_enabled' || field === 'auto_verify')) {
						features[section][field] = true;
					} else {
						features[section][field] = val;
					}
				}
			});

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_save_native_features',
				nonce: bizgrowth360AppBuilder.nonce,
				features: JSON.stringify(features)
			}, function (res) {
				if (res.success) {
					btn.html('<span class="dashicons dashicons-yes-alt" style="margin-right:6px;vertical-align:text-top;"></span> Saved Successfully!').css({
						'background-color': '#10B981',
						'border-color': '#059669',
						'color': '#ffffff'
					});
					statusEl.html('<span style="display:inline-flex;align-items:center;gap:6px;color:#065F46;font-weight:700;font-size:13px;background:#D1FAE5;border:1px solid #6EE7B7;padding:6px 14px;border-radius:8px;"><span class="dashicons dashicons-yes"></span> ' + (res.data.message || 'Settings saved successfully!') + '</span>').show();

					showToast(res.data.message || 'Native features saved successfully!', 'success');
					showNotice(res.data.message || 'Native features settings saved successfully.', 'success');
					updateFeatureChips(res.data.status);

					setTimeout(function () {
						btn.prop('disabled', false).html(originalHtml).attr('style', '');
						setTimeout(function () { statusEl.fadeOut(800); }, 3000);
					}, 2500);
				} else {
					btn.prop('disabled', false).html('<span class="dashicons dashicons-warning" style="margin-right:6px;vertical-align:text-top;"></span> Save Failed').css({
						'background-color': '#EF4444',
						'border-color': '#DC2626',
						'color': '#ffffff'
					});
					statusEl.html('<span style="display:inline-flex;align-items:center;gap:6px;color:#991B1B;font-weight:700;font-size:13px;background:#FEE2E2;border:1px solid #FCA5A5;padding:6px 14px;border-radius:8px;"><span class="dashicons dashicons-warning"></span> ' + (res.data.message || 'Save error') + '</span>').show();
					showToast('Save error: ' + (res.data.message || 'Could not save settings.'), 'error');
					setTimeout(function () {
						btn.html(originalHtml).attr('style', '');
					}, 3500);
				}
			}).fail(function () {
				btn.prop('disabled', false).html('<span class="dashicons dashicons-warning" style="margin-right:6px;vertical-align:text-top;"></span> Connection Error').css({
					'background-color': '#EF4444',
					'border-color': '#DC2626',
					'color': '#ffffff'
				});
				statusEl.html('<span style="display:inline-flex;align-items:center;gap:6px;color:#991B1B;font-weight:700;font-size:13px;background:#FEE2E2;border:1px solid #FCA5A5;padding:6px 14px;border-radius:8px;"><span class="dashicons dashicons-warning"></span> Connection error saving features.</span>').show();
				showToast('Connection error communicating with server.', 'error');
				setTimeout(function () {
					btn.html(originalHtml).attr('style', '');
				}, 3500);
			});
		});

		$(document).on('submit', '#bg360-native-features-form', function (e) {
			e.preventDefault();
			$('#bg360-save-features-btn').trigger('click');
		});

		// Open Test Push Modal
		$('#bg360-open-test-push-btn').on('click', function () {
			$('#test-push-response').empty();
			$('#bg360-test-push-modal').fadeIn(200);
		});

		// Send Test Push
		$('#bg360-send-push-confirm-btn').on('click', function () {
			var sendBtn = $(this);
			var responseDiv = $('#test-push-response');
			var title = $('#test_push_title').val();
			var body = $('#test_push_body').val();
			var url = $('#test_push_url').val();

			sendBtn.prop('disabled', true).text('Dispatching FCM Push...');
			responseDiv.html('<p style="color:#64748B;">Connecting to Firebase Cloud Messaging...</p>');

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_send_test_push',
				nonce: bizgrowth360AppBuilder.nonce,
				title: title,
				body: body,
				url: url
			}, function (res) {
				sendBtn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Send Notification Now');
				if (res.success) {
					responseDiv.html('<div class="notice notice-success inline" style="margin:5px 0; padding:8px 12px;"><p>' + escapeHtml(res.data.message) + '</p></div>');
				} else {
					responseDiv.html('<div class="notice notice-error inline" style="margin:5px 0; padding:8px 12px;"><p>' + escapeHtml(res.data.message || 'Failed to dispatch push notification.') + '</p></div>');
				}
			}).fail(function () {
				sendBtn.prop('disabled', false).html('<span class="dashicons dashicons-controls-play"></span> Send Notification Now');
				responseDiv.html('<div class="notice notice-error inline" style="margin:5px 0; padding:8px 12px;"><p>Network request failed.</p></div>');
			});
		});

		// Preview assetlinks.json Modal
		$('#bg360-preview-assetlinks-btn').on('click', function () {
			var sha256 = $('#feat_deep_sha256').val();
			$('#bg360-assetlinks-content').text('Generating Digital Asset Links payload...');
			$('#bg360-assetlinks-modal').fadeIn(200);

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_generate_assetlinks',
				nonce: bizgrowth360AppBuilder.nonce,
				sha256_fingerprint: sha256
			}, function (res) {
				if (res.success && res.data.assetlinks_json) {
					$('#bg360-assetlinks-content').text(res.data.assetlinks_json);
				} else {
					$('#bg360-assetlinks-content').text('Could not generate assetlinks.json');
				}
			}).fail(function () {
				$('#bg360-assetlinks-content').text('Error fetching assetlinks.json');
			});
		});

		// Modal close buttons
		$('[data-close]').on('click', function (e) {
			e.preventDefault();
			var target = $(this).data('close');
			$(target).fadeOut(150);
		});
	}

	function updateFeatureChips(statuses) {
		if (!statuses) return;
		$.each(statuses, function (key, status) {
			var chip = $('#chip-' + key);
			if (chip.length) {
				var badge = chip.find('.bg360-status-badge');
				badge.removeClass('bg360-chip-configured bg360-chip-needs_setup bg360-chip-enabled bg360-chip-disabled')
					.addClass('bg360-chip-' + status)
					.text(status.charAt(0).toUpperCase() + status.slice(1).replace('_', ' '));
			}
		});
	}

	/**
	 * Super Admin SaaS Multi-Tenant Management
	 */
	function initSaasPlatform() {
		$('#bg360-saas-search').on('input', debounce(function () {
			loadSaasPlatformData();
		}, 400));

		$('#bg360-saas-status-filter').on('change', function () {
			loadSaasPlatformData();
		});

		$('#bg360-toggle-registration').on('change', function () {
			var enabled = $(this).is(':checked') ? 1 : 0;
			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_saas_toggle_registration',
				nonce: bizgrowth360AppBuilder.nonce,
				enabled: enabled
			}, function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				}
			});
		});

		// Suspend / Reactivate Workspace
		$(document).on('click', '.bg360-saas-status-btn', function () {
			var wsId = $(this).data('ws-id');
			var newStatus = $(this).data('new-status');
			var btn = $(this);

			btn.prop('disabled', true);
			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_saas_update_status',
				nonce: bizgrowth360AppBuilder.nonce,
				workspace_id: wsId,
				status: newStatus
			}, function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					loadSaasPlatformData();
				} else {
					showToast(res.data.message, 'error');
					btn.prop('disabled', false);
				}
			});
		});

		// Change Plan
		$(document).on('change', '.bg360-saas-plan-select', function () {
			var wsId = $(this).data('ws-id');
			var planId = $(this).val();

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_saas_change_plan',
				nonce: bizgrowth360AppBuilder.nonce,
				workspace_id: wsId,
				plan_id: planId
			}, function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
				} else {
					showToast(res.data.message, 'error');
				}
			});
		});

		// Delete Workspace
		$(document).on('click', '.bg360-saas-delete-btn', function () {
			var wsId = $(this).data('ws-id');
			if (!confirm('Are you sure you want to permanently delete this customer workspace? All tenant apps and usage will be removed.')) return;

			$.post(bizgrowth360AppBuilder.ajax_url, {
				action: 'bizgrowth360_saas_delete_workspace',
				nonce: bizgrowth360AppBuilder.nonce,
				workspace_id: wsId
			}, function (res) {
				if (res.success) {
					showToast(res.data.message, 'success');
					loadSaasPlatformData();
				} else {
					showToast(res.data.message, 'error');
				}
			});
		});
	}

	function loadSaasPlatformData(page) {
		page = page || 1;
		var search = $('#bg360-saas-search').val() || '';
		var status = $('#bg360-saas-status-filter').val() || '';

		$.post(bizgrowth360AppBuilder.ajax_url, {
			action: 'bizgrowth360_saas_get_workspaces',
			nonce: bizgrowth360AppBuilder.nonce,
			page: page,
			search: search,
			status: status
		}, function (res) {
			if (res.success) {
				var m = res.data.metrics;
				$('#saas-total-customers').text(m.total_customers);
				$('#saas-active-customers').text(m.active_customers);
				$('#saas-suspended-customers').text(m.suspended_customers);
				$('#saas-total-apps').text(m.total_apps);

				renderSaasWorkspacesTable(res.data.results.items, res.data.plans);
			}
		});
	}

	function renderSaasWorkspacesTable(items, plans) {
		var tbody = $('#bg360-saas-workspaces-body');
		tbody.empty();

		if (!items || items.length === 0) {
			tbody.html('<tr><td colspan="7" style="text-align:center; padding: 20px; color:#64748B;">No tenant workspaces match the selected criteria.</td></tr>');
			return;
		}

		items.forEach(function (w) {
			var statusClass = w.status === 'active' ? 'background:#DCFCE7;color:#166534;' : 'background:#FEE2E2;color:#991B1B;';
			var statusActionBtn = w.status === 'active'
				? '<button type="button" class="button button-small bg360-saas-status-btn" data-ws-id="' + w.id + '" data-new-status="suspended" style="color:#B91C1C;"><span class="dashicons dashicons-lock"></span> Suspend</button>'
				: '<button type="button" class="button button-small bg360-saas-status-btn" data-ws-id="' + w.id + '" data-new-status="active" style="color:#15803D;"><span class="dashicons dashicons-unlock"></span> Reactivate</button>';

			var planSelectOptions = (plans || []).map(function (p) {
				var selected = parseInt(p.id, 10) === parseInt(w.plan_id, 10) ? ' selected' : '';
				return '<option value="' + p.id + '"' + selected + '>' + escapeHtml(p.name) + '</option>';
			}).join('');

			var planSelect = '<select class="bg360-saas-plan-select" data-ws-id="' + w.id + '" style="font-size:12px; height:28px;">' + planSelectOptions + '</select>';

			var deleteBtn = parseInt(w.id, 10) === 1 ? '' : '<button type="button" class="button button-small bg360-saas-delete-btn" data-ws-id="' + w.id + '" style="color:#B91C1C;" title="Delete Workspace"><span class="dashicons dashicons-trash"></span></button>';

			var row = $(
				'<tr>' +
				'  <td><code>#' + w.id + '</code></td>' +
				'  <td><strong>' + escapeHtml(w.name) + '</strong>' + (w.business_name ? '<br><small style="color:#64748B;">' + escapeHtml(w.business_name) + '</small>' : '') + '</td>' +
				'  <td>' + escapeHtml(w.user_email || '-') + '</td>' +
				'  <td>' + planSelect + '</td>' +
				'  <td><span class="bg360-badge" style="' + statusClass + '">' + escapeHtml(w.status.toUpperCase()) + '</span></td>' +
				'  <td>' + escapeHtml(w.created_at) + '</td>' +
				'  <td><div style="display:flex;gap:6px;align-items:center;">' + statusActionBtn + deleteBtn + '</div></td>' +
				'</tr>'
			);
			tbody.append(row);
		});
	}

})(jQuery);
