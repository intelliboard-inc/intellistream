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
 * Recovery window tool: re-reads Moodle's standard logstore for a date window.
 *
 * When the buffer refuses live events (it is full), Moodle still writes them to
 * {logstore_standard_log}. This tool re-reads one window [from, to) of that table and ships
 * it as `window_logstore` entity_snapshot records; the receiving pipeline adds back only the
 * events it does not already have. The tool never changes Moodle data. It reads the logstore in fixed id blocks, pauses when
 * the buffer backlog is over half its cap, honours per-run and per-hour row caps, and
 * refuses a window it cannot prove was captured on purpose (capture off, tracking settings
 * changed or forced in config.php, standard logstore off or older than its retention).
 *
 * Records: one per logstore row {kind: row, id: <logid>, request_id, logid, the fields in
 * COLUMNS} (never ip, other, realuserid or origin), and one request record per run,
 * re-sent at the end. Every record id = uuid5(site | window_logstore | request_id | key),
 * so two runs never overwrite each other.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Re-read a window of the standard logstore and ship it for count-once recovery.
 */
class window_tool {
    /** Entity name. The pipeline keeps it out of every normal path (window_ prefix). */
    const ENTITY = 'window_logstore';

    /** The logstore columns shipped, in contract order. Never ip / other / realuserid. */
    const COLUMNS = ['userid', 'courseid', 'contextid', 'contextinstanceid', 'contextlevel',
        'edulevel', 'eventname', 'component', 'action', 'target', 'objectid', 'crud',
        'relateduserid', 'timecreated'];

    /** Rows read per logstore query (fixed id blocks: no whole-window result set). */
    const BLOCK = 5000;

    /** Default ceiling for rows in one run (--confirm-large goes past it). */
    const DEFAULT_MAX_ROWS = 2000000;

    /** Hard ceiling for rows in one run: a larger window must be split. */
    const HARD_MAX_ROWS = 50000000;

    /** Default pace: logstore rows read per hour (shipped rows are a subset). */
    const DEFAULT_ROWS_PER_HOUR = 1000000;

    /** The pace is clamped to this range, whoever sets it (CLI or the webhook). */
    const MIN_ROWS_PER_HOUR = 50000;

    /** Upper bound of the pace. */
    const MAX_ROWS_PER_HOUR = 5000000;

    /** The run state is saved at most this often while reading (always on pause / finish). */
    const SAVE_EVERY_SECONDS = 60;

    /**
     * A window must end at least this long ago: the standard logstore writes rows in
     * buffered batches, so a row can arrive after its timecreated (with a higher id).
     */
    const SETTLE_SECONDS = HOURSECS;

    /** The capture settings whose change inside (or forcing for) a window refuses it. */
    const CAPTURE_SETTINGS = ['enabled', 'trackadmin', 'siteid'];

    /**
     * The first plugin version whose webhook `set_plugin_config` writes config_log for the
     * capture settings. A change made through the webhook before this version was installed
     * left no trace, so the pipeline needs an operator attestation for such a window.
     */
    const WEBHOOK_LOGGED_SINCE_VERSION = 2026112400;

    /** config_plugins key prefix of the run states (component local_intellistream). */
    const STATE_PREFIX = 'window_run_';

    /** config_plugins key of the stop request (a request id, or '*'). */
    const STOP_KEY = 'window_run_stop';

    /** Finished run states kept (newest first); older complete/stopped/refused ones are deleted. */
    const KEEP_FINISHED = 20;

