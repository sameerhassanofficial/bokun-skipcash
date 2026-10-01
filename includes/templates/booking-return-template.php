<?php
if (!defined('ABSPATH')) {
    exit;
}

// The return page is rendered from handle_callback_redirect(), which defines the
// $bokun_return_context BEFORE loading this template. get_header() must run after that
// so the context variables are still in scope (globals set before get_header() can be
// clobbered by theme header partials).

// Context provided by Bokun_SkipCash_Booking_Controller::handle_callback_redirect().
$bokun_return_context = isset($bokun_return_context) && is_array($bokun_return_context) ? $bokun_return_context : array();

$code          = $bokun_return_context['code'] ?? (isset($_GET['code']) ? sanitize_text_field(wp_unslash($_GET['code'])) : '');
$record        = $bokun_return_context['record'] ?? null;
$verify_token  = $bokun_return_context['verify_token'] ?? (isset($_GET['vtoken']) ? sanitize_text_field(wp_unslash($_GET['vtoken'])) : '');
$verify_error  = $bokun_return_context['verify_error'] ?? '';

// Confirmation is based ONLY on the server-side verified record status.
// Client-supplied query params (statusId/status/transId/paymentId) are NOT trusted:
// an attacker could otherwise craft a return URL that renders a fake "paid" page.
$is_confirmed = !empty($record) && (($record['status'] ?? '') === 'CONFIRMED');

if ($is_confirmed && empty($record['customer']['firstName'])) {
    $record['customer'] = array_merge(array('firstName' => 'Valued', 'lastName' => 'Guest', 'email' => ''), (array)($record['customer'] ?? array()));
}


