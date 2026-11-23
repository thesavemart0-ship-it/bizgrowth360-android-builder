/**
 * Bizgrowth360 App Builder SaaS Customer Portal JavaScript
 */

(function ($) {
	'use strict';

	var activePollTimer = null;
	var currentAppId = 0;

	$(document).ready(function () {
		initAuth();
		if (window.bg360Portal && window.bg360Portal.is_logged_in) {
			initPortalNavigation();
			initAppManagement();
			initBuildCenter();
			initWorkspaceSettings();

			// Initial view from hash or overview
			var initialHash = window.location.hash ? window.location.hash.replace('#', '') : 'overview';
			switchView(initialHash);
		}
	});

	/* -------------------------------------------------------------------------
	 * AUTHENTICATION (Login / Register / Reset)
	 * ------------------------------------------------------------------------- */
	function initAuth() {
		// Tab Switcher in Auth Card
		$('.bg360-auth-tab').on('click', function () {
			var target = $(this).data('auth-target');
			$('.bg360-auth-tab').removeClass('active');
			$(this).addClass('active');

			$('.bg360-auth-form').removeClass('active');
			$('#bg360-' + target + '-form').addClass('active');
			$('#bg360-auth-alert-zone').empty();
		});

		$('#bg360-open-reset-btn').on('click', function (e) {
			e.preventDefault();
			$('.bg360-auth-form').removeClass('active');
			$('#bg360-reset-form').addClass('active');
		});

		$('#bg360-back-to-login-btn').on('click', function (e) {
			e.preventDefault();
			$('.bg360-auth-tab[data-auth-target="login"]').trigger('click');
		});

		// Login Form Submit
		$('#bg360-login-form').on('submit', function (e) {
			e.preventDefault();
			var btn = $('#bg360-login-btn');
			var form = $(this);

			btn.prop('disabled', true).text('Signing in...');
			$('#bg360-auth-alert-zone').empty();

			$.post(bg360Portal.ajax_url, {
				action: 'bg360_saas_login',
				nonce: bg360Portal.auth_nonce,
				username: $('#login_user').val(),
				password: $('#login_pass').val(),
				remember: form.find('input[name="remember"]').is(':checked') ? 1 : 0
			}, function (res) {
				btn.prop('disabled', false).text('Sign In to Workspace');
				if (res.success) {
					showAlert('success', res.data.message, '#bg360-auth-alert-zone');
					setTimeout(function () {
						window.location.href = res.data.redirect_url;
					}, 800);
				} else {
					showAlert('error', res.data.message, '#bg360-auth-alert-zone');
				}
			}).fail(function () {
				btn.prop('disabled', false).text('Sign In to Workspace');
				showAlert('error', 'Connection error. Please try again.', '#bg360-auth-alert-zone');
			});
		});

		// Register Form Submit
		$('#bg360-register-form').on('submit', function (e) {
			e.preventDefault();
			var btn = $('#bg360-register-btn');

			btn.prop('disabled', true).text('Creating Workspace...');
			$('#bg360-auth-alert-zone').empty();

			$.post(bg360Portal.ajax_url, {
				action: 'bg360_saas_register',
				nonce: bg360Portal.auth_nonce,
				full_name: $('#reg_name').val(),
				business_name: $('#reg_business').val(),
				email: $('#reg_email').val(),
				phone: $('#reg_phone').val(),
				password: $('#reg_pass').val(),
				confirm_password: $('#reg_pass_confirm').val()
			}, function (res) {
				btn.prop('disabled', false).text('Register Free Workspace');
				if (res.success) {
					showAlert('success', res.data.message, '#bg360-auth-alert-zone');
					setTimeout(function () {
						window.location.href = res.data.redirect_url;
					}, 1000);
				} else {
					showAlert('error', res.data.message, '#bg360-auth-alert-zone');
				}
			}).fail(function () {
				btn.prop('disabled', false).text('Register Free Workspace');
				showAlert('error', 'Connection error. Please try again.', '#bg360-auth-alert-zone');
			});
		});

		// Password Reset Form Submit
		$('#bg360-reset-form').on('submit', function (e) {
			e.preventDefault();
			var btn = $('#bg360-reset-btn');

			btn.prop('disabled', true).text('Sending...');
			$('#bg360-auth-alert-zone').empty();

			$.post(bg360Portal.ajax_url, {
				action: 'bg360_saas_reset_password',
				nonce: bg360Portal.auth_nonce,
				email: $('#reset_email').val()
			}, function (res) {
				btn.prop('disabled', false).text('Send Reset Instructions');
				if (res.success) {
					showAlert('success', res.data.message, '#bg360-auth-alert-zone');
				} else {
					showAlert('error', res.data.message, '#bg360-auth-alert-zone');
				}
			}).fail(function () {
				btn.prop('disabled', false).text('Send Reset Instructions');
				showAlert('error', 'Connection error. Please try again.', '#bg360-auth-alert-zone');
			});
		});

		// Logout
		$(document).on('click', '#bg360-portal-logout-btn', function (e) {
			e.preventDefault();
			$.post(bg360Portal.ajax_url, {
				action: 'bg360_saas_logout'
			}, function (res) {
				window.location.href = res.data.redirect_url || window.location.href;
			});
		});
	}

	/* -------------------------------------------------------------------------
	 * PORTAL NAVIGATION & VIEW SWITCHING
	 * ------------------------------------------------------------------------- */
	function initPortalNavigation() {
		$('.bg360-portal-nav a').on('click', function (e) {
			e.preventDefault();
			var view = $(this).data('view');
			switchView(view);
		});

		$(document).on('click', '[data-view-target]', function (e) {
			e.preventDefault();
			var view = $(this).data('view-target');
			switchView(view);
		});

		$('#bg360-mobile-menu-toggle').on('click', function () {
			$('.bg360-portal-sidebar').toggleClass('open');
		});

		$(document).on('click', function (e) {
			if (!$(e.target).closest('.bg360-portal-sidebar, #bg360-mobile-menu-toggle').length) {
				$('.bg360-portal-sidebar').removeClass('open');
			}
		});
	}

	function switchView(viewName) {
		viewName = viewName || 'overview';
		$('.bg360-portal-sidebar').removeClass('open');

		$('.bg360-nav-item').removeClass('active');
		$('.bg360-nav-item[data-view="' + viewName + '"]').addClass('active');

		$('.bg360-view-panel').removeClass('active');
		var targetPanel = $('#view-' + viewName);
		if (targetPanel.length) {
			targetPanel.addClass('active');
		} else {
			$('#view-overview').addClass('active');
		}

		window.location.hash = viewName;

		var titles = {
			'overview': 'Dashboard Overview',
			'apps': 'My Applications',
			'create-app': currentAppId > 0 ? 'Edit Application' : 'Create New Application',
			'builds': 'Cloud Build Center',
			'usage': 'Resource Usage & Limits',
			'settings': 'Workspace Settings'
		};
		$('#bg360-page-title').text(titles[viewName] || 'Portal');

		if (viewName === 'create-app' && currentAppId === 0) {
			resetAppForm();
		}

		if (viewName === 'builds') {
			loadBuildHistory();
			checkActiveBuild();
		}
	}

	/* -------------------------------------------------------------------------
	 * APP MANAGEMENT (CRUD)
	 * ------------------------------------------------------------------------- */
	function initAppManagement() {
		// Save App
		$('#bg360-app-form').on('submit', function (e) {
			e.preventDefault();
			var btn = $('#bg360-save-app-btn');
			btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Saving Application...');

			var formData = {
				action: 'bg360_portal_save_app',
				nonce: bg360Portal.portal_nonce,
				app_id: $('#app_id').val(),
				app_name: $('#app_name').val(),
				package_id: $('#package_id').val(),
				website_url: $('#website_url').val(),
				version_name: $('#version_name').val(),
				version_code: $('#version_code').val(),
				primary_color: $('#primary_color').val(),
				status_bar_color: $('#status_bar_color').val(),
				app_icon: $('#app_icon').val(),
				splash_image: $('#splash_image').val(),
				orientation: $('#orientation').val()
			};

			$.post(bg360Portal.ajax_url, formData, function (res) {
				btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Application');
				if (res.success) {
					showAlert('success', res.data.message, '#bg360-portal-notice-zone');
					reloadAppsList();
					switchView('apps');
				} else {
					showAlert('error', res.data.message, '#bg360-portal-notice-zone');
				}
			}).fail(function () {
				btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Save Application');
				showAlert('error', 'Connection error saving application.', '#bg360-portal-notice-zone');
			});
		});

		// Edit App button click
		$(document).on('click', '.bg360-btn-edit-app', function () {
			var appId = $(this).data('app-id');
			loadAppForEdit(appId);
		});

		// Clone App
		$(document).on('click', '.bg360-btn-clone-app', function () {
			var appId = $(this).data('app-id');
			if (!confirm('Are you sure you want to clone this application?')) return;

			$.post(bg360Portal.ajax_url, {
				action: 'bg360_portal_clone_app',
				nonce: bg360Portal.portal_nonce,
				app_id: appId
			}, function (res) {
				if (res.success) {
					showAlert('success', res.data.message, '#bg360-portal-notice-zone');
					reloadAppsList();
				} else {
					showAlert('error', res.data.message, '#bg360-portal-notice-zone');
				}
			});
		});

		// Delete App
		$(document).on('click', '.bg360-btn-delete-app', function () {
			var appId = $(this).data('app-id');
			if (!confirm('Are you sure you want to delete this application? Historical build records will be preserved.')) return;

			$.post(bg360Portal.ajax_url, {
				action: 'bg360_portal_delete_app',
				nonce: bg360Portal.portal_nonce,
				app_id: appId
			}, function (res) {
				if (res.success) {
					showAlert('success', res.data.message, '#bg360-portal-notice-zone');
					reloadAppsList();
				} else {
					showAlert('error', res.data.message, '#bg360-portal-notice-zone');
				}
			});
		});

		// Quick trigger build from app card
		$(document).on('click', '.bg360-trigger-build-btn', function () {
			var appId = $(this).data('app-id');
			$('#build_app_select').val(appId);
			switchView('builds');
		});
	}

	function resetAppForm() {
		currentAppId = 0;
		$('#app_id').val(0);
		$('#bg360-form-heading').html('<span class="dashicons dashicons-plus-alt2"></span> Create New Application');
		$('#bg360-app-form')[0].reset();
		$('#primary_color').val('#7C3AED');
		$('#status_bar_color').val('#6D28D9');
	}

	function loadAppForEdit(appId) {
		$.post(bg360Portal.ajax_url, {
			action: 'bg360_portal_get_apps',
			nonce: bg360Portal.portal_nonce
		}, function (res) {
			if (res.success && res.data.apps) {
				var targetApp = null;
				res.data.apps.forEach(function (app) {
					if (parseInt(app.id, 10) === parseInt(appId, 10)) {
						targetApp = app;
					}
				});

				if (targetApp) {
					currentAppId = targetApp.id;
					$('#app_id').val(targetApp.id);
					$('#app_name').val(targetApp.app_name);
					$('#package_id').val(targetApp.package_id);
					$('#website_url').val(targetApp.website_url);
					$('#version_name').val(targetApp.version_name);
					$('#version_code').val(targetApp.version_code);
					$('#primary_color').val(targetApp.primary_color || '#7C3AED');
					$('#status_bar_color').val(targetApp.status_bar_color || '#6D28D9');
					$('#app_icon').val(targetApp.app_icon || '');
					$('#splash_image').val(targetApp.splash_image || '');
					$('#orientation').val(targetApp.orientation || 'portrait');

					$('#bg360-form-heading').html('<span class="dashicons dashicons-edit"></span> Edit Application: ' + escapeHtml(targetApp.app_name));
					switchView('create-app');
				}
			}
		});
	}

	function reloadAppsList() {
		$.post(bg360Portal.ajax_url, {
			action: 'bg360_portal_get_apps',
			nonce: bg360Portal.portal_nonce
		}, function (res) {
			if (res.success) {
				renderAppsGrid(res.data.apps);
				updateAppSelectDropdown(res.data.apps);
			}
		});
	}

	function renderAppsGrid(apps) {
		var container = $('#bg360-apps-grid-container');
		container.empty();

		if (!apps || apps.length === 0) {
			container.html(
				'<div class="bg360-empty-state" style="grid-column: 1 / -1;">' +
				'  <span class="dashicons dashicons-smartphone"></span>' +
				'  <p>You have no applications yet. Click "Create New Application" above to get started.</p>' +
				'</div>'
			);
			return;
		}

		apps.forEach(function (app) {
			var avatarContent = app.app_icon ? '<img src="' + escapeHtml(app.app_icon) + '" alt="icon" />' : '<span class="dashicons dashicons-smartphone"></span>';
			var card = $(
				'<div class="bg360-app-card" data-app-id="' + app.id + '">' +
				'  <div class="bg360-app-card-top">' +
				'    <div class="bg360-app-avatar" style="background-color:' + (app.primary_color || '#7C3AED') + '">' + avatarContent + '</div>' +
				'    <div class="bg360-app-meta">' +
				'      <h4>' + escapeHtml(app.app_name) + '</h4>' +
				'      <code>' + escapeHtml(app.package_id) + '</code>' +
				'      <a href="' + escapeHtml(app.website_url) + '" target="_blank" class="bg360-app-url">' + escapeHtml(app.website_url) + '</a>' +
				'    </div>' +
				'  </div>' +
				'  <div class="bg360-app-card-info">' +
				'    <span>v' + escapeHtml(app.version_name) + ' (Build ' + app.version_code + ')</span>' +
				'    <span class="bg360-badge bg360-badge-idle">' + escapeHtml(app.orientation) + '</span>' +
				'  </div>' +
				'  <div class="bg360-app-card-actions">' +
				'    <button type="button" class="bg360-btn bg360-btn-primary bg360-btn-small bg360-trigger-build-btn" data-app-id="' + app.id + '"><span class="dashicons dashicons-cloud"></span> Build App</button>' +
				'    <button type="button" class="bg360-btn bg360-btn-secondary bg360-btn-small bg360-btn-edit-app" data-app-id="' + app.id + '"><span class="dashicons dashicons-edit"></span> Edit</button>' +
				'    <button type="button" class="bg360-btn bg360-btn-secondary bg360-btn-small bg360-btn-clone-app" data-app-id="' + app.id + '" title="Clone"><span class="dashicons dashicons-admin-page"></span></button>' +
				'    <button type="button" class="bg360-btn bg360-btn-danger bg360-btn-small bg360-btn-delete-app" data-app-id="' + app.id + '" title="Delete"><span class="dashicons dashicons-trash"></span></button>' +
				'  </div>' +
				'</div>'
			);
			container.append(card);
		});
	}

	function updateAppSelectDropdown(apps) {
		var select = $('#build_app_select');
		select.empty();
		if (apps && apps.length) {
			apps.forEach(function (app) {
				select.append('<option value="' + app.id + '">' + escapeHtml(app.app_name) + ' (' + escapeHtml(app.package_id) + ')</option>');
			});
		}
	}

	/* -------------------------------------------------------------------------
	 * BUILD CENTER & LIVE COMPILATION
	 * ------------------------------------------------------------------------- */
	function initBuildCenter() {
		$('#bg360-portal-build-trigger-form').on('submit', function (e) {
			e.preventDefault();
			var btn = $('#bg360-portal-start-build-btn');
			var appId = $('#build_app_select').val();
			var target = $('#build_target_select').val();

			if (!appId) {
				showAlert('error', 'Please select an application to build.', '#bg360-portal-notice-zone');
				return;
			}

			if (!confirm('Trigger cloud compilation for this application?')) {
				return;
			}

			btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Dispatching to Cloud Compiler...');

			$.post(bg360Portal.ajax_url, {
				action: 'bg360_portal_trigger_build',
				nonce: bg360Portal.portal_nonce,
				app_id: appId,
				target: target
			}, function (res) {
				btn.prop('disabled', false).html('<span class="dashicons dashicons-hammer"></span> Compile & Generate Android Packages');
				if (res.success) {
					showAlert('success', res.data.message, '#bg360-portal-notice-zone');
					showActiveBuild(res.data.build);
					startPollingBuild(res.data.build_id);
				} else {
					showAlert('error', res.data.message, '#bg360-portal-notice-zone');
				}
			}).fail(function () {
				btn.prop('disabled', false).html('<span class="dashicons dashicons-hammer"></span> Compile & Generate Android Packages');
				showAlert('error', 'Connection error triggering build.', '#bg360-portal-notice-zone');
			});
		});
	}

	function checkActiveBuild() {
		$.post(bg360Portal.ajax_url, {
			action: 'bg360_portal_get_build_status',
			nonce: bg360Portal.portal_nonce
		}, function (res) {
			if (res.success && res.data.build) {
				showActiveBuild(res.data.build);
				startPollingBuild(res.data.build.build_id);
			} else {
				$('#bg360-portal-active-build-card').hide();
			}
		});
	}

	function showActiveBuild(b) {
		$('#bg360-portal-active-app-name').html('<strong>' + escapeHtml(b.app_name) + '</strong> (v' + escapeHtml(b.version_name) + ' build ' + b.version_code + ') &bull; <code>' + escapeHtml(b.build_id) + '</code>');
		$('#bg360-portal-active-stage').text(b.stage || b.status);
		$('#bg360-portal-active-build-card').fadeIn();
	}

	function startPollingBuild(buildId) {
		if (activePollTimer) clearInterval(activePollTimer);

		activePollTimer = setInterval(function () {
			$.post(bg360Portal.ajax_url, {
				action: 'bg360_portal_get_build_status',
				nonce: bg360Portal.portal_nonce,
				build_id: buildId
			}, function (res) {
				if (res.success && res.data.build) {
					var b = res.data.build;
					$('#bg360-portal-active-stage').text(b.stage || b.status);

					if (b.status === 'completed') {
						clearInterval(activePollTimer);
						$('#bg360-portal-active-stage').removeClass('bg360-badge-progress').addClass('bg360-badge-success').text('Completed!');
						renderArtifactButtons(b.artifacts, b.build_id);
						loadBuildHistory();
					} else if (b.status === 'failed' || b.status === 'cancelled') {
						clearInterval(activePollTimer);
						$('#bg360-portal-active-stage').removeClass('bg360-badge-progress').addClass('bg360-badge-failure').text(b.status.toUpperCase());
						loadBuildHistory();
					}
				}
			});
		}, 4000);
	}

	function renderArtifactButtons(artifacts, buildId) {
		var container = $('#bg360-portal-artifact-buttons');
		container.empty();

		if (artifacts) {
			var parsed = typeof artifacts === 'string' ? JSON.parse(artifacts) : artifacts;
			if (Array.isArray(parsed) && parsed.length) {
				parsed.forEach(function (art) {
					var dlUrl = bg360Portal.download_base_url + '&build_id=' + encodeURIComponent(buildId) + '&artifact_id=' + encodeURIComponent(art.id) + '&_wpnonce=' + encodeURIComponent(bg360Portal.download_nonce);
					var btn = $(
						'<a href="' + dlUrl + '" class="bg360-btn bg360-btn-primary bg360-btn-small" target="_blank">' +
						'  <span class="dashicons dashicons-download"></span> Download ' + escapeHtml(art.name) + ' (' + (art.size_in_bytes ? Math.round(art.size_in_bytes / 1024 / 1024) + ' MB' : '') + ')' +
						'</a>'
					);
					container.append(btn);
				});
				$('#bg360-portal-artifacts-zone').show();
			}
		}
	}

	function loadBuildHistory() {
		$.post(bg360Portal.ajax_url, {
			action: 'bg360_portal_get_builds',
			nonce: bg360Portal.portal_nonce,
			per_page: 20
		}, function (res) {
			if (res.success && res.data.items) {
				var tbody = $('#bg360-portal-build-history-body');
				tbody.empty();

				if (res.data.items.length === 0) {
					tbody.html('<tr><td colspan="6" style="text-align:center;color:#64748B;">No historical builds yet.</td></tr>');
					return;
				}

				res.data.items.forEach(function (b) {
					var statusClass = 'bg360-badge-idle';
					if (b.status === 'completed') statusClass = 'bg360-badge-success';
					else if (b.status === 'failed') statusClass = 'bg360-badge-failure';
					else if (['queued', 'in_progress', 'dispatching'].indexOf(b.status) !== -1) statusClass = 'bg360-badge-progress';

					var dlHtml = '-';
					if (b.artifacts) {
						var parsed = typeof b.artifacts === 'string' ? JSON.parse(b.artifacts) : b.artifacts;
						if (Array.isArray(parsed) && parsed.length) {
							dlHtml = parsed.map(function (art) {
								var dlUrl = bg360Portal.download_base_url + '&build_id=' + encodeURIComponent(b.build_id) + '&artifact_id=' + encodeURIComponent(art.id) + '&_wpnonce=' + encodeURIComponent(bg360Portal.download_nonce);
								return '<a href="' + dlUrl + '" class="bg360-link-small" target="_blank"><span class="dashicons dashicons-download"></span> ' + escapeHtml(art.name) + '</a>';
							}).join('<br>');
						}
					}

					var row = $(
						'<tr>' +
						'  <td><code>' + escapeHtml(b.build_id) + '</code></td>' +
						'  <td><strong>' + escapeHtml(b.app_name) + '</strong></td>' +
						'  <td><code>' + escapeHtml(b.build_type.toUpperCase()) + '</code></td>' +
						'  <td><span class="bg360-badge ' + statusClass + '">' + escapeHtml(b.status) + '</span></td>' +
						'  <td>' + escapeHtml(b.created_at) + '</td>' +
						'  <td>' + dlHtml + '</td>' +
						'</tr>'
					);
					tbody.append(row);
				});
			}
		});
	}

	/* -------------------------------------------------------------------------
	 * WORKSPACE SETTINGS
	 * ------------------------------------------------------------------------- */
	function initWorkspaceSettings() {
		$('#bg360-workspace-settings-form').on('submit', function (e) {
			e.preventDefault();
			var btn = $('#bg360-save-ws-btn');
			var statusText = $('#bg360-ws-status-text');

			btn.prop('disabled', true).text('Updating...');
			statusText.text('');

			$.post(bg360Portal.ajax_url, {
				action: 'bg360_portal_update_workspace',
				nonce: bg360Portal.portal_nonce,
				name: $('#ws_name').val(),
				business_name: $('#ws_business').val(),
				phone: $('#ws_phone').val()
			}, function (res) {
				btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Update Workspace Profile');
				if (res.success) {
					statusText.text(res.data.message).css('color', '#10B981');
					setTimeout(function () { statusText.fadeOut(500, function () { $(this).text('').show(); }); }, 3000);
				} else {
					statusText.text(res.data.message).css('color', '#EF4444');
				}
			}).fail(function () {
				btn.prop('disabled', false).html('<span class="dashicons dashicons-saved"></span> Update Workspace Profile');
				statusText.text('Connection error.').css('color', '#EF4444');
			});
		});
	}

	/* -------------------------------------------------------------------------
	 * HELPERS
	 * ------------------------------------------------------------------------- */
	function showAlert(type, message, targetSelector) {
		targetSelector = targetSelector || '#bg360-portal-notice-zone';
		var alertClass = 'bg360-alert bg360-alert-' + type;
		var icon = type === 'success' ? 'yes' : (type === 'error' ? 'warning' : 'info');
		var html = $(
			'<div class="' + alertClass + '">' +
			'  <span class="dashicons dashicons-' + icon + '"></span>' +
			'  <span>' + escapeHtml(message) + '</span>' +
			'</div>'
		);
		$(targetSelector).empty().append(html);
		$('html, body').animate({ scrollTop: $(targetSelector).offset().top - 80 }, 300);
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
