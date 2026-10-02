<?php
require_once __DIR__ . '/auth.php';
requireLogin();
require_once __DIR__ . '/layout.php';
renderHeader('Installation Guide', 'installation');
?>

<!-- How It Works -->
<div class="mb-5">
    <h5 class="fw-bold text-success mb-3"><i class="bi bi-gear-wide-connected"></i> How It Works</h5>
    <div class="row g-4">
        <div class="col-md-4">
            <div class="text-center p-4 bg-white rounded-4 shadow-sm">
                <div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:60px;height:60px;">
                    <i class="bi bi-cloud-arrow-up text-success" style="font-size:1.5rem;"></i>
                </div>
                <h6 class="mt-3">1. Send via API</h6>
                <p class="text-muted small mb-0">Your billing system sends a message request to the server via a simple URL call.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-center p-4 bg-white rounded-4 shadow-sm">
                <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:60px;height:60px;">
                    <i class="bi bi-phone text-primary" style="font-size:1.5rem;"></i>
                </div>
                <h6 class="mt-3">2. Phone Picks It Up</h6>
                <p class="text-muted small mb-0">The FreeISP Replies app on your Android phone polls the server and picks up pending messages.</p>
            </div>
        </div>
        <div class="col-md-4">
            <div class="text-center p-4 bg-white rounded-4 shadow-sm">
                <div class="rounded-circle bg-success bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:60px;height:60px;">
                    <i class="bi bi-whatsapp text-success" style="font-size:1.5rem;"></i>
                </div>
                <h6 class="mt-3">3. Delivered via WhatsApp</h6>
                <p class="text-muted small mb-0">The app sends the message through your actual WhatsApp account — fully automatic.</p>
            </div>
        </div>
    </div>
</div>

<!-- Download -->
<div class="card mb-4">
    <div class="card-body text-center py-4">
        <h5 class="fw-bold"><i class="bi bi-download text-success"></i> Download the App</h5>
        <p class="text-muted">Download the FreeISP Replies Android app to get started.</p>
        <a href="https://whatsappreplies.ispledger.com/whatsapp.apk" class="btn btn-wa btn-lg px-5">
            <i class="bi bi-download"></i> Download APK
        </a>
        <div class="mt-2 small text-muted">
            <i class="bi bi-android2"></i> Android 8.0+ required &bull; ~6 MB
        </div>
    </div>
</div>

