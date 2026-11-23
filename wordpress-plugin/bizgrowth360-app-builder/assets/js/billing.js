/**
 * Bizgrowth360 billing pricing, checkout and subscription dashboard.
 */
(function ($) {
	'use strict';

	var B = window.bizgrowth360Billing || {};
	var state = {
		planId: 0,
		planName: '',
		interval: 'monthly',
		monthly: 0,
		annual: 0,
		mode: 'subscribe',
		discount: 0,
		coupon: ''
	};

	function money(amount) {
		var symbol = B.currency_symbol || 'â‚¹';
		return symbol + Number(amount || 0).toFixed(2);
	}

	function message(text, type, selector) {
		var el = $(selector || '#bg360-checkout-message');
		el.removeClass('is-error is-success').addClass(type === 'error' ? 'is-error' : 'is-success').text(text).show();
	}

	function clearMessage(selector) {
		$(selector || '#bg360-checkout-message').hide().text('');
	}

	function priceForInterval() {
		return state.interval === 'annual' ? Number(state.annual || 0) : Number(state.monthly || 0);
	}

	function updateTotals() {
		var price = priceForInterval();
		var discount = Math.min(Number(state.discount || 0), price);
		var total = Math.max(0, price - discount);
		$('#bg360-checkout-subtotal').text(money(price));
		$('#bg360-checkout-discount').text('-' + money(discount));
		$('#bg360-checkout-total').text(money(total));
		$('#bg360-checkout-interval').text(state.interval === 'annual' ? B.strings.annual : B.strings.monthly);
		$('#bg360-checkout-plan').text(state.planName);
	}

	function selectInterval(interval) {
		state.interval = interval === 'annual' ? 'annual' : 'monthly';
		$('.bg360-interval-btn').removeClass('is-active').filter('[data-interval="' + state.interval + '"]').addClass('is-active');
		$('.bg360-plan-card').each(function () {
			var card = $(this);
			var price = state.interval === 'annual' ? card.data('annual') : card.data('monthly');
			card.find('.bg360-plan-price .amount').text(money(price));
			card.find('.bg360-plan-price small').text(state.interval === 'annual' ? B.strings.per_year : B.strings.per_month);
		});
		if ($('#bg360-checkout-modal').hasClass('is-open')) {
			state.monthly = Number($('.bg360-plan-card[data-plan-id="' + state.planId + '"]').data('monthly') || 0);
			state.annual = Number($('.bg360-plan-card[data-plan-id="' + state.planId + '"]').data('annual') || 0);
			state.discount = 0;
			state.coupon = '';
			$('#bg360-checkout-coupon').val('');
			updateTotals();
		}
	}

	function openCheckout(planId, mode) {
		var card = $('.bg360-plan-card[data-plan-id="' + planId + '"]');
		if (!card.length) return;
		state.planId = Number(planId);
		state.planName = String(card.data('name') || '');
		state.monthly = Number(card.data('monthly') || 0);
		state.annual = Number(card.data('annual') || 0);
		state.mode = mode || 'subscribe';
		state.discount = 0;
		state.coupon = '';
		$('#bg360-checkout-coupon').val('');
		clearMessage();
		updateTotals();
		$('#bg360-checkout-modal').addClass('is-open').attr('aria-hidden', 'false');
	}

	function closeCheckout() {
		$('#bg360-checkout-modal').removeClass('is-open').attr('aria-hidden', 'true');
	}

	function loadRazorpay() {
		if (window.Razorpay) return Promise.resolve();
		return new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = 'https://checkout.razorpay.com/v1/checkout.js';
			script.onload = resolve;
			script.onerror = function () { reject(new Error('Could not load Razorpay Checkout.')); };
			document.head.appendChild(script);
		});
	}

	function verifyPayment(transactionId, payload) {
		clearMessage();
		$('#bg360-checkout-submit').prop('disabled', true).text(B.strings.verifying);
		$.post(B.ajax_url, $.extend({
			action: 'bizgrowth360_billing_verify',
			nonce: B.nonce,
			transaction_id: transactionId
		}, payload || {})).done(function (res) {
			$('#bg360-checkout-submit').prop('disabled', false).text(B.strings.pay_now);
			if (res.success) {
				message(res.data.message || B.strings.payment_success, 'success');
				setTimeout(function () { window.location.reload(); }, 1200);
			} else {
				message((res.data && res.data.message) || B.strings.payment_failed, 'error');
			}
		}).fail(function (xhr) {
			$('#bg360-checkout-submit').prop('disabled', false).text(B.strings.pay_now);
			var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : B.strings.payment_failed;
			message(msg, 'error');
		});
	}

	function handleCheckoutData(data) {
		if (!data) {
			message(B.strings.checkout_failed, 'error');
			return;
		}
		if (data.trial_started || data.free_activated || data.changed || data.scheduled) {
			message(data.message || B.strings.success, 'success');
			setTimeout(function () { window.location.reload(); }, 1400);
			return;
		}
		if (data.checkout_url) {
			window.location.href = data.checkout_url;
			return;
		}
		if (data.order_id && data.gateway === 'razorpay') {
			loadRazorpay().then(function () {
				var config = data.public_config || {};
				var options = {
					key: config.key_id,
					amount: Math.round(Number(data.amount) * 100),
					currency: data.currency || 'INR',
					name: config.name || document.title,
					description: data.description || state.planName,
					order_id: data.order_id,
					prefill: data.prefill || {},
					theme: { color: '#2563EB' },
					handler: function (response) {
						verifyPayment(data.transaction_id, {
							razorpay_order_id: response.razorpay_order_id,
							razorpay_payment_id: response.razorpay_payment_id,
							razorpay_signature: response.razorpay_signature
						});
					},
					modal: {
						ondismiss: function () {
							message(B.strings.cancelled, 'error');
						}
					}
				};
				var rzp = new window.Razorpay(options);
				rzp.on('payment.failed', function (response) {
					message((response.error && response.error.description) || B.strings.payment_failed, 'error');
				});
				rzp.open();
			}).catch(function (err) {
				message(err.message || B.strings.checkout_failed, 'error');
			});
			return;
		}
		message(B.strings.checkout_failed, 'error');
	}

	function submitCheckout() {
		clearMessage();
		var action = state.mode === 'change' ? 'bizgrowth360_billing_plan_change' : 'bizgrowth360_billing_checkout';
		$('#bg360-checkout-submit').prop('disabled', true).text(B.strings.creating_order);
		$.post(B.ajax_url, {
			action: action,
			nonce: B.nonce,
			plan_id: state.planId,
			interval: state.interval,
			coupon: state.coupon,
			gateway: $('#bg360-checkout-gateway').val()
		}).done(function (res) {
			$('#bg360-checkout-submit').prop('disabled', false).text(B.strings.pay_now);
			if (!res.success) {
				message((res.data && res.data.message) || B.strings.checkout_failed, 'error');
				return;
			}
			if (res.data.checkout) {
				handleCheckoutData(res.data.checkout);
			} else {
				handleCheckoutData(res.data);
			}
		}).fail(function (xhr) {
			$('#bg360-checkout-submit').prop('disabled', false).text(B.strings.pay_now);
			var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : B.strings.checkout_failed;
			message(msg, 'error');
		});
	}

	function applyCoupon() {
		var code = $('#bg360-checkout-coupon').val();
		if (!code) return;
		clearMessage();
		$.post(B.ajax_url, {
			action: 'bizgrowth360_billing_coupon',
			nonce: B.nonce,
			plan_id: state.planId,
			interval: state.interval,
			coupon: code
		}).done(function (res) {
			if (res.success) {
				state.coupon = code;
				state.discount = Number(res.data.discount || 0);
				updateTotals();
				message(res.data.message || B.strings.coupon_applied, 'success');
			} else {
				state.coupon = '';
				state.discount = 0;
				updateTotals();
				message((res.data && res.data.message) || B.strings.coupon_invalid, 'error');
			}
		}).fail(function (xhr) {
			var msg = xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : B.strings.coupon_invalid;
			message(msg, 'error');
		});
	}

	function cancelSubscription() {
		if (!window.confirm(B.strings.confirm_cancel)) return;
		$.post(B.ajax_url, { action: 'bizgrowth360_billing_cancel', nonce: B.nonce }).done(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				window.alert((res.data && res.data.message) || B.strings.error);
			}
		});
	}

	function resumeSubscription() {
		$.post(B.ajax_url, { action: 'bizgrowth360_billing_resume', nonce: B.nonce }).done(function (res) {
			if (res.success) {
				window.location.reload();
			} else {
				window.alert((res.data && res.data.message) || B.strings.error);
			}
		});
	}

	function saveProfile(e) {
		e.preventDefault();
		var $form = $(this);
		var $msg = $('#bg360-profile-message');
		$msg.removeClass('is-error is-success').hide();
		$.post(B.ajax_url, $form.serialize() + '&action=bizgrowth360_billing_profile&nonce=' + encodeURIComponent(B.nonce)).done(function (res) {
			if (res.success) {
				$msg.addClass('is-success').text(res.data.message || B.strings.saved).show();
			} else {
				$msg.addClass('is-error').text((res.data && res.data.message) || B.strings.error).show();
			}
		}).fail(function () {
			$msg.addClass('is-error').text(B.strings.error).show();
		});
	}

	$(function () {
		$('.bg360-interval-btn').on('click', function () {
			selectInterval($(this).data('interval'));
		});

		$('.bg360-select-plan').on('click', function (e) {
			e.preventDefault();
			if (!B.logged_in) {
				window.location.href = B.login_url;
				return;
			}
			openCheckout($(this).data('plan-id'), $(this).data('mode') || 'subscribe');
		});

		$('.bg360-billing-modal-close, .bg360-billing-modal').on('click', function (e) {
			if (e.target === this || $(this).hasClass('bg360-billing-modal-close')) {
				closeCheckout();
			}
		});

		$('#bg360-checkout-submit').on('click', submitCheckout);
		$('#bg360-apply-coupon').on('click', applyCoupon);
		$('.bg360-cancel-subscription').on('click', cancelSubscription);
		$('.bg360-resume-subscription').on('click', resumeSubscription);
		$('#bg360-billing-profile-form').on('submit', saveProfile);

		// Stripe returns with a session ID; verify it server-side.
		var params = new URLSearchParams(window.location.search);
		if (params.get('bizgrowth360_checkout') === 'success' && params.get('session_id') && params.get('bizgrowth360_tx')) {
			verifyPayment(params.get('bizgrowth360_tx'), { session_id: params.get('session_id') });
		}

		selectInterval('monthly');
	});
})(jQuery);
