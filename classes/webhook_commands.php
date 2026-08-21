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
 * Inbound control-webhook command dispatcher for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Verifies and executes signed control-plane commands delivered to webhook.php.
 *
 * Transport-agnostic: webhook.php reads the raw request body + the signature
 * header and hands both here. This class does ALL of the auth + dispatch so the
 * public entry point stays a thin shim (mirrors how dwell.php delegates capture
 * to buffer/exporter).
 *
 * Auth model (a signed request, NOT a Moodle web-service token — the control
 * plane holds no Moodle account for a push site):
 *   - HMAC-SHA256 over the RAW body with the per-connection `webhook_secret`
 *     (minted by the control plane, pasted into plugin settings). Verified
 *     FIRST and in constant time so unsigned/forged requests die cheaply.
 *   - `timestamp` inside the signed body must be within ±TS_WINDOW seconds
 *     (anti-replay for a captured request).
 *   - `command_id` is the idempotency key: a re-delivered command (the caller
 *     retries with backoff) is applied at most once (MUC `webhook_seen` guard).
 *   - `site_id` in the body must equal this plugin's configured Site ID — a
 *     second identity check so a misrouted call is rejected.
 *
 * The dispatcher is deliberately general-purpose: `action` selects a handler,
 * so further actions plug in with one case.
 *
 * All handlers reuse EXISTING local primitives — no new backfill/reset logic.
 * Long operations (a reset triggers the multi-minute backfill) queue an adhoc
 * task and return 202 immediately; they never run inline in the HTTP request.
 */
class webhook_commands {
    /** Seconds of clock skew tolerated between the control plane and this site. */
    const TS_WINDOW = 300;

    /** Largest request body we will read (defensive). */
    const MAX_BODY = 65536;

    /** MUC application-cache area used for the command_id replay guard. */
    const SEEN_CACHE = 'webhook_seen';

