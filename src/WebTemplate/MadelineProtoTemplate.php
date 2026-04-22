<?php declare(strict_types=1);

/**
 * This file is part of MadelineProto.
 * MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 * See the GNU Affero General Public License for more details.
 * You should have received a copy of the GNU General Public License along with MadelineProto.
 * If not, see <http://www.gnu.org/licenses/>.
 *
 * @author    Daniil Gentili <daniil@daniil.it>
 * @copyright 2016-2025 Daniil Gentili <daniil@daniil.it>
 * @license   https://opensource.org/licenses/AGPL-3.0 AGPLv3
 * @link https://docs.madelineproto.xyz MadelineProto documentation
 */

namespace danog\MadelineProto\WebTemplate;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;

use function htmlentities;
use function implode;
use function json_encode;
use function sprintf;
use function str_replace;

/**
 * Default self-contained MadelineProto auth template.
 */
class MadelineProtoTemplate implements WebTemplateInterface
{
    public function renderLoginSelectionPage(LoginSelectionPage $page): string
    {
        $content = $this->renderNotices($page->notices);
        $content .= '<form method="POST" class="auth-stack">';
        $content .= $this->renderChoiceOptions([
            ChoiceOption::radio('type', 'phone', $page->userLabel, true),
            ChoiceOption::radio('type', 'bot', $page->botLabel),
        ]);
        $content .= $this->renderSubmitButton($page->submitLabel);
        $content .= '</form>';

        if ($page->passkeyPrompt || $page->qrPrompt) {
            $content .= '<div class="auth-divider"><span>Other ways to sign in</span></div>';
        }

        if ($page->passkeyPrompt) {
            $content .= $this->renderPasskeyPrompt($page->passkeyPrompt);
        }

        if ($page->qrPrompt) {
            $content .= $this->renderQrPrompt($page->qrPrompt);
        }

        return $this->renderLayout($page->title, $content);
    }

    public function renderPhoneNumberPage(PhoneNumberPage $page): string
    {
        return $this->renderFormPage(new FormPage(
            $page->title,
            $page->submitLabel,
            $page->notices,
            [FormField::text('phone_number', $page->phoneLabel, autocomplete: 'tel', autofocus: true)],
        ));
    }

    public function renderBotTokenPage(BotTokenPage $page): string
    {
        return $this->renderFormPage(new FormPage(
            $page->title,
            $page->submitLabel,
            $page->notices,
            [FormField::text('token', $page->tokenLabel, autocomplete: 'off', autofocus: true, autocapitalize: 'none')],
        ));
    }

    public function renderPhoneCodePage(PhoneCodePage $page): string
    {
        return $this->renderFormPage(new FormPage(
            $page->title,
            $page->submitLabel,
            $page->notices,
            [FormField::text('phone_code', $page->codeLabel, autocomplete: 'one-time-code', autofocus: true, inputMode: 'numeric')],
        ));
    }

    public function renderPasswordPage(PasswordPage $page): string
    {
        return $this->renderFormPage(new FormPage(
            $page->title,
            $page->submitLabel,
            $page->notices,
            [FormField::password('password', $page->passwordLabel, autocomplete: 'current-password', autofocus: true)],
        ));
    }

    public function renderSignupPage(SignupPage $page): string
    {
        return $this->renderFormPage(new FormPage(
            $page->title,
            $page->submitLabel,
            $page->notices,
            [
                FormField::text('first_name', $page->firstNameLabel, autocomplete: 'given-name', autofocus: true),
                FormField::text('last_name', $page->lastNameLabel, required: false, autocomplete: 'family-name'),
            ],
        ));
    }

    public function renderApiCredentialsPage(ApiPage $page): string
    {
        return $this->renderApiPage($page);
    }

    public function renderChoicePage(ChoicePage $page): string
    {
        $content = $this->renderNotices($page->notices);
        $content .= '<form method="POST" class="auth-stack">';
        $content .= $this->renderChoiceOptions($page->options);
        $content .= $this->renderSubmitButton($page->submitLabel);
        $content .= '</form>';

        if ($page->qrPrompt) {
            $content .= $this->renderQrPrompt($page->qrPrompt);
        }

        return $this->renderLayout($page->title, $content);
    }