    /** A request id: a lower-case UUID. */
    const REQUEST_ID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /**
     * Everything the pipeline (and the operator) needs to judge a window, read from Moodle.
     *
     * @param int $from window start (epoch, inclusive)
     * @param int $to window end (epoch, exclusive)
     * @return array facts; 'refuse' is a reason string when the window must not be read
     */
    public static function facts(int $from, int $to): array {
        global $CFG, $DB;
        $facts = [
            'refuse' => null,
            'config_changes' => [],
            'forced' => [],
            'latest_siteid_set_at' => null,
            'webhook_log_since' => null,
            'loglifetime_days' => (int)get_config('logstore_standard', 'loglifetime'),
            'logguests' => (bool)get_config('logstore_standard', 'logguests'),
            'plugin_version' => (int)config::plugin_version(),
            'exclude_userids' => [],
        ];
        if ($from >= $to) {
            $facts['refuse'] = 'window: --from must be before --to';
            return $facts;
        }
        if ($to > time() - self::SETTLE_SECONDS) {
            $facts['refuse'] = 'window: --to must be at least ' . (self::SETTLE_SECONDS / MINSECS)
                . ' minutes ago (the logstore can write rows late): run a recent window later';
            return $facts;
        }

        // The standard logstore must be on and present.
        $stores = array_filter(array_map('trim', explode(
            ',',
            (string)get_config('tool_log', 'enabled_stores')
        )));
        if (!in_array('logstore_standard', $stores, true)) {
            $facts['refuse'] = 'the standard logstore (logstore_standard) is not enabled';
            return $facts;
        }
        if (!services\config_service::table_is_real('logstore_standard_log')) {
            $facts['refuse'] = 'the table logstore_standard_log does not exist';
            return $facts;
        }
        if ($facts['loglifetime_days'] > 0 && $from < time() - $facts['loglifetime_days'] * DAYSECS) {
            $facts['refuse'] = 'the window is older than the logstore retention (loglifetime '
                . $facts['loglifetime_days'] . ' days): its rows are already deleted';
            return $facts;
        }

        // A setting forced in config.php never reaches config_log, so its history is unknown.
        $forced = isset($CFG->forced_plugin_settings[config::COMPONENT])
            ? array_keys((array)$CFG->forced_plugin_settings[config::COMPONENT]) : [];
        $facts['forced'] = array_values(array_intersect(self::CAPTURE_SETTINGS, $forced));
        if ($facts['forced']) {
            $facts['refuse'] = 'capture settings are forced in config.php ('
                . implode(', ', $facts['forced']) . '): their history is not on record';
            return $facts;
        }

        // Changes of the capture settings inside the window.
        [$insql, $inparams] = $DB->get_in_or_equal(self::CAPTURE_SETTINGS, SQL_PARAMS_NAMED);
        $changes = $DB->get_records_sql(
            "SELECT id, name, timemodified FROM {config_log}
              WHERE plugin = :plugin AND name {$insql}
                AND timemodified >= :f AND timemodified < :t
           ORDER BY timemodified, id",
            ['plugin' => config::COMPONENT, 'f' => $from, 't' => $to] + $inparams
        );
        foreach ($changes as $c) {
            $facts['config_changes'][] = ['name' => $c->name, 'at' => (int)$c->timemodified];
        }
        if ($facts['config_changes']) {
            $facts['refuse'] = 'a capture setting changed inside the window ('
                . implode(', ', array_unique(array_column($facts['config_changes'], 'name')))
                . '): split the window around the change';
            return $facts;
        }

        // The value each setting had during the window: unchanged inside it, so it is the
        // old value of the first change after it, or the current value if none came after.
        // Read exactly as config::enabled() / trackadmin_enabled() do: (bool)(int), with
        // their defaults when the value was unset (enabled 0, trackadmin 1).
        if (!self::flag(self::value_during('enabled', $to, config::enabled() ? '1' : '0'), false)) {
            $facts['refuse'] = 'capture was switched off (enabled = 0) during the window';
            return $facts;
        }
        $trackadmin = self::flag(self::value_during(
            'trackadmin',
            $to,
            config::trackadmin_enabled() ? '1' : '0'
        ), true);
        if (!$trackadmin) {
            // The observer dropped site-admin events live, so the tool drops them too.
            if (isset($CFG->config_php_settings['siteadmins'])) {
                $facts['refuse'] = 'siteadmins is set in config.php: the admin list of the '
                    . 'window is not on record (trackadmin is off)';
                return $facts;
            }
            $adminchange = $DB->record_exists_select(
                'config_log',
                "(plugin IS NULL OR plugin = 'core') AND name = :n AND timemodified >= :f AND timemodified < :t",
                ['n' => 'siteadmins', 'f' => $from, 't' => $to]
            );
            if ($adminchange) {
                $facts['refuse'] = 'the site admin list changed inside the window (trackadmin '
                    . 'is off): split the window around the change';
                return $facts;
            }
            $admins = self::value_during('siteadmins', $to, (string)($CFG->siteadmins ?? ''), true);
            $facts['exclude_userids'] = array_values(array_filter(array_map(
                'intval',
                explode(',', (string)$admins)
            )));
        }

        $siteidset = $DB->get_field_sql(
            "SELECT MAX(timemodified) FROM {config_log} WHERE plugin = :p AND name = 'siteid'",
            ['p' => config::COMPONENT]
        );
        $facts['latest_siteid_set_at'] = $siteidset ? (int)$siteidset : null;
        $since = $DB->get_field_sql(
            "SELECT MIN(timemodified) FROM {upgrade_log}
              WHERE plugin = :p AND version >= :v",
            ['p' => config::COMPONENT, 'v' => self::WEBHOOK_LOGGED_SINCE_VERSION]
        );
        $facts['webhook_log_since'] = $since ? (int)$since : null;
        return $facts;
    }

