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

/**
 * Adapter for legacy raw HTML templates.
 */
final readonly class LegacyHtmlTemplate implements WebTemplateInterface
{
    public function __construct(private string $template)
    {
    }

    public function renderLoginSelectionPage(LoginSelectionPage $page): string
    {
        $body = $this->renderNotices($page->notices);

        $trailer = '';
        if ($page->passkeyPrompt) {
            $trailer .= $this->renderPasskeyPrompt($page->passkeyPrompt);
        }
        if ($page->qrPrompt) {
            $trailer .= $this->renderQrPrompt($page->qrPrompt);
        }

        return $this->render(
            $page->title,
            $page->submitLabel,
            $body,
            $this->renderOptions([
                ChoiceOption::radio('type', 'phone', $page->userLabel, true),
                ChoiceOption::radio('type', 'bot', $page->botLabel),
            ]),
            $trailer,
        );
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
        return $this->render(
            $page->title,
            $page->submitLabel,
            $this->renderNotices($page->notices),
            $this->renderOptions($page->options),
            $page->qrPrompt ? $this->renderQrPrompt($page->qrPrompt) : '',
        );
    }

    public function renderFormPage(FormPage $page): string
    {
        return $this->render(
            $page->title,
            $page->submitLabel,
            $this->renderNotices($page->notices),
            $this->renderFields($page->fields),
        );
    }

    public function renderApiPage(ApiPage $page): string
    {
        $body = $this->renderNotices($page->notices);
        if ($page->introHtml !== '') {
            $body .= '<p>'.$page->introHtml.'</p>';
        }

        if ($page->steps !== []) {
            $body .= '<ol>'.implode('', array_map(
                static fn (InstructionStep $step): string => '<li>'.$step->html.'</li>',
                $page->steps,
            )).'</ol>';
        }

        return $this->render(
            $page->title,
            $page->submitLabel,
            $body,
            $this->renderFields($page->fields),
        );
    }

    private function render(string $title, string $submitLabel, string $body, string $form, string $trailer = ''): string
    {
        $content = '<h1>'.$this->escape($title).'</h1>'.$body;

        return sprintf($this->template, $content, $form, $this->escape($submitLabel), $trailer);
    }

    /**
     * @param list<PageNotice> $notices
     */
    private function renderNotices(array $notices): string
    {
        return implode('', array_map(
            static fn (PageNotice $notice): string => '<div>'.$notice->html.'</div>',
            $notices,
        ));
    }

    /**
     * @param list<FormField> $fields
     */
    private function renderFields(array $fields): string
    {
        return implode('', array_map(function (FormField $field): string {
            return sprintf(
                '<label>%s<input%s/></label>',
                $this->escape($field->label),
                $this->renderFieldAttributes($field),
            );
        }, $fields));
    }

    /**
     * @param list<ChoiceOption> $options
     */
    private function renderOptions(array $options): string
    {
        return implode('', array_map(function (ChoiceOption $option): string {
            return sprintf(
                '<label><input type="radio" name="%s" value="%s"%s/>%s</label>',
                $this->escape($option->name),
                $this->escape($option->value),
                $option->checked ? ' checked' : '',
                $this->escape($option->label),
            );
        }, $options));
    }

    private function renderQrPrompt(QrCodePrompt $prompt): string
    {
        $requestPath = json_encode($prompt->requestPath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';

        return sprintf(
            '<div id="qr-code-container" hidden><p>%s</p><div id="qr-code"></div></div>'
            .'<script>(function(){var requestPath=%s;function longPollQr(query){var qrCodeContainer=document.getElementById("qr-code-container");var qrCode=document.getElementById("qr-code");var x=new XMLHttpRequest();x.onload=function(){var res=JSON.parse(this.responseText);if(res.logged_in){window.location=window.location;}else{qrCodeContainer.hidden=false;qrCode.innerHTML=res.svg;longPollQr("waitQrCodeOrLogin");}};x.open("GET", requestPath+"?"+query, true);x.send();}longPollQr("getQrCode");}());</script>',
            $this->escape($prompt->message),
            $requestPath,
        );
    }

    private function renderPasskeyPrompt(PasskeyPrompt $prompt): string
    {
        $requestPath = json_encode($prompt->requestPath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '""';

        return sprintf(
            '<div id="passkey-login-container" hidden><p><strong>%s</strong></p><p>%s</p><button id="passkey-login-button" type="button">%s</button><p id="passkey-login-status" hidden></p></div>'
            .'<script>(function(){var requestPath=%s;var container=document.getElementById("passkey-login-container");var button=document.getElementById("passkey-login-button");var status=document.getElementById("passkey-login-status");if(!container||!button||!status){return;}if(!window.PublicKeyCredential||!navigator.credentials||typeof navigator.credentials.get!=="function"){return;}container.hidden=false;function setStatus(message,isError){status.hidden=false;status.textContent=message;status.style.color=isError?"#dc2626":"inherit";}function toBase64Url(buffer){var bytes=new Uint8Array(buffer);var binary="";for(var i=0;i<bytes.length;i++){binary+=String.fromCharCode(bytes[i]);}return btoa(binary).replace(/\+/g,"-").replace(/\//g,"_").replace(/=+$/g,"");}function fromBase64Url(value){var normalized=value.replace(/-/g,"+").replace(/_/g,"/");while(normalized.length%%4!==0){normalized+="=";}var binary=atob(normalized);var bytes=new Uint8Array(binary.length);for(var i=0;i<binary.length;i++){bytes[i]=binary.charCodeAt(i);}return bytes.buffer;}function normalizePublicKeyOptions(value){var publicKey=value&&value.publicKey?value.publicKey:value;if(!publicKey||typeof publicKey!=="object"){throw new Error("Invalid passkey options received from the server.");}if(typeof publicKey.challenge==="string"){publicKey.challenge=fromBase64Url(publicKey.challenge);}if(Array.isArray(publicKey.allowCredentials)){publicKey.allowCredentials=publicKey.allowCredentials.map(function(credential){if(credential&&typeof credential.id==="string"){credential.id=fromBase64Url(credential.id);}return credential;});}return publicKey;}async function requestJson(url,options){var response=await fetch(url,options);var payload=await response.json();if(!response.ok||(payload&&payload.ok===false)){throw new Error(payload&&payload.error?payload.error:"Passkey login failed.");}return payload;}button.addEventListener("click",async function(){button.disabled=true;setStatus("Approve the passkey request to continue.",false);try{var initPayload=await requestJson(requestPath+"?getPasskeyLogin=1",{headers:{"Accept":"application/json"}});var credential=await navigator.credentials.get({publicKey:normalizePublicKeyOptions(initPayload.publicKey||initPayload.options||initPayload)});if(!credential||!credential.response){throw new Error("No passkey credential was returned by the browser.");}var clientData=JSON.parse(new TextDecoder().decode(credential.response.clientDataJSON));await requestJson(requestPath+"?completePasskeyLogin=1",{method:"POST",headers:{"Accept":"application/json","Content-Type":"application/json"},body:JSON.stringify({credential:{_:"inputPasskeyCredentialPublicKey",id:credential.id,raw_id:toBase64Url(credential.rawId),response:{_:"inputPasskeyResponseLogin",client_data:clientData,authenticator_data:toBase64Url(credential.response.authenticatorData),signature:toBase64Url(credential.response.signature),user_handle:credential.response.userHandle?toBase64Url(credential.response.userHandle):""}}})});window.location=window.location;}catch(error){setStatus(error&&error.message?error.message:"Passkey login failed.",true);}finally{button.disabled=false;}});}());</script>',
            $this->escape($prompt->title),
            $this->escape($prompt->description),
            $this->escape($prompt->buttonLabel),
            $requestPath,
        );
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

    private function escape(string $value): string
    {
        return htmlentities($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}