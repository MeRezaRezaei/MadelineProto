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

/**
 * Input field definition for forms.
 */
final readonly class FormField
{
    public function __construct(
        public string $name,
        public string $label,
        public string $type = 'text',
        public bool $required = true,
        public ?string $autocomplete = null,
        public ?string $inputMode = null,
        public ?string $autocapitalize = null,
        public bool $autofocus = false,
    ) {
    }

    public static function text(
        string $name,
        string $label,
        bool $required = true,
        ?string $autocomplete = null,
        bool $autofocus = false,
        ?string $inputMode = null,
        ?string $autocapitalize = null,
    ): self {
        return new self($name, $label, 'text', $required, $autocomplete, $inputMode, $autocapitalize, $autofocus);
    }

    public static function password(
        string $name,
        string $label,
        bool $required = true,
        ?string $autocomplete = null,
        bool $autofocus = false,
    ): self {
        return new self($name, $label, 'password', $required, $autocomplete, null, null, $autofocus);
    }
}