    public function renderFormPage(FormPage $page): string
    {
        $content = $this->renderNotices($page->notices);
        $content .= '<form method="POST" class="auth-stack">';
        $content .= $this->renderFields($page->fields);
        $content .= $this->renderSubmitButton($page->submitLabel);
        $content .= '</form>';

        return $this->renderLayout($page->title, $content);
    }

    public function renderApiPage(ApiPage $page): string
    {
        $content = $this->renderNotices($page->notices);

        if ($page->introHtml !== '') {
            $content .= '<div class="auth-copy auth-copy-intro">'.$this->decorateRichText($page->introHtml).'</div>';
        }

        if ($page->steps !== []) {
            $content .= '<ol class="auth-copy auth-copy-list">';
            $content .= implode('', array_map(
                fn (InstructionStep $step): string => '<li>'.$this->decorateRichText($step->html).'</li>',
                $page->steps,
            ));
            $content .= '</ol>';
        }

        $content .= '<form method="POST" class="auth-stack auth-stack-spacious">';
        $content .= $this->renderFields($page->fields);
        $content .= $this->renderSubmitButton($page->submitLabel);
        $content .= '</form>';

        return $this->renderLayout($page->title, $content, true);
    }

    private function renderLayout(string $title, string $content, bool $wide = false): string
    {
        $pageTitle = $this->escape($title).' - MadelineProto';
        $heading = $this->escape($title);
        $panelClass = $wide ? 'auth-panel auth-panel-wide' : 'auth-panel';
        $styles = $this->renderStyles();
        $logo = $this->renderLogo();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <meta name="color-scheme" content="dark"/>
    <title>{$pageTitle}</title>
    <style>{$styles}</style>
</head>
<body>
    <main class="auth-shell">
        <div class="{$panelClass}">
            {$logo}
            <h1 class="auth-title">{$heading}</h1>
            {$content}
        </div>
    </main>
</body>
</html>
HTML;
    }

    /**
     * @param list<PageNotice> $notices
     */
    private function renderNotices(array $notices): string
    {
        if ($notices === []) {
            return '';
        }

        return '<div class="auth-notices">'.implode('', array_map(function (PageNotice $notice): string {
            [$class, $html] = match ($notice->tone) {
                'error' => ['auth-notice auth-notice-error', $notice->html],
                'info' => ['auth-notice auth-notice-info', $this->decorateRichText($notice->html)],
                default => ['auth-notice auth-notice-warning', $this->decorateRichText($notice->html)],
            };

            return sprintf('<div class="%s">%s</div>', $class, $html);
        }, $notices)).'</div>';
    }

    /**
     * @param list<FormField> $fields
     */
    private function renderFields(array $fields): string
    {
        return '<div class="auth-fields">'.implode('', array_map(function (FormField $field): string {
            return sprintf(
                '<label class="auth-field"><input%s class="auth-input" placeholder=" "/><span class="auth-label">%s</span></label>',
                $this->renderFieldAttributes($field),
                $this->escape($field->label),
            );
        }, $fields)).'</div>';
    }

    /**
     * @param list<ChoiceOption> $options
     */
    private function renderChoiceOptions(array $options): string
    {
        return '<div class="auth-choice-list">'.implode('', array_map(function (ChoiceOption $option): string {
            $caption = $this->getChoiceCaption($option);
            $captionHtml = $caption !== ''
                ? '<span class="auth-choice-caption">'.$this->escape($caption).'</span>'
                : '';

            return sprintf(
                '<label class="auth-choice"><input type="radio" name="%s" value="%s" class="auth-choice-input"%s/><span class="auth-choice-copy"><span class="auth-choice-title">%s</span>%s</span></label>',
                $this->escape($option->name),
                $this->escape($option->value),
                $option->checked ? ' checked' : '',
                $this->escape($option->label),
                $captionHtml,
            );
        }, $options)).'</div>';
    }

    private function renderSubmitButton(string $submitLabel): string
    {
        return sprintf(
            '<button type="submit" class="auth-button auth-button-primary">%s</button>',
            $this->escape($submitLabel),
        );
    }