<!-- Installation Steps -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-list-ol"></i> Installation Steps</div>
    <div class="card-body">

        <div class="alert alert-warning py-2 small">
            <i class="bi bi-info-circle-fill"></i>
            <strong>Note:</strong> This app is distributed as an APK file (not from Play Store). You will need to allow installation from unknown sources.
        </div>

        <!-- Step 1 -->
        <div class="d-flex align-items-start gap-3 mb-4">
            <span class="badge bg-success rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;flex-shrink:0;">1</span>
            <div>
                <h6 class="fw-bold mb-1">Download the APK</h6>
                <p class="mb-1">Open this page on your Android phone and tap the Download button above, or visit:</p>
                <code class="d-block p-2 bg-light rounded small">https://whatsappreplies.ispledger.com/whatsapp.apk</code>
            </div>
        </div>

        <!-- Step 2 -->
        <div class="d-flex align-items-start gap-3 mb-4">
            <span class="badge bg-success rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;flex-shrink:0;">2</span>
            <div>
                <h6 class="fw-bold mb-1">Disable Google Play Protect</h6>
                <p class="mb-1">Google Play Protect may block installation of apps downloaded outside the Play Store. You <strong>must</strong> disable it first:</p>
                <ol class="mb-2 small">
                    <li>Open the <strong>Google Play Store</strong> app</li>
                    <li>Tap your <strong>profile icon</strong> (top-right corner)</li>
                    <li>Tap <strong>"Play Protect"</strong></li>
                    <li>Tap the <strong>gear icon (<i class="bi bi-gear"></i>)</strong> in the top-right</li>
                    <li>Turn <strong>OFF</strong> "Scan apps with Play Protect"</li>
                    <li>Confirm by tapping <strong>"Turn off"</strong></li>
                </ol>
                <div class="alert alert-info py-2 small mb-0">
                    <i class="bi bi-lightbulb-fill"></i> You can re-enable Play Protect after installing. The app is safe — it just isn't on the Play Store.
                </div>
            </div>
        </div>

        <!-- Step 3 -->
        <div class="d-flex align-items-start gap-3 mb-4">
            <span class="badge bg-success rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;flex-shrink:0;">3</span>
            <div>
                <h6 class="fw-bold mb-1">Allow Installation from Unknown Sources</h6>
                <p class="mb-1">When you try to install, Android will warn you about unknown sources:</p>
                <ul class="mb-0 small">
                    <li>Tap <strong>"Settings"</strong> on the warning popup</li>
                    <li>Enable <strong>"Allow from this source"</strong> for your browser (Chrome, etc.)</li>
                    <li>Go back and tap <strong>"Install"</strong></li>
                </ul>
            </div>
        </div>

        <!-- Step 4 -->
        <div class="d-flex align-items-start gap-3 mb-4">
            <span class="badge bg-success rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;flex-shrink:0;">4</span>
            <div>
                <h6 class="fw-bold mb-1">Allow Restricted Settings (Android 13+)</h6>
                <p class="mb-1">This is <strong>required</strong> before you can enable the Accessibility Service and Notification Listener:</p>
                <ol class="mb-2 small">
                    <li>Go to your phone's <strong>Settings</strong></li>
                    <li>Tap <strong>Apps</strong> (or "Apps & notifications")</li>
                    <li>Find and tap <strong>FreeISP Replies</strong> in the app list</li>
                    <li>Tap the <strong>three dots menu (<i class="bi bi-three-dots-vertical"></i>)</strong> in the top-right corner of the screen</li>
                    <li>Tap <strong>"Allow restricted settings"</strong></li>
                    <li>Confirm with your <strong>PIN, pattern, or fingerprint</strong></li>
                </ol>
                <div class="alert alert-warning py-2 small mb-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>Important:</strong> You MUST navigate manually to <strong>Settings > Apps > FreeISP Replies</strong> to see the three dots menu. Opening app info directly from the app or from "App Settings" shortcut may NOT show the three dots menu on some phones (Samsung, Xiaomi, etc.).
                </div>
                <div class="alert alert-info py-2 small mb-0">
                    <i class="bi bi-lightbulb-fill"></i>
                    <strong>Why?</strong> Android 13+ blocks certain permissions for apps installed outside the Play Store. This is a one-time step and does not affect your phone's security.
                </div>
            </div>
        </div>

        <!-- Step 5 -->
        <div class="d-flex align-items-start gap-3 mb-4">
            <span class="badge bg-success rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;flex-shrink:0;">5</span>
            <div>
                <h6 class="fw-bold mb-1">Open the App & Enter Server Details</h6>
                <p class="mb-1">Launch FreeISP Replies and fill in:</p>
                <table class="table table-sm table-bordered small mb-1">
                    <tr><td class="fw-bold" style="width:130px;">Server URL</td><td><code>https://whatsappreplies.ispledger.com</code> (must start with https://)</td></tr>
                    <tr><td class="fw-bold">API Key</td><td>Copy from the <strong>Dashboard</strong> page (top of page)</td></tr>
                    <tr><td class="fw-bold">Poll Interval</td><td>How often to check for new messages (default: 5 seconds)</td></tr>
                </table>
                <p class="mb-0 small">Tap <strong>"Save Settings"</strong>, then <strong>"Test Connection"</strong> to verify. The WhatsApp type (Personal or Business) is controlled from the dashboard.</p>
            </div>
        </div>

        <!-- Step 6 -->
        <div class="d-flex align-items-start gap-3 mb-4">
            <span class="badge bg-success rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;flex-shrink:0;">6</span>
            <div>
                <h6 class="fw-bold mb-1">Enable Required Permissions</h6>
                <p class="mb-2">The app needs three permissions. Enable them from within the app:</p>
                <table class="table table-sm table-bordered small">
                    <thead class="table-light">
                        <tr><th>Permission</th><th>What It Does</th><th>How to Enable</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><i class="bi bi-hand-index text-primary"></i> <strong>Accessibility</strong></td>
                            <td>Auto-taps the send button in WhatsApp for new contacts</td>
                            <td>Tap "Enable Accessibility" in app &rarr; Find <strong>FreeISP Replies</strong> &rarr; Toggle ON</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-bell text-info"></i> <strong>Notification Listener</strong></td>
                            <td>Enables 100% background sending by using WhatsApp's notification reply</td>
                            <td>Tap "Enable Notification Listener" in app &rarr; Find <strong>FreeISP Replies</strong> &rarr; Toggle ON</td>
                        </tr>
                        <tr>
                            <td><i class="bi bi-battery-charging text-warning"></i> <strong>Battery</strong></td>
                            <td>Prevents Android from killing the app in background</td>
                            <td>Tap "Disable Battery Optimization" in app &rarr; Allow</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Step 7 -->
        <div class="d-flex align-items-start gap-3 mb-3">
            <span class="badge bg-success rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;flex-shrink:0;">7</span>
            <div>
                <h6 class="fw-bold mb-1">Start the Relay Service</h6>
                <p class="mb-1">Tap the green <strong>"Start Relay Service"</strong> button. The app will poll for messages and send them automatically.</p>
                <div class="alert alert-success py-2 small mb-0">
                    <i class="bi bi-check-circle-fill"></i> <strong>Done!</strong> You can minimize the app and use your phone normally. Messages will be sent in the background.
                </div>
                <div class="alert alert-warning py-2 small mt-2 mb-0">
                    <i class="bi bi-arrow-repeat"></i> <strong>After updating the app:</strong> Open the app, tap <strong>"Stop Relay Service"</strong> and then tap <strong>"Start Relay Service"</strong> again. This ensures the new version is fully active.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- What to Expect -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-eye"></i> What to Expect</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="p-3 bg-light rounded-3">
                    <h6 class="text-success"><i class="bi bi-check-circle-fill"></i> Known Contacts</h6>
                    <p class="small mb-0">Messages are sent <strong>100% in the background</strong>. No WhatsApp UI appears, no interruption. This uses WhatsApp's notification reply mechanism.</p>
                </div>
            </div>
            <div class="col-md-6">
                <div class="p-3 bg-light rounded-3">
                    <h6 class="text-primary"><i class="bi bi-lightning-fill"></i> New Contacts</h6>
                    <p class="small mb-0">WhatsApp opens for <strong>1-2 seconds</strong> to send the message, then closes. This only happens once per contact — after that, it's fully silent.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Important Notes -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-exclamation-triangle"></i> Important Notes</div>
    <div class="card-body">
        <ul class="mb-0 small">
            <li class="mb-2"><strong>Phone must be online:</strong> Your phone needs a stable internet connection (Wi-Fi recommended) at all times for messages to be sent.</li>
            <li class="mb-2"><strong>WhatsApp opens briefly for new contacts:</strong> For contacts who have never messaged your number, WhatsApp will briefly flash open to send. Use a <strong>dedicated phone</strong> to avoid interruptions.</li>
            <li class="mb-2"><strong>Low ban risk with moderate usage:</strong> This sends messages through your real WhatsApp account, so WhatsApp is unlikely to ban you for moderate volumes. Best for PPPoE customer notifications.</li>
            <li class="mb-2"><strong>Caution with hotspot/high volume:</strong> If you have many hotspot customers, the message volume may be too high and WhatsApp could flag your account. Use this feature cautiously for high-volume scenarios.</li>
            <li><strong>Dedicated phone recommended:</strong> A basic Android 8.0+ phone with Wi-Fi, always charging, is the ideal setup for ISP use.</li>
        </ul>
    </div>
