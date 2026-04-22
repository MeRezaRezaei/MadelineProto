<?php declare(strict_types=1);

use danog\MadelineProto\API;
use danog\MadelineProto\Exception;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\WebAuthn\WebPasskey;

use const ENT_QUOTES;
use const ENT_SUBSTITUTE;
use const JSON_HEX_AMP;
use const JSON_HEX_APOS;
use const JSON_HEX_QUOT;
use const JSON_HEX_TAG;
use const PHP_SAPI;

require 'vendor/autoload.php';

$api = new API(__DIR__.'/user.madeline');

if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
	$api->start();
	return;
}

$self = $api->start();

$action = $_GET['action'] ?? null;
if (\is_string($action) && $action !== '') {
	handleDashboardAction($api, $action);
}

$dashboardState = getDashboardState($api);
$rpId = WebPasskey::getRpId();

renderDashboard(
	\is_array($self) ? $self : [],
	$dashboardState['passkeys'],
	$dashboardState['error'],
	$rpId,
);

/**
 * @return array{passkeys: list<array{_: string, id: string, name: string, date: int, software_emoji_id?: int, last_usage_date?: int}>, error: string}
 */
function getDashboardState(API $api): array
{
	try {
		$passkeys = $api->account->getPasskeys()['passkeys'] ?? [];
		return [
			'passkeys' => \is_array($passkeys) ? $passkeys : [],
			'error' => '',
		];
	} catch (RPCErrorException|Exception $e) {
		return [
			'passkeys' => [],
			'error' => $e->getMessage(),
		];
	}
}

function handleDashboardAction(API $api, string $action): never
{
	try {
		switch ($action) {
			case 'passkey-init':
				jsonResponse([
					'ok' => true,
					'options' => WebPasskey::overrideRpId($api->account->initPasskeyRegistration()['options'] ?? []),
					'rpId' => WebPasskey::getRpId(),
				]);

			case 'passkey-list':
				jsonResponse([
					'ok' => true,
					'passkeys' => $api->account->getPasskeys()['passkeys'] ?? [],
				]);

			case 'passkey-register':
				requireJsonPost();
				$payload = readJsonBody();
				if (!isset($payload['credential']) || !\is_array($payload['credential'])) {
					throw new Exception('Missing passkey credential payload.');
				}

				$registered = $api->account->registerPasskey(WebPasskey::normalizeRegistrationCredential($payload['credential']));
				jsonResponse([
					'ok' => true,
					'passkey' => $registered,
					'passkeys' => $api->account->getPasskeys()['passkeys'] ?? [],
				]);

			case 'passkey-delete':
				requireJsonPost();
				$payload = readJsonBody();
				if (!isset($payload['id']) || !\is_string($payload['id']) || $payload['id'] === '') {
					throw new Exception('Missing passkey ID.');
				}

				$api->account->deletePasskey($payload['id']);
				jsonResponse([
					'ok' => true,
					'passkeys' => $api->account->getPasskeys()['passkeys'] ?? [],
				]);

			case 'logout':
				requireJsonPost();
				$api->logout();
				jsonResponse([
					'ok' => true,
					'redirect' => (string) strtok($_SERVER['REQUEST_URI'] ?? '/', '?'),
				]);

			default:
				jsonResponse([
					'ok' => false,
					'error' => 'Unknown dashboard action.',
				], 404);
		}
	} catch (RPCErrorException|Exception $e) {
		jsonResponse([
			'ok' => false,
			'error' => $e->getMessage(),
		], 400);
	}
}

/**
 * @return array<string, mixed>
 */
function readJsonBody(): array
{
	$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
	if (!\is_array($payload)) {
		throw new Exception('Invalid JSON payload.');
	}

	return $payload;
}

function requireJsonPost(): void
{
	if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
		throw new Exception('This action requires POST.');
	}
}

/**
 * @param array<string, mixed> $payload
 */
function jsonResponse(array $payload, int $status = 200): never
{
	http_response_code($status);
	header('Content-Type: application/json');
	echo json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
	exit;
}