    /**
     * Verify + dispatch one signed command.
     *
     * @param string $rawbody Raw request body (php://input).
     * @param string $sig     Client-supplied hex HMAC-SHA256 signature.
     * @return array{0:int,1:array} [http status, JSON-serialisable body]
     */
    public static function handle(string $rawbody, string $sig): array {
        $secret = config::webhook_secret();
        if ($secret === '') {
            // Not paired for control commands yet.
            return [503, ['status' => 'error', 'detail' => 'not_configured']];
        }
        if ($rawbody === '' || strlen($rawbody) > self::MAX_BODY) {
            return [400, ['status' => 'error', 'detail' => 'bad_request']];
        }

        // 1) Signature FIRST (cheap constant-time reject before any parsing/DB).
        $expected = hash_hmac('sha256', $rawbody, $secret);
        if ($sig === '' || !hash_equals($expected, $sig)) {
            return [403, ['status' => 'error', 'detail' => 'forbidden']];
        }

        $in = json_decode($rawbody, true);
        if (!is_array($in)) {
            return [400, ['status' => 'error', 'detail' => 'bad_request']];
        }

        // 2) Freshness window (anti-replay for a captured request).
        $ts = (int)($in['timestamp'] ?? 0);
        if ($ts <= 0 || abs(time() - $ts) > self::TS_WINDOW) {
            return [408, ['status' => 'error', 'detail' => 'stale']];
        }

        // 3) Second identity check: the command must target THIS site.
        $siteid = config::site_id();
        if ($siteid === '' || (string)($in['site_id'] ?? '') !== $siteid) {
            return [409, ['status' => 'error', 'detail' => 'site_mismatch']];
        }

        // 4) Idempotency: apply each command_id at most once (retries no-op).
        $cmdid = (string)($in['command_id'] ?? '');
        if ($cmdid === '') {
            return [400, ['status' => 'error', 'detail' => 'bad_request']];
        }
        $cache = \cache::make(config::COMPONENT, self::SEEN_CACHE);
        if ($cache->has($cmdid)) {
            return [200, ['status' => 'ok', 'detail' => 'already_applied', 'command_id' => $cmdid]];
        }

        // 5) Dispatch. A handler failure is isolated + reported generically.
        $action = (string)($in['action'] ?? '');
        $payload = isset($in['payload']) && is_array($in['payload']) ? $in['payload'] : [];
        try {
            [$status, $body] = self::dispatch($action, $payload);
        } catch (\Throwable $e) {
            debugging('local_intellistream webhook ' . $action . ' failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return [500, ['status' => 'error', 'detail' => 'exec_failed']];
        }

        // Mark applied only on a non-error outcome so a transient failure can be
        // retried by the caller (the command_id survives).
        if ($status < 400) {
            $cache->set($cmdid, time());
        }
        $body['command_id'] = $cmdid;
        return [$status, $body];
    }

    /**
     * Route an action to its handler.
     *
     * @param string $action
     * @param array $payload
     * @return array{0:int,1:array}
     */
    private static function dispatch(string $action, array $payload): array {
        switch ($action) {
            case 'reset_migration':
                return self::reset_migration();
            case 'reset_datatype':
                return self::reset_datatype((string)($payload['datatype'] ?? ''));
            case 'delete_adhoc':
                return self::delete_adhoc((int)($payload['id'] ?? 0));
            case 'set_lti_role':
                return self::set_lti_role(
                    (isset($payload['ids']) && is_array($payload['ids'])) ? $payload['ids'] : [],
                    (isset($payload['roles']) && is_array($payload['roles'])) ? $payload['roles'] : []
                );
            case 'get_plugin_config':
                return self::get_plugin_config();
            case 'set_plugin_config':
                return self::set_plugin_config((string)($payload['name'] ?? ''), $payload['value'] ?? null);
            case 'update_ingest_credentials':
                return self::update_ingest_credentials(
                    (string)($payload['accesskey'] ?? ''),
                    (string)($payload['secretkey'] ?? '')
                );
            case 'save_task':
                return self::save_task($payload);
            case 'targeted_refetch':
                return self::targeted_refetch(
                    (isset($payload['entities']) && is_array($payload['entities'])) ? $payload['entities'] : [],
                    (int)($payload['from'] ?? 0),
                    (int)($payload['to'] ?? 0)
                );
            case 'get_datatype_config':
                return self::get_datatype_config((string)($payload['datatype'] ?? ''));
            case 'save_datatype_config':
                return self::save_datatype_config($payload);
            default:
                return [400, ['status' => 'error', 'detail' => 'unknown_action']];
        }
    }

    /**
     * Reset the whole migration: clear every backfill watermark/flag, then
     * queue a full backfill run. Equivalent to IntelliData's reset_migration.
     *
     * @return array{0:int,1:array}
     */
    private static function reset_migration(): array {
        backfill::reset();
        self::queue_backfill([]);
        return [202, ['status' => 'accepted', 'action' => 'reset_migration']];
    }

    /**
     * Reset a single datatype: clear that entity's watermark + done flag (and
     * the sticky campaign-complete flag so the run proceeds), then queue a
     * backfill restricted to that entity — only it re-ships.
     *
     * @param string $datatype Exporter registry entity name.
     * @return array{0:int,1:array}
     */
    private static function reset_datatype(string $datatype): array {
        if ($datatype === '') {
            return [400, ['status' => 'error', 'detail' => 'missing_datatype']];
        }
        // Only real keyset entities carry a per-entity watermark; reject the
        // derived/aggregate markers (a per-entity reset is meaningless there).
        $registry = exporter::registry_with_overrides();
        if (!isset($registry[$datatype]) || !empty($registry[$datatype]['derived'])) {
            return [422, ['status' => 'error', 'detail' => 'not_resettable', 'datatype' => $datatype]];
        }
        unset_config('backfill_wm_' . $datatype, config::COMPONENT);
        unset_config('backfill_done_' . $datatype, config::COMPONENT);
        unset_config('backfill_complete', config::COMPONENT);
        self::queue_backfill([$datatype]);
        return [202, ['status' => 'accepted', 'action' => 'reset_datatype', 'datatype' => $datatype]];
    }

    /**
     * Delete a queued ad-hoc task by id — ONLY if it belongs to this plugin.
     * (A push site normally has no export ad-hoc tasks, so this usually finds
     * nothing to do; included for parity with IntelliData.)
     *
     * @param int $id mdl_task_adhoc.id
     * @return array{0:int,1:array}
     */
    private static function delete_adhoc(int $id): array {
        global $DB;
        if ($id <= 0) {
            return [400, ['status' => 'error', 'detail' => 'missing_id']];
        }
        $record = $DB->get_record('task_adhoc', ['id' => $id]);
        if (!$record) {
            return [200, ['status' => 'ok', 'detail' => 'not_found', 'id' => $id]];
        }
        // Guard: never touch another component's task.
        $classname = ltrim((string)$record->classname, '\\');
        if (strpos($classname, 'local_intellistream\\') !== 0) {
            return [403, ['status' => 'error', 'detail' => 'not_owned']];
        }
        $DB->delete_records('task_adhoc', ['id' => $id]);
        return [200, ['status' => 'ok', 'action' => 'delete_adhoc', 'id' => $id]];
    }

    /**
     * (Re)assign the IntelliBoard LTI role to a set of users. Mirrors IntelliData's
     * `local_intellidata_set_lti_role` WS: the control plane computes {ids, roles}
     * (explicit user ids ∪ holders of the mapped source roles) from the connection's
     * Roles Settings + Enable Learner/Teacher LTI toggles and delivers them here.
     * Queues the existing adhoc task (same one the removed WS used) and returns 202;
     * the diff-based convergence engine + int-hardening live in
     * lti_service::set_lti_role.
     *
     * @param array $ids   Explicit Moodle user ids.
     * @param array $roles Source role ids whose holders also get the LTI role.
     * @return array{0:int,1:array}
     */
    private static function set_lti_role(array $ids, array $roles): array {
        $task = new \local_intellistream\task\set_lti_role_adhoc_task();
        $task->set_custom_data(['ids' => array_values($ids), 'roles' => array_values($roles)]);
        \core\task\manager::queue_adhoc_task($task);
        return [202, ['status' => 'accepted', 'action' => 'set_lti_role']];
    }

    /**
     * Queue a TARGETED re-fetch triggered from the control plane
     * (supernova-admin "Targeted re-fetch" action) instead of the plugin's own
     * refetch.php page. Re-emits a chosen set of entity types for a [from, to]
     * window into the buffer -> S3; a repair tool for pipeline gaps.
     *
     * Reuses the exact same adhoc task the page trigger queues
     * ({@see \local_intellistream\task\run_targeted_refetch_adhoc_task}) so both
     * surfaces share one code path: the task reads {entities, from, to} custom
     * data and calls {@see \local_intellistream\targeted_refetch::run()}, which
     * is stateless (own batch, no cdc_state/backfill watermark mutation) and idempotent.
     *
     * The window is validated here (the driver guards again on execute); an empty
     * entity list means "all entities". Timestamps are epoch seconds (UTC).
     *
     * @param array $entities Registry keys to re-fetch; empty = all.
     * @param int $from Window start (epoch seconds), inclusive.
     * @param int $to Window end (epoch seconds), inclusive.
     * @return array{0:int,1:array}
     */
    private static function targeted_refetch(array $entities, int $from, int $to): array {
        if ($from <= 0 || $to <= 0 || $from >= $to) {
            // Require a strictly positive window; from === to is a zero-duration
            // no-op that would otherwise return 202 while fetching nothing.
            return [400, ['status' => 'error', 'detail' => 'invalid_window']];
        }
        $task = new \local_intellistream\task\run_targeted_refetch_adhoc_task();
        $task->set_custom_data([
            'entities' => array_values($entities),
            'from'     => $from,
            'to'       => $to,
        ]);
        \core\task\manager::queue_adhoc_task($task);
        return [202, ['status' => 'accepted', 'action' => 'targeted_refetch']];
    }

    /**
     * Return the plugin config + Moodle env + scheduled-task registry snapshot so
     * the control-plane admin can DISPLAY it (the "Moodle config" / "Plugin
     * config" / "Cron config" panels). Read-only; assembled live from the plugin's
     * own settings page + Moodle's task tables. Synchronous (cheap) — the answer
     * travels back in the `data` field of this response body.
     *
     * @return array{0:int,1:array}
     */
    private static function get_plugin_config(): array {
        return [200, [
            'status' => 'ok',
            'action' => 'get_plugin_config',
            'data'   => \local_intellistream\services\plugin_report::config_snapshot(),
        ]];
    }

    /**
     * Set a single plugin config value from the control-plane admin ("Edit plugin
     * data"). Only a real, non-secret setting of THIS plugin may be written
     * ({@see plugin_report::is_writable}); unknown/secret keys are rejected.
     * Mirrors IntelliData's `local_intellidata_set_plugin_config` write.
     *
     * @param string $name  Config key (no plugin prefix), e.g. 'trackmedia'.
     * @param mixed  $value Scalar value to store.
     * @return array{0:int,1:array}
     */
    private static function set_plugin_config(string $name, $value): array {
        if ($name === '') {
            return [400, ['status' => 'error', 'detail' => 'missing_name']];
        }
        if (!\local_intellistream\services\plugin_report::is_writable($name)) {
            return [422, ['status' => 'error', 'detail' => 'not_writable', 'name' => $name]];
        }
        if ($value !== null && !is_scalar($value)) {
            return [400, ['status' => 'error', 'detail' => 'bad_value']];
        }
        // Apply the setting's declared PARAM_* type. is_writable() above says which
        // keys may be written; this says what may be stored in one. The type binds
        // only the admin form, so before this the webhook could put a non-URL in
        // `endpoint` or a non-numeric in `loadgatefactor`.
        $clean = \local_intellistream\services\plugin_report::clean_for_write($name, $value);
        if ($clean === null) {
            return [400, ['status' => 'error', 'detail' => 'bad_value', 'name' => $name]];
        }
        set_config($name, $clean, config::COMPONENT);
        // The 'updated' key mirrors the IntelliData WS reply the admin save flow checks for.
        return [200, ['status' => 'updated', 'action' => 'set_plugin_config', 'name' => $name]];
    }

    /**
     * Rotate this site's S3 ingest credential from the control plane. Writes the NEW
     * access + secret key delivered over the signed webhook; the shipper reads them on its next run
     * (s3_client::from_config(), no caching), so events keep flowing to the SAME events/<site_id>/
     * folder under the new credential. ONLY these two keys change — bucket, endpoint, region, prefix
     * and Site ID are untouched.
     *
     * Unlike set_plugin_config this deliberately writes the *secret* accesskey/secretkey settings
     * (which is_writable() blocks) — it IS the credential-rotation channel, authenticated by the same
     * HMAC signature every webhook command carries. After writing it drops the plugin's config-cache
     * entry so a fresh cron/web process reads the rotated values immediately. (A live long-running
     * worker's in-process static cache cannot be reached from here; the control plane covers that by
     * gating old-key revocation on ship-proof — see plugin_report ship_proof + the admin confirm job.)
     *
     * @param string $accesskey New S3 access key.
     * @param string $secretkey New S3 secret key.
     * @return array{0:int,1:array}
     */
    private static function update_ingest_credentials(string $accesskey, string $secretkey): array {
        $accesskey = trim($accesskey);
        $secretkey = trim($secretkey);
        if ($accesskey === '' || $secretkey === '') {
            return [400, ['status' => 'error', 'detail' => 'missing_credentials']];
        }

        set_config('accesskey', $accesskey, config::COMPONENT);
        set_config('secretkey', $secretkey, config::COMPONENT);

        // Belt-and-suspenders: explicitly drop the plugin's entry from the core config cache (same
        // primitive set_config uses) so any NEW process reads the rotated credential without waiting.
        \cache::make('core', 'config')->delete(config::COMPONENT);

        return [200, ['status' => 'updated', 'action' => 'update_ingest_credentials']];
    }

    /**
     * Reschedule / enable / disable one of THIS plugin's scheduled tasks from the
     * control-plane admin ("Edit cron task"). Guards ownership (never touches
     * another component's task) and validates the cron fields. Mirrors
     * IntelliData's `local_intellidata_save_task` write.
     *
     * @param array $payload {taskname|classname, minute, hour, day, month, dayofweek, disabled}
     * @return array{0:int,1:array}
     */
    private static function save_task(array $payload): array {
        $classname = trim((string)($payload['classname'] ?? ''));
        $taskname = trim((string)($payload['taskname'] ?? ''));
        if ($classname === '' && $taskname !== '') {
            $classname = '\\local_intellistream\\task\\' . ltrim($taskname, '\\');
        }
        if ($classname === '') {
            return [400, ['status' => 'error', 'detail' => 'missing_task', 'data' => 'Missing task name']];
        }
        $classname = '\\' . ltrim($classname, '\\');

        $task = \core\task\manager::get_scheduled_task($classname);
        if (!$task) {
            return [404, ['status' => 'error', 'detail' => 'task_not_found', 'data' => 'Unknown task']];
        }
        // Never reconfigure a task that isn't ours.
        if ($task->get_component() !== config::COMPONENT) {
            return [403, ['status' => 'error', 'detail' => 'not_owned', 'data' => 'Task not owned by plugin']];
        }

        // Validate any supplied cron field (permissive Moodle cron syntax).
        foreach (['minute', 'hour', 'day', 'month', 'dayofweek'] as $f) {
            if (array_key_exists($f, $payload)) {
                $v = (string)$payload[$f];
                if ($v === '' || !preg_match('/^[0-9*,\/-]+$/', $v)) {
                    return [422, ['status' => 'error', 'detail' => 'bad_cron_field', 'data' => 'Invalid ' . $f]];
                }
            }
        }

        if (array_key_exists('minute', $payload)) {
            $task->set_minute((string)$payload['minute']);
        }
        if (array_key_exists('hour', $payload)) {
            $task->set_hour((string)$payload['hour']);
        }
        if (array_key_exists('day', $payload)) {
            $task->set_day((string)$payload['day']);
        }
        if (array_key_exists('month', $payload)) {
            $task->set_month((string)$payload['month']);
        }
        if (array_key_exists('dayofweek', $payload)) {
            $task->set_day_of_week((string)$payload['dayofweek']);
        }
        if (array_key_exists('disabled', $payload)) {
            $task->set_disabled((bool)(int)$payload['disabled']);
        }
        // Mark customised so Moodle keeps our schedule across upgrades (matches
        // what the in-Moodle "Edit task settings" form does).
        $task->set_customised(true);
        \core\task\manager::configure_scheduled_task($task);

        return [200, ['status' => 'ok', 'action' => 'save_task', 'data' => 'Task updated']];
    }

    /**
     * Read one datatype's effective config for the control-plane admin
     * "Edit datatype config" form. Mirrors IntelliData's
     * `local_intellidata_get_plugin_datatype_config` reply shape so
     * supernova-admin renders it unchanged: {record, isrequire, canbedisabled,
     * enableexport, newtracking}. The effective config comes from the exporter's
     * override-aware catalog (built-in defaults merged with the
     * local_intellistream_config override row); `enabled=0` surfaces as
     * status/enableexport = 0.
     *
     * @param string $datatype
     * @return array{0:int,1:array}
     */
    private static function get_datatype_config(string $datatype): array {
        $datatype = trim($datatype);
        if ($datatype === '') {
            return [400, ['status' => 'error', 'detail' => 'missing_datatype']];
        }

        $entry = null;
        foreach (exporter::datatype_config_catalog() as $c) {
            if (($c['datatype'] ?? null) === $datatype) {
                $entry = $c;
                break;
            }
        }
        if ($entry === null) {
            return [404, ['status' => 'error', 'detail' => 'unknown_datatype']];
        }

        // Built-in registry entries are required-by-default; only optional/log/
        // custom datatypes may be disabled (mirrors IntelliData is_required_by_default
        // + protects downstream ETL/marts from losing a core table).
        $builtin = exporter::registry();
        $isrequire = isset($builtin[$datatype]);

        $record = [
            'datatype'           => $datatype,
            'tableindex'         => '',
            'tabletype'          => (int)$entry['tabletype'],
            'status'             => (int)$entry['status'],
            'timemodified_field' => (string)$entry['timemodified_field'],
            'filterbyid'         => (int)$entry['filterbyid'],
            'rewritable'         => (int)$entry['rewritable'],
            'events_tracking'    => (int)$entry['events_tracking'],
        ];

        return [200, ['status' => 'ok', 'action' => 'get_datatype_config', 'data' => [
            'record'        => $record,
            'isrequire'     => (int)$isrequire,
            'canbedisabled' => (int)!$isrequire,
            'enableexport'  => (int)$entry['exportenabled'],
            'newtracking'   => 1,
        ]]];
    }

    /**
     * Persist one datatype's admin config from the control-plane "Edit datatype
     * config" form. Push parity for IntelliData's
     * `local_intellidata_set_plugin_datatype_config`: the meaningful controls are
     * Enable/Disable Export (→ the `enabled` gate the exporter already honors via
     * registry_with_overrides) and Table Type. The pull-only strategy fields
     * (timemodified_field/filterbyid/rewritable) are auto-derived by the push
     * plugin and intentionally NOT persisted here. Existing custom_table/columns/
     * discovered on the row are preserved (config_repository::save merges).
     *
     * @param array $payload {datatype, enableexport?|status?, tabletype?}
     * @return array{0:int,1:array}
     */
    private static function save_datatype_config(array $payload): array {
        $datatype = trim((string)($payload['datatype'] ?? ''));
        if ($datatype === '') {
            return [400, ['status' => 'error', 'detail' => 'missing_datatype', 'data' => 'Missing datatype']];
        }

        $builtin = exporter::registry();
        $isbuiltin = isset($builtin[$datatype]);
        $repo = new \local_intellistream\repositories\config_repository();
        $existing = $repo->get($datatype);
        $islog = in_array($datatype, [
            \local_intellistream\datatypes\syslogs_datatype::ENTITY,
            \local_intellistream\datatypes\exceptions_datatype::ENTITY,
        ], true);
        $iscustom = $existing !== null && !empty($existing->custom_table);
        if (!$isbuiltin && !$islog && !$iscustom) {
            return [404, ['status' => 'error', 'detail' => 'unknown_datatype', 'data' => 'Unknown datatype']];
        }

        // Enable/disable: prefer enableexport, fall back to status; null = not
        // provided (a tabletype-only save must not touch the enabled gate).
        $enable = array_key_exists('enableexport', $payload)
            ? (int)$payload['enableexport']
            : (array_key_exists('status', $payload) ? (int)$payload['status'] : null);

        // Required-core (built-in) datatypes may never be disabled — a missing
        // core table breaks downstream ETL/marts.
        if ($isbuiltin && $enable === 0) {
            return [422, [
                'status' => 'error',
                'detail' => 'cannot_disable_required',
                'data'   => 'This datatype is required and cannot be disabled.',
            ]];
        }

        // Load-merge-save: start from the existing row so custom_table/columns/
        // discovered survive, then apply the two editable fields.
        $record = $existing ? (array)$existing : [];
        unset($record['id']);
        if ($enable !== null) {
            $record['enabled'] = $enable ? 1 : 0;
        }
        if (array_key_exists('tabletype', $payload) && $payload['tabletype'] !== '') {
            $tt = (int)$payload['tabletype'];
            if (
                in_array($tt, [
                exporter::DATATYPE_TABLETYPE_REQUIRED,
                exporter::DATATYPE_TABLETYPE_OPTIONAL,
                exporter::DATATYPE_TABLETYPE_LOGS,
                ], true)
            ) {
                $record['tabletype'] = $tt;
            }
        }

        $repo->save($datatype, $record);

        return [200, ['status' => 'ok', 'action' => 'save_datatype_config', 'data' => 'Updated']];
    }

    /**
     * Queue the run_backfill adhoc task, optionally restricted to `$only`.
     *
     * @param array $only Entity names (empty = full run).
     * @return void
     */
    private static function queue_backfill(array $only): void {
        $task = new \local_intellistream\task\run_backfill_adhoc_task();
        $task->set_custom_data(['only' => array_values($only)]);
        \core\task\manager::queue_adhoc_task($task);
    }
}