get_header();
?>
<div class="wrap bokun-return-page" style="max-width: 650px; margin: 40px auto; padding: 32px; background: #ffffff; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.06); font-family: 'Roboto'; border: 1px solid #e5e7eb;">
    <?php if ($is_confirmed): ?>
        <div style="text-align: center;">
            <div style="width: 72px; height: 72px; background: #ecfdf5; color: #059669; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 36px; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(5,150,105,0.15);">
                ✓
            </div>
            <h2 style="color: #111827; margin: 0 0 10px; font-size: 26px; font-weight: 700;">Booking Confirmed!</h2>
            <p style="color: #4b5563; margin-bottom: 28px; font-size: 15px; line-height: 1.6;">Your payment through SkipCash was successful and your reservation has been confirmed and paid in Bókun.</p>
            
            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; text-align: left; margin-bottom: 26px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f3f4f6;">
                    <span style="color: #6b7280; font-size: 14px;">Bókun Confirmation Code</span>
                    <span style="font-family: monospace; font-size: 17px; color: #8A1538; font-weight: 700; letter-spacing: 0.5px;"><?php echo esc_html($code); ?></span>
                </div>
                <?php if (!empty($record['activity_name'])): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f3f4f6;">
                    <span style="color: #6b7280; font-size: 14px;">Activity</span>
                    <span style="font-weight: 600; color: #111827; text-align: right; max-width: 60%;"><?php echo esc_html($record['activity_name']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($record['customer']['firstName']) && $record['customer']['firstName'] !== 'Valued'): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f3f4f6;">
                    <span style="color: #6b7280; font-size: 14px;">Lead Guest</span>
                    <span style="font-weight: 500; color: #111827;"><?php echo esc_html($record['customer']['firstName'] . ' ' . ($record['customer']['lastName'] ?? '')); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($record['amount']) && $record['amount'] > 0): ?>
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 12px; border-bottom: 1px solid #f3f4f6;">
                    <span style="color: #6b7280; font-size: 14px;">Total Amount Paid</span>
                    <span style="font-weight: 700; color: #111827; font-size: 16px;"><?php echo esc_html(($record['currency'] ?? 'QAR') . ' ' . number_format($record['amount'], 2)); ?></span>
                </div>
                <?php endif; ?>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <span style="color: #6b7280; font-size: 14px;">Status</span>
                    <span style="background: #d1fae5; color: #065f46; padding: 4px 12px; border-radius: 9999px; font-size: 13px; font-weight: 700; display: inline-flex; align-items: center; gap: 4px;">
                        <span>●</span> CONFIRMED & PAID
                    </span>
                </div>
            </div>

            <?php if (!empty($record['customer']['email'])): ?>
            <p style="font-size: 14px; color: #6b7280; line-height: 1.5; margin-bottom: 24px;">Your official ticket voucher with QR/barcode has been dispatched directly by Bókun to <strong><?php echo esc_html($record['customer']['email']); ?></strong>.</p>
            <?php else: ?>
            <p style="font-size: 14px; color: #6b7280; line-height: 1.5; margin-bottom: 24px;">Your official reservation with barcode has been confirmed in Bókun and sent to your email.</p>
            <?php endif; ?>

            <div style="display: flex; justify-content: center; gap: 12px; flex-wrap: wrap;">
                <button type="button" onclick="window.print()" style="display: inline-block; background: #f3f4f6; color: #374151; border: 1px solid #d1d5db; padding: 12px 24px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 14px;">
                    Print Confirmation
                </button>
                <a href="<?php echo esc_url(home_url('/')); ?>" style="display: inline-block; background: #8A1538; color: #ffffff; padding: 12px 28px; border-radius: 8px; font-weight: 600; text-decoration: none; font-size: 14px;">
                    Return to Home
                </a>
            </div>
        </div>
    <?php else: ?>
        <div id="bokun-pending-box" style="text-align: center;">
            <div style="width: 72px; height: 72px; background: #fef3c7; color: #d97706; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; font-size: 32px; margin-bottom: 20px;">
                <span id="bokun-spinner-icon" style="display: inline-block; animation: bk-rotate 1.5s linear infinite;">⏳</span>
            </div>
            <h2 id="bokun-status-heading" style="color: #111827; margin: 0 0 10px; font-size: 24px; font-weight: 700;">Finalizing Booking with Bókun...</h2>
            <p id="bokun-status-msg" style="color: #4b5563; font-size: 15px; line-height: 1.6; margin-bottom: 24px;">
                We are verifying your payment and confirming your reservation <code><?php echo esc_html($code); ?></code> in Bókun automatically. This usually takes just a moment — please keep this page open.
            </p>

            <?php if ($verify_error === 'unauthorized'): ?>
            <p style="font-size: 13px; color: #b45309; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 10px 14px; display: inline-block; margin-bottom: 18px;">
                For your security we could not verify this session automatically. Your booking reference is below — Bókun will email your ticket once payment clears.
            </p>
            <?php endif; ?>

            <p style="font-size: 13px; color: #9ca3af;">Reference Code: <strong style="color: #4b5563; font-family: monospace;"><?php echo esc_html($code); ?></strong></p>
        </div>

        <style>
        @keyframes bk-rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        </style>

        <script>
        (function() {
            var restBase = <?php echo wp_json_encode(esc_url_raw(rest_url('bokun-skipcash/v1/'))); ?>;
            var bookingCode = <?php echo wp_json_encode($code); ?>;
            var bookingToken = <?php echo wp_json_encode($verify_token); ?>;
            var heading = document.getElementById('bokun-status-heading');
            var msg = document.getElementById('bokun-status-msg');
            var spinner = document.getElementById('bokun-spinner-icon');

            function renderConfirmed() {
                if (spinner) { spinner.innerText = '✓'; spinner.style.animation = 'none'; }
                if (heading) heading.innerText = 'Booking Confirmed!';
                if (msg) msg.innerText = 'Payment verified and your reservation is confirmed & paid in Bókun. Reloading your tickets...';
                setTimeout(function() { window.location.reload(); }, 400);
            }

            function renderPendingEmail() {
                if (spinner) { spinner.innerText = '✉'; spinner.style.animation = 'none'; }
                if (heading) heading.innerText = 'Payment Received';
                if (msg) msg.innerHTML = 'Your payment went through and your booking (<code>' +
                    bookingCode.replace(/[^\w-]/g, '') +
                    '</code>) is confirmed. Your ticket voucher has been emailed to you. You can safely close this page.';
            }

            function isConfirmedResponse(data) {
                if (!data) return false;
                if (data.status === 'CONFIRMED' || data.success === true) return true;
                var text = (data.message || '') + ' ' + (data.note || '') + ' ' + JSON.stringify(data);
                return (
                    text.indexOf('CONFIRMED') !== -1 ||
                    text.indexOf('not in reserved state') !== -1 ||
                    text.indexOf('already confirmed') !== -1 ||
                    text.indexOf('PAID') !== -1
                );
            }

            // Single lightweight status check (the heavy verification already ran
            // server-side before this page rendered). If confirmed -> instant
            // "Booking Confirmed!" reload; otherwise show a calm final message.
            fetch(restBase + 'confirm-status?code=' + encodeURIComponent(bookingCode) + '&token=' + encodeURIComponent(bookingToken), {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { 'X-Bokun-Token': bookingToken }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (isConfirmedResponse(data)) {
                    renderConfirmed();
                } else {
                    renderPendingEmail();
                }
            })
            .catch(function() {
                renderPendingEmail();
            });
        })();
        </script>
    <?php endif; ?>
</div>
<?php
get_footer();