    private function renderQrPrompt(QrCodePrompt $prompt): string
    {
        $requestPath = json_encode($prompt->requestPath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';
        $message = $this->escape($prompt->message);
        $steps = $prompt->steps === []
            ? ''
            : '<ol class="auth-qr-help">'.implode('', array_map(
                fn (string $step): string => '<li>'.$this->escape($step).'</li>',
                $prompt->steps,
            )).'</ol>';

        return <<<HTML
<section class="auth-qr-section" aria-live="polite">
    <p class="auth-qr-caption">{$message}</p>
    <div id="qr-code-container" class="auth-qr-canvas">
        <div id="qr-code-placeholder" class="auth-status">Loading QR code…</div>
        <div id="qr-code" class="auth-qr-image hidden"></div>
    </div>
    {$steps}
    <noscript><p class="auth-status">QR login needs JavaScript, but manual sign-in still works.</p></noscript>
</section>
<script>
(function () {
    var requestPath = {$requestPath};
    var qrCode = document.getElementById("qr-code");
    var placeholder = document.getElementById("qr-code-placeholder");
    function longPollQr(query) {
        var x = new XMLHttpRequest();
        x.onload = function () {
            var res = JSON.parse(this.responseText);
            if (res.logged_in) {
                window.location = window.location;
            } else {
                qrCode.classList.remove("hidden");
                placeholder.hidden = true;
                qrCode.innerHTML = res.svg;
                longPollQr("waitQrCodeOrLogin");
            }
        };
        x.open("GET", requestPath + "?" + query, true);
        x.send();
    }
    longPollQr("getQrCode");
}());
</script>
HTML;
    }

    private function renderPasskeyPrompt(PasskeyPrompt $prompt): string
    {
        $requestPath = json_encode($prompt->requestPath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';
        $title = $this->escape($prompt->title);
        $description = $this->escape($prompt->description);
        $buttonLabel = $this->escape($prompt->buttonLabel);

        return <<<HTML
<section id="passkey-login-container" class="auth-passkey" hidden>
    <div class="auth-passkey-header">
        <div>
            <h2 class="auth-passkey-title">{$title}</h2>
            <p class="auth-passkey-copy">{$description}</p>
        </div>
        <button id="passkey-login-button" type="button" class="auth-button auth-button-secondary">{$buttonLabel}</button>
    </div>
    <p id="passkey-login-status" class="auth-status" hidden></p>
</section>
<script>
(function () {
    var requestPath = {$requestPath};
    var container = document.getElementById("passkey-login-container");
    var button = document.getElementById("passkey-login-button");
    var status = document.getElementById("passkey-login-status");
    if (!container || !button || !status) {
        return;
    }
    if (!window.PublicKeyCredential || !navigator.credentials || typeof navigator.credentials.get !== "function") {
        return;
    }
    container.hidden = false;

    function setStatus(message, isError) {
        status.textContent = message;
        status.hidden = false;
        status.className = isError ? "auth-status auth-status-error" : "auth-status";
    }

    function toBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = "";
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
    }

    function fromBase64Url(value) {
        var normalized = value.replace(/-/g, "+").replace(/_/g, "/");
        while (normalized.length % 4 !== 0) {
            normalized += "=";
        }
        var binary = atob(normalized);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    function normalizePublicKeyOptions(value) {
        var publicKey = value && value.publicKey ? value.publicKey : value;
        if (!publicKey || typeof publicKey !== "object") {
            throw new Error("Invalid passkey options received from the server.");
        }
        if (typeof publicKey.challenge === "string") {
            publicKey.challenge = fromBase64Url(publicKey.challenge);
        }
        if (Array.isArray(publicKey.allowCredentials)) {
            publicKey.allowCredentials = publicKey.allowCredentials.map(function (credential) {
                if (credential && typeof credential.id === "string") {
                    credential.id = fromBase64Url(credential.id);
                }
                return credential;
            });
        }
        return publicKey;
    }

    async function requestJson(url, options) {
        var response = await fetch(url, options);
        var payload = await response.json();
        if (!response.ok || (payload && payload.ok === false)) {
            throw new Error(payload && payload.error ? payload.error : "Passkey login failed.");
        }
        return payload;
    }

    button.addEventListener("click", async function () {
        button.disabled = true;
        setStatus("Approve the passkey request to continue.", false);
        try {
            var initPayload = await requestJson(requestPath + "?getPasskeyLogin=1", {
                headers: {"Accept": "application/json"}
            });
            var credential = await navigator.credentials.get({
                publicKey: normalizePublicKeyOptions(initPayload.publicKey || initPayload.options || initPayload)
            });
            if (!credential || !credential.response) {
                throw new Error("No passkey credential was returned by the browser.");
            }
            var clientData = JSON.parse(new TextDecoder().decode(credential.response.clientDataJSON));
            await requestJson(requestPath + "?completePasskeyLogin=1", {
                method: "POST",
                headers: {
                    "Accept": "application/json",
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    credential: {
                        _: "inputPasskeyCredentialPublicKey",
                        id: credential.id,
                        raw_id: toBase64Url(credential.rawId),
                        response: {
                            _: "inputPasskeyResponseLogin",
                            client_data: clientData,
                            authenticator_data: toBase64Url(credential.response.authenticatorData),
                            signature: toBase64Url(credential.response.signature),
                            user_handle: credential.response.userHandle ? toBase64Url(credential.response.userHandle) : ""
                        }
                    }
                })
            });
            window.location = window.location;
        } catch (error) {
            setStatus(error && error.message ? error.message : "Passkey login failed.", true);
        } finally {
            button.disabled = false;
        }
    });
}());
</script>
HTML;
    }

    private function renderFieldAttributes(FormField $field): string
    {
        $attributes = [
            ' type="'.$this->escape($field->type).'"',
            ' name="'.$this->escape($field->name).'"',
        ];

        if ($field->required) {
            $attributes[] = ' required';
        }
        if ($field->autocomplete !== null) {
            $attributes[] = ' autocomplete="'.$this->escape($field->autocomplete).'"';
        }
        if ($field->inputMode !== null) {
            $attributes[] = ' inputmode="'.$this->escape($field->inputMode).'"';
        }
        if ($field->autocapitalize !== null) {
            $attributes[] = ' autocapitalize="'.$this->escape($field->autocapitalize).'"';
        }
        if ($field->autofocus) {
            $attributes[] = ' autofocus';
        }

        return implode('', $attributes);
    }

    private function renderStyles(): string
    {
        return <<<'CSS'
:root {
    --mp-bg: #060914;
    --mp-bg-deep: #080d1e;
    --mp-panel: rgba(9, 14, 33, 0.82);
    --mp-panel-highlight: rgba(255, 255, 255, 0.08);
    --mp-border: rgba(106, 231, 255, 0.18);
    --mp-border-strong: rgba(255, 79, 200, 0.32);
    --mp-text: #f8f5ff;
    --mp-muted: #b8c3e3;
    --mp-subtle: #7f8cb2;
    --mp-primary: #ff4fc8;
    --mp-primary-strong: #ff2ab5;
    --mp-secondary: #63e7ff;
    --mp-secondary-strong: #34d9ff;
    --mp-tertiary: #9f67ff;
    --mp-success: #71ffd1;
    --mp-danger: #ff8aa4;
    --mp-warning-bg: rgba(255, 191, 102, 0.14);
    --mp-warning-border: rgba(255, 191, 102, 0.34);
    --mp-warning-text: #ffd68d;
    --mp-info-bg: rgba(99, 231, 255, 0.12);
    --mp-info-border: rgba(99, 231, 255, 0.3);
    --mp-info-text: #9befff;
    --mp-error-bg: rgba(255, 86, 130, 0.12);
    --mp-error-border: rgba(255, 86, 130, 0.3);
    --mp-shadow: 0 28px 80px rgba(2, 5, 16, 0.66);
    --mp-shadow-glow: 0 0 28px rgba(255, 79, 200, 0.18), 0 0 42px rgba(99, 231, 255, 0.12);
}
* {
    box-sizing: border-box;
}
html {
    color-scheme: dark;
}
body {
    margin: 0;
    min-height: 100vh;
    background:
        radial-gradient(circle at 15% 18%, rgba(99, 231, 255, 0.12), transparent 30%),
        radial-gradient(circle at 85% 10%, rgba(255, 79, 200, 0.14), transparent 28%),
        radial-gradient(circle at 50% 100%, rgba(159, 103, 255, 0.18), transparent 36%),
        linear-gradient(180deg, #04050f 0%, var(--mp-bg) 45%, var(--mp-bg-deep) 100%);
    color: var(--mp-text);
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
body::before {
    content: "";
    position: fixed;
    inset: 0;
    background:
        linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
    background-size: 36px 36px;
    mask-image: radial-gradient(circle at center, rgba(0, 0, 0, 0.85), transparent 90%);
    pointer-events: none;
    opacity: 0.22;
}
a {
    color: inherit;
}
strong,
b {
    color: #ffffff;
}
code,
kbd {
    border-radius: .5rem;
    background: rgba(255, 255, 255, 0.06);
    padding: .15rem .4rem;
    font-size: .92em;
}
.auth-shell {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 3rem 1.25rem;
}
.auth-panel {
    position: relative;
    width: min(100%, 30rem);
    padding: 2rem 1.5rem 1.75rem;
    border-radius: 1.75rem;
    border: 1px solid var(--mp-border);
    background: linear-gradient(180deg, rgba(14, 20, 44, 0.92), var(--mp-panel));
    box-shadow: var(--mp-shadow), var(--mp-shadow-glow);
    backdrop-filter: blur(18px);
    text-align: center;
    overflow: hidden;
}
.auth-panel::before,
.auth-panel::after {
    content: "";
    position: absolute;
    inset: auto;
    border-radius: 999px;
    pointer-events: none;
}
.auth-panel::before {
    top: -4rem;
    right: -3rem;
    width: 10rem;
    height: 10rem;
    background: radial-gradient(circle, rgba(99, 231, 255, 0.26), transparent 70%);
}
.auth-panel::after {
    bottom: -5rem;
    left: -2rem;
    width: 12rem;
    height: 12rem;
    background: radial-gradient(circle, rgba(255, 79, 200, 0.24), transparent 72%);
}
.auth-panel-wide {
    width: min(100%, 36rem);
}
.auth-logo {
    position: relative;
    z-index: 1;
    display: grid;
    justify-items: center;
    gap: .85rem;
    margin: 0 auto 1.5rem;
}
.auth-logo-svg {
    width: min(100%, 8.5rem);
    height: auto;
    filter: drop-shadow(0 0 24px rgba(255, 79, 200, 0.18));
}
.auth-logo-wordmark {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    border: 1px solid rgba(255, 255, 255, 0.08);
    background: rgba(255, 255, 255, 0.03);
    padding: .5rem .95rem;
    font-size: .76rem;
    font-weight: 700;
    letter-spacing: .28em;
    text-transform: uppercase;
    color: #fef3ff;
    text-shadow: 0 0 16px rgba(255, 79, 200, 0.45);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05), 0 0 18px rgba(99, 231, 255, 0.08);
}
.auth-title {
    position: relative;
    z-index: 1;
    margin: 0;
    font-size: clamp(1.5rem, 4vw, 2rem);
    line-height: 1.08;
    font-weight: 700;
    letter-spacing: -.04em;
    color: #ffffff;
}
.auth-notices {
    position: relative;
    z-index: 1;
    width: min(100%, 24rem);
    margin: 0 auto 1.25rem;
    display: grid;
    gap: .8rem;
    text-align: left;
}
.auth-notice {
    border-radius: 1rem;
    border: 1px solid transparent;
    padding: .95rem 1rem;
    font-size: .9375rem;
    line-height: 1.55;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
}
.auth-notice a,
.auth-inline-link {
    color: var(--mp-secondary);
    font-weight: 600;
    text-decoration: none;
}
.auth-notice a:hover,
.auth-inline-link:hover {
    text-decoration: underline;
}
.auth-notice-error {
    background: var(--mp-error-bg);
    border-color: var(--mp-error-border);
    color: var(--mp-danger);
}
.auth-notice-warning {
    background: var(--mp-warning-bg);
    border-color: var(--mp-warning-border);
    color: var(--mp-warning-text);
}
.auth-notice-info {
    background: var(--mp-info-bg);
    border-color: var(--mp-info-border);
    color: var(--mp-info-text);
}
.auth-stack {
    position: relative;
    z-index: 1;
    width: min(100%, 24rem);
    margin: 2.5rem auto 0;
    display: grid;
    gap: 1.25rem;
    text-align: left;
}
.auth-stack-spacious {
    margin-top: 1.9rem;
}
.auth-fields,
.auth-choice-list {
    display: grid;
    gap: 1rem;
}
.auth-field {
    position: relative;
    display: block;
}
.auth-input {
    width: 100%;
    min-height: 56px;
    border-radius: 1rem;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: rgba(10, 16, 37, 0.82);
    padding: 1.15rem 1rem .85rem;
    font-size: 1rem;
    line-height: 1.3;
    color: var(--mp-text);
    transition: border-color .2s ease, box-shadow .2s ease, background-color .2s ease, transform .2s ease;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
}
.auth-input::placeholder {
    color: transparent;
}
.auth-input:hover:not(:focus) {
    border-color: rgba(255, 255, 255, 0.22);
}
.auth-input:focus {
    border-color: var(--mp-secondary);
    box-shadow: 0 0 0 1px rgba(99, 231, 255, 0.5) inset, 0 0 0 4px rgba(99, 231, 255, 0.12), 0 0 24px rgba(255, 79, 200, 0.12);
    background: rgba(12, 19, 42, 0.94);
    outline: none;
    transform: translateY(-1px);
}
.auth-label {
    position: absolute;
    inset-inline-start: 1rem;
    top: 50%;
    transform: translateY(-50%);
    padding: 0 .35rem;
    border-radius: 999px;
    background: linear-gradient(180deg, rgba(9, 14, 33, 0.96), rgba(9, 14, 33, 0.78));
    color: var(--mp-subtle);
    pointer-events: none;
    transform-origin: left center;
    transition: top .2s ease, transform .2s ease, color .2s ease, letter-spacing .2s ease;
}
.auth-input:focus + .auth-label,
.auth-input:not(:placeholder-shown) + .auth-label {
    top: 0;
    transform: translateY(-50%) scale(.82);
    color: var(--mp-secondary);
    letter-spacing: .08em;
}
.auth-choice {
    display: flex;
    align-items: center;
    gap: .875rem;
    padding: 1rem;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-radius: 1rem;
    cursor: pointer;
    background: rgba(10, 16, 37, 0.72);
    transition: border-color .2s ease, transform .2s ease, box-shadow .2s ease, background-color .2s ease;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
}
.auth-choice:hover {
    border-color: rgba(255, 79, 200, 0.44);
    background: rgba(13, 20, 46, 0.92);
    transform: translateY(-1px);
    box-shadow: 0 12px 28px rgba(2, 8, 24, 0.35), 0 0 18px rgba(255, 79, 200, 0.08);
}
.auth-choice-input {
    width: 1.125rem;
    height: 1.125rem;
    margin: 0;
    accent-color: var(--mp-primary);
    flex: 0 0 auto;
}
.auth-choice-copy {
    display: grid;
    gap: .22rem;
}
.auth-choice-title {
    font-size: 1rem;
    line-height: 1.3;
    color: var(--mp-text);
    font-weight: 600;
}
.auth-choice-caption {
    font-size: .875rem;
    line-height: 1.3;
    color: var(--mp-muted);
}
.auth-button {
    width: 100%;
    min-height: 56px;
    border-radius: 1rem;
    border: none;
    font-size: .95rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    transition: transform .2s ease, box-shadow .2s ease, opacity .2s ease, background-color .2s ease;
    cursor: pointer;
}
.auth-button:focus {
    outline: none;
    box-shadow: 0 0 0 4px rgba(99, 231, 255, 0.12);
}
.auth-button:hover:not(:disabled) {
    transform: translateY(-1px);
}
.auth-button:disabled {
    cursor: default;
    opacity: .65;
}
.auth-button-primary {
    background: linear-gradient(135deg, var(--mp-secondary) 0%, var(--mp-tertiary) 48%, var(--mp-primary) 100%);
    color: #050814;
    box-shadow: 0 16px 36px rgba(4, 10, 26, 0.32), 0 0 28px rgba(255, 79, 200, 0.16);
}
.auth-button-primary:hover:not(:disabled) {
    box-shadow: 0 20px 44px rgba(4, 10, 26, 0.4), 0 0 36px rgba(255, 79, 200, 0.22);
}
.auth-button-secondary {
    background: rgba(255, 255, 255, 0.04);
    color: var(--mp-secondary);
    border: 1px solid rgba(99, 231, 255, 0.22);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.02);
}
.auth-button-secondary:hover:not(:disabled) {
    background: rgba(99, 231, 255, 0.08);
}
.auth-divider {
    position: relative;
    z-index: 1;
    width: min(100%, 24rem);
    margin: 1.35rem auto 0;
    display: flex;
    align-items: center;
    gap: .75rem;
    color: var(--mp-subtle);
    font-size: .75rem;
    font-weight: 700;
    letter-spacing: .18em;
    text-transform: uppercase;
}
.auth-divider::before,
.auth-divider::after {
    content: "";
    flex: 1 1 auto;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.22), transparent);
}
.auth-passkey {
    position: relative;
    z-index: 1;
    width: min(100%, 24rem);
    margin: 1rem auto 0;
    padding: 1rem;
    border-radius: 1.1rem;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: rgba(10, 16, 37, 0.72);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.03);
}
.auth-passkey-header {
    display: grid;
    gap: 1rem;
    align-items: center;
}
.auth-passkey-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 700;
    color: #ffffff;
}
.auth-passkey-copy,
.auth-status,
.auth-qr-caption,
.auth-copy {
    color: var(--mp-muted);
    font-size: .9375rem;
    line-height: 1.55;
}
.auth-passkey-copy {
    margin: .35rem 0 0;
}
.auth-status {
    margin: .9rem 0 0;
    padding: .85rem 1rem;
    border-radius: .95rem;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: rgba(255, 255, 255, 0.04);
    text-align: center;
}
.auth-status-error {
    border-color: rgba(255, 86, 130, 0.28);
    background: rgba(255, 86, 130, 0.12);
    color: var(--mp-danger);
}
.auth-qr-section {
    position: relative;
    z-index: 1;
    width: min(100%, 30rem);
    margin: 1.25rem auto 0;
    display: grid;
    gap: 1rem;
    justify-items: center;
}
.auth-qr-caption {
    margin: 0;
    text-align: center;
    max-width: 30rem;
}
.auth-qr-canvas {
    width: min(100%, 240px);
    min-height: 240px;
    border-radius: 1.5rem;
    border: 1px solid rgba(255, 255, 255, 0.12);
    background: rgba(8, 12, 29, 0.92);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04), 0 0 24px rgba(99, 231, 255, 0.08);
}
.auth-qr-image {
    width: 100%;
}
.auth-qr-image svg {
    display: block;
    width: 100%;
    height: auto;
}
.auth-qr-help,
.auth-copy-list {
    width: min(100%, 30rem);
    margin: 0;
    padding-inline-start: 1.25rem;
    text-align: left;
    color: var(--mp-muted);
    font-size: .9375rem;
    line-height: 1.55;
}
.auth-qr-help li,
.auth-copy-list li {
    margin-top: .5rem;
}
.auth-copy {
    position: relative;
    z-index: 1;
    width: min(100%, 30rem);
    margin: 1rem auto 0;
    text-align: left;
}
.auth-copy-intro {
    margin-top: 0;
}
.hidden {
    display: none !important;
}
@media (min-width: 640px) {
    .auth-panel {
        padding: 2.35rem 2rem 2rem;
    }
    .auth-passkey-header {
        grid-template-columns: minmax(0, 1fr) auto;
    }
}
@media (max-width: 639px) {
    .auth-shell {
        align-items: flex-start;
        padding-top: 1.5rem;
        padding-bottom: 1.5rem;
    }
    .auth-panel {
        padding: 1.5rem 1rem 1.15rem;
        border-radius: 1.4rem;
    }
    .auth-logo-wordmark {
        letter-spacing: .2em;
        font-size: .72rem;
    }
    .auth-stack,
    .auth-notices,
    .auth-divider,
    .auth-passkey {
        width: 100%;
    }
    .auth-input,
    .auth-button {
        min-height: 52px;
    }
}
@media (prefers-reduced-motion: no-preference) {
    .auth-logo-svg {
        animation: auth-float 4.8s ease-in-out infinite;
    }
    .auth-panel::before,
    .auth-panel::after {
        animation: auth-pulse 7s ease-in-out infinite;
    }
}
@keyframes auth-float {
    0%,
    100% {
        transform: translateY(0);
    }
    50% {
        transform: translateY(-4px);
    }
}
@keyframes auth-pulse {
    0%,
    100% {
        opacity: .8;
        transform: scale(1);
    }
    50% {
        opacity: 1;
        transform: scale(1.06);
    }
}
CSS;
    }

    private function renderLogo(): string
    {
        return <<<'HTML'
<div class="auth-logo" aria-hidden="true">
    <svg class="auth-logo-svg" viewBox="0 0 220 220" role="presentation" xmlns="http://www.w3.org/2000/svg">
        <defs>
            <radialGradient id="mp-core" cx="50%" cy="38%" r="70%">
                <stop offset="0%" stop-color="#1b295f"/>
                <stop offset="100%" stop-color="#090d20"/>
            </radialGradient>
            <linearGradient id="mp-ring" x1="26" y1="36" x2="190" y2="188" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#63e7ff"/>
                <stop offset="52%" stop-color="#fef5ff"/>
                <stop offset="100%" stop-color="#ff4fc8"/>
            </linearGradient>
            <linearGradient id="mp-stroke" x1="54" y1="52" x2="161" y2="169" gradientUnits="userSpaceOnUse">
                <stop offset="0%" stop-color="#63e7ff"/>
                <stop offset="48%" stop-color="#a687ff"/>
                <stop offset="100%" stop-color="#ff4fc8"/>
            </linearGradient>
            <filter id="mp-neon-glow" x="-45%" y="-45%" width="190%" height="190%">
                <feDropShadow dx="0" dy="0" stdDeviation="7" flood-color="#63e7ff" flood-opacity="0.7"/>
                <feDropShadow dx="0" dy="0" stdDeviation="11" flood-color="#ff4fc8" flood-opacity="0.46"/>
            </filter>
        </defs>
        <circle cx="110" cy="110" r="89" fill="url(#mp-core)" stroke="url(#mp-ring)" stroke-width="4"/>
        <circle cx="110" cy="110" r="77" fill="none" stroke="#63e7ff" stroke-opacity="0.18" stroke-width="2"/>
        <path d="M64 155V63L110 132L156 63V155" fill="none" stroke="url(#mp-stroke)" stroke-width="16" stroke-linecap="round" stroke-linejoin="round" filter="url(#mp-neon-glow)"/>
        <path d="M64 155V63L110 132L156 63V155" fill="none" stroke="#ffffff" stroke-opacity="0.72" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
        <path d="M167 54l4 12l12 4l-12 4l-4 12l-4-12l-12-4l12-4z" fill="#ff78d8" filter="url(#mp-neon-glow)"/>
    </svg>
    <span class="auth-logo-wordmark">MadelineProto</span>
</div>
HTML;
    }

    private function decorateRichText(string $html): string
    {
        return str_replace('<a ', '<a class="auth-inline-link" ', $html);
    }

    private function getChoiceCaption(ChoiceOption $option): string
    {
        return match ($option->value) {
            'phone' => 'Use your Telegram account',
            'bot' => 'Continue with a bot token',
            default => '',
        };
    }

    private function escape(string $value): string
    {
        return htmlentities($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