</div>

<!-- Troubleshooting -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-wrench-adjustable"></i> Troubleshooting</div>
    <div class="card-body p-0">
        <div class="accordion accordion-flush" id="faqAccordion">
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed small fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                        "Restricted setting" error when enabling Accessibility Service
                    </button>
                </h2>
                <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body small">
                        <ol>
                            <li>Open <strong>Settings</strong> &rarr; <strong>Apps</strong></li>
                            <li>Find and tap <strong>FreeISP Replies</strong></li>
                            <li>Tap the <strong>three-dot menu (<i class="bi bi-three-dots-vertical"></i>)</strong> in the top-right</li>
                            <li>Tap <strong>"Allow restricted settings"</strong></li>
                            <li>Enter your PIN/fingerprint to confirm</li>
                            <li>Go back and enable Accessibility Service again</li>
                        </ol>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed small fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                        Messages stuck on "pending" status
                    </button>
                </h2>
                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body small">
                        <ul class="mb-0">
                            <li>Make sure the relay service is <strong>running</strong> (green status in the app)</li>
                            <li>Check your <strong>internet connection</strong> on the phone</li>
                            <li>Verify the <strong>Server URL</strong> and <strong>API Key</strong> are correct (use "Test Connection")</li>
                            <li>Make sure <strong>Battery Optimization</strong> is disabled for FreeISP Replies</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed small fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                        Service stops working after some time
                    </button>
                </h2>
                <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body small">
                        <p>Some manufacturers aggressively kill background apps. To prevent this:</p>
                        <ul class="mb-0">
                            <li><strong>Disable Battery Optimization</strong> for FreeISP Replies</li>
                            <li><strong>Lock the app</strong> in recent apps (swipe down on the card or tap the lock icon)</li>
                            <li><strong>Xiaomi/MIUI:</strong> Settings &rarr; Battery &rarr; App battery saver &rarr; FreeISP Replies &rarr; No restrictions</li>
                            <li><strong>Samsung:</strong> Settings &rarr; Battery &rarr; Background usage limits &rarr; Never sleeping apps &rarr; Add FreeISP Replies</li>
                            <li><strong>Huawei:</strong> Settings &rarr; Battery &rarr; App launch &rarr; FreeISP Replies &rarr; Manage manually &rarr; Enable all</li>
                        </ul>
                    </div>
                </div>
            </div>

            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button collapsed small fw-semibold" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                        Notification Listener not working / Cached replies stays at 0
                    </button>
                </h2>
                <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                    <div class="accordion-body small">
                        <ul class="mb-0">
                            <li>Make sure you enabled <strong>"Allow restricted settings"</strong> first (Step 3)</li>
                            <li>Go to Settings &rarr; Notifications &rarr; Notification access &rarr; Enable <strong>FreeISP Replies</strong></li>
                            <li>After enabling, have someone send you a WhatsApp message to trigger caching</li>
                            <li>The "Cached replies" count in the app should increase</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- API Quick Start -->