    /**
     * A stored setting value as the plugin reads it: (bool)(int), or $default when unset.
     *
     * @param string $value stored value ('' = unset)
     * @param bool $default the setting's default
     * @return bool
     */
    private static function flag(string $value, bool $default): bool {
        return $value === '' ? $default : (bool)(int)$value;
    }

    /**
     * The value a setting had just before $at: the old value of its first config_log change
     * at or after $at, or $current when it has not changed since.
     *
     * @param string $name setting name
     * @param int $at epoch
     * @param string $current the current value
     * @param bool $core true for a core setting (config_log.plugin IS NULL)
     * @return string
     */
    private static function value_during(string $name, int $at, string $current, bool $core = false): string {
        global $DB;
        // Core settings are logged with plugin NULL (admin settings, "set main admin") or
        // 'core' (admin/roles/admins.php adds and removes site admins with 'core').
        $plugin = $core ? "(plugin IS NULL OR plugin = 'core')" : 'plugin = :p';
        $params = ['n' => $name, 't' => $at] + ($core ? [] : ['p' => config::COMPONENT]);
        $first = $DB->get_records_select(
            'config_log',
            "{$plugin} AND name = :n AND timemodified >= :t",
            $params,
            'timemodified ASC, id ASC',
            'id, oldvalue',
            0,
            1
        );
        if (!$first) {
            return $current;
        }
        return (string)reset($first)->oldvalue;
    }

