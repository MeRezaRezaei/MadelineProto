<?php

declare(strict_types=1);

/**
 * Start module.
 *
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

namespace danog\MadelineProto\Wrappers;

use Amp\CancelledException;
use Amp\CompositeCancellation;
use danog\MadelineProto\API;
use danog\MadelineProto\Exception;
use danog\MadelineProto\Ipc\Client;
use danog\MadelineProto\Lang;
use danog\MadelineProto\MTProto;
use danog\MadelineProto\RPCErrorException;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Tools;
use danog\MadelineProto\WebTemplate\BotTokenPage;
use danog\MadelineProto\WebTemplate\LoginSelectionPage;
use danog\MadelineProto\WebTemplate\PageNotice;
use danog\MadelineProto\WebTemplate\PasskeyPrompt;
use danog\MadelineProto\WebTemplate\PasswordPage;
use danog\MadelineProto\WebTemplate\PhoneCodePage;
use danog\MadelineProto\WebTemplate\PhoneNumberPage;
use danog\MadelineProto\WebTemplate\QrCodePrompt;
use danog\MadelineProto\WebTemplate\SignupPage;

use const PHP_SAPI;

use function Amp\ByteStream\getOutputBufferStream;
use function Amp\ByteStream\getStdout;

/**
 * Manages simple logging in and out.
 *
 * @property Settings $settings Settings
 *
 * @internal
 */