<div class="card mb-4">
    <div class="card-header"><i class="bi bi-code-slash"></i> API Quick Start</div>
    <div class="card-body">
        <p class="small">Once your phone is set up and the relay service is running, send messages from any system:</p>

        <h6 class="small fw-bold mt-3">Simple URL method (recommended for billing systems)</h6>
        <pre class="bg-dark text-light p-3 rounded-3 small"><code>https://whatsappreplies.ispledger.com/api/sendWA.php?to=[number]&msg=[text]&secret=YOUR_API_KEY</code></pre>

        <h6 class="small fw-bold mt-3">PHP example</h6>
        <pre class="bg-dark text-light p-3 rounded-3 small"><code>$phone = '254712345678';
$message = urlencode('Hello! Your invoice is ready.');
$secret = 'YOUR_API_KEY';

$url = "https://whatsappreplies.ispledger.com/api/sendWA.php?to={$phone}&msg={$message}&secret={$secret}";
$response = file_get_contents($url);
echo $response;</code></pre>

        <h6 class="small fw-bold mt-3">JSON POST method (advanced)</h6>
        <pre class="bg-dark text-light p-3 rounded-3 small"><code>curl -X POST https://whatsappreplies.ispledger.com/api.php/send \
  -H "X-API-Key: YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"phone": "254712345678", "message": "Hello!"}'</code></pre>

        <p class="mt-3 mb-0 text-muted small">
            <i class="bi bi-key"></i> Your API key is on the <strong>Dashboard</strong> page — ready to copy.
            Phone numbers should include the country code without the + sign (e.g., 254 for Kenya).
        </p>
    </div>
</div>

<?php renderFooter(); ?>