    /**
     * The id range and row count of the window (for --dry-run and the cap check).
     *
     * @param int $from window start
     * @param int $to window end
     * @param int|null $courseid optional course filter
     * @return array{minid:int,maxid:int,rows:int}
     */
    public static function estimate(int $from, int $to, ?int $courseid = null): array {
        global $DB;
        [$where, $params] = self::window_where($from, $to, $courseid);
        $r = $DB->get_record_sql("SELECT MIN(id) AS minid, MAX(id) AS maxid, COUNT(1) AS n
                                    FROM {logstore_standard_log} WHERE {$where}", $params);
        return ['minid' => (int)($r->minid ?? 0), 'maxid' => (int)($r->maxid ?? 0), 'rows' => (int)($r->n ?? 0)];
    }

    /**
     * WHERE clause for the window.
     *
     * @param int $from window start
     * @param int $to window end
     * @param int|null $courseid optional course
     * @return array [sql, params]
     */
    private static function window_where(int $from, int $to, ?int $courseid): array {
        // Anonymous rows (e.g. an anonymous feedback response) stay out: the recovered copy
        // would carry the user id without the anonymity flag.
        $where = 'timecreated >= :wfrom AND timecreated < :wto AND anonymous = 0';
        $params = ['wfrom' => $from, 'wto' => $to];
        if ($courseid) {
            $where .= ' AND courseid = :wcourse';
            $params['wcourse'] = $courseid;
        }
        return [$where, $params];
    }

    /**
     * Start (or resume) one run. Returns the run state after this invocation.
     *
     * @param array $opts from, to, courseid (nullable), max_rows, rows_per_hour, max_seconds,
     *                    resume (request id or ''), confirm_large (bool), output (callable|null),
     *                    request_id (optional: the id for a new run, minted by the caller)
     * @return array state: request_id, status, rows, max_logid, last_id, reason
     */
    public static function run(array $opts): array {
        $out = $opts['output'] ?? function ($m) {
        };
        if (!config::enabled()) {
            return self::refuse_queued($opts, 'local_intellistream is disabled now');
        }
        $siteid = config::site_id();
        if ($siteid === '') {
            return self::refuse_queued($opts, 'this site is not paired (no siteid)');
        }
        $lock = \core\lock\lock_config::get_lock_factory(config::COMPONENT)
            ->get_lock('window_tool', 0, 24 * HOURSECS);
        if (!$lock) {
            // Not final: the background task tries again later ('busy'); nothing is saved,
            // so a queued or paused run keeps its state.
            return ['status' => 'refused', 'busy' => true, 'reason' => 'another window run is in progress',
                'request_id' => (string)($opts['resume'] ?? '') ?: ((string)($opts['request_id'] ?? '') ?: null)];
        }
        try {
            return self::run_locked($opts, $siteid, $out);
        } finally {
            $lock->release();
        }
    }

    /**
     * The body of run(), under the site-wide window lock.
     *
     * @param array $opts see run()
     * @param string $siteid pairing id
     * @param callable $out progress printer
     * @return array state
     */
    private static function run_locked(array $opts, string $siteid, callable $out): array {
        global $DB;
        // A new run whose id already has a running state is the same task executed again
        // (core retries an adhoc task that threw after the run started): continue it.
        if (empty($opts['resume']) && !empty($opts['request_id'])) {
            $old = self::load_state((string)$opts['request_id']);
            if ($old && ($old['status'] ?? '') === 'running') {
                $opts['resume'] = (string)$opts['request_id'];
            }
        }
        if (!empty($opts['resume'])) {
            $state = self::load_state((string)$opts['resume']);
            if (!$state) {
                return ['status' => 'refused', 'reason' => 'no run with request id ' . $opts['resume']];
            }
            if (in_array($state['status'], ['complete', 'stopped'], true)) {
                return $state + ['reason' => 'this run already finished'];
            }
            if (in_array($state['status'], ['queued', 'refused'], true)) {
                // Only a run that started (and paused) can resume.
                return ['status' => 'refused', 'reason' => "request {$opts['resume']} is {$state['status']}, not paused"];
            }
            if ($state['site_id'] !== $siteid) {
                return self::refuse_queued($opts, 'the site was re-paired since this run started');
            }
            // A run that paused before its first row may never have sent its request record
            // (the buffer refused it): send it now, so the pipeline sees the run from the
            // start. Sending it again is harmless: the record keeps the same id.
            if ((int)$state['rows'] === 0 && !self::ship_request($state)) {
                return self::pause($state, 'the buffer refused the request record');
            }
        } else {
            $from = (int)$opts['from'];
            $to = (int)$opts['to'];
            $courseid = !empty($opts['courseid']) ? (int)$opts['courseid'] : null;
            $requestid = (string)($opts['request_id'] ?? '');
            if ($requestid !== '' && !self::valid_request_id($requestid)) {
                return ['status' => 'refused', 'reason' => 'bad request id'];
            }
            $old = $requestid !== '' ? self::load_state($requestid) : null;
            if ($old && ($old['status'] ?? '') !== 'queued') {
                // A 'queued' state is the webhook's own placeholder for this run: start it.
                return ['status' => 'refused', 'reason' => 'request id already used'];
            }
            $refuse = function (string $reason) use ($requestid, $siteid, $from, $to, $courseid) {
                if ($requestid !== '') {
                    // A caller that minted the id (the webhook) follows the run by it:
                    // record the refusal so recover_window_status shows why.
                    self::save_state(['request_id' => $requestid, 'site_id' => $siteid,
                        'window_from' => $from, 'window_to' => $to, 'course_id' => $courseid,
                        'status' => 'refused', 'reason' => $reason, 'rows' => 0, 'last_id' => 0,
                        'upto_id' => 0, 'started' => time()]);
                    self::clear_stop($requestid);
                }
                return ['status' => 'refused', 'reason' => $reason, 'request_id' => $requestid ?: null];
            };
            $facts = self::facts($from, $to);
            if ($facts['refuse']) {
                return $refuse($facts['refuse']);
            }
            $est = self::estimate($from, $to, $courseid);
            $sizerefusal = self::size_refusal(
                $est,
                (int)($opts['max_rows'] ?? self::DEFAULT_MAX_ROWS),
                !empty($opts['confirm_large'])
            );
            if ($sizerefusal !== null) {
                return $refuse($sizerefusal);
            }
            $state = [
                'request_id' => $requestid !== '' ? $requestid : \core\uuid::generate(),
                'site_id' => $siteid,
                'window_from' => $from,
                'window_to' => $to,
                'course_id' => $courseid,
                'status' => 'running',
                'rows' => 0,
                'max_logid' => null,
                'last_id' => $est['minid'] - 1,
                'upto_id' => $est['maxid'],
                'facts' => $facts,
                'rows_per_hour' => self::clamp_rate((int)($opts['rows_per_hour'] ?? self::DEFAULT_ROWS_PER_HOUR)),
                'started' => time(),
            ];
            self::save_state($state);
            if (!self::ship_request($state)) {
                return self::pause($state, 'the buffer refused the request record');
            }
            $out("request {$state['request_id']}: {$est['rows']} row(s) in the window");
        }

        // The pace asked for when the run started holds for every slice and resume, unless the
        // caller of this invocation sets one.
        $rate = self::clamp_rate((int)($opts['rows_per_hour'] ?? $state['rows_per_hour'] ?? self::DEFAULT_ROWS_PER_HOUR));
        $deadline = !empty($opts['max_seconds']) ? time() + (int)$opts['max_seconds'] : null;
        // Background (yield) mode: read one slice quota, then pause and let the task re-queue
        // itself after `yield` seconds, instead of sleeping in a cron slot.
        $yield = (int)($opts['yield'] ?? 0);
        $slicequota = $yield > 0 ? max(self::BLOCK, intdiv($rate * $yield, HOURSECS)) : 0;
        $started = microtime(true);
        $readthisinvocation = 0;
        $lastsave = time();
        $exclude = array_flip(array_map('intval', $state['facts']['exclude_userids'] ?? []));
        [$where, $params] = self::window_where(
            (int)$state['window_from'],
            (int)$state['window_to'],
            $state['course_id'] ? (int)$state['course_id'] : null
        );
        $cols = implode(', ', self::COLUMNS);
        $pluginversion = (int)config::plugin_version();
        global $CFG;
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;

        while ($state['last_id'] < $state['upto_id']) {
            if (self::stop_requested($state['request_id'])) {
                return self::finish($state, 'stopped', 'stopped by --stop');
            }
            if ($deadline !== null && time() >= $deadline) {
                return self::pause($state, 'time budget reached: resume with --resume');
            }
            try {
                exporter::window_backpressure();
            } catch (\RuntimeException $e) {
                return self::pause($state, $e->getMessage());
            }
            if ($slicequota > 0 && $readthisinvocation >= $slicequota) {
                return self::pause($state, 'pace: slice quota read, the next slice continues');
            }
            // Interactive (CLI) run: pace to the per-hour rate of rows read, checked per block
            // so the stop flag stays live.
            $allowed = $rate * max(1.0, (microtime(true) - $started)) / HOURSECS;
            if ($slicequota === 0 && $readthisinvocation > $allowed && $readthisinvocation >= self::BLOCK) {
                sleep(5);
                continue;
            }
            $upto = min($state['last_id'] + self::BLOCK, $state['upto_id']);
            $rows = $DB->get_records_sql(
                "SELECT id, {$cols} FROM {logstore_standard_log}
                  WHERE id > :lastid AND id <= :upto AND {$where}
               ORDER BY id",
                ['lastid' => $state['last_id'], 'upto' => $upto] + $params
            );
            foreach ($rows as $row) {
                if ($exclude && isset($exclude[(int)$row->userid])) {
                    continue;   // Trackadmin off: the observer never captured a site admin's event.
                }
                $reason = null;
                if (!self::ship_row($state, $row, $pluginversion, $moodleversion, $reason)) {
                    if ($reason === buffer::REFUSED_TRANSIENT) {
                        $state['last_id'] = (int)$row->id - 1;
                        return self::pause($state, 'the buffer refused a record (full): resume later');
                    }
                    $out("row {$row->id} refused for good (too large); not shipped");
                    continue;
                }
                $state['rows']++;
                $state['max_logid'] = (int)$row->id;
            }
            $readthisinvocation += $upto - $state['last_id'];
            $state['last_id'] = $upto;
            // The pair last_id + rows is saved together, so a crash re-reads at most the blocks
            // since the last save: their record ids repeat and the pipeline drops the copies.
            if (time() - $lastsave >= self::SAVE_EVERY_SECONDS) {
                self::save_state($state);
                $lastsave = time();
            }
        }
        buffer::flush();
        return self::finish($state, 'complete', null);
    }

    /**
     * Ship one logstore row as a window record.
     *
     * @param array $state run state
     * @param \stdClass $row logstore row
     * @param int $pluginversion plugin version
     * @param int|null $moodleversion Moodle version
     * @param string|null $reason out: refusal kind
     * @return bool accepted
     */
    private static function ship_row(
        array $state,
        \stdClass $row,
        int $pluginversion,
        ?int $moodleversion,
        ?string &$reason = null
    ): bool {
        $data = ['kind' => 'row', 'id' => (string)$row->id, 'request_id' => $state['request_id'],
            'logid' => (int)$row->id];
        foreach (self::COLUMNS as $c) {
            $data[$c] = $row->{$c};
        }
        return buffer::append_record(self::envelope(
            $state,
            (string)$row->id,
            $data,
            $pluginversion,
            $moodleversion
        ), $reason);
    }

    /**
     * Ship (or re-ship) the request record with the current status and counts.
     *
     * @param array $state run state
     * @return bool accepted
     */
    private static function ship_request(array $state): bool {
        $f = $state['facts'];
        $data = [
            'kind' => 'request',
            'id' => 'request:' . $state['request_id'],
            'request_id' => $state['request_id'],
            'site_id' => $state['site_id'],
            'window_from' => (int)$state['window_from'],
            'window_to' => (int)$state['window_to'],
            'course_id' => $state['course_id'],
            'status' => $state['status'],
            'row_count' => (int)$state['rows'],
            'max_logid' => $state['max_logid'],
            'latest_siteid_set_at' => $f['latest_siteid_set_at'],
            'webhook_log_since' => $f['webhook_log_since'],
            'config_changes' => $f['config_changes'],
            'forced' => $f['forced'],
            'loglifetime_days' => $f['loglifetime_days'],
            'logguests' => $f['logguests'],
            'plugin_version' => $f['plugin_version'],
        ];
        $ok = buffer::append_record(self::envelope(
            $state,
            'request',
            $data,
            (int)config::plugin_version(),
            null
        ));
        buffer::flush();
        return $ok;
    }

    /**
     * The entity_snapshot envelope of a window record.
     *
     * @param array $state run state
     * @param string $key row logid or 'request'
     * @param array $data entity_data
     * @param int $pluginversion plugin version
     * @param int|null $moodleversion Moodle version
     * @return array
     */
    private static function envelope(
        array $state,
        string $key,
        array $data,
        int $pluginversion,
        ?int $moodleversion
    ): array {
        return [
            'id' => exporter::window_record_id($state['site_id'], $state['request_id'], $key),
            'site_id' => $state['site_id'],
            'captured_at' => clock::now(),
            'plugin_version' => $pluginversion,
            'moodle_version' => $moodleversion,
            'record_type' => 'entity_snapshot',
            'entity' => self::ENTITY,
            'snapshot_batch' => $state['request_id'],
            'entity_data' => $data,
        ];
    }

    /**
     * End a run (complete or stopped): re-ship the request record with the final count.
     *
     * @param array $state run state
     * @param string $status complete | stopped
     * @param string|null $reason why
     * @return array state
     */
    private static function finish(array $state, string $status, ?string $reason): array {
        $state['status'] = $status;
        $state['finished'] = time();
        if (!self::ship_request($state)) {
            // The final record must reach the pipeline, or it waits forever: stay resumable.
            $state['status'] = 'running';
            return self::pause($state, 'the buffer refused the final request record: resume later');
        }
        self::save_state($state);
        self::clear_stop($state['request_id']);
        return $state + ['reason' => $reason];
    }

    /**
     * Pause a run: it stays 'running' and resumes from last_id with --resume.
     *
     * @param array $state run state
     * @param string $reason why
     * @return array state
     */
    private static function pause(array $state, string $reason): array {
        buffer::flush();
        self::save_state($state);
        return $state + ['paused' => true, 'reason' => $reason];
    }

    /**
     * Record a run the IntelliBoard service asked for, before its background task starts, so a
     * repeated delivery of the same request is recognised and its status is visible at once.
     *
     * @param string $requestid caller-minted request id
     * @param int $from window start (epoch s)
     * @param int $to window end (epoch s)
     * @param int|null $courseid course filter
     */
    public static function save_queued(string $requestid, int $from, int $to, ?int $courseid): void {
        self::save_state(['request_id' => $requestid, 'site_id' => config::site_id(),
            'window_from' => $from, 'window_to' => $to, 'course_id' => $courseid,
            'status' => 'queued', 'reason' => null, 'rows' => 0, 'last_id' => 0, 'upto_id' => 0,
            'started' => time()]);
    }

    /**
     * Why a window is too large to run, or null. The run reads every logstore id between the
     * first and last matching row (a course filter can match few rows in a huge id range),
     * so what it reads is bounded too.
     *
     * @param array $est estimate(): minid, maxid, rows
     * @param int $maxrows --max-rows
     * @param bool $confirmlarge --confirm-large
     * @return string|null
     */
    public static function size_refusal(array $est, int $maxrows, bool $confirmlarge): ?string {
        $maxrows = min($maxrows, self::HARD_MAX_ROWS);
        if ($est['rows'] > self::HARD_MAX_ROWS) {
            return "the window holds {$est['rows']} rows, over the hard ceiling " . self::HARD_MAX_ROWS . ': split it';
        }
        $span = $est['rows'] > 0 ? $est['maxid'] - $est['minid'] + 1 : 0;
        if ($span > self::HARD_MAX_ROWS) {
            return "the window spans {$span} logstore ids, over the hard ceiling " . self::HARD_MAX_ROWS . ': split it';
        }
        if ($est['rows'] > $maxrows && !$confirmlarge) {
            return "the window holds {$est['rows']} rows, over --max-rows {$maxrows}: "
                . 're-run with a smaller window or --confirm-large';
        }
        return null;
    }

    /**
     * The pace in rows read per hour, clamped to [MIN_ROWS_PER_HOUR, MAX_ROWS_PER_HOUR].
     *
     * @param int $rowsperhour requested pace
     * @return int
     */
    public static function clamp_rate(int $rowsperhour): int {
        return max(self::MIN_ROWS_PER_HOUR, min(self::MAX_ROWS_PER_HOUR, $rowsperhour));
    }

    /**
     * Whether the value is a request id (lower-case UUID).
     *
     * @param string $value candidate
     * @return bool
     */
    public static function valid_request_id(string $value): bool {
        return (bool)preg_match(self::REQUEST_ID_REGEX, $value);
    }

    /**
     * Whether this site has any state for the request id.
     *
     * @param string $requestid request id
     * @return bool
     */
    public static function known(string $requestid): bool {
        return self::load_state($requestid) !== null;
    }

    /**
     * Whether a known request is for exactly this window (start, end, course).
     *
     * @param string $requestid request id
     * @param int $from window start (epoch s)
     * @param int $to window end (epoch s)
     * @param int|null $courseid course filter
     * @return bool false when the id is unknown or names another window
     */
    public static function same_window(string $requestid, int $from, int $to, ?int $courseid): bool {
        $state = self::load_state($requestid);
        return $state !== null
            && (int)($state['window_from'] ?? 0) === $from
            && (int)($state['window_to'] ?? 0) === $to
            && (int)($state['course_id'] ?? 0) === (int)$courseid;
    }

    /**
     * Forget a queued placeholder (its background task could not be queued).
     *
     * @param string $requestid request id
     */
    public static function forget_queued(string $requestid): void {
        $state = self::load_state($requestid);
        if ($state && ($state['status'] ?? '') === 'queued') {
            unset_config(self::STATE_PREFIX . $requestid, config::COMPONENT);
        }
    }

    /**
     * A refusal before the run starts reading: for a queued run, or a paused run whose resume
     * cannot go on, record it so its status says why.
     *
     * @param array $opts see run()
     * @param string $reason why
     * @return array state
     */
    private static function refuse_queued(array $opts, string $reason): array {
        $requestid = !empty($opts['resume']) ? (string)$opts['resume'] : (string)($opts['request_id'] ?? '');
        $state = $requestid !== '' ? self::load_state($requestid) : null;
        // A queued run, or a paused one whose resume is refused for good: record it, so its
        // status (and a '*' stop) never treats it as live again.
        if ($state && in_array($state['status'] ?? '', ['queued', 'running'], true)) {
            $state['status'] = 'refused';
            $state['reason'] = $reason;
            self::save_state($state);
            self::clear_stop($requestid);
        }
        return ['status' => 'refused', 'reason' => $reason, 'request_id' => $requestid ?: null];
    }

    /**
     * One line per run for the IntelliBoard service (its relay keeps only a short `detail`).
     *
     * @param string $requestid one run, or '' for the newest runs
     * @param int $limit how many runs when $requestid is ''
     * @return string
     */
    public static function summary(string $requestid = '', int $limit = 3): string {
        if ($requestid !== '') {
            $state = self::load_state($requestid);
            if (!$state) {
                return 'request_id=' . $requestid . ' status=unknown';
            }
            $runs = [$state];
        } else {
            $runs = array_slice(self::runs(), 0, $limit);
            if (!$runs) {
                return 'no runs';
            }
        }
        $lines = [];
        foreach ($runs as $r) {
            $lines[] = sprintf(
                'request_id=%s status=%s rows=%d window=%s..%s%s%s',
                $r['request_id'],
                $r['status'],
                (int)($r['rows'] ?? 0),
                gmdate('Y-m-d\TH:i\Z', (int)$r['window_from']),
                gmdate('Y-m-d\TH:i\Z', (int)$r['window_to']),
                !empty($r['course_id']) ? ' course=' . (int)$r['course_id'] : '',
                !empty($r['reason']) ? ' reason=' . $r['reason'] : ''
            );
        }
        return implode('; ', $lines);
    }

    /**
     * Ask the running (or next resumed) run to stop at its next block.
     *
     * @param string $requestid request id ('*' = any run)
     * @return string|null why the stop was not set, or null when it was
     */
    public static function request_stop(string $requestid): ?string {
        // A stop is kept until the run it names finishes, so set it only for a run that
        // will see it: else a '*' sent while idle would stop the next run at its first block.
        $live = ['queued', 'running'];
        if ($requestid === '*') {
            $any = false;
            foreach (self::runs() as $r) {
                $any = $any || in_array($r['status'] ?? '', $live, true);
            }
            if (!$any) {
                return 'no run is queued or running';
            }
        } else {
            $state = self::load_state($requestid);
            if (!$state) {
                return 'no run with request id ' . $requestid;
            }
            if (!in_array($state['status'] ?? '', $live, true)) {
                return "request {$requestid} is {$state['status']}";
            }
        }
        set_config(self::STOP_KEY, $requestid, config::COMPONENT);
        return null;
    }

    /**
     * Clear the stop request when it names this run (or any run): the run has ended.
     *
     * @param string $requestid request id
     */
    private static function clear_stop(string $requestid): void {
        if (self::stop_requested($requestid)) {
            unset_config(self::STOP_KEY, config::COMPONENT);
        }
    }

    /**
     * Whether a stop was requested for this run.
     *
     * @param string $requestid request id
     * @return bool
     */
    private static function stop_requested(string $requestid): bool {
        global $DB;
        // Read the table, not the config cache: a long-running run must see a stop set by
        // another process (the webhook) at once.
        $v = (string)$DB->get_field(
            'config_plugins',
            'value',
            ['plugin' => config::COMPONENT, 'name' => self::STOP_KEY]
        );
        return $v !== '' && ($v === '*' || $v === $requestid);
    }

    /**
     * Every run this site has state for, newest first.
     *
     * @return array[] run states
     */
    public static function runs(): array {
        $all = [];
        foreach ((array)get_config(config::COMPONENT) as $k => $v) {
            if (strpos($k, self::STATE_PREFIX) === 0 && $k !== self::STOP_KEY) {
                $s = json_decode((string)$v, true);
                if (is_array($s)) {
                    $all[] = $s;
                }
            }
        }
        usort($all, function ($a, $b) {
            return ($b['started'] ?? 0) <=> ($a['started'] ?? 0);
        });
        return $all;
    }

    /**
     * The newest run states for the IntelliBoard service, without the window facts (which carry
     * site admin user ids when trackadmin is off).
     *
     * @return array[]
     */
    public static function runs_public(): array {
        return array_map(function ($r) {
            unset($r['facts']);
            return $r;
        }, array_slice(self::runs(), 0, self::KEEP_FINISHED));
    }

    /**
     * Persist the run state (config_plugins; not a config_log write).
     *
     * @param array $state run state
     */
    private static function save_state(array $state): void {
        set_config(self::STATE_PREFIX . $state['request_id'], json_encode($state), config::COMPONENT);
        if (in_array($state['status'] ?? '', ['complete', 'stopped', 'refused'], true)) {
            self::prune_finished();
        }
    }

    /**
     * Keep the newest KEEP_FINISHED finished run states; delete older ones, so the plugin's
     * config (loaded into Moodle's config cache) does not grow with every run.
     */
    private static function prune_finished(): void {
        $finished = array_filter(self::runs(), function ($r) {
            return in_array($r['status'] ?? '', ['complete', 'stopped', 'refused'], true);
        });
        foreach (array_slice(array_values($finished), self::KEEP_FINISHED) as $old) {
            unset_config(self::STATE_PREFIX . $old['request_id'], config::COMPONENT);
        }
    }

    /**
     * Load a run's state.
     *
     * @param string $requestid request id
     * @return array|null
     */
    private static function load_state(string $requestid): ?array {
        $v = get_config(config::COMPONENT, self::STATE_PREFIX . $requestid);
        $s = $v !== false ? json_decode((string)$v, true) : null;
        return is_array($s) ? $s : null;
    }
}
