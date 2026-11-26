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
 * Exceptions datatype shape for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\datatypes;

/**
 * Defines the on-the-wire shape of an `exceptions` record.
 *
 * intellidata-parity note: intellidata's own source tree under
 * `entities/exceptions/` contains only a single exception CLASS
 * (`invalid_parameter_exception`), not a captured datatype, and is never
 * referenced from `datatypes_service::get_required_datatypes()`. There
 * is therefore no upstream record schema to mirror byte-for-byte; this
 * class declares the schema we have chosen for parity-of-coverage, so
 * downstream ETL has a single source of truth.
 *
 * The record is buffered (via `\local_intellistream\buffer::append_record()`)
 * by `\local_intellistream\observers\exceptions_observer`. It is NOT a row
 * the Moodle DB ever stores; the entity key is `exceptions` and the
 * `record_type` field is `exception`.
 *
 * Record schema:
 *
 *   id              uuid     — record identity, generated per event
 *   site_id         uuid     — stable site id (config::site_id())
 *   captured_at     int      — unix seconds (clock::now())
 *   plugin_version  string   — this plugin's version code
 *   moodle_version  int|null — $CFG->version when known
 *   record_type     string   — fixed literal 'exception'
 *   error_class     string   — Moodle event class (e.g. webservice_function_called)
 *                              or PHP exception class when invoked from an
 *                              external handler
 *   error_message   string   — short human-readable summary
 *   error_file      string   — source file (best-effort; may be empty for events)
 *   error_line      int|null — source line (best-effort; may be null for events)
 *   stack_trace     string   — debug_backtrace() rendered as a string,
 *                              truncated to fit MAX_EVENT_BYTES (see buffer)
 *   userid          int|null — acting Moodle user id (0 when no session)
 *   courseid        int|null — course context if discoverable, else null
 *   url             string   — current request URL (best-effort)
 *
 * This class is a documentation / marker only -- it intentionally has no
 * runtime methods. The observer constructs the record dict directly,
 * matching the buffer-record convention used by the existing
 * `local_intellistream\observer::capture()` hot-path.
 *
 * @copyright 2026 IntelliBoard / IntelliStream
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exceptions_datatype {
    /** Entity / registry key. */
    const ENTITY = 'exceptions';

    /** Fixed record_type literal stamped on every buffered record. */
    const RECORD_TYPE = 'exception';

    /**
     * Documentation-only schema list -- enumerates the fields the
     * observer must set on every record. Downstream consumers can
     * introspect this if they need to validate or document the feed.
     *
     * @return string[]
     */
    public static function fields(): array {
        return [
            'id',
            'site_id',
            'captured_at',
            'plugin_version',
            'moodle_version',
            'record_type',
            'error_class',
            'error_message',
            'error_file',
            'error_line',
            'stack_trace',
            'userid',
            'courseid',
            'url',
        ];
    }
}
