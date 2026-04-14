<?php
// MailerLite plugin settings panel (rendered inside the Advanced page)
$mlConfig = \LiteMD\Config::getPluginConfig('mailerlite', []);
$apiKey = $mlConfig['api_key'] ?? '';
$basePath = \LiteMD\BasePath::detect('/admin');
$webhookUrl = rtrim(
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
    . $basePath, '/'
) . '/newsletter-webhook';
?>
<link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>/plugins/mailerlite/assets/settings.css">

            <div class="advanced-form" style="max-width:500px;padding:1.25rem">
                <h2 class="advanced-section-title" style="margin-top:0">MailerLite Settings</h2>
                <p class="advanced-section-desc">API credentials and webhook configuration for MailerLite integration.</p>

                <label class="advanced-field">
                    <span class="advanced-label">API Key</span>
                    <input type="password" id="mailerlite-api-key" class="advanced-input" value="<?= htmlspecialchars($apiKey, ENT_QUOTES, 'UTF-8') ?>">
                </label>

                <div class="advanced-field">
                    <span class="advanced-label">Webhook URL</span>
                    <div class="ml-webhook-row">
                        <code class="ml-webhook-url" id="mailerlite-webhook-url"><?= htmlspecialchars($webhookUrl, ENT_QUOTES, 'UTF-8') ?></code>
                        <button type="button" class="ml-webhook-copy" id="mailerlite-copy-btn" title="Copy to clipboard">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                            </svg>
                        </button>
                    </div>
                    <span class="advanced-hint">Paste this into MailerLite under Integrations &rarr; Webhooks. Select the <code>subscriber.unsubscribed</code> event.</span>
                </div>

                <div class="advanced-actions">
                    <button class="advanced-btn advanced-btn-primary" id="mailerlite-settings-save">Save</button>
                </div>
            </div>

<script>
(function () {
    var saveBtn = document.getElementById("mailerlite-settings-save");
    if (!saveBtn) return;

    // Copy webhook URL to clipboard
    var copyBtn = document.getElementById("mailerlite-copy-btn");
    if (copyBtn) {
        copyBtn.addEventListener("click", function () {
            var url = document.getElementById("mailerlite-webhook-url").textContent;
            navigator.clipboard.writeText(url).then(function () {
                copyBtn.classList.add("ml-copied");
                setTimeout(function () { copyBtn.classList.remove("ml-copied"); }, 1500);
            });
        });
    }

    saveBtn.addEventListener("click", function () {
        EditorUtils.apiPost("mailerlite-settings-save", {
            api_key: document.getElementById("mailerlite-api-key").value,
            csrf: (window.EDITOR_CONFIG || {}).csrfToken || ""
        }).then(function () {
            alert("MailerLite settings saved.");
        }).catch(function (err) {
            alert(err.message || "Failed to save.");
        });
    });
})();
</script>
