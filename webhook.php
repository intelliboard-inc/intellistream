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
 * Inbound control-webhook endpoint for local_intellistream.
 *
 * Receives an HMAC-signed POST from the IntelliStream control plane (supernova)
 * carrying an operational command — reset migration, reset datatype, delete
 * ad-hoc task (and, later, other actions). It authenticates the request itself
 * (a per-connection shared secret, NOT a Moodle web-service token) and delegates
 * verification + execution to \local_intellistream\webhook_commands.
 *
 * This is a lightweight signed API receiver, not a Moodle form/page — AJAX_SCRIPT
 * keeps Moodle from emitting page chrome or redirecting to login, and
 * NO_MOODLE_COOKIES=true means no session is started (auth IS the HMAC).
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_MOODLE_COOKIES', true);

require(__DIR__ . '/../../config.php');

header('Content-Type: application/json; charset=utf-8');

/**
 * Emit a terminal JSON response and stop.
 *
 * @param int $httpstatus
 * @param array $body
 * @return void
 */
function local_intellistream_webhook_respond(int $httpstatus, array $body): void {
    if (!headers_sent()) {
        http_response_code($httpstatus);
    }
    echo json_encode($body);
    exit;
}

try {
    // Master gate. Mirrors the observer's config::enabled() gate.
    if (!\local_intellistream\config::enabled()) {
        local_intellistream_webhook_respond(403, ['status' => 'error', 'detail' => 'disabled']);
    }

    // Commands are POST-only.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        local_intellistream_webhook_respond(405, ['status' => 'error', 'detail' => 'method']);
    }

    // HTTPS enforcement (default on). Toggleable only for a co-located/test box
    // served over plain HTTP; production always keeps this on. is_https() honours
    // Moodle's reverse-proxy config ($CFG->sslproxy).
    if (\local_intellistream\config::webhook_require_https() && !is_https()) {
        local_intellistream_webhook_respond(400, ['status' => 'error', 'detail' => 'https_required']);
    }

    $raw = (string)file_get_contents('php://input');
    // The signature travels in a header so it is not part of the signed body.
    $sig = (string)($_SERVER['HTTP_X_INTELLISTREAM_SIGNATURE'] ?? '');

    [$status, $body] = \local_intellistream\webhook_commands::handle($raw, $sig);
    local_intellistream_webhook_respond($status, $body);
} catch (\Throwable $e) {
    // Never leak internals to the caller.
    local_intellistream_webhook_respond(500, ['status' => 'error', 'detail' => 'error']);
}
