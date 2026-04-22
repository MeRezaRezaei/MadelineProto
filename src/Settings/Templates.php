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

namespace danog\MadelineProto\Settings;

use danog\MadelineProto\SettingsAbstract;
use danog\MadelineProto\WebTemplate\MadelineProtoTemplate;
use danog\MadelineProto\WebTemplate\LegacyHtmlTemplate;
use danog\MadelineProto\WebTemplate\WebTemplateFactory;
use danog\MadelineProto\WebTemplate\WebTemplateInterface;

use function hash;
use function in_array;

/**
 * Web and CLI template settings for login.
 */
final class Templates extends SettingsAbstract
{
    /**
     * Previous built-in web template used for querying app information.
     */
    private const LEGACY_HTML_TEMPLATE = '<!DOCTYPE html><html><head><title>MadelineProto</title></head><body><h1>MadelineProto</h1><p>%s</p><form method="POST">%s<button type="submit"/>%s</button></form>%s</body></html>';

    /**
     * SHA-256 of the previous built-in Material-styled template.
     */
    private const PREVIOUS_DEFAULT_HTML_TEMPLATE_SHA256 = 'f475fd45c58823764d8eddf00290f62dc65aa0cbba9df32e7e72b8baaf4c0634';

    /**
     * SHA-256 of the previous built-in Materialize-based template.
     */
    private const MATERIALIZE_DEFAULT_HTML_TEMPLATE_SHA256 = 'd476343e390f25adbad82d647251b8e56c01eb7d2a31137cf9f819dbc104cf51';

    /**
     * Previous built-in renderer class name before the custom CSS refresh.
     */
    private const PREVIOUS_DEFAULT_HTML_TEMPLATE_CLASS = 'danog\\MadelineProto\\WebTemplate\\TailwindTelegramTemplate';

    /**
     * Default web template renderer class used for querying app information.
     */
    private const DEFAULT_HTML_TEMPLATE = MadelineProtoTemplate::class;

    /**
     * Web template renderer class used for querying app information.
     */
    protected string $htmlTemplate = self::DEFAULT_HTML_TEMPLATE;

    /**
     * Legacy raw HTML template markup for the legacy template adapter.
     */
    protected string $htmlTemplateMarkup = '';

    public function __wakeup(): void
    {
        if (!isset($this->htmlTemplateMarkup)) {
            $this->htmlTemplateMarkup = '';
        }
    }

    /**
     * Normalize a stored template identifier to a renderer class and optional legacy markup.
     *
     * @return array{0: string, 1: string}
     */
    public static function normalizeTemplateIdentifier(string $template, bool $allowEmpty = false, string $legacyMarkup = ''): array
    {
        if ($template === '') {
            return [$allowEmpty ? '' : self::DEFAULT_HTML_TEMPLATE, ''];
        }

        if ($template === LegacyHtmlTemplate::class) {
            return $legacyMarkup === ''
                ? [$allowEmpty ? '' : self::DEFAULT_HTML_TEMPLATE, '']
                : [LegacyHtmlTemplate::class, $legacyMarkup];
        }

        if ($template === self::PREVIOUS_DEFAULT_HTML_TEMPLATE_CLASS) {
            return [self::DEFAULT_HTML_TEMPLATE, ''];
        }

        if ($template === self::LEGACY_HTML_TEMPLATE || in_array(hash('sha256', $template), [self::PREVIOUS_DEFAULT_HTML_TEMPLATE_SHA256, self::MATERIALIZE_DEFAULT_HTML_TEMPLATE_SHA256], true)) {
            return [self::DEFAULT_HTML_TEMPLATE, ''];
        }

        if (WebTemplateFactory::isTemplateClass($template)) {
            return [$template, ''];
        }

        return [LegacyHtmlTemplate::class, $template];
    }

    #[\Override]
    public function merge(SettingsAbstract $other): void
    {
        if (!$other instanceof self) {
            parent::merge($other);
            return;
        }

        [$template, $markup] = self::normalizeTemplateIdentifier($other->htmlTemplate, false, $other->htmlTemplateMarkup);
        if ($template !== $this->getHtmlTemplate() || $markup !== $this->getHtmlTemplateMarkup()) {
            $this->htmlTemplate = $template;
            $this->htmlTemplateMarkup = $markup;
            $this->changed = true;
        }
    }

    /**
     * Get web template renderer class used for querying app information.
     */
    public function getHtmlTemplate(): string
    {
        [$this->htmlTemplate, $this->htmlTemplateMarkup] = self::normalizeTemplateIdentifier($this->htmlTemplate, false, $this->htmlTemplateMarkup);

        return $this->htmlTemplate;
    }

    /**
     * Get legacy raw HTML template markup, if any.
     */
    public function getHtmlTemplateMarkup(): string
    {
        $this->getHtmlTemplate();

        return $this->htmlTemplateMarkup;
    }

    /**
     * Instantiate the configured web template renderer.
     */
    public function getHtmlTemplateRenderer(): WebTemplateInterface
    {
        return WebTemplateFactory::fromTemplate($this->getHtmlTemplate(), $this->htmlTemplateMarkup);
    }

    /**
     * Set web template renderer class used for querying app information.
     *
     * Legacy raw HTML strings are automatically wrapped by the legacy adapter renderer.
     *
     * @param string $htmlTemplate Web template renderer class or legacy raw HTML template.
     */
    public function setHtmlTemplate(string $htmlTemplate): self
    {
        [$this->htmlTemplate, $this->htmlTemplateMarkup] = self::normalizeTemplateIdentifier($htmlTemplate, false);

        return $this;
    }
}
