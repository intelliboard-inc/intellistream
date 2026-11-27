<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Exception for internal export errors.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\exception;

/**
 * An internal error of the export, backfill, login count or encryption code.
 *
 * The plugin catches these itself and never shows them to a user. The exception
 * code tells the caller what happened (for example exporter::REFUSED_TRANSIENT_CODE).
 * It extends \RuntimeException so that callers which catch \RuntimeException
 * keep catching it.
 */
class export_exception extends \RuntimeException {
}