trait Start
{
    /**
     * Log in to telegram (via CLI or web).
     */
    public function start(): array
    {
        if ($this->getAuthorization() === \danog\MadelineProto\API::LOGGED_IN) {
            return $this instanceof Client ? $this->getSelf() : $this->fullGetSelf();
        }
        if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
            if ($this->getAuthorization() === API::NOT_LOGGED_IN) {
                $stdout = getStdout();
                do {
                    $qr = $this->qrLogin();
                    if (!$qr) {
                        $this->serialize();
                        return $this->fullGetSelf();
                    }
                    $stdout->write($qr->getQRText(2));

                    $expire = $qr->getExpirationCancellation();
                    $login = $qr->getLoginCancellation();

                    $cancel = new CompositeCancellation($expire, $login);

                    try {
                        $result = Tools::readLine(Lang::$current_lang['loginQr'].PHP_EOL.Lang::$current_lang['loginManual'], $cancel);
                        break;
                    } catch (CancelledException) {
                        if ($login->isRequested()) {
                            $stdout->write(PHP_EOL.PHP_EOL.Lang::$current_lang['loginQrCodeSuccessful'].PHP_EOL);
                            if ($this->getAuthorization() === \danog\MadelineProto\API::WAITING_PASSWORD) {
                                $this->complete2faLogin(Tools::readLine(sprintf(Lang::$current_lang['loginUserPass'], $this->getHint())));
                            }
                            $this->serialize();
                            return $this->fullGetSelf();
                        }

                        $stdout->write(PHP_EOL.Lang::$current_lang['loginQrCodeExpired'].PHP_EOL);
                    }
                } while (true);
                if (str_contains($result, ':')) {
                    $this->botLogin($result);
                } else {
                    $this->phoneLogin($result);
                }
            }
            if ($this->getAuthorization() === \danog\MadelineProto\API::WAITING_CODE) {
                $this->completePhoneLogin(Tools::readLine(Lang::$current_lang['loginUserCode']));
            }
            if ($this->getAuthorization() === \danog\MadelineProto\API::WAITING_PASSWORD) {
                $this->complete2faLogin(Tools::readLine(sprintf(Lang::$current_lang['loginUserPass'], $this->getHint())));
            }
            if ($this->getAuthorization() === \danog\MadelineProto\API::WAITING_SIGNUP) {
                $this->completeSignup(Tools::readLine(Lang::$current_lang['signupFirstName']), Tools::readLine(Lang::$current_lang['signupLastName']));
            }
            $this->serialize();
            return $this->fullGetSelf();
        }
        if ($this->getAuthorization() === API::NOT_LOGGED_IN) {
            if (isset($_GET['getPasskeyLogin'])) {
                $this->webPasskeyLoginOptions();
            } elseif (isset($_GET['completePasskeyLogin'])) {
                $this->webCompletePasskeyLoginRequest();
            } elseif (isset($_POST['phone_number'])) {
                $this->webPhoneLogin();
            } elseif (isset($_POST['token'])) {
                $this->webBotLogin();
            } else {
                $this->webEcho();
            }
        } elseif ($this->getAuthorization() === \danog\MadelineProto\API::WAITING_CODE) {
            if (isset($_POST['phone_code'])) {
                $this->webCompletePhoneLogin();
            } else {
                $this->webEcho(Lang::$current_lang['loginNoCode']);
            }
        } elseif ($this->getAuthorization() === \danog\MadelineProto\API::WAITING_PASSWORD) {
            if (isset($_POST['password'])) {
                $this->webComplete2faLogin();
            } else {
                $this->webEcho(Lang::$current_lang['loginUserPassWeb']);
            }
        } elseif ($this->getAuthorization() === \danog\MadelineProto\API::WAITING_SIGNUP) {
            if (isset($_POST['first_name'])) {
                $this->webCompleteSignup();
            } else {
                $this->webEcho(Lang::$current_lang['loginNoName']);
            }
        }
        if ($this->getAuthorization() === \danog\MadelineProto\API::LOGGED_IN) {
            $this->serialize();
            return $this->fullGetSelf();
        }
        die;
    }
    private function webPhoneLogin(): void
    {
        try {
            $this->phoneLogin($_POST['phone_number']);
            $this->webEcho();
        } catch (RPCErrorException $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        } catch (Exception $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        }
    }
    private function webCompletePhoneLogin(): void
    {
        try {
            $this->completePhoneLogin($_POST['phone_code']);
            $this->webEcho();
        } catch (RPCErrorException $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        } catch (Exception $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        }
    }
    private function webComplete2faLogin(): void
    {
        try {
            $this->complete2faLogin($_POST['password']);
            $this->webEcho();
        } catch (RPCErrorException $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        } catch (Exception $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        }
    }
    private function webCompleteSignup(): void
    {
        try {
            $this->completeSignup($_POST['first_name'], $_POST['last_name'] ?? '');
            $this->webEcho();
        } catch (RPCErrorException $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        } catch (Exception $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        }
    }
    private function webBotLogin(): void
    {
        try {
            $this->botLogin($_POST['token']);
            $this->webEcho();
        } catch (RPCErrorException $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        } catch (Exception $e) {
            $this->webEcho(sprintf(Lang::$current_lang['apiError'], $e->getMessage()));
        }
    }

    private function webPasskeyLoginOptions(): void
    {
        try {
            $options = $this->getPasskeyLoginOptions();
            $this->webJsonResponse([
                'ok' => true,
                'publicKey' => $options['options'] ?? null,
            ]);
        } catch (RPCErrorException $e) {
            $this->webJsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            $this->webJsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    private function webCompletePasskeyLoginRequest(): void
    {
        try {
            $payload = json_decode(file_get_contents('php://input') ?: '[]', true);
            if (!\is_array($payload) || !isset($payload['credential']) || !\is_array($payload['credential'])) {
                throw new Exception('Missing passkey credential payload.');
            }

            $this->completePasskeyLogin($this->normalizeWebPasskeyCredential($payload['credential']));
            $this->webJsonResponse([
                'ok' => true,
                'reload' => true,
            ]);
        } catch (RPCErrorException $e) {
            $this->webJsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            $this->webJsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
        }
    }

    /**
     * @param array<string, mixed> $credential
     * @return array{_: 'inputPasskeyCredentialPublicKey', id: string, raw_id: string, response: array{_: 'inputPasskeyResponseLogin', client_data: mixed, authenticator_data: string, signature: string, user_handle: string}}
     */
    private function normalizeWebPasskeyCredential(array $credential): array
    {
        if (($credential['_'] ?? null) !== 'inputPasskeyCredentialPublicKey') {
            throw new Exception('Invalid passkey credential type.');
        }
        if (!isset($credential['id']) || !\is_string($credential['id']) || $credential['id'] === '') {
            throw new Exception('Missing passkey credential ID.');
        }
        if (!isset($credential['raw_id']) || !\is_string($credential['raw_id']) || $credential['raw_id'] === '') {
            throw new Exception('Missing passkey raw credential ID.');
        }
        if (!isset($credential['response']) || !\is_array($credential['response'])) {
            throw new Exception('Missing passkey response payload.');
        }

        $response = $credential['response'];
        if (($response['_'] ?? null) !== 'inputPasskeyResponseLogin') {
            throw new Exception('Invalid passkey response type.');
        }
        if (!isset($response['client_data'])) {
            throw new Exception('Missing passkey client data.');
        }
        if (\is_string($response['client_data'])) {
            $decodedClientData = json_decode($response['client_data'], true);
            if (!\is_array($decodedClientData)) {
                throw new Exception('Invalid passkey client data.');
            }
            $response['client_data'] = $decodedClientData;
        }
        foreach (['authenticator_data', 'signature'] as $field) {
            if (!isset($response[$field]) || !\is_string($response[$field]) || $response[$field] === '') {
                throw new Exception("Missing passkey {$field}.");
            }
            $response[$field] = Tools::base64urlDecode($response[$field]);
        }
        $credential['raw_id'] = Tools::base64urlDecode($credential['raw_id']);
        if (!isset($response['user_handle']) || !\is_string($response['user_handle']) || $response['user_handle'] === '') {
            $response['user_handle'] = '';
        } else {
            $response['user_handle'] = Tools::base64urlDecode($response['user_handle']);
        }

        $credential['response'] = $response;

        /** @var array{_: 'inputPasskeyCredentialPublicKey', id: string, raw_id: string, response: array{_: 'inputPasskeyResponseLogin', client_data: mixed, authenticator_data: string, signature: string, user_handle: string}} $credential */
        return $credential;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function webJsonResponse(array $payload, int $status = 200): void
    {
        http_response_code($status);
        header('Content-type: application/json');
        getOutputBufferStream()->write((string) json_encode($payload));
    }

    /**
     * Echo page to console.
     *
     * @param string $message Error message
     */
    private function webEcho(string $message = ''): void
    {
        $auth = $this->getAuthorization();
        $renderer = $this->getSettings()->getTemplates()->getHtmlTemplateRenderer();
        if ($auth === API::NOT_LOGGED_IN) {
            if (isset($_POST['type'])) {
                if ($_POST['type'] === 'phone') {
                    getOutputBufferStream()->write($renderer->renderPhoneNumberPage(new PhoneNumberPage(
                        str_replace(':', '', Lang::$current_lang['loginUser']),
                        Lang::$current_lang['go'],
                        $this->getWebNotices($message),
                        Lang::$current_lang['loginUserPhoneWeb'],
                    )));
                } else {
                    getOutputBufferStream()->write($renderer->renderBotTokenPage(new BotTokenPage(
                        str_replace(':', '', Lang::$current_lang['loginBot']),
                        Lang::$current_lang['go'],
                        $this->getWebNotices($message),
                        Lang::$current_lang['loginBotTokenWeb'],
                    )));
                }
            } elseif (isset($_GET['waitQrCodeOrLogin']) || isset($_GET['getQrCode'])) {
                header('Content-type: application/json');
                try {
                    $qr = $this->qrLogin();
                    if (isset($_GET['waitQrCodeOrLogin'])) {
                        $qr = $qr?->waitForLoginOrQrCodeExpiration(
                            Tools::getTimeoutCancellation(5.0, "Timeout while waiting for QR code or login")
                        );
                    }
                } catch (CancelledException) {
                    $qr = $this->qrLogin();
                }
                if ($qr) {
                    $result = [
                        'logged_in' => false,
                        'svg' => $qr->getQRSvg(400, 2),
                    ];
                } else {
                    $result = [
                        'logged_in' => true,
                    ];
                }
                getOutputBufferStream()->write(json_encode($result));
                return;
            } else {
                \assert(isset($_SERVER['REQUEST_URI']));
                $requestPath = explode('?', $_SERVER['REQUEST_URI'], 2)[0] ?? '';
                getOutputBufferStream()->write($renderer->renderLoginSelectionPage(new LoginSelectionPage(
                    Lang::$current_lang['loginChoosePromptWeb'],
                    Lang::$current_lang['go'],
                    $this->getWebNotices($message),
                    Lang::$current_lang['loginOptionUser'],
                    Lang::$current_lang['loginOptionBot'],
                    new QrCodePrompt(
                        Lang::$current_lang['loginWebQr'],
                        $requestPath,
                        [
                            Lang::$current_lang['loginWebQr1'],
                            Lang::$current_lang['loginWebQr2'],
                            Lang::$current_lang['loginWebQr3'],
                        ],
                    ),
                    new PasskeyPrompt(
                        'Passkey login',
                        'Sign in with a saved passkey on this device using WebAuthn.',
                        'Use a passkey',
                        $requestPath,
                    ),
                )));
            }
        } elseif ($auth === \danog\MadelineProto\API::WAITING_CODE) {
            getOutputBufferStream()->write($renderer->renderPhoneCodePage(new PhoneCodePage(
                str_replace(':', '', Lang::$current_lang['loginUserCode']),
                Lang::$current_lang['go'],
                $this->getWebNotices($message),
                Lang::$current_lang['loginUserPhoneCodeWeb'],
            )));
        } elseif ($auth === \danog\MadelineProto\API::WAITING_PASSWORD) {
            getOutputBufferStream()->write($renderer->renderPasswordPage(new PasswordPage(
                Lang::$current_lang['loginUserPassWeb'],
                Lang::$current_lang['go'],
                $this->getWebNotices($message),
                sprintf(Lang::$current_lang['loginUserPassHint'], $this->getHint()),
            )));
        } elseif ($auth === \danog\MadelineProto\API::WAITING_SIGNUP) {
            getOutputBufferStream()->write($renderer->renderSignupPage(new SignupPage(
                Lang::$current_lang['signupWeb'],
                Lang::$current_lang['go'],
                $this->getWebNotices($message),
                Lang::$current_lang['signupFirstNameWeb'],
                Lang::$current_lang['signupLastNameWeb'],
            )));
        } else {
            return;
        }
    }

    /**
     * Build notices for the web login pages.
     *
     * @return list<PageNotice>
     */
    private function getWebNotices(string $message = ''): array
    {
        $notices = [];
        if ($message !== '') {
            $notices[] = PageNotice::error($message);
        }

        $warnings = MTProto::getWebWarnings();
        if ($warnings !== '') {
            $notices[] = PageNotice::html('warning', $warnings);
        }

        return $notices;
    }
}