/**
 * @param array<string, mixed> $self
 * @param list<array{_: string, id: string, name: string, date: int, software_emoji_id?: int, last_usage_date?: int}> $passkeys
 */
function renderDashboard(array $self, array $passkeys, string $error, string $rpId): never
{
	$displayName = trim((string) ($self['first_name'] ?? '').' '.(string) ($self['last_name'] ?? ''));
	if ($displayName === '') {
		$displayName = (string) ($self['username'] ?? 'Telegram user');
	}

	$username = isset($self['username']) && \is_string($self['username']) && $self['username'] !== ''
		? '@'.$self['username']
		: 'ID '.(string) ($self['id'] ?? 'unknown');

	$notice = $error !== ''
		? '<div class="dashboard-notice dashboard-notice-error">'.escapeHtml($error).'</div>'
		: '';

	$displayNameHtml = escapeHtml($displayName);
	$usernameHtml = escapeHtml($username);
	$rpIdHtml = escapeHtml($rpId);
	$requestPath = jsonEncodeForInlineScript((string) strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));
	$initialPasskeys = jsonEncodeForInlineScript($passkeys);
	$rpIdJson = jsonEncodeForInlineScript($rpId);

	echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1"/>
	<meta name="color-scheme" content="dark"/>
	<title>MadelineProto Passkey Dashboard</title>
	<style>
		:root {
			--bg: #050814;
			--bg-elevated: rgba(10, 17, 38, 0.88);
			--bg-panel: rgba(13, 21, 48, 0.86);
			--border: rgba(120, 231, 255, 0.16);
			--border-strong: rgba(255, 92, 201, 0.26);
			--text: #f8fbff;
			--muted: #b5c4e4;
			--subtle: #8290b7;
			--primary: #ff5cc9;
			--secondary: #6ce8ff;
			--accent: #a879ff;
			--success: #77ffd4;
			--warning: #ffd78f;
			--danger: #ff9cb6;
			--shadow: 0 28px 90px rgba(0, 0, 0, 0.42);
		}
		* { box-sizing: border-box; }
		html { color-scheme: dark; }
		body {
			margin: 0;
			min-height: 100vh;
			background:
				radial-gradient(circle at 15% 15%, rgba(108, 232, 255, 0.12), transparent 26%),
				radial-gradient(circle at 85% 0%, rgba(255, 92, 201, 0.16), transparent 30%),
				radial-gradient(circle at 50% 110%, rgba(168, 121, 255, 0.18), transparent 34%),
				linear-gradient(180deg, #040611 0%, var(--bg) 44%, #080d1f 100%);
			color: var(--text);
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
			mask-image: radial-gradient(circle at center, rgba(0, 0, 0, 0.88), transparent 88%);
			pointer-events: none;
			opacity: 0.25;
		}
		a { color: inherit; }
		code {
			padding: .18rem .45rem;
			border-radius: .6rem;
			background: rgba(255, 255, 255, 0.06);
			font-size: .92em;
		}
		.dashboard-shell {
			width: min(1120px, calc(100% - 2rem));
			margin: 0 auto;
			padding: 2rem 0 3rem;
			position: relative;
			z-index: 1;
		}
		.dashboard-hero,
		.dashboard-card {
			border: 1px solid var(--border);
			background: linear-gradient(180deg, rgba(12, 19, 42, 0.92), var(--bg-elevated));
			box-shadow: var(--shadow);
			backdrop-filter: blur(18px);
		}
		.dashboard-hero {
			border-radius: 2rem;
			padding: 1.75rem;
			overflow: hidden;
			position: relative;
		}
		.dashboard-hero::before,
		.dashboard-hero::after {
			content: "";
			position: absolute;
			border-radius: 999px;
			pointer-events: none;
		}
		.dashboard-hero::before {
			top: -3rem;
			right: -3rem;
			width: 12rem;
			height: 12rem;
			background: radial-gradient(circle, rgba(255, 92, 201, 0.18), transparent 72%);
		}
		.dashboard-hero::after {
			bottom: -4rem;
			left: -3rem;
			width: 14rem;
			height: 14rem;
			background: radial-gradient(circle, rgba(108, 232, 255, 0.14), transparent 72%);
		}
		.dashboard-hero-grid {
			display: grid;
			gap: 1.5rem;
			align-items: start;
		}
		.dashboard-badge {
			display: inline-flex;
			align-items: center;
			gap: .55rem;
			padding: .45rem .8rem;
			border-radius: 999px;
			border: 1px solid rgba(255, 255, 255, 0.12);
			background: rgba(255, 255, 255, 0.04);
			font-size: .78rem;
			font-weight: 700;
			letter-spacing: .14em;
			text-transform: uppercase;
			color: #fceeff;
		}
		.dashboard-title {
			margin: 1rem 0 .4rem;
			font-size: clamp(2rem, 4vw, 3.2rem);
			line-height: 1.02;
			letter-spacing: -.05em;
		}
		.dashboard-copy {
			max-width: 46rem;
			margin: 0;
			color: var(--muted);
			line-height: 1.65;
			font-size: 1rem;
		}
		.dashboard-user {
			display: grid;
			gap: .3rem;
			margin-top: 1.4rem;
		}
		.dashboard-user-name {
			font-size: 1.1rem;
			font-weight: 700;
		}
		.dashboard-user-meta {
			color: var(--muted);
		}
		.dashboard-actions {
			display: grid;
			gap: .9rem;
			align-content: start;
		}
		.dashboard-action-card {
			border: 1px solid rgba(255, 255, 255, 0.1);
			border-radius: 1.3rem;
			padding: 1rem;
			background: rgba(255, 255, 255, 0.04);
		}
		.dashboard-action-title {
			margin: 0 0 .35rem;
			font-size: 1rem;
			font-weight: 700;
		}
		.dashboard-action-copy {
			margin: 0;
			color: var(--muted);
			line-height: 1.55;
			font-size: .95rem;
		}
		.dashboard-grid {
			display: grid;
			gap: 1.25rem;
			margin-top: 1.25rem;
		}
		.dashboard-card {
			border-radius: 1.55rem;
			padding: 1.2rem;
		}
		.dashboard-card h2 {
			margin: 0;
			font-size: 1.15rem;
			letter-spacing: -.02em;
		}
		.dashboard-card-header {
			display: flex;
			flex-wrap: wrap;
			justify-content: space-between;
			gap: .9rem;
			align-items: center;
			margin-bottom: .95rem;
		}
		.dashboard-chip-row {
			display: flex;
			flex-wrap: wrap;
			gap: .7rem;
		}
		.dashboard-chip {
			padding: .48rem .72rem;
			border-radius: 999px;
			background: rgba(255, 255, 255, 0.05);
			border: 1px solid rgba(255, 255, 255, 0.1);
			font-size: .84rem;
			color: var(--muted);
		}
		.dashboard-button {
			appearance: none;
			border: none;
			border-radius: 1rem;
			min-height: 48px;
			padding: 0 1rem;
			font-size: .92rem;
			font-weight: 700;
			letter-spacing: .08em;
			text-transform: uppercase;
			cursor: pointer;
			transition: transform .2s ease, opacity .2s ease, box-shadow .2s ease, background .2s ease;
		}
		.dashboard-button:hover:not(:disabled) {
			transform: translateY(-1px);
		}
		.dashboard-button:disabled {
			opacity: .62;
			cursor: default;
		}
		.dashboard-button-primary {
			color: #08101f;
			background: linear-gradient(135deg, var(--secondary) 0%, var(--accent) 45%, var(--primary) 100%);
			box-shadow: 0 16px 40px rgba(0, 0, 0, 0.22), 0 0 28px rgba(255, 92, 201, 0.16);
		}
		.dashboard-button-secondary {
			color: var(--secondary);
			background: rgba(255, 255, 255, 0.04);
			border: 1px solid rgba(108, 232, 255, 0.22);
		}
		.dashboard-button-danger {
			color: var(--danger);
			background: rgba(255, 255, 255, 0.04);
			border: 1px solid rgba(255, 156, 182, 0.22);
		}
		.dashboard-status,
		.dashboard-notice,
		.dashboard-empty {
			border-radius: 1rem;
			padding: .9rem 1rem;
			line-height: 1.55;
			font-size: .95rem;
		}
		.dashboard-status {
			display: none;
			margin-top: .9rem;
			border: 1px solid rgba(255, 255, 255, 0.1);
			background: rgba(255, 255, 255, 0.04);
			color: var(--muted);
		}
		.dashboard-status-visible { display: block; }
		.dashboard-status-error,
		.dashboard-notice-error {
			border: 1px solid rgba(255, 156, 182, 0.28);
			background: rgba(255, 156, 182, 0.12);
			color: var(--danger);
		}
		.dashboard-status-success {
			border: 1px solid rgba(119, 255, 212, 0.28);
			background: rgba(119, 255, 212, 0.12);
			color: var(--success);
		}
		.dashboard-status-warning,
		.dashboard-notice-warning {
			border: 1px solid rgba(255, 215, 143, 0.26);
			background: rgba(255, 215, 143, 0.1);
			color: var(--warning);
		}
		.dashboard-notice { margin-top: 1.1rem; }
		.dashboard-notice a { color: inherit; }
		.dashboard-list {
			display: grid;
			gap: .95rem;
		}
		.dashboard-passkey {
			display: grid;
			gap: .9rem;
			padding: 1rem;
			border-radius: 1.2rem;
			border: 1px solid rgba(255, 255, 255, 0.1);
			background: rgba(255, 255, 255, 0.04);
		}
		.dashboard-passkey-top {
			display: flex;
			justify-content: space-between;
			gap: .9rem;
			align-items: flex-start;
		}
		.dashboard-passkey-name {
			margin: 0;
			font-size: 1rem;
			font-weight: 700;
		}
		.dashboard-passkey-id {
			margin: .35rem 0 0;
			color: var(--subtle);
			font-family: ui-monospace, SFMono-Regular, SFMono-Regular, Menlo, monospace;
			font-size: .84rem;
		}
		.dashboard-meta {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
			gap: .75rem;
		}
		.dashboard-meta-item {
			border-radius: .95rem;
			padding: .8rem .9rem;
			background: rgba(255, 255, 255, 0.035);
			border: 1px solid rgba(255, 255, 255, 0.07);
		}
		.dashboard-meta-label {
			display: block;
			color: var(--subtle);
			font-size: .76rem;
			text-transform: uppercase;
			letter-spacing: .12em;
			margin-bottom: .35rem;
		}
		.dashboard-empty {
			border: 1px dashed rgba(255, 255, 255, 0.14);
			background: rgba(255, 255, 255, 0.03);
			color: var(--muted);
		}
		@media (min-width: 920px) {
			.dashboard-hero-grid {
				grid-template-columns: minmax(0, 1.5fr) minmax(280px, .9fr);
			}
			.dashboard-grid {
				grid-template-columns: minmax(0, 1.1fr) minmax(0, .9fr);
			}
		}
		@media (max-width: 919px) {
			.dashboard-shell {
				width: min(100%, calc(100% - 1rem));
				padding-top: 1rem;
			}
		}
	</style>
</head>
<body>
	<main class="dashboard-shell">
		<section class="dashboard-hero">
			<div class="dashboard-hero-grid">
				<div>
					<span class="dashboard-badge">Passkeys · Unofficial WebAuthn</span>
					<h1 class="dashboard-title">Create Telegram passkeys without borrowing <code>telegram.org</code>.</h1>
					<p class="dashboard-copy">This dashboard uses Telegram's passkey APIs with the unofficial-client tweak described in the Corefork docs: it rewrites the WebAuthn RP ID to <code>{$rpIdHtml}</code> so passkeys live in a separate unofficial namespace.</p>
					<div class="dashboard-user">
						<div class="dashboard-user-name">{$displayNameHtml}</div>
						<div class="dashboard-user-meta">{$usernameHtml}</div>
					</div>
					{$notice}
				</div>
				<div class="dashboard-actions">
					<div class="dashboard-action-card">
						<h2 class="dashboard-action-title">Quick checklist</h2>
						<p class="dashboard-action-copy">WebAuthn only works in a secure context and the host must match <code>{$rpIdHtml}</code> or be its subdomain. If you test elsewhere, the browser will politely refuse and dramatically roll its eyes.</p>
					</div>
					<div class="dashboard-chip-row">
						<span class="dashboard-chip">RP ID: <strong>{$rpIdHtml}</strong></span>
						<span class="dashboard-chip">Session: <code>user.madeline</code></span>
					</div>
				</div>
			</div>
		</section>

		<section class="dashboard-grid">
			<section class="dashboard-card">
				<div class="dashboard-card-header">
					<div>
						<h2>Create a new passkey</h2>
					</div>
					<button id="create-passkey" class="dashboard-button dashboard-button-primary" type="button">Create passkey</button>
				</div>
				<p class="dashboard-copy">Generate a passkey for the currently logged-in account, then store it in your browser or password manager under the unofficial RP ID namespace.</p>
				<div id="dashboard-status" class="dashboard-status" aria-live="polite"></div>
				<div id="dashboard-warning-slot"></div>
			</section>

			<section class="dashboard-card">
				<div class="dashboard-card-header">
					<h2>Session controls</h2>
					<button id="logout-button" class="dashboard-button dashboard-button-secondary" type="button">Log out</button>
				</div>
				<p class="dashboard-copy">Need a clean slate? Log out here and MadelineProto will drop back to the built-in login flow.</p>
			</section>
		</section>

		<section class="dashboard-card" style="margin-top: 1.25rem;">
			<div class="dashboard-card-header">
				<h2>Existing passkeys</h2>
				<button id="refresh-passkeys" class="dashboard-button dashboard-button-secondary" type="button">Refresh</button>
			</div>
			<div id="passkey-list" class="dashboard-list"></div>
		</section>
	</main>

	<script>
	(function () {
		var requestPath = {$requestPath};
		var rpId = {$rpIdJson};
		var state = {
			passkeys: {$initialPasskeys}
		};

		var createButton = document.getElementById("create-passkey");
		var refreshButton = document.getElementById("refresh-passkeys");
		var logoutButton = document.getElementById("logout-button");
		var status = document.getElementById("dashboard-status");
		var warningSlot = document.getElementById("dashboard-warning-slot");
		var list = document.getElementById("passkey-list");

		if (!createButton || !refreshButton || !logoutButton || !status || !warningSlot || !list) {
			return;
		}

		function escapeHtml(value) {
			return String(value)
				.replace(/&/g, "&amp;")
				.replace(/</g, "&lt;")
				.replace(/>/g, "&gt;")
				.replace(/\"/g, "&quot;")
				.replace(/'/g, "&#039;");
		}

		function setStatus(message, tone) {
			status.className = "dashboard-status dashboard-status-visible";
			if (tone === "error") {
				status.classList.add("dashboard-status-error");
			} else if (tone === "success") {
				status.classList.add("dashboard-status-success");
			} else if (tone === "warning") {
				status.classList.add("dashboard-status-warning");
			}
			status.textContent = message;
		}

		function clearStatus() {
			status.className = "dashboard-status";
			status.textContent = "";
		}

		function setBusy(isBusy) {
			createButton.disabled = isBusy;
			refreshButton.disabled = isBusy;
			logoutButton.disabled = isBusy;
		}

		function formatDate(timestamp) {
			if (!timestamp) {
				return "—";
			}
			try {
				return new Intl.DateTimeFormat(undefined, {
					dateStyle: "medium",
					timeStyle: "short"
				}).format(new Date(Number(timestamp) * 1000));
			} catch (error) {
				return String(timestamp);
			}
		}

		function shortenId(id) {
			if (id.length <= 22) {
				return id;
			}
			return id.slice(0, 12) + "…" + id.slice(-8);
		}

		function renderPasskeys() {
			if (!Array.isArray(state.passkeys) || state.passkeys.length === 0) {
				list.innerHTML = '<div class="dashboard-empty">No passkeys have been registered for this account yet. Create one above to populate the list.</div>';
				return;
			}

			list.innerHTML = state.passkeys.map(function (passkey) {
				var name = escapeHtml(passkey.name || "Unnamed passkey");
				var id = escapeHtml(passkey.id || "");
				var shortId = escapeHtml(shortenId(passkey.id || ""));
				var created = escapeHtml(formatDate(passkey.date || 0));
				var lastUsed = escapeHtml(formatDate(passkey.last_usage_date || 0));
				return ''
					+ '<article class="dashboard-passkey">'
					+ '  <div class="dashboard-passkey-top">'
					+ '    <div>'
					+ '      <p class="dashboard-passkey-name">' + name + '</p>'
					+ '      <p class="dashboard-passkey-id" title="' + id + '">' + shortId + '</p>'
					+ '    </div>'
					+ '    <button class="dashboard-button dashboard-button-danger" type="button" data-delete-id="' + id + '">Delete</button>'
					+ '  </div>'
					+ '  <div class="dashboard-meta">'
					+ '    <div class="dashboard-meta-item"><span class="dashboard-meta-label">Created</span>' + created + '</div>'
					+ '    <div class="dashboard-meta-item"><span class="dashboard-meta-label">Last used</span>' + lastUsed + '</div>'
					+ '  </div>'
					+ '</article>';
			}).join("");
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

		function normalizeCreationOptions(value, fallbackRpId) {
			var options = value && value.publicKey ? value.publicKey : value;
			if (!options || typeof options !== "object") {
				throw new Error("Invalid passkey creation options received from the server.");
			}

			if (fallbackRpId && typeof fallbackRpId === "string") {
				if (options.rp && typeof options.rp === "object") {
					options.rp.id = fallbackRpId;
				}
				if (Object.prototype.hasOwnProperty.call(options, "rpId") && typeof options.rpId === "string") {
					options.rpId = fallbackRpId;
				}
			}

			if (window.PublicKeyCredential && typeof window.PublicKeyCredential.parseCreationOptionsFromJSON === "function") {
				return window.PublicKeyCredential.parseCreationOptionsFromJSON(options);
			}

			if (typeof options.challenge === "string") {
				options.challenge = fromBase64Url(options.challenge);
			}
			if (options.user && typeof options.user === "object" && typeof options.user.id === "string") {
				options.user.id = fromBase64Url(options.user.id);
			}
			if (Array.isArray(options.excludeCredentials)) {
				options.excludeCredentials = options.excludeCredentials.map(function (credential) {
					if (credential && typeof credential.id === "string") {
						credential.id = fromBase64Url(credential.id);
					}
					return credential;
				});
			}
			return options;
		}

		async function requestJson(url, options) {
			var response = await fetch(url, options);
			var payload = await response.json();
			if (!response.ok || (payload && payload.ok === false)) {
				throw new Error(payload && payload.error ? payload.error : "The request failed.");
			}
			return payload;
		}

		function createCredentialPayload(credential) {
			if (!credential || !credential.response || !credential.response.attestationObject) {
				throw new Error("No passkey credential was returned by the browser.");
			}

			return {
				credential: {
					_: "inputPasskeyCredentialPublicKey",
					id: credential.id,
					raw_id: toBase64Url(credential.rawId),
					response: {
						_: "inputPasskeyResponseRegister",
						client_data: JSON.parse(new TextDecoder().decode(credential.response.clientDataJSON)),
						attestation_data: toBase64Url(credential.response.attestationObject)
					}
				}
			};
		}

		async function refreshPasskeys(message) {
			var payload = await requestJson(requestPath + "?action=passkey-list", {
				headers: {"Accept": "application/json"}
			});
			state.passkeys = Array.isArray(payload.passkeys) ? payload.passkeys : [];
			renderPasskeys();
			if (message) {
				setStatus(message, "success");
			}
		}

		function renderEnvironmentWarnings() {
			var warnings = [];
			if (!window.isSecureContext) {
				warnings.push("This page is not running in a secure context, so WebAuthn will be blocked by the browser.");
			}
			if (!window.PublicKeyCredential || !navigator.credentials || typeof navigator.credentials.create !== "function") {
				warnings.push("This browser does not expose the WebAuthn credential-creation APIs needed for passkeys.");
				createButton.disabled = true;
			}
			if (typeof location.hostname === "string" && location.hostname !== rpId && !location.hostname.endsWith("." + rpId)) {
				warnings.push("The current host (" + location.hostname + ") does not match the placeholder RP ID " + rpId + ". Replace the placeholder before expecting passkeys to work here.");
			}

			if (warnings.length === 0) {
				warningSlot.innerHTML = "";
				return;
			}

			warningSlot.innerHTML = warnings.map(function (message) {
				return '<div class="dashboard-notice dashboard-notice-warning">' + escapeHtml(message) + '</div>';
			}).join("");
		}

		createButton.addEventListener("click", async function () {
			clearStatus();
			setBusy(true);
			setStatus("Ask your browser or password manager to create a passkey…", "warning");
			try {
				var payload = await requestJson(requestPath + "?action=passkey-init", {
					headers: {"Accept": "application/json"}
				});
				var credential = await navigator.credentials.create({
					publicKey: normalizeCreationOptions(payload.options || payload.publicKey || payload, payload.rpId || rpId)
				});
				await requestJson(requestPath + "?action=passkey-register", {
					method: "POST",
					headers: {
						"Accept": "application/json",
						"Content-Type": "application/json"
					},
					body: JSON.stringify(createCredentialPayload(credential))
				});
				await refreshPasskeys("Passkey created successfully.");
			} catch (error) {
				setStatus(error && error.message ? error.message : "Passkey creation failed.", "error");
			} finally {
				setBusy(false);
			}
		});

		refreshButton.addEventListener("click", async function () {
			clearStatus();
			setBusy(true);
			try {
				await refreshPasskeys("Passkey list refreshed.");
			} catch (error) {
				setStatus(error && error.message ? error.message : "Could not refresh the passkey list.", "error");
			} finally {
				setBusy(false);
			}
		});

		logoutButton.addEventListener("click", async function () {
			clearStatus();
			setBusy(true);
			try {
				var payload = await requestJson(requestPath + "?action=logout", {
					method: "POST",
					headers: {"Accept": "application/json"}
				});
				window.location = payload.redirect || requestPath;
			} catch (error) {
				setStatus(error && error.message ? error.message : "Could not log out.", "error");
				setBusy(false);
			}
		});

		list.addEventListener("click", async function (event) {
			var target = event.target;
			if (!(target instanceof HTMLElement)) {
				return;
			}
			var id = target.getAttribute("data-delete-id");
			if (!id) {
				return;
			}
			if (!window.confirm("Delete this passkey from the current Telegram account?")) {
				return;
			}

			clearStatus();
			setBusy(true);
			try {
				var payload = await requestJson(requestPath + "?action=passkey-delete", {
					method: "POST",
					headers: {
						"Accept": "application/json",
						"Content-Type": "application/json"
					},
					body: JSON.stringify({id: id})
				});
				state.passkeys = Array.isArray(payload.passkeys) ? payload.passkeys : [];
				renderPasskeys();
				setStatus("Passkey deleted.", "success");
			} catch (error) {
				setStatus(error && error.message ? error.message : "Could not delete the passkey.", "error");
			} finally {
				setBusy(false);
			}
		});

		renderPasskeys();
		renderEnvironmentWarnings();
	}());
	</script>
</body>
</html>
HTML;

	exit;
}

function escapeHtml(string $value): string
{
	return htmlentities($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonEncodeForInlineScript(mixed $value): string
{
	return json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: 'null';
}