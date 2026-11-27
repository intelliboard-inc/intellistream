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
 * Bulk entity exporter for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Snapshots full Moodle core entity rows into the buffer.
 *
 * The adapter is otherwise purely event-triggered, so a fresh install has no
 * record of users / courses / enrolments / grades that already existed. This
 * exporter walks each core table read-only (via $DB->get_recordset) and
 * appends one `entity_snapshot` record per row to the same buffer the event
 * observer writes to.
 *
 * Background job, not the hot path: it processes rows in chunks and yields
 * mtrace() progress. It issues only SELECTs — it never writes the Moodle DB.
 */
class exporter {
    /**
     * Column names that never leave the site, whatever the curated registry, an
     * admin column override or dynamic discovery asks for. Every name is a value
     * that grants access or money on possession alone.
     *
     * Enforced where records enter the buffer: {@see registry_with_overrides()}, the
     * funnel every export path resolves its SELECT list through, and
     * {@see strip_forbidden_row_keys()} for rows read with `SELECT *` (any new code
     * that buffers a row it did not select by name must use it too).
     *
     * Matched case-insensitively by whole name, not substring, so reporting data such
     * as `lesson.usepassword` or `external_tokens.tokentype` is kept. Generic names
     * that are a credential on one table only are in {@see FORBIDDEN_TABLE_COLUMNS}.
     */
    const FORBIDDEN_COLUMNS = [
        'password',
        'secret',
        'secretkey',
        'accesskey',
        'enrolmentkey',
        'resourcekey',
        'servicesalt',
        'privatekey',
        'apikey',
        'token',
        'seatkey',
        'sessdata',
        // Credential columns whose names a whole-name match does not catch.
        'clientsecret', // OAuth2 client secret, on oauth2_issuer (core).
        'refreshtoken', // OAuth2 refresh token, on oauth2_system_account + badge_backpack_oauth2 (core).
        'consumersecret', // LTI consumer secret, on enrol_lti_users.
        'privatetoken', // Webservice private token, on external_tokens (core).
        'confirmtoken', // Account-link token, on auth_oauth2_linked_login (core).
        'guestpassword', // Meeting guest password, on bigbluebuttonbn.
        'quitpassword', // SEB quit password, on quizaccess_seb_quizsettings.
        'authtoken', // Third-party auth token, on respondusws_auth_users.
        'moderatorpass', // Meeting moderator password, on bigbluebuttonbn.
        'viewerpass', // Meeting viewer password, on bigbluebuttonbn.
        'guestlinkuid', // Guest join-link id (joins on possession), on bigbluebuttonbn.
        'studentpassword', // Session self-marking password, on attendance_sessions.
        'rotateqrcodesecret', // Rotating QR code secret, on attendance_sessions.
        'guesturl', // Guest join URL (joins on possession), on collaborate.
    ];

    /**
     * Per-table forbidden columns, for credential columns whose name is too generic
     * to blocklist globally.
     *
     * For example `local_intellicart_coupons.code` redeems a discount on possession,
     * `user_private_key.value` authenticates as its `userid` and `sessions.sid` can be
     * replayed; but `code`, `value` and `sid` are ordinary data on many other tables,
     * so they are blocked only on the table where they are a credential.
     *
     * Table name => list of column names, both matched case-insensitively.
     */
    const FORBIDDEN_TABLE_COLUMNS = [
        'local_intellicart_coupons' => ['code'],
        'user_private_key' => ['value'], // Webservice/RSS key.
        'sessions' => ['sid'], // Replayable session id.
        'customcert_issues' => ['code'], // Certificate verification token.
        'lti' => ['instructorcustomparameters'], // Free text that can carry tool keys.
    ];

    /**
     * Private-message body columns, exported only when an admin has explicitly
     * enabled `exportmessagebodies`.
     *
     * These are not credentials, so they are not in the lists above — they are the
     * full text of every private message on the site. Default off: a site ships
     * message metadata (who, when, which conversation) unless someone deliberately
     * opts in to the bodies. The setting is not writable over the control webhook,
     * so sending message content off-site is a local admin decision that the site
     * can audit, not something that can be switched on remotely.
     */
    const MESSAGE_BODY_COLUMNS = ['fullmessage', 'fullmessagehtml', 'smallmessage'];

    /** Registry entities whose SELECT lists carry {@see MESSAGE_BODY_COLUMNS}. */
    const MESSAGE_ENTITIES = ['messages', 'message'];

    /**
     * Characters an over-size record's text field is cut to (see shrink_to_fit()):
     * the width of a text column in the reporting warehouse, and enough to keep the
     * text readable in a report.
     */
    const SHRINK_FIELD_CHARS = 50000;

    /** Text fields at or under this many bytes are never changed by shrink_to_fit(). */
    const SHRINK_MIN_BYTES = 1024;

    /** @var array|null The entity registry, loaded once per request. */
    private static $registrydata = null;

    /**
     * Entity registry: snapshot name => [table, columns], defined in
     * {@see \local_intellistream\local\entity_registry::definitions()}.
     *
     * `table` is the Moodle table name without the prefix; `columns` is the SELECT
     * list ('*' exports the whole row; sensitive columns are removed by
     * {@see FORBIDDEN_COLUMNS}). `'derived' => true` marks an entity whose own task
     * computes and sends its rows (request_derived()); `table` then names the table
     * whose presence is checked. `'clockless' => true` marks a table with rows whose
     * watermark column is NULL or 0; it turns on the id-keyset lane in
     * export_incremental(), together with what the verification sweep observes
     * ({@see CLOCKLESS_DETECTED_KEY}).
     *
     * @return array<string, array{table:string, columns?:string, derived?:bool,
     *     clockless?:bool}>
     */
    public static function registry(): array {
        if (self::$registrydata === null) {
            self::$registrydata = local\entity_registry::definitions();
        }
        return self::$registrydata;
    }

    /**
     * Change-timestamp columns usable to drive incremental export, in priority
     * order. The first one present in an entity's column set is its watermark.
     *
     * `timeupdated` is included (second, so a table carrying both still prefers
     * `timemodified`) because some tables, for example
     * `local_intellicart_checkout`/`_icheckout`, track their last change in
     * `timeupdated`, not `timemodified`. Without it, those tables would key
     * incremental export on `timecreated` and miss updates (e.g. payment_status)
     * until the verification sweep.
     */
    const WATERMARK_COLUMNS = ['timemodified', 'timeupdated', 'timecreated', 'timeaccess', 'timeadded'];

    /**
     * Config key holding the incremental lane's whole position as one JSON
     * document: `{"wm":{"<entity>":<timestamp>,...}}`.
     *
     * One key rather than one per entity because every set_config() write on a
     * plugin purges that plugin's entire configuration cache, which
     * config::enabled() reads on every page render of the capture path. The state
     * is written once at the end of a run (and, while entities are read in pages,
     * at most once a minute before that), and not at all when no watermark moved.
     *
     * @var string
     */
    const CDC_STATE_KEY = 'cdc_state';

    /**
     * Key prefix of the older one-key-per-entity watermark format.
     *
     * The 2026070900 upgrade step still writes these keys (upgrade steps are not
     * rewritten). cdc_state() adopts them the first time it reads, so an upgrading
     * site keeps its exact position and never re-ships history, and
     * cdc_state_save() then removes them once.
     *
     * @var string
     */
    const CDC_LEGACY_PREFIX = 'cdc_wm_';

    /**
     * Config key holding the entities the verification sweep actually observed
     * clockless rows in, as a JSON list.
     *
     * The registry 'clockless' flag is only a starting set: which tables carry
     * clockless rows differs from site to site. The verification sweep already reads
     * every row of every table, so recording what it sees costs no extra query and
     * covers the site's own tables within a day.
     *
     * @var string
     */
    const CLOCKLESS_DETECTED_KEY = 'clockless_detected';

    /**
     * Largest span of ids the clockless lane may cover in one pass.
     *
     * The lane exists because a clockless row has no usable change clock, so it
     * is keyed on `id` instead. Left unbounded, the first pass on a site that
     * has never run it would try to ship the entire backlog at once. That is not
     * merely slow: buffer::append()
     * refuses records once the buffer is at its byte cap, export_entity()
     * counts a refusal as `dropped`, and `dropped` deliberately does not block
     * `complete` (see export_entity()), so the watermark would advance over
     * rows that were never shipped and never retry them. Bounding the window
     * keeps every pass small, and the keyset makes it resumable.
     *
     * @var int
     */
    const CLOCKLESS_ID_CHUNK = 50000;

    /**
     * Smallest advance of the task_log mark that is copied into plugin config
     * (seconds of timeend); see export_task_state().
     *
     * @var int
     */
    public const TASK_LOG_MARK_STEP_SEC = 300;

    /**
     * How many refresh-task intervals an entity's watermark may be old for the
     * incremental read to skip its existence check.
     *
     * The watermark is the change time of the newest row this lane shipped. For an
     * entity that changed within the last two runs the COUNT would only add a second
     * pass over the table; a quieter entity gets the COUNT, which PostgreSQL can run
     * as a parallel pass where a read ordered by id often runs serially. Both paths
     * yield the same rows and watermark. The interval comes from the task's own
     * schedule (sweep::interval()), so a changed schedule keeps the two-run window.
     *
     * @var int
     */
    public const CDC_ACTIVE_RUNS = 2;

    /**
     * Rows in the first page of a paged read (the incremental lane, the historical
     * backfill) for an entity that has not read in pages before.
     *
     * A page is one `... ORDER BY id LIMIT n` query. On MySQL, Moodle runs every
     * recordset with MYSQLI_STORE_RESULT, so the whole result of a query is copied
     * into PHP memory before the first row is read: an unbounded read of a large
     * table is limited only by memory_limit. A page caps that copy.
     * Nothing has measured the first page's rows, so it is the smallest; later pages
     * are sized from what the previous one cost (next_page_rows()). The incremental
     * lane starts an entity at the size it last paged at; the backfill starts each
     * call at this size.
     *
     * @var int
     */
    public const PAGE_ROWS_START = 100;

    /**
     * Smallest page, however large the rows are. It bounds the row count, not the
     * bytes: a page of rows near buffer::MAX_EVENT_BYTES (or larger ones, which the
     * buffer then refuses) can still take this many times that much memory. Pages
     * shrink towards PAGE_BYTES from the second page on. An entity's first paged read
     * starts at PAGE_ROWS_START rows (nothing has measured them); the lane's later
     * runs start at the size it carried, the backfill's at PAGE_ROWS_START.
     *
     * @var int
     */
    public const PAGE_ROWS_MIN = 100;

    /**
     * Default largest page, in rows; the setting `pagerowsmax` overrides it (no
     * admin page; for a site that needs a smaller page).
     *
     * @var int
     */
    public const PAGE_ROWS_MAX = 5000;

    /**
     * Default memory one page's result may take, in bytes; the setting `pagebytes`
     * overrides it. Pages are resized towards this from the memory the previous
     * page's result actually took.
     *
     * @var int
     */
    public const PAGE_BYTES = 67108864;

    /**
     * Seconds one entity may spend paging in one incremental run before the lane
     * moves on, so a large catch-up on one table neither holds the task for hours
     * nor keeps the entities after it waiting. Its window is frozen and the next
     * run resumes it. The setting `pagebudgetsec` overrides it.
     *
     * @var int
     */
    public const PAGE_BUDGET_SEC = 120;

    /**
     * Seconds below the start of a paged read that its window ends at.
     *
     * A paged window must have a fixed upper bound: a row read on page 1 and changed
     * before the last page has a new watermark value, and must fall above the bound
     * so the next window reads it again. The bound is set from the clock when the
     * read starts, this far below it, to allow for clock difference between the
     * Moodle nodes that stamp `timemodified` and the one running this task.
     *
     * @var int
     */
    public const PAGE_CLOCK_SKEW_SEC = 300;

    /**
     * Least seconds between two saves of the lane's state within a run (an entity that
     * read in pages saves its position when it finishes; the run's final save is not
     * throttled).
     *
     * @var int
     */
    public const PAGE_SAVE_SEC = 60;

    /**
     * Coerce a decoded JSON object into a string => int map.
     *
     * Shared by the two watermark maps in the state document so cdc_state()
     * does not carry a near-identical loop per map.
     *
     * @param mixed $raw Decoded JSON value; anything not array-ish yields [].
     * @return array<string, int>
     */
    private static function int_map($raw): array {
        $out = [];
        foreach ((array)$raw as $key => $value) {
            $out[(string)$key] = (int)$value;
        }
        return $out;
    }

    /**
     * A timestamp-lane watermark: an int when integral, else the decimal string.
     *
     * A decimal or float column can be a watermark ({@see numeric_column()}).
     * Truncating its mark to an int would leave every row between the int and the
     * real mark above it, so they would be re-sent on every run. A non-integral mark is
     * kept as the string the database returned, not as a PHP float: Moodle's
     * drivers bind a float parameter at 14 significant digits, which can round the
     * mark up past a row not yet read. An integral value stays an int, so an
     * integer column's SQL parameters are unchanged.
     *
     * @param mixed $value A stored or read watermark value.
     * @return int|string
     */
    private static function wm_number($value) {
        if (is_int($value)) {
            return $value;
        }
        // A float (only from a state document written before marks were kept as
        // text) is rendered at its shortest round-trip form, not a fixed precision.
        $text = is_float($value) ? (string)json_encode($value) : trim((string)$value);
        if (!is_numeric($text)) {
            return 0;
        }
        if ((float)$text == (int)$text && strpbrk($text, 'eE') === false) {
            return (int)$text;
        }
        return $text;
    }

    /**
     * A mark to bind against a given watermark column: a non-integral mark against
     * an integer column (whose type changed since the mark was taken) is floored, so
     * the database is never asked to compare an integer column with a fraction.
     *
     * @param string $table Table name without the prefix.
     * @param string $column Watermark column.
     * @param int|string $mark From wm_number().
     * @return int|string
     */
    private static function wm_for_column(string $table, string $column, $mark) {
        global $DB;
        if (is_int($mark)) {
            return $mark;
        }
        try {
            $info = $DB->get_columns($table)[$column] ?? null;
        } catch (\Throwable $e) {
            $info = null;
        }
        return ($info !== null && ($info->meta_type ?? '') === 'I') ? (int)floor((float)$mark) : $mark;
    }

    /**
     * Coerce a decoded JSON object into a string => watermark map
     * ({@see wm_number()}), for the timestamp lane's `wm` map.
     *
     * @param mixed $raw Decoded JSON value; anything not array-ish yields [].
     * @return array<string, int|string>
     */
    private static function wm_map($raw): array {
        $out = [];
        foreach ((array)$raw as $key => $value) {
            $out[(string)$key] = self::wm_number($value);
        }
        return $out;
    }

    /**
     * Decode the incremental lane's resume records.
     *
     * One record per entity whose last scan stopped part-way, by the buffer, by
     * the page size or by the time budget: `lo`/`hi` are the frozen timestamp
     * window, `id` the last id settled, `seen` the highest mark settled in the
     * window so far (where the window completes; absent from a record written
     * before it existed), and `idlo`/`idhi` the frozen clockless id window when
     * that lane was part of the scan. A malformed record is dropped, which is always safe: the
     * entity's watermarks were never advanced past it, so the next run simply
     * re-scans the window from its start.
     *
     * @param mixed $raw Decoded JSON.
     * @return array<string, array<string, int|string>>
     */
    private static function resume_map($raw): array {
        $out = [];
        foreach ((array)$raw as $entity => $rec) {
            if (!is_array($rec) || !isset($rec['lo'], $rec['hi'], $rec['id'])) {
                continue;
            }
            $one = ['lo' => self::wm_number($rec['lo']), 'hi' => self::wm_number($rec['hi']), 'id' => (int)$rec['id']];
            if (isset($rec['seen'])) {
                $one['seen'] = self::wm_number($rec['seen']);
            }
            if (isset($rec['idlo'], $rec['idhi'])) {
                $one['idlo'] = (int)$rec['idlo'];
                $one['idhi'] = (int)$rec['idhi'];
            }
            $out[(string)$entity] = $one;
        }
        return $out;
    }

    /**
     * Entities the verification sweep last observed clockless rows in.
     *
     * @return array<string, int> Entity name => max clockless id delivered by
     *     the last complete verification pass (0 when unknown, e.g. a legacy list).
     */
    private static function clockless_detected(): array {
        $raw = get_config(config::COMPONENT, self::CLOCKLESS_DETECTED_KEY);
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $value) {
            if (is_int($key) && is_string($value) && $value !== '') {
                // Legacy shape: a plain list of entity names, no delivered
                // position. Reads as 0, which is the safe direction — the lane
                // walks from the start rather than skipping.
                $out[$value] = 0;
                continue;
            }
            if (is_string($key) && $key !== '') {
                $out[$key] = (int)$value;
            }
        }
        return $out;
    }

    /**
     * Read the incremental lane's state document.
     *
     * `idwm` is the clockless lane's position (max `id` shipped per entity). A
     * document without it reads as an empty map, which is the correct starting point.
     *
     * `resume` holds the frozen window of each entity whose last scan stopped
     * part-way (by the buffer, the page size or the time budget); see
     * resume_map(). `page` holds the page size each entity last read at. Both are
     * written only while non-empty and read as empty when absent.
     *
     * @return array{wm: array<string, int|float>, idwm: array<string, int>,
     *     idseen: array<string, int>, resume: array<string, array<string, int|string>>,
     *     page: array<string, int>, migrated: bool}
     */
    private static function cdc_state(): array {
        $state = ['wm' => [], 'idwm' => [], 'idseen' => [], 'resume' => [], 'page' => [], 'migrated' => false];

        $raw = get_config(config::COMPONENT, self::CDC_STATE_KEY);
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $state['wm'] = self::wm_map($decoded['wm'] ?? []);
                $state['idwm'] = self::int_map($decoded['idwm'] ?? []);
                $state['idseen'] = self::int_map($decoded['idseen'] ?? []);
                $state['resume'] = self::resume_map($decoded['resume'] ?? []);
                $state['page'] = self::int_map($decoded['page'] ?? []);
                return $state;
            }
            // Corrupt document. Falling through to the legacy scan is the safe
            // direction: at worst the lane restarts from 0 and re-ships, which
            // the downstream UPSERT-on-id absorbs. Treating it as empty and
            // advancing would skip rows instead.
            mtrace('local_intellistream: cdc_state is not valid JSON — rebuilding from any '
                . 'per-entity watermarks still present.');
        }

        foreach ((array)get_config(config::COMPONENT) as $key => $value) {
            if ($key === self::CDC_STATE_KEY || strpos($key, self::CDC_LEGACY_PREFIX) !== 0) {
                continue;
            }
            $entity = substr($key, strlen(self::CDC_LEGACY_PREFIX));
            if ($entity !== '') {
                $state['wm'][$entity] = self::wm_number($value);
            }
        }
        if ($state['wm']) {
            $state['migrated'] = true;
        }
        return $state;
    }

    /**
     * Start the timestamp lane of an entity just below $ts, if it has never run for it.
     * Called by the historical backfill when it finishes an entity, with $ts taken
     * before the entity's first read: rows changed while the backfill read it are then
     * sent by the lane, and nothing older is sent twice.
     *
     * @param string $entity
     * @param int $ts Unix seconds; ignored when not positive.
     * @return void
     */
    public static function seed_incremental_watermark(string $entity, int $ts): void {
        if ($ts <= 0) {
            return;
        }
        $lock = self::cdc_state_lock();
        try {
            // The stored document, not this process's cached copy: the lane may have
            // written a position for the entity since this process read its config.
            $stored = self::cdc_state_stored();
            $original = $stored ?? self::cdc_state();
            if (isset($original['wm'][$entity])) {
                return;   // The lane already has a position of its own.
            }
            $state = $original;
            $state['wm'][$entity] = $ts;
            self::cdc_state_write($state);
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * Persist the incremental lane's state: at the end of a run, and before that at
     * most once every PAGE_SAVE_SEC, when an entity that read in pages finishes.
     *
     * A no-op when the document is unchanged. With $original, each entity the
     * caller did not change takes the value stored now, not the one loaded: another
     * process (the backfill seeding an entity's watermark) may have written it in
     * between, and saving the loaded copy would undo that.
     *
     * @param array $state As returned by cdc_state().
     * @param array|null $original The state as the caller loaded it.
     * @return void
     */
    private static function cdc_state_save(array $state, ?array $original = null): void {
        // On the run that adopts per-entity watermarks from before the document existed
        // there is usually no stored document (null: nothing to merge). When there is
        // one, a backfill seed wrote it during the run, and the merge keeps that seed.
        $lock = null;
        try {
            if ($original !== null) {
                // Read, merge and write as one step against the other writer (the seed).
                $lock = self::cdc_state_lock();
                $stored = self::cdc_state_stored();
                if ($stored !== null) {
                    $state = self::cdc_state_merge($state, $original, $stored);
                }
            }
            self::cdc_state_write($state);
        } finally {
            if ($lock) {
                $lock->release();
            }
        }
    }

    /**
     * The lock that makes reading, merging and writing the lane's state one step, or
     * null when it cannot be taken within 30 seconds (the caller then writes unlocked).
     *
     * It is held for one read and one write (the seed's read may adopt the legacy
     * per-entity watermarks first), so it expires after 120 seconds:
     * where a lock outlives the process that took it (the database-record factory),
     * a holder killed mid-save then delays the writes after it by minutes, not a day.
     *
     * @return \core\lock\lock|null
     */
    private static function cdc_state_lock() {
        try {
            $factory = \core\lock\lock_config::get_lock_factory(config::COMPONENT);
            $lock = $factory->get_lock('cdc_state', 30, 120);
            $why = 'not taken within 30s';
        } catch (\Throwable $e) {
            $lock = false;
            $why = 'failed: ' . $e->getMessage();
        }
        if (!$lock) {
            mtrace("local_intellistream: cdc_state lock {$why}; state written without it.");
            return null;
        }
        return $lock;
    }

    /**
     * Encode and write the lane's state document; see cdc_state_save().
     *
     * @param array $state As returned by cdc_state().
     * @return void
     */
    private static function cdc_state_write(array $state): void {
        // All the maps, always. Encoding only 'wm' here while cdc_state() reads
        // 'idwm' would drop the clockless position on every run: the lane would
        // restart from 0 for ever, re-shipping the same backlog every run.
        $doc = [
            'wm' => self::wm_map($state['wm']),
            'idwm' => array_map('intval', (array)($state['idwm'] ?? [])),
            'idseen' => array_map('intval', (array)($state['idseen'] ?? [])),
        ];
        // Only while some entity is mid-window, so a site that never hits the
        // buffer cap keeps a byte-identical document (and no extra write).
        if (!empty($state['resume'])) {
            $doc['resume'] = $state['resume'];
        }
        // Likewise only once some entity has read in pages.
        if (!empty($state['page'])) {
            $doc['page'] = array_map('intval', $state['page']);
        }
        $payload = json_encode($doc);
        if ($payload === false) {
            // Never lose a run over an encoding failure. Not advancing means the
            // next run re-exports the same window, which is the safe direction.
            mtrace('local_intellistream: could not encode cdc_state — watermarks not advanced.');
            return;
        }
        if ((string)get_config(config::COMPONENT, self::CDC_STATE_KEY) === $payload) {
            return;
        }
        set_config(self::CDC_STATE_KEY, $payload, config::COMPONENT);

        if (!empty($state['migrated'])) {
            // The per-entity keys are folded into the document. Drop them so a
            // later read cannot resurrect a stale position, and so the config
            // table does not keep one dead row per entity.
            foreach ((array)get_config(config::COMPONENT) as $key => $unusedvalue) {
                if ($key !== self::CDC_STATE_KEY && strpos($key, self::CDC_LEGACY_PREFIX) === 0) {
                    unset_config($key, config::COMPONENT);
                }
            }
        }
    }

    /**
     * The lane's state as stored now, read from the database (not the config cache,
     * and from the primary on a site with a read replica), or null when there is no
     * document yet or it cannot be read (the caller then falls back to cdc_state(),
     * or saves what it has).
     *
     * No document is null, not an empty state: cdc_state() is what adopts the
     * older per-entity watermark keys, and a document written from an empty state
     * would make that adoption unreachable (see db/upgrade.php).
     *
     * @return array|null As cdc_state(), without the legacy migration.
     */
    private static function cdc_state_stored(): ?array {
        global $DB;
        // In a transaction, so that a site with a read replica answers from the
        // primary: the replica can lag behind the write this read must see. A caller
        // already in one reads from the primary anyway, and is left alone.
        $own = !$DB->is_transaction_started();
        $transaction = null;
        try {
            if ($own) {
                $transaction = $DB->start_delegated_transaction();
            }
            $raw = $DB->get_field('config_plugins', 'value', ['plugin' => config::COMPONENT, 'name' => self::CDC_STATE_KEY]);
            if ($transaction !== null) {
                $transaction->allow_commit();
            }
        } catch (\Throwable $e) {
            if ($transaction !== null && !$transaction->is_disposed()) {
                try {
                    $transaction->rollback($e);
                } catch (\Throwable $rethrown) {
                    // The rollback rethrows $e; the caller saves what it has.
                    unset($rethrown);
                }
            }
            if ($own && $DB->is_transaction_started()) {
                // The transaction this method opened is still open: the commit failed
                // (the database connection failed between the read and the commit), or
                // BEGIN or the rollback did. Fail now: a task's runner then rolls it back
                // and fails the task (lib/classes/cron.php from Moodle 4.2, lib/cronlib.php
                // before), and the CLI stops, instead of working on inside it.
                throw $e;
            }
            return null;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return [
            'wm' => self::wm_map($decoded['wm'] ?? []),
            'idwm' => self::int_map($decoded['idwm'] ?? []),
            'idseen' => self::int_map($decoded['idseen'] ?? []),
            'resume' => self::resume_map($decoded['resume'] ?? []),
            'page' => self::int_map($decoded['page'] ?? []),
        ];
    }

    /**
     * Merge a caller's state into the stored one: each entity the caller changed
     * (added, removed or altered against $original) keeps the caller's value; every
     * other entity takes the stored value.
     *
     * @param array $state The caller's state.
     * @param array $original The state as the caller loaded it.
     * @param array $stored The state as stored now.
     * @return array
     */
    private static function cdc_state_merge(array $state, array $original, array $stored): array {
        foreach (['wm', 'idwm', 'idseen', 'resume', 'page'] as $map) {
            $mine = (array)($state[$map] ?? []);
            $before = (array)($original[$map] ?? []);
            $now = (array)($stored[$map] ?? []);
            foreach (array_keys($mine + $before + $now) as $entity) {
                $had = array_key_exists($entity, $before);
                $has = array_key_exists($entity, $mine);
                if ($had !== $has || ($has && $mine[$entity] !== $before[$entity])) {
                    continue; // Changed by the caller: its value stands.
                }
                if (array_key_exists($entity, $now)) {
                    $mine[$entity] = $now[$entity];
                } else {
                    unset($mine[$entity]);
                }
            }
            $state[$map] = $mine;
        }
        return $state;
    }

    /**
     * The lowest id in an incremental window, to start its first page at; null when
     * not worth reading (the watermark column leads no index, so finding it would be
     * a second pass over the table) or when it cannot be read, false when the window
     * is empty.
     *
     * @param string $table Table name without the prefix.
     * @param string $wmcol Resolved watermark column.
     * @param string $select The window's WHERE clause.
     * @param array $params Its bound parameters.
     * @return int|false|null
     */
    private static function window_first_id(string $table, string $wmcol, string $select, array $params) {
        global $DB;
        if (!self::column_leads_index($table, $wmcol)) {
            return null;
        }
        try {
            // MIN(id + 0), not MIN(id): for MIN(id) a planner may walk the primary key
            // from the first row until a row matches (measured on PostgreSQL 14: 0.4 to
            // 0.7 s on 500k rows, for windows of 1 to 30 %), which is the walk this
            // lookup exists to avoid. On the expression it reads the window's rows
            // through the watermark index instead.
            $first = $DB->get_field_sql("SELECT MIN(id + 0) FROM {" . $table . "} WHERE {$select}", $params);
        } catch (\Throwable $e) {
            return null;
        }
        return ($first === null || $first === false) ? false : (int)$first;
    }

    /**
     * Whether a column is the first column of an index on a table (cached per process).
     *
     * @param string $table Table name without the prefix.
     * @param string $column Column name.
     * @return bool
     */
    private static function column_leads_index(string $table, string $column): bool {
        global $DB;
        static $leading = [];
        if (!isset($leading[$table])) {
            $leading[$table] = [];
            try {
                foreach ($DB->get_indexes($table) as $index) {
                    $cols = $index['columns'] ?? [];
                    if ($cols) {
                        $leading[$table][strtolower((string)reset($cols))] = true;
                    }
                }
            } catch (\Throwable $e) {
                $leading[$table] = [];
            }
        }
        return isset($leading[$table][strtolower($column)]);
    }

    /**
     * The larger of two watermark values, either of which may be null.
     *
     * @param int|string|null $a A watermark value (see wm_number()), or null.
     * @param int|string|null $b Another, or null.
     * @return int|string|null
     */
    private static function max_mark($a, $b) {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }
        return (float)$b > (float)$a ? $b : $a;
    }

    /**
     * The smaller of two watermark values.
     *
     * @param int|string $a A watermark value (see wm_number()).
     * @param int|string $b Another.
     * @return int|string
     */
    private static function min_mark($a, $b) {
        return (float)$b < (float)$a ? $b : $a;
    }


    /**
     * Resolve the change-timestamp column that drives incremental export for an
     * entity, or null when it has none (verification sweep only). Derived from the
     * registry 'columns' list when explicit; when '*'/null the live table is read.
     *
     * @param string|null $columns Registry columns string ('*'/null = whole row).
     * @param string $table Moodle table name (unprefixed).
     * @param string|null $override Explicit per-entity watermark column (registry
     *        'wmcol'); used when present so tables whose real change column is not
     *        one of WATERMARK_COLUMNS (e.g. forum_posts.modified, course_modules.added,
     *        task_log.timeend in export_task_state()) can still be watermarked.
     * @return string|null
     */
    protected static function watermark_column(?string $columns, string $table, ?string $override = null): ?string {
        global $DB;
        $have = [];
        $cols = $columns === null ? '' : trim($columns);
        if ($cols !== '' && $cols !== '*') {
            foreach (preg_split('/[\s,]+/', $cols) as $c) {
                $c = trim($c);
                if ($c !== '') {
                    $have[strtolower($c)] = true;
                }
            }
        } else {
            // Where the list is '*' or null, introspect the live table's columns.
            try {
                foreach (array_keys($DB->get_columns($table)) as $c) {
                    $have[strtolower($c)] = true;
                }
            } catch (\Throwable $e) {
                return null;
            }
        }
        // Explicit per-entity override wins when that column is actually present
        // on the entity. Falls through to the standard scan when the override is
        // absent/misconfigured, so we never return a non-existent column.
        $chosen = null;
        if ($override !== null && $override !== '' && isset($have[strtolower($override)])) {
            $chosen = strtolower($override);
        } else {
            foreach (self::WATERMARK_COLUMNS as $col) {
                if (isset($have[$col])) {
                    $chosen = $col;
                    break;
                }
            }
        }
        return ($chosen !== null && self::numeric_column($table, $chosen)) ? $chosen : null;
    }

    /** @var array<string, bool> Tables already reported as having a non-numeric watermark column. */
    private static $textwatermark = [];

    /**
     * Whether a column holds numbers, so it can be a watermark.
     *
     * The watermark column is chosen by name (WATERMARK_COLUMNS), but a custom table
     * can carry a text `timemodified`, over which no mark can advance. Such a column
     * is not a watermark: the entity is left to the verification sweep, and a CLI run
     * says so once per table. Uses $DB->get_columns() (cached by Moodle); when the
     * metadata cannot be read the answer is yes.
     *
     * @param string $table
     * @param string $column Lower-case column name.
     * @return bool
     */
    private static function numeric_column(string $table, string $column): bool {
        global $DB;
        try {
            $meta = $DB->get_columns($table);
        } catch (\Throwable $e) {
            return true;
        }
        $info = $meta[$column] ?? null;
        if ($info === null || !isset($info->meta_type)) {
            return true;
        }
        if (in_array($info->meta_type, ['I', 'N', 'F', 'R'], true)) {
            return true;
        }
        if (CLI_SCRIPT && !isset(self::$textwatermark[$table])) {
            self::$textwatermark[$table] = true;
            mtrace("local_intellistream: table '{$table}' — column '{$column}' is not numeric, so it cannot be a "
                . 'watermark; the table is left to the verification sweep.');
        }
        return false;
    }

    /**
     * Memoised resolutions, keyed "table|columns|wmcol". Per-process; a schema
     * change mid-request is not a thing, and an override change produces a
     * different key, so no invalidation is needed.
     *
     * @var array<string, array{columns: ?string, wmcol: ?string, missing: string[]}>
     */
    private static $resolvedcolumns = [];

    /**
     * Memoised effective registry for this request, or null when not yet built.
     *
     * Unlike $resolvedcolumns above, this one does need invalidation: it is keyed
     * on nothing, so a config write in the same request would otherwise be
     * invisible to a later read. {@see reset_registry_cache()}.
     *
     * @var array<string, array{table:string, columns?:string, derived?:bool}>|null
     */
    private static $registrycache = null;

    /**
     * Set by strip_forbidden_columns() when it left out an entity whose columns could
     * not be read.
     *
     * @var bool
     */
    private static $registryincomplete = false;

    /**
     * When a registry that left an entity out stops being reused (unix time), so the
     * columns are tried again; 0 while the memoised registry is complete.
     *
     * @var int
     */
    private static $registryretryat = 0;

    /** Seconds a registry that left an entity out is reused before it is built again. */
    const INCOMPLETE_REGISTRY_TTL = 60;

    /**
     * Entity => declared columns absent from the live table, accumulated across
     * this process for the one-line drift summary. Diagnostics only; never feeds
     * a SELECT.
     *
     * @var array<string, string[]>
     */
    private static $columndrift = [];

    /**
     * Columns deliberately declared for more than one Moodle schema, so their
     * absence is expected rather than drift.
     *
     * Some entities declare the union of a pre-4.0 and a 4.0+ shape: on 3.9 a quiz
     * slot names its question directly, while 4.0+ routes it through
     * question_references -> question_versions. On any site one half is always
     * missing, so these are left out of the drift record (a permanent entry would
     * hide real drift). They are still dropped from the SELECT like any absent column.
     *
     * Only columns declared for cross-version reasons belong here. A column that is
     * simply newer than the host (quiz_slots.displaynumber on 4.1) is genuine drift.
     *
     * @var array<string, string[]> entity => column names.
     */
    private static $versionoptionalcolumns = [
        'question'   => ['category', 'hidden', 'idnumber', 'version'],
        'quiz_slots' => ['questionid', 'questioncategoryid', 'includingsubcategories'],
    ];

    /**
     * Record an entity's absent columns as drift, minus the ones we expect to be
     * absent because they belong to another Moodle version's schema.
     *
     * @param string   $entity  Registry key.
     * @param string[] $missing Columns the live table does not have.
     */
    private static function record_column_drift(string $entity, array $missing): void {
        $expected = self::$versionoptionalcolumns[$entity] ?? [];
        if ($expected) {
            $missing = array_values(array_diff($missing, $expected));
        }
        if ($missing) {
            self::$columndrift[$entity] = $missing;
        }
    }

    /**
     * Narrow an entity's declared column list to the columns the host Moodle
     * actually has, and derive its watermark from the survivors.
     *
     * Moodle moves columns between releases (quiz_slots.displaynumber arrived in
     * 4.2) and third-party tables change on their own schedule. One absent column
     * makes the whole SELECT throw, so the entity would export zero rows; dropping
     * the absent column keeps the rest of the table.
     *
     * Called at the point of use rather than inside registry_with_overrides():
     * capture_entity_match() needs one entity per observed event, not every entity,
     * and task_registry() entities (shipped by ship_events) never pass through that
     * funnel. Runs after strip_forbidden_columns(), which expands a '*' entry first,
     * so a column withheld for security is never reported here as drift.
     *
     * @param string $entity Registry key, for the drift record only.
     * @param string $table Unprefixed Moodle table name.
     * @param string|null $columns Declared list; '*'/null = whole row.
     * @param string|null $wmcol Registry 'wmcol' override, if any.
     * @return array{columns: ?string, wmcol: ?string, missing: string[]}
     *         columns === null means there is no usable SELECT list on this site
     *         and the caller must skip the entity.
     */
    protected static function resolve_entity_columns(
        string $entity,
        string $table,
        ?string $columns,
        ?string $wmcol = null
    ): array {
        $cols = $columns === null ? '' : trim($columns);

        // Whole-row export: `SELECT *` cannot name a column that is not there, and
        // watermark_column() already introspects for this shape. Nothing to
        // resolve, and deliberately no get_columns() call of our own — this is
        // why the resolver is free for exactly the entities where drift is
        // impossible.
        if ($cols === '' || $cols === '*') {
            return [
                'columns' => $columns,
                'wmcol' => self::watermark_column($columns, $table, $wmcol),
                'missing' => [],
            ];
        }

        $memokey = $table . '|' . $cols . '|' . (string) $wmcol;
        if (isset(self::$resolvedcolumns[$memokey])) {
            $hit = self::$resolvedcolumns[$memokey];
            // Re-record: the memo is keyed by table, but drift is reported per
            // entity, and two entities can share a table.
            if ($hit['missing']) {
                self::record_column_drift($entity, $hit['missing']);
            }
            return $hit;
        }

        $r = \local_intellistream\services\config_service::intersect_columns($table, $cols);

        if ($r['columns'] === null && !$r['missing']) {
            // Introspection failed outright, so there is nothing to compare
            // against. Fail open: hand back the declared list unchanged.
            // table_exists() has already covered the ordinary "table is gone"
            // case upstream. Deliberately not memoised, so a transient failure
            // does not stick for the life of the process.
            return [
                'columns' => $columns,
                'wmcol' => self::watermark_column($columns, $table, $wmcol),
                'missing' => [],
            ];
        }

        $safe = $r['columns'];

        // The `id` column is load-bearing downstream, not just another column: callers
        // order by it and buffer_entity_row() reads $row->id to build the
        // deterministic entity uuid. Every registry entry declares it first, so
        // its absence from the survivors means the table has no `id` — and then
        // there is no salvageable export, only a slower failure. Realistically
        // this is a table-name collision with a third-party plugin rather than a
        // dropped column, which is why the callers report it distinctly.
        if ($safe !== null) {
            $hasid = false;
            foreach (preg_split('/\s*,\s*/', $safe) as $c) {
                if (strtolower(trim($c)) === 'id') {
                    $hasid = true;
                    break;
                }
            }
            if (!$hasid) {
                $safe = null;
            }
        }

        $resolved = [
            'columns' => $safe,
            // Derive the watermark from the survivors. Without this, an entity
            // whose declared timestamp column is absent builds
            // `WHERE timemodified > :cdcwm` and `MAX(timemodified)` against a
            // column that is not there: both throw, both are swallowed, and the
            // entity ships nothing on every run with its watermark
            // pinned at 0. Passing an explicit list keeps watermark_column() on
            // its string-parsing branch; its numeric check reads Moodle's cached
            // column metadata, so this costs no query after the first per table.
            'wmcol' => $safe === null ? null : self::watermark_column($safe, $table, $wmcol),
            'missing' => $r['missing'],
        ];

        self::$resolvedcolumns[$memokey] = $resolved;
        if ($resolved['missing']) {
            self::record_column_drift($entity, $resolved['missing']);
        }

        return $resolved;
    }

    /**
     * Entities whose declared columns are (partly) absent on this site, as
     * accumulated so far in this process.
     *
     * @return array<string, string[]> entity => missing column names.
     */
    public static function column_drift(): array {
        return self::$columndrift;
    }

    /**
     * One-line summary of accumulated column drift, or null when there is none.
     *
     * Deliberately aggregated rather than logged per entity: export_task_state()
     * calls export_entity() three times a minute and export_incremental() runs 96
     * times a day, so a per-entity line would be thousands of identical rows a
     * day. Drift is a state: the per-datatype `datatype_config` snapshot carries
     * `columns_missing` for machine consumption; this line is just so an operator
     * reading cron output sees it at all.
     *
     * @return string|null
     */
    protected static function column_drift_summary(): ?string {
        if (!self::$columndrift) {
            return null;
        }
        $cols = 0;
        $parts = [];
        foreach (self::$columndrift as $entity => $missing) {
            $cols += count($missing);
            $parts[] = $entity . ': ' . implode(',', $missing);
        }
        return sprintf(
            'local_intellistream: column drift — %d entit%s, %d declared column(s) absent (%s).',
            count(self::$columndrift),
            count(self::$columndrift) === 1 ? 'y' : 'ies',
            $cols,
            implode('; ', $parts)
        );
    }

    /**
     * The table's current MAX(id), or null when it cannot be read.
     *
     * A primary-key lookup on every supported database. The obvious alternative
     * — MAX(CASE WHEN <wm> IS NULL THEN id END) — cannot use an index at all
     * and would cost a full table scan every pass.
     *
     * @param string $table Table name without the prefix.
     * @return int|null
     */
    private static function table_max_id(string $table): ?int {
        global $DB;
        try {
            $max = $DB->get_field_sql("SELECT MAX(id) FROM {" . $table . "}");
        } catch (\Throwable $e) {
            return null;
        }
        return ($max === null || $max === false) ? null : (int)$max;
    }

    /**
     * Bounded id window for an entity's clockless rows, or null when there is
     * nothing new to do on that lane.
     *
     * The upper bound is the max id observed by the previous pass, not this one: an
     * id is allocated at INSERT but visible only at COMMIT, so a pass that read
     * MAX(id) itself could advance past an id whose transaction commits later. An id
     * at or below the previous observation has had a whole refresh interval to
     * commit (a time bound, since one bulk enrolment can allocate thousands of ids).
     * A longer transaction is left to the verification sweep.
     *
     * @param int $idsince Last id this lane shipped for the entity.
     * @param int $ceiling Max id observed on the previous pass; 0 on the first.
     * @return array{0:int, 1:int}|null [since, max], or null when nothing is new.
     */
    private static function clockless_window(int $idsince, int $ceiling): ?array {
        // Capped so a site that has never run this lane cannot try to ship its
        // whole backlog at once; see CLOCKLESS_ID_CHUNK.
        $idmax = min($ceiling, $idsince + self::CLOCKLESS_ID_CHUNK);
        return $idmax > $idsince ? [$idsince, $idmax] : null;
    }

    /**
     * Build the incremental lane's WHERE clause and bound parameters.
     *
     * The timestamp clause is always present. The clockless clause is appended
     * only for an entity known to carry rows the timestamp clause cannot reach:
     * `COALESCE(<wm>, 0) <= 0` is not indexable, and even with its bounded `id`
     * range it is more work than the single index range of the timestamp clause.
     * Entities without clockless rows get the single-clause predicate.
     *
     * @param string $wmcol Resolved watermark column.
     * @param int|string $since Timestamp lane's position (a decimal string only for
     *        a non-integral mark on a decimal column; see wm_number()).
     * @param int|string|null $wmbound Timestamp lane's upper bound, or null for none.
     *        Only a frozen resume window has one; an ordinary pass reads
     *        everything above $since and takes its next mark from the rows it
     *        read. Equal to $since makes the clause trivially false.
     * @param array|null $clockless Id window [from, to], or null for none.
     * @return array [$select, $params]
     */
    private static function cdc_select(
        string $wmcol,
        $since,
        $wmbound,
        ?array $clockless
    ): array {
        $select = "{$wmcol} > :cdcwm";
        $params = ['cdcwm' => $since];
        if ($wmbound !== null) {
            $select .= " AND {$wmcol} <= :cdcmax";
            $params['cdcmax'] = $wmbound;
        }
        if ($clockless === null) {
            return [$select, $params];
        }
        $select = "({$select}) OR (COALESCE({$wmcol}, 0) <= 0 "
            . 'AND id > :cdcidwm AND id <= :cdcidmax)';
        $params['cdcidwm'] = $clockless[0];
        $params['cdcidmax'] = $clockless[1];
        return [$select, $params];
    }

    /**
     * Whether an entity's watermark says it changed within the last $window
     * seconds, so its incremental read should run without the existence check.
     *
     * A watermark more than $window in the future is not treated as recent: it
     * is not a Unix time in seconds (a custom table's millisecond column, say),
     * and the existence check is the safe default for it.
     *
     * @param int|string $since The entity's current watermark (see wm_number()).
     * @param int $window CDC_ACTIVE_RUNS refresh intervals, in seconds.
     * @return bool
     */
    private static function cdc_recently_active($since, int $window): bool {
        $since = (int)$since;
        $now = time();
        return $since > $now - $window && $since <= $now + $window;
    }

    /**
     * A paged-read tuning value (no admin page; set from the CLI): its default when
     * unset or not a whole number, so a mistyped value cannot quietly slow a site down.
     *
     * @param string $name Setting name.
     * @param int $default Value when the setting is unset or not a whole number.
     * @return int
     */
    private static function tuning_int(string $name, int $default): int {
        $value = config::get($name, null);
        return ($value !== null && preg_match('/^\s*\d+\s*$/', (string)$value)) ? (int)$value : $default;
    }

    /**
     * Largest page of a paged read, in rows.
     *
     * @return int
     */
    private static function page_rows_max(): int {
        return max(self::PAGE_ROWS_MIN, self::tuning_int('pagerowsmax', self::PAGE_ROWS_MAX));
    }

    /**
     * Seconds one entity may page in one incremental run (PAGE_BUDGET_SEC, or the
     * setting `pagebudgetsec`; 0 reads one page per run).
     *
     * @return int
     */
    private static function page_budget_sec(): int {
        return max(0, self::tuning_int('pagebudgetsec', self::PAGE_BUDGET_SEC));
    }

    /**
     * Seconds the incremental lane may page in one run, over all entities: two thirds
     * of the refresh task's interval, so a catch-up run ends before the next one is due
     * and leaves the verification sweep its share. Read once per run. A soft limit: it
     * is checked between pages, so a page already started finishes, and after it an
     * entity still gets its first page (a window that fits in one page is read in
     * full); one that needs more is frozen and resumed next run.
     *
     * @return int
     */
    private static function lane_budget_sec(): int {
        return intdiv(2 * sweep::interval(), 3);
    }

    /**
     * Rows for the next page of a paged read, from what the last page cost.
     *
     * The cost is the memory the last page's result took (export_entity() measures
     * it around the query). On MySQL that is the whole result, so the page is sized
     * to keep a result near the byte target whatever the rows hold. On PostgreSQL a
     * read with a limit is not a cursor (Moodle uses one only without a limit), and
     * the client library holds the page outside PHP's memory count: it measures as
     * small, so the page grows to its largest and is bounded by its row count.
     *
     * @param int $rows Rows the last page returned.
     * @param int $bytes Memory its result took, in bytes (0 when unknown).
     * @param int $current The last page's size, in rows.
     * @return int Between PAGE_ROWS_MIN and page_rows_max().
     */
    public static function next_page_rows(int $rows, int $bytes, int $current): int {
        $max = self::page_rows_max();
        if ($rows <= 0) {
            return max(self::PAGE_ROWS_MIN, min($max, $current));
        }
        $target = max(1, self::tuning_int('pagebytes', self::PAGE_BYTES));
        if ($bytes <= 0) {
            // Nothing measurable (held outside PHP's count, as on PostgreSQL): grow,
            // at most doubling.
            return max(self::PAGE_ROWS_MIN, min($max, $current * 2));
        }
        $perrow = max(1, intdiv($bytes, $rows));
        // At most double per page, so one cheap page cannot jump to the largest size
        // on a table whose rows grow further on.
        $next = min(intdiv($target, $perrow), $current * 2);
        return max(self::PAGE_ROWS_MIN, min($max, $next));
    }

    /**
     * Upper bound for a window being read in pages, or null when none can be set yet.
     *
     * The bound is PAGE_CLOCK_SKEW_SEC below the clock at the start of the read, so
     * a row changed during the read falls above it, into the next window. A window
     * that starts later than that is bounded at the second before the read started;
     * one that starts in the future has no bound yet (null: the caller holds it for
     * a run). A watermark that is not a Unix time in seconds (more than $window
     * ahead of the clock, e.g. a millisecond column) is bounded at the highest mark
     * read instead.
     *
     * @param int|string $since The window's start (see wm_number()).
     * @param int|string|null $readmax Highest mark the first page settled.
     * @param int $opened Unix time the read started.
     * @param int $window CDC_ACTIVE_RUNS refresh intervals, in seconds.
     * @return int|string|null
     */
    private static function page_window_bound($since, $readmax, int $opened, int $window) {
        // Judged on the rows read too, not only on the window's start: a millisecond
        // column starts at 0 like any other (new to the lane, or seeded in seconds by
        // the backfill), and a clock bound in seconds would then sit below every value
        // it holds, so each run would read the same first page and move on.
        if ((float)$since > $opened + $window || ($readmax !== null && (float)$readmax > $opened + $window)) {
            return ($readmax !== null && (float)$readmax > (float)$since) ? $readmax : $since;
        }
        return self::page_clock_bound($since, $opened, $window);
    }

    /**
     * Whether a completed paged window leaves a backlog to read in the same run.
     *
     * Yes when the watermark is still below the clock bound of the entity's first
     * window (a catch-up), or when the column is not a time (a millisecond mark above
     * the clock). Otherwise the watermark only moved by the seconds the read took;
     * on a table with steady writes, further windows would re-read a page of recent
     * rows each until the budget ran out, so the next run reads those rows instead.
     *
     * @param int|float|string $newwm Where the window moved the watermark.
     * @param int $opened Unix time the window's read started.
     * @param int $entitystart Unix time the entity's first window started.
     * @param int $window Seconds within which a mark still reads as a time (see page_clock_bound()).
     * @return bool
     */
    private static function window_continues($newwm, int $opened, int $entitystart, int $window): bool {
        return (float)$newwm > $opened + $window || (float)$newwm < $entitystart - self::PAGE_CLOCK_SKEW_SEC;
    }

    /**
     * Where an open window that was read to its end moves the watermark: the highest
     * mark it read, but no higher than the second before the read started when that
     * mark is at or after that second.
     *
     * The next window is `> watermark`, so a row changed in the same second as the
     * highest mark read, after the read passed it, would be skipped for ever.
     * Stopping below the read's first second re-reads those seconds next run
     * (absorbed by UPSERT-on-id). A mark more than $window ahead is left as it was.
     *
     * @param int|string|null $readmax Highest mark the read settled (see wm_number()).
     * @param int $opened Unix time the read started.
     * @param int $window Seconds within which a mark still reads as a time.
     * @return int|string|null
     */
    private static function open_window_mark($readmax, int $opened, int $window) {
        if ($readmax !== null && (float)$readmax >= $opened && (float)$readmax <= $opened + $window) {
            return $opened - 1;
        }
        return $readmax;
    }

    /**
     * The clock part of page_window_bound(), known before the read: PAGE_CLOCK_SKEW_SEC
     * below the clock, or the second before it for a window that starts within that;
     * null for a window whose start is not yet in the past, or is not in seconds.
     *
     * A row changed after the read started is stamped at or after it, so it falls
     * above. The bound only limits the read: the window completes at the highest mark
     * it settled (see export_incremental()). Holding a recent window instead would hold
     * every bulk update for a run.
     *
     * @param int|string $since The window's start (see wm_number()).
     * @param int $opened Unix time the read started.
     * @param int $window CDC_ACTIVE_RUNS refresh intervals, in seconds.
     * @return int|null
     */
    private static function page_clock_bound($since, int $opened, int $window): ?int {
        if ((float)$since > $opened + $window) {
            return null;
        }
        foreach ([$opened - self::PAGE_CLOCK_SKEW_SEC, $opened - 1] as $bound) {
            if ($bound > (float)$since) {
                return $bound;
            }
        }
        return null;
    }

    /**
     * Whether the buffer can take more records, as far as the incremental lane needs
     * to know before it reads.
     *
     * "Yes" may come from the published measurement (projected forward, plus this
     * process's own output, with one file's worth of room to spare); "no" only from
     * an exact measurement at or over the cap, the verdict buffer::have_capacity()
     * gives an append, so a measurement published before the shipper drained the
     * buffer cannot stop the lane.
     *
     * @return bool
     */
    private static function lane_has_room(): bool {
        $dir = config::buffer_dir();
        if ($dir === '' || !is_dir($dir)) {
            return true; // Nothing to measure yet; the append path decides.
        }
        $cap = config::max_buffer_bytes();
        // The published measurement may only say yes, with one more file's worth of
        // room to spare; an exact measurement decides, below the cap, exactly as
        // buffer::have_capacity() decides for an append.
        $quick = $cap - config::rotate_size_bytes() - buffer::MAX_EVENT_BYTES;
        if ($quick > 0 && self::backlog_within($dir, $quick, false)) {
            return true;
        }
        return buffer::measure($dir) < $cap;
    }

    /**
     * Whether an incremental window holds at least one row.
     *
     * Uses the read's own WHERE clause and parameters, so it cannot answer "nothing"
     * for a window the read would return rows for (a row committed in between is read
     * next run, as no mark moved). The answer is used only to skip the read. A plain
     * COUNT, because PostgreSQL can run an aggregate as a parallel pass, while a read
     * ordered by id or `LIMIT 1` often runs serially. A failed check answers true, so
     * the read runs and reports the failure itself.
     *
     * @param string $table Table name without the prefix.
     * @param string $select The read's WHERE clause (no "WHERE" keyword).
     * @param array $params The read's bound parameters.
     * @return bool
     */
    private static function window_has_rows(string $table, string $select, array $params): bool {
        global $DB;
        try {
            return $DB->count_records_select($table, $select, $params, 'COUNT(1)') > 0;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Incremental (CDC) export: ship only rows changed since the last run, per
     * entity, using watermarks kept in CDC_STATE_KEY (refresh_entities task).
     * Derived and timestamp-less entities are left to their task or the sweep.
     *
     * The next mark is taken from the rows the scan read, not a separate SELECT MAX(),
     * and advances only when the entity's scan ran to the end. A permanent buffer
     * refusal is counted and skipped; a temporary one freezes the window in
     * `cdc_state.resume` for the next run. Rows may be sent twice (absorbed by
     * UPSERT-on-id); a transaction that commits below a mark already read is left to
     * the verification sweep. An unpaired site does not run at all.
     *
     * @param string|null $batch snapshot_batch UUID; generated if null.
     * @return array{batch:string, rows:int, entities:int}
     */
    public static function export_incremental(?string $batch = null): array {
        $batch = $batch ?? \core\uuid::generate();
        $total = 0;
        $entities = 0;
        $held = 0;

        // Nothing may run before the site is paired. buffer::append_record()
        // refuses every record while the site id is empty, so a run here would
        // scan every table, ship nothing, and advance every watermark over the
        // rows it did not ship — the whole point of the watermark, lost silently.
        if (config::site_id() === '') {
            mtrace('local_intellistream: no site id — incremental export skipped until the site is paired.');
            return ['batch' => $batch, 'rows' => 0, 'entities' => 0];
        }

        // A full buffer refuses every row, so a run now could only read tables into
        // memory and settle nothing. Hold every watermark and read nothing; the next
        // run reads from the same place once the shipper has made room.
        if (!self::lane_has_room()) {
            mtrace('local_intellistream: incremental export skipped — the buffer is full. Every '
                . 'watermark is held; the next run reads from the same place once the buffer has been shipped.');
            return ['batch' => $batch, 'rows' => 0, 'entities' => 0];
        }

        // Report only this run's drift — see the verification sweep.
        self::$columndrift = [];
        $registry = self::registry_with_overrides();
        $state = self::cdc_state();
        // As loaded: a save merges back what another process (the backfill's seed)
        // wrote meanwhile for the entities this run did not change.
        $original = $state;
        // Frozen windows first, so an entity part-way through a catch-up is not held
        // back behind the rest of the registry by the run budget.
        $frozen = array_intersect_key($registry, $state['resume']);
        $registry = $frozen + array_diff_key($registry, $frozen);
        // Registry flag or what the verification sweep actually saw; see
        // CLOCKLESS_DETECTED_KEY for why a static list is not enough.
        $detected = self::clockless_detected();
        $activewindow = self::CDC_ACTIVE_RUNS * sweep::interval();
        // Set when the buffer refuses a row it may take later: it is full for every
        // entity, so the rest of the run reads nothing.
        $bufferfull = false;
        $skipped = 0;
        $runstart = time();
        $lanebudget = self::lane_budget_sec();
        $lastsave = $runstart;
        $entitybudget = min(self::page_budget_sec(), $lanebudget);
        foreach ($registry as $entity => $def) {
            // Keep the sweeper from mistaking a long run with few appends for an
            // abandoned file. First statement in the loop so it still runs for the
            // entities that `continue` out below.
            buffer::keepalive();
            if (!empty($def['derived'])) {
                continue; // Aggregate — kept and sent by its own task.
            }
            if (backfill::gated((string)$entity)) {
                continue; // New install: the historical backfill owns it until it is done.
            }
            $table = $def['table'] ?? null;
            if ($table === null) {
                continue;
            }
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                continue;
            }
            // Resolve against the live schema, and take the watermark from the
            // surviving columns. Deriving it from the declared list would let a
            // drifted entity build `WHERE <absent> > :cdcwm`, which throws and is
            // swallowed, so the entity would ship nothing with its watermark stuck at 0.
            $resolved = self::resolve_entity_columns(
                $entity,
                $table,
                $def['columns'] ?? null,
                $def['wmcol'] ?? null
            );
            if ($resolved['columns'] === null) {
                continue; // Nothing selectable on this site — export_entity() reports it.
            }
            $wmcol = $resolved['wmcol'];
            if ($wmcol === null) {
                continue; // No change timestamp — the verification sweep only.
            }
            if ($bufferfull) {
                $skipped++;
                continue; // Its watermark and any frozen window are left as they are.
            }
            if (!isset($state['wm'][$entity])) {
                // No position when the run loaded the state: the backfill may have
                // seeded one since (it releases an entity when it finishes). Reading
                // from 0 would re-send the whole table, and the run's save would then
                // replace the seed.
                $stored = self::cdc_state_stored();
                if ($stored !== null && isset($stored['wm'][$entity])) {
                    $state['wm'][$entity] = $stored['wm'][$entity];
                }
            }
            $since = self::wm_for_column($table, $wmcol, self::wm_number($state['wm'][$entity] ?? 0));
            // No SELECT MAX({$wmcol}) here: the next mark comes out of the rows
            // the read returned (see the docblock). A quiet entity is asked first
            // whether its window holds any row, and an active one is read
            // directly, so neither pays for the table twice in the usual case.

            // The clockless lane. A row whose watermark is NULL or 0 never
            // satisfies `wmcol > :since`. Moodle produces such rows in bulk (an
            // ungraded grade_grades row materialised at enrolment has a NULL
            // timemodified), and the verification sweep can be disabled. `id` is
            // the one monotonic key such a row has, so it gets a second,
            // keyset-bound window here.
            //
            // Applied only to entities known to carry clockless rows: the OR below
            // cannot use an index, so on a table with an indexed watermark column
            // it would turn an index range scan into a full scan for no benefit.
            $clockless = null;
            if (!empty($def['clockless']) || isset($detected[$entity])) {
                // Read the ceiling before recording this pass's observation:
                // the window is bounded by what the previous pass saw, so an
                // id allocated by a still-open transaction has had a full pass
                // interval to commit. See clockless_window().
                $ceiling = (int)($state['idseen'][$entity] ?? 0);
                $observed = self::table_max_id($table);
                if ($observed !== null) {
                    // An observation, not a delivery watermark — recorded even
                    // when the scan below fails, because it only ever says
                    // "ids up to here existed at this time".
                    $state['idseen'][$entity] = $observed;
                }

                if (!isset($state['idwm'][$entity])) {
                    // First time this entity reaches the lane. Start from the
                    // max clockless id the last verification pass delivered rather
                    // than from 0: those rows are already downstream, and
                    // walking a large table from the start in CLOCKLESS_ID_CHUNK
                    // steps would re-ship every one of them. The registry flag
                    // gets this from db/upgrade.php; a detected entity gets it
                    // here, so the two routes into the lane behave alike.
                    $state['idwm'][$entity] = (int)($detected[$entity] ?? 0);
                }

                if ($observed !== null && $observed < (int)$state['idwm'][$entity]) {
                    // The table shrank (a restore from an older backup, a
                    // truncate-and-reimport). Left alone the watermark would sit
                    // above every existing id and the lane would do nothing for
                    // this entity until ids climbed back past the old mark.
                    //
                    // Reset to 0, not to the new max: after a restore the ids below
                    // the mark are whatever the backup held, not the rows that were
                    // shipped. The chunked re-walk this costs is absorbed downstream
                    // by UPSERT-on-id; clamping would silently drop unsent rows.
                    mtrace("local_intellistream: {$entity} — clockless id watermark "
                        . "({$state['idwm'][$entity]}) is above the table's current max "
                        . "({$observed}). The table shrank, so the id space being tracked "
                        . 'is gone; restarting this lane from 0 and re-walking in bounded '
                        . 'passes rather than assuming the surviving rows were shipped.');
                    $state['idwm'][$entity] = 0;
                    $state['idseen'][$entity] = $observed;
                    $ceiling = $observed;
                    // A frozen window from before the shrink describes ids of the
                    // old id space. Dropping it is safe: no watermark was ever
                    // advanced past it.
                    unset($state['resume'][$entity]);
                }

                $clockless = self::clockless_window(
                    (int)$state['idwm'][$entity],
                    $ceiling
                );
            }

            // The read is paged (see PAGE_ROWS_START): each pass of this loop reads at
            // most $pagerows rows in id order. A window that holds more than one page
            // is frozen after its first page, exactly as a stop by the buffer freezes
            // one, and the next page resumes after the last id settled. A window that
            // fits in one page is read, settled and advanced in one pass. When a frozen
            // window completes and budget remains, the next window is read in the same
            // run.
            [$basesince, $baseclockless] = [$since, $clockless];
            // The size this entity last paged at, or the smallest page: a first page of
            // rows nothing has measured yet must not be the one that runs out of memory.
            $lastsize = (int)($state['page'][$entity] ?? self::PAGE_ROWS_START);
            $pagerows = max(self::PAGE_ROWS_MIN, min(self::page_rows_max(), $lastsize));
            $entitystart = time();
            $counted = false;
            $paged = false;
            do {
                $more = false;
                [$since, $clockless] = [$basesince, $baseclockless];
                // The clock for this window's bound, taken before anything of it is
                // read (the first-id lookup included).
                $opened = time();
                $resume = $state['resume'][$entity] ?? null;
                if ($resume !== null) {
                    // A previous scan was stopped part-way through this window (by the
                    // buffer, by the page size, or by the time budget). Finish that
                    // window, from the row after the last one settled, before opening a
                    // new one — both lanes frozen exactly as they were, because
                    // recomputing either would put rows below the resume id that the
                    // stopped scan never reached.
                    $since = self::wm_for_column($table, $wmcol, $resume['lo']);
                    $bound = self::wm_for_column($table, $wmcol, $resume['hi']);
                    $clockless = isset($resume['idlo'], $resume['idhi'])
                        ? [$resume['idlo'], $resume['idhi']] : null;
                    if ($bound <= $since && $clockless === null) {
                        unset($state['resume'][$entity]); // An empty frozen window owes nothing.
                        break;
                    }
                } else {
                    // Open above: nothing to bound against without the probe, and
                    // nothing lost by it, because the mark is taken from what is read.
                    $bound = null;
                }

                [$select, $params] = self::cdc_select($wmcol, $since, $bound, $clockless);
                if ($resume !== null) {
                    $select = "({$select}) AND id > :cdcresume";
                    $params['cdcresume'] = $resume['id'];
                }

                $outcome = null;
                $quiet = $resume === null && !self::cdc_recently_active($since, $activewindow);
                $empty = $quiet && !self::window_has_rows($table, $select, $params);
                if (!$empty && $resume === null && $clockless === null) {
                    // A page reads in id order, so without a lower id bound the first page
                    // of a small window walks the primary key from the table's first row
                    // to reach it (seconds, on a large table). Start it at the window's
                    // lowest id, read through the watermark index.
                    $first = self::window_first_id($table, $wmcol, $select, $params);
                    if ($first === false) {
                        $empty = true;
                    } else if ($first !== null) {
                        $select = "({$select}) AND id >= :cdcfirst";
                        $params['cdcfirst'] = $first;
                    }
                }
                if ($empty) {
                    // Nothing in this window: exactly what an empty read would have
                    // reported, without opening the read. A frozen window never takes
                    // this path, because it must be completed.
                    $rowshere = 0;
                    $outcome = ['complete' => true, 'rows' => 0, 'dropped' => 0, 'error' => null,
                        'stopped' => false, 'lastid' => null, 'maxwm' => null, 'fetched' => 0, 'resultbytes' => 0];
                } else {
                    // The bound a full first page would freeze at, when it is a clock
                    // value: the page notes its highest mark at or below it as it reads,
                    // which is where that window then completes from.
                    $cap = $resume === null ? self::page_clock_bound($since, $opened, $activewindow) : null;
                    $rowshere = self::export_entity(
                        $entity,
                        $batch,
                        $registry,
                        $select,
                        $params,
                        $outcome,
                        true,
                        $pagerows,
                        $cap === null ? null : self::wm_for_column($table, $wmcol, $cap)
                    );
                }
                $total += $rowshere;
                // Counted per entity that did something, once however many pages it
                // took. Every entity is read on every run, so counting reads would
                // report the whole registry on every idle run.
                if (!$counted && ($rowshere > 0 || !empty($outcome['dropped']) || !empty($outcome['stopped']))) {
                    $entities++;
                    $counted = true;
                }
                // The highest timestamp over the rows this read settled; null when
                // none carried one (nothing changed, or clockless rows only).
                $readmax = $outcome['maxwm'] === null ? null : self::wm_number($outcome['maxwm']);
                // A page read to its end that came back full: the window may hold more.
                $full = !empty($outcome['complete']) && (int)($outcome['fetched'] ?? 0) >= $pagerows;

                if (!empty($outcome['stopped'])) {
                    // The buffer refused a row it may accept later. Freeze this window
                    // and resume after the last settled id next run; no watermark moves.
                    //
                    // The upper bound of a new frozen window is the highest timestamp
                    // over the rows settled before the stop, not open-ended: the resume
                    // is `id > lastid`, so a settled row changed during the pause would
                    // be skipped by it, and must therefore fall above the bound, in the
                    // window after. Unsent rows above the bound land there too; unsent
                    // rows at or below it, same-second ties included, are the resume's.
                    // A window being resumed keeps its own bound.
                    $hi = $bound ?? max($since, $readmax ?? $since);
                    $rec = [
                        'lo' => $since,
                        'hi' => $hi,
                        'id' => (int)($outcome['lastid'] ?? ($resume['id'] ?? 0)),
                        // The highest mark settled in the window so far (at or below its
                        // bound: a resumed read is bounded, and a new bound is that mark).
                        'seen' => self::max_mark($resume['seen'] ?? $since, $readmax),
                    ];
                    if ($clockless !== null) {
                        $rec['idlo'] = $clockless[0];
                        $rec['idhi'] = $clockless[1];
                    }
                    $held++;
                    // The buffer is full for every entity, not just this one: reading
                    // the rest would only fill memory with rows nobody can take now.
                    $bufferfull = true;
                    if ($hi <= $since && $clockless === null) {
                        // Nothing settled with a timestamp, so there is no window to
                        // finish: the ordinary read from $since next run is the same
                        // read. Recording one would only cost that run.
                        unset($state['resume'][$entity]);
                        mtrace("local_intellistream: {$entity} — the buffer refused a row it may "
                            . "accept later; watermark HELD at {$since}, next run re-reads from there.");
                    } else {
                        $state['resume'][$entity] = $rec;
                        mtrace("local_intellistream: {$entity} — the buffer refused a row it may "
                            . "accept later; window frozen, next run resumes after id {$rec['id']}.");
                    }
                } else if ($full) {
                    // More rows than one page. Settle this page and go on with the next,
                    // behind a fixed upper bound so that a row on this page changed
                    // before the last page lands in the window after (PAGE_CLOCK_SKEW_SEC).
                    $paged = true;
                    $lastid = (int)($outcome['lastid'] ?? ($resume['id'] ?? 0));
                    if ($resume !== null && $lastid <= (int)$resume['id']) {
                        // A full page that settled nothing past its start: paging on would
                        // read the same page for ever. Keep the window for the next run.
                        $held++;
                        mtrace("local_intellistream: {$entity} — a page after id {$resume['id']} settled no "
                            . 'row past it; window kept for the next run.');
                        break;
                    }
                    if ($resume !== null) {
                        // Same frozen window, next page. Its rows are all at or below the
                        // bound, so the highest of them is a mark the window has seen.
                        $state['resume'][$entity]['id'] = $lastid;
                        $state['resume'][$entity]['seen'] = self::max_mark($resume['seen'] ?? $since, $readmax);
                    } else {
                        $hi = self::page_window_bound($since, $readmax, $opened, $activewindow);
                        if ($hi === null || ($hi <= $since && $clockless === null)) {
                            // The window starts at or after the clock (a watermark in the
                            // future), so no bound below the clock is above it yet. Hold it:
                            // the next run reads from the same place. This page's rows are
                            // sent again then, which the downstream UPSERT-on-id absorbs.
                            $held++;
                            mtrace("local_intellistream: {$entity} — more than {$pagerows} rows above a "
                                . "watermark ({$since}) that is not yet in the past; watermark HELD, "
                                . 'next run reads this window in pages.');
                            break;
                        }
                        $hi = self::wm_for_column($table, $wmcol, $hi);
                        // The highest mark this first page settled at or below the bound,
                        // noted as it read (its rows above the bound are the window
                        // after's). A bound that is not the clock bound is the page's own
                        // highest mark (a column not in seconds), which is then that.
                        $seen = ($cap !== null && (float)$hi === (float)$cap)
                            ? ($outcome['maxwmcap'] ?? $since)
                            : ($readmax ?? $since);
                        $rec = ['lo' => $since, 'hi' => $hi, 'id' => $lastid, 'seen' => $seen];
                        if ($clockless !== null) {
                            $rec['idlo'] = $clockless[0];
                            $rec['idhi'] = $clockless[1];
                        }
                        $state['resume'][$entity] = $rec;
                    }
                    $pagerows = self::next_page_rows(
                        (int)$outcome['fetched'],
                        (int)($outcome['resultbytes'] ?? 0),
                        $pagerows
                    );
                    // Both budgets are checked between pages, so they are soft: a page
                    // already started finishes.
                    if (time() - $entitystart < $entitybudget && time() - $runstart < $lanebudget) {
                        $more = true;
                    } else {
                        $held++;
                        mtrace("local_intellistream: {$entity} — read in pages for "
                            . (time() - $entitystart) . "s this run; window frozen, next run resumes after id {$lastid}.");
                    }
                } else if (!empty($outcome['complete'])) {
                    // Both lanes advance together or neither does: the scan that
                    // covers them is a single pass, so advancing one while holding
                    // the other would step over rows the held lane still owes.
                    //
                    // An ordinary read moves to the highest timestamp it read, but not past
                    // the second before it started (open_window_mark()), and stays put
                    // when it read none. A finished frozen window moves to the highest mark
                    // it settled, never above its bound: moving to the bound itself (a clock
                    // value for a paged window) would step over a row that becomes visible
                    // late with a mark between the two, and over every value of a column
                    // that is not a time. A resume record without `seen` moves to its bound
                    // (its bound is the highest mark read).
                    if ($bound === null) {
                        $newwm = self::open_window_mark($readmax, $opened, $activewindow);
                        if ($newwm !== null) {
                            $newwm = self::wm_for_column($table, $wmcol, $newwm);
                        }
                    } else if (isset($resume['seen'])) {
                        $newwm = self::min_mark($bound, self::max_mark($resume['seen'], $readmax));
                    } else {
                        $newwm = $bound;
                    }
                    $moved = $newwm !== null && $newwm > $since;
                    if ($moved) {
                        $state['wm'][$entity] = $newwm;
                    }
                    if ($clockless !== null) {
                        $state['idwm'][$entity] = $clockless[1];
                    }
                    unset($state['resume'][$entity]);
                    // A paged window may have more above it (on a table whose ids follow
                    // its marks, everything above a page's highest mark). Read the next
                    // window in this run while budget remains: for a window this run read
                    // in pages, or a frozen one that carries `seen`, and only while a
                    // backlog is left (window_continues()). Not for a resume record without
                    // `seen` (it completes at its bound), nor for an entity with a clockless
                    // id window, recomputed only at a run's start.
                    if (
                        $resume !== null && ($paged || isset($resume['seen'])) && $moved && $baseclockless === null
                        && self::window_continues($newwm, $opened, $entitystart, $activewindow)
                        && time() - $entitystart < $entitybudget && time() - $runstart < $lanebudget
                    ) {
                        $basesince = self::wm_for_column($table, $wmcol, $newwm);
                        $more = true;
                    }
                } else {
                    // The scan did not finish, so part of this window was never read.
                    // Holding the watermark is what makes the next run re-export it;
                    // the downstream UPSERT-on-id absorbs the overlap.
                    $held++;
                    mtrace("local_intellistream: {$entity} — watermark HELD at {$since} "
                        . '(scan did not complete); the next run re-exports this window.');
                }
            } while ($more);
            if ($paged) {
                // Start from this size next run. Keep the position too, so a later entity's
                // read that ends the process does not lose it, but at most once a minute:
                // each save purges the plugin's config cache.
                $state['page'][$entity] = $pagerows;
                if (time() - $lastsave >= self::PAGE_SAVE_SEC) {
                    self::cdc_state_save($state, $original);
                    $lastsave = time();
                }
            }
        }

        // The run's config write, skipped when nothing moved (an entity that read in
        // pages may also have saved when it finished, at most once a minute).
        self::cdc_state_save($state, $original);

        mtrace(sprintf(
            'local_intellistream: incremental export — %d changed row(s) across %d entit%s (batch %s).',
            $total,
            $entities,
            $entities === 1 ? 'y' : 'ies',
            $batch
        ));
        if ($held > 0) {
            mtrace(sprintf(
                'local_intellistream: incremental export — %d entit%s did not finish scanning; '
                    . 'their watermarks were held for the next run.',
                $held,
                $held === 1 ? 'y' : 'ies'
            ));
        }
        if ($skipped > 0) {
            mtrace(sprintf(
                'local_intellistream: incremental export — %d entit%s not read after the buffer filled; '
                    . 'their watermarks were held for the next run.',
                $skipped,
                $skipped === 1 ? 'y' : 'ies'
            ));
        }
        if (($drift = self::column_drift_summary()) !== null) {
            mtrace($drift);
        }
        return ['batch' => $batch, 'rows' => $total, 'entities' => $entities];
    }

    /**
     * Registry of Moodle's own task tables, kept separate from registry() on
     * purpose.
     *
     * These are only exported filtered to this plugin's own tasks (see
     * export_task_state()). In registry() the sweep and export_incremental() would
     * ship the host's entire task history (every component's tasks). So they are
     * passed explicitly as the $registry argument to export_entity(), which never
     * consults registry() when a registry is supplied.
     *
     * Column lists match the Moodle task schema (task_log / task_scheduled /
     * task_adhoc).
     *
     * @return array
     */
    public static function task_registry(): array {
        return [
            // Completed runs (success and failure). Moodle writes a row only
            // when a task finishes, so this is the post-completion task log.
            'task_log' => [
                'table'   => 'task_log',
                'columns' => 'id, type, component, classname, userid, timestart, '
                    . 'timeend, dbreads, dbwrites, result, output, hostname, pid',
                // This lane watermarks on completion time, and `timeend` is not
                // one of WATERMARK_COLUMNS, so it has to be declared. It is what
                // lets export_task_state() take the next mark out of the rows its
                // read returned; without it `maxwm` is always null and the lane
                // would never advance, re-shipping its whole history every minute.
                'wmcol'   => 'timeend',
            ],
            // Persistent per-class schedule/status. timestarted/pid are set
            // while a run is in progress and cleared when it finishes — the
            // basis for the Running-Tasks in-progress view.
            'task_scheduled' => [
                'table'   => 'task_scheduled',
                'columns' => 'id, component, classname, lastruntime, nextruntime, '
                    . 'faildelay, disabled, timestarted, hostname, pid',
            ],
            // Queue rows: present while pending, removed on success, persist on
            // failure (faildelay/nextruntime bumped).
            'task_adhoc' => [
                'table'   => 'task_adhoc',
                'columns' => 'id, component, classname, nextruntime, faildelay, '
                    . 'customdata, userid, timecreated, timestarted, hostname, pid',
            ],
        ];
    }

    /**
     * Task classes this plugin has removed. A run that started before the upgrade
     * can still finish, and be logged, after it. When a task class is removed, add
     * its name here.
     *
     * @var string[]
     */
    public const RETIRED_TASK_CLASSES = [
        'local_intellistream\\task\\daily_snapshot',
        'local_intellistream\\task\\maintain_lti_roles',
        'local_intellistream\\task\\targeted_refetch',
    ];

    /**
     * This plugin's task class names, as Moodle stores them in `task_log.classname`
     * (no leading backslash). Every task class of the plugin is in its `task` namespace.
     *
     * @return string[]
     */
    private static function task_class_names(): array {
        $names = [];
        foreach (array_keys(\core_component::get_component_classes_in_namespace(config::COMPONENT, 'task')) as $name) {
            $names[] = ltrim($name, '\\');
        }
        return $names;
    }

    /**
     * Export this plugin's Moodle task tables, so the IntelliBoard service can
     * show this plugin's task log, running tasks and ad-hoc tasks.
     *
     * Called from the 1-minute ship_events task so a finished run is sent quickly.
     * Every query is limited to this plugin's tasks; task_log is watermarked on
     * `timeend`, while the small task_scheduled / task_adhoc sets (with the live
     * timestarted/pid) are sent whole every pass. Best-effort: the caller wraps this
     * in try/catch so a task export failure never blocks file shipping.
     *
     * @param string $batch snapshot_batch UUID shared by this pass.
     * @return int rows buffered.
     */
    public static function export_task_state(string $batch): int {
        global $DB;

        $comp = config::COMPONENT;
        $reg = self::task_registry();
        $rows = 0;

        // Task_log: completed runs since the last watermark.
        // timeend is a decimal seconds timestamp; the watermark is the max
        // timeend already shipped. First run (unset) ships this plugin's whole
        // task_log history once, then only newer completions. The stored value
        // keeps full string precision, and the WHERE binds that same string.
        $wmkey = 'task_wm_task_log';
        $stored = get_config($comp, $wmkey);
        $stored = is_numeric($stored) ? (string)$stored : '0';
        // The mark moves on nearly every run (each of this plugin's own task runs
        // adds a row), so it is kept beside the buffer (buffer::write_mark()) and
        // copied into config only every TASK_LOG_MARK_STEP_SEC, as a backstop if
        // that file is lost; the later of the two is where the lane stands.
        $file = buffer::read_mark('task_log');
        $since = $file !== null && (float)$file > (float)$stored ? $file : $stored;
        // The next mark comes out of the rows this read returned (`maxwm`), so no
        // separate SELECT MAX(timeend) pass is needed every minute.
        // Neither `component` nor `timeend` has an index, so a read on them scans the whole
        // task log every minute. Once the first pass has sent the history, the read is
        // by this plugin's task classes instead, which `classname` indexes: the current
        // classes and RETIRED_TASK_CLASSES, whose rows can still finish after an upgrade.
        // A class name is the plugin's own, so this also finds the rows that Moodle 3.9
        // logs with an empty component. The first pass keeps the component filter, so it
        // sends the whole history once.
        $select = 'component = :comp AND timeend > :wm';
        $params = ['comp' => $comp, 'wm' => $since];
        if ((float)$since > 0) {
            [$insql, $inparams] = $DB->get_in_or_equal(
                array_merge(self::task_class_names(), self::RETIRED_TASK_CLASSES),
                SQL_PARAMS_NAMED,
                'tlcls'
            );
            $select = "classname {$insql} AND timeend > :wm";
            $params = $inparams + ['wm' => $since];
        }
        $refusedbefore = buffer::transient_refusals();
        $outcome = null;
        $rows += self::export_entity(
            'task_log',
            $batch,
            $reg,
            $select,
            $params,
            $outcome
        );
        // Only past rows that were written. A row the buffer refused for now (full,
        // unpaired, a failed write) is still owed, and moving the mark past it would
        // leave it behind for good; the whole window is read again next pass
        // instead. A scan that failed part-way is treated the same.
        $complete = !empty($outcome['complete']) && buffer::transient_refusals() === $refusedbefore;
        // Raw, so the stored value keeps the precision the read returned, and bound
        // as that string above: a float parameter is bound at 14 significant
        // digits, which could round the mark past a row not yet read.
        $newmax = $outcome['maxwm'] ?? null;
        if ($complete && $newmax !== null && (float)$newmax > (float)$since) {
            buffer::write_mark('task_log', (string)$newmax);
            // Config only on the step, even when the file cannot be written: a failing
            // file must not turn this back into a set_config() every minute. The rows
            // since the config mark are then re-read (fixed ids, idempotent) until it moves.
            if ((float)$newmax - (float)$stored >= self::TASK_LOG_MARK_STEP_SEC) {
                set_config($wmkey, (string)$newmax, $comp);
            }
        }

        // Task_scheduled + task_adhoc: full filtered state each pass.
        $rows += self::export_entity(
            'task_scheduled',
            $batch,
            $reg,
            'component = :comp',
            ['comp' => $comp]
        );
        $rows += self::export_entity(
            'task_adhoc',
            $batch,
            $reg,
            'component = :comp',
            ['comp' => $comp]
        );

        return $rows;
    }

    // Datatype "table type" categories, matching the values the IntelliBoard
    // service displays (0=Required, 1=Optional, 2=Logs).

    /** @var int Table type: required for the product to function. */
    const DATATYPE_TABLETYPE_REQUIRED = 0;

    /** @var int Table type: optional, exported only when enabled. */
    const DATATYPE_TABLETYPE_OPTIONAL = 1;

    /** @var int Table type: high-volume log data. */
    const DATATYPE_TABLETYPE_LOGS     = 2;

    /**
     * Build the per-datatype configuration catalog the IntelliBoard service shows.
     * Every value is computed from the effective registry entry:
     *   - tabletype: Required (built-in), Optional (admin-added), Logs (log datatypes);
     *   - timemodified_field: the watermark column (watermark_column()), or '';
     *   - rewritable: 1 when there is no watermark (re-sent by the sweep), else 0;
     *   - filterbyid: 1 for id-keyed tables, 0 for derived aggregates;
     *   - events_tracking: 1 only for the log datatypes captured from events;
     *   - status/exportenabled: 1 when enabled and exported; a discovered table listed
     *     while discovery export is off has exportenabled 0 (no rows are sent).
     *
     * @return array list of associative config rows.
     */
    public static function datatype_config_catalog(): array {
        $builtin = self::registry();                 // Curated built-ins -> Required.
        $overrides = (new \local_intellistream\repositories\config_repository())->get_all();
        $catalog = [];

        // Enumerate built-ins + any admin-added custom entities. Unlike the export
        // path (registry_with_overrides(), which drops disabled datatypes), the
        // catalog keeps disabled entries so the IntelliBoard service can still
        // list them and re-enable them.
        $names = array_keys($builtin);
        foreach ($overrides as $datatype => $row) {
            if (!isset($builtin[$datatype]) && !empty($row->custom_table)) {
                $names[] = $datatype;
            }
        }

        foreach ($names as $datatype) {
            $def = $builtin[$datatype] ?? null;
            $override = $overrides[$datatype] ?? null;
            $table = $def['table'] ?? null;
            if ($table === null && $override && !empty($override->custom_table)) {
                // A stored `custom_table` is admin free text, and the plugin's own
                // help text documents setting it by editing the config table
                // directly. It reaches watermark_column() -> $DB->get_columns(),
                // which interpolates the table name unescaped into the catalog
                // query on Postgres (pgsql_native_moodle_database::fetch_columns).
                // So this path checks table_is_real() first, like the export path.
                $candidate = (string)$override->custom_table;
                $table = \local_intellistream\services\config_service::table_is_real($candidate)
                    ? $candidate
                    : null;
            }
            $columns = $def['columns'] ?? (($override && isset($override->custom_columns)) ? $override->custom_columns : null);
            $derived = !empty($def['derived']);
            // The `wmcol` field is included so the six entities that rely on an override
            // (course_modules, forum_posts, survey_answers, attendance_log,
            // lesson_attempts, chat_messages) are reported to the IntelliBoard service
            // with their real timemodified field, as they ship incrementally.
            $resolved = ($table !== null && !$derived)
                ? self::resolve_entity_columns($datatype, $table, $columns, $def['wmcol'] ?? null)
                : ['columns' => null, 'wmcol' => null, 'missing' => []];
            $wm = $resolved['wmcol'];

            // Report drift for curated entries only. For an admin `custom_table`
            // row $columns is un-whitelisted PARAM_RAW free text, and its rejected
            // tokens must never be shipped off-site.
            $missing = isset($builtin[$datatype]) ? $resolved['missing'] : [];

            $enabled = ($override && (int)$override->enabled === 0) ? 0 : 1;
            $tabletype = ($override && isset($override->tabletype) && $override->tabletype !== null && $override->tabletype !== '')
                ? (int)$override->tabletype
                : (isset($builtin[$datatype]) ? self::DATATYPE_TABLETYPE_REQUIRED : self::DATATYPE_TABLETYPE_OPTIONAL);
            // A discovered table (not a curated built-in) exports only while
            // dynamicdiscoveryexport is on; until then it is listed, with its own
            // enabled flag as `status`, but reported as not exporting.
            $exporting = $enabled;
            if (
                !isset($builtin[$datatype]) && $override && !empty($override->discovered)
                    && !\local_intellistream\services\config_service::dynamic_discovery_export_enabled()
            ) {
                $exporting = 0;
            }

            $catalog[] = [
                'datatype'           => $datatype,
                'tabletype'          => $tabletype,
                'status'             => $enabled,
                'exportenabled'      => $exporting,
                'timemodified_field' => $wm ?? '',
                'rewritable'         => $wm === null ? 1 : 0,
                'filterbyid'         => $derived ? 0 : 1,
                'events_tracking'    => 0,
                // Declared columns this Moodle does not have. One current row per
                // datatype (deterministic uuid5 -> downstream UPSERT), so drift is
                // carried as state that self-heals on upgrade rather than as an
                // ever-growing log. Empty string on a healthy site.
                'columns_missing'    => $missing ? implode(',', $missing) : '',
            ];
        }

        // Log datatypes: captured live from Moodle events (not entity snapshots),
        // so tabletype=Logs and events_tracking=Yes. Enumerated from the plugin's
        // own log datatype marker classes rather than a hand-kept list; an admin
        // enable/disable override is honored the same way.
        foreach (
            [
            \local_intellistream\datatypes\syslogs_datatype::ENTITY,
            \local_intellistream\datatypes\exceptions_datatype::ENTITY,
            ] as $logtype
        ) {
            $override = $overrides[$logtype] ?? null;
            $enabled = ($override && (int)$override->enabled === 0) ? 0 : 1;
            $catalog[] = [
                'datatype'           => $logtype,
                'tabletype'          => self::DATATYPE_TABLETYPE_LOGS,
                'status'             => $enabled,
                'exportenabled'      => $enabled,
                'timemodified_field' => '',
                'rewritable'         => 0,
                'filterbyid'         => 0,
                'events_tracking'    => 1,
                // Log datatypes are event-captured, not table snapshots, so they
                // have no declared column list to drift. Present for a uniform
                // row shape.
                'columns_missing'    => '',
            ];
        }

        return $catalog;
    }

    /**
     * Ship the datatype-config catalog so the IntelliBoard service can show the
     * datatypes configuration.
     *
     * One `datatype_config` entity_snapshot per datatype, keyed by the datatype
     * name (deterministic id, so downstream keeps one current row per datatype).
     * Sent through the ordinary buffer like any entity snapshot. Static metadata,
     * so it is emitted from the periodic refresh (not the 1-min shipper).
     *
     * @param string $batch snapshot_batch UUID shared by this pass.
     * @return int datatypes buffered.
     */
    public static function export_datatype_config(string $batch): int {
        global $CFG;
        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;

        $n = 0;
        foreach (self::datatype_config_catalog() as $cfg) {
            $payload = [
                'id'             => self::entity_uuid($siteid, 'datatype_config', $cfg['datatype']),
                'site_id'        => $siteid,
                'captured_at'    => clock::now(),
                'plugin_version' => $pluginversion,
                'moodle_version' => $moodleversion,
                'record_type'    => 'entity_snapshot',
                'entity'         => 'datatype_config',
                'snapshot_batch' => $batch,
                // The 'id' inside entity_data is the datatype name (the logical key);
                // the IntelliBoard service assigns its own display row number.
                'entity_data'    => array_merge(['id' => $cfg['datatype']], $cfg),
            ];
            if (buffer::append_record($payload)) {
                $n++;
            }
        }
        mtrace("local_intellistream: datatype_config catalog — {$n} datatype(s) buffered (batch {$batch}).");
        return $n;
    }

    /**
     * Fixed namespace UUID for deterministic entity_snapshot ids (RFC 4122 v5).
     * Do not change it: altering it shifts every snapshot id and breaks
     * de-duplication against rows already received downstream.
     */
    const ENTITY_ID_NAMESPACE = '6f1b2c3d-4e5a-4b6c-8d7e-9f0a1b2c3d4e';

    /**
     * Deterministic id for an entity_snapshot row, derived from
     * (site_id, entity, primary key). The same logical Moodle record therefore
     * gets the same id on every scheduled export, so the downstream UPSERT-on-id
     * collapses re-snapshots of an unchanged row (and updates a changed row in
     * place) instead of storing a new row per run. `$pk` is null for singleton
     * snapshots (e.g. the inform_dyn_schema catalog).
     *
     * @param string $siteid the site's pairing id
     * @param string $entity entity/datatype name
     * @param int|string|null $pk Moodle primary key (null = singleton)
     * @return string RFC 4122 v5 UUID
     */
    private static function entity_uuid(string $siteid, string $entity, $pk = null): string {
        $name = $pk === null ? "{$siteid}|{$entity}" : "{$siteid}|{$entity}|{$pk}";
        return self::uuid5(self::ENTITY_ID_NAMESPACE, $name);
    }

    /**
     * Id of a recovery-window record (window_tool). The request is part of the
     * name, so two runs over the same logstore rows get different ids: downstream
     * de-duplicates on the id and keeps one version per id, so a shared id would
     * make the second run overwrite (or be dropped against) the first.
     *
     * @param string $siteid the site's pairing id
     * @param string $requestid the run's request id
     * @param string $key the logstore row id, or 'request' for the request record
     * @return string RFC 4122 v5 UUID
     */
    public static function window_record_id(string $siteid, string $requestid, string $key): string {
        return self::entity_uuid($siteid, window_tool::ENTITY, $requestid . '|' . $key);
    }

    /**
     * The backfill backpressure, pause only, for the recovery window tool: it
     * never runs a shipping pass inline (the ship_events task drains the buffer), it only
     * throws export_exception when the backlog is over half the buffer cap.
     */
    public static function window_backpressure(): void {
        self::backfill_apply_backpressure(false);
    }

    /**
     * RFC 4122 v5 (SHA-1, name-based) UUID. Moodle's \core\uuid::generate() is
     * random v4 only, so compute the name-based variant here.
     *
     * @param string $namespace 36-char namespace UUID
     * @param string $name      name within the namespace
     * @return string
     */
    private static function uuid5(string $namespace, string $name): string {
        $nhex = str_replace('-', '', $namespace);
        $nbytes = '';
        for ($i = 0, $len = strlen($nhex); $i < $len; $i += 2) {
            $nbytes .= chr(hexdec(substr($nhex, $i, 2)));
        }
        $hash = sha1($nbytes . $name);
        return sprintf(
            '%08s-%04s-%04x-%04x-%12s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000, // Version 5.
            (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000, // Variant RFC 4122.
            substr($hash, 20, 12)
        );
    }

    /**
     * Emit the table schema catalog as a single `inform_dyn_schema` entity_snapshot.
     * Lists every non-derived entity of {@see registry_with_overrides()} (so disabled
     * rows and forbidden columns are already removed) with its columns from
     * {@see \moodle_database::get_columns()}. Listing a table moves no data. Shape:
     * { "<datatype>": { "name": "<datatype>", "fields": { "<col>": {type, ...} } } },
     * keyed by the datatype the per-row snapshots carry as `entity`. Not paged:
     * downstream keeps only the latest `snapshot_batch` and drops unchanged records,
     * so a partly changed paged catalog would be truncated.
     *
     * @param string     $batch    snapshot_batch UUID shared by this run.
     * @param array|null $registry Pre-resolved effective registry (column
     *        overrides applied); resolved on demand when null.
     * @return int Number of tables catalogued; 0 when none or too large to emit.
     */
    public static function export_inform_dyn_schema(string $batch, ?array $registry = null): int {
        global $DB, $CFG;

        $registry = $registry ?? self::registry_with_overrides();
        $catalog = [];
        foreach ($registry as $datatype => $def) {
            buffer::keepalive(); // See export_entity().
            // Derived entities are computed in PHP, not snapshotted from a table:
            // `userlogins` ships {id, logins} aggregated out of
            // logstore_standard_log, so introspecting its backing table would
            // describe columns the entity never carries — a candidate view with
            // columns the data can never fill.
            //
            // Note the catalog key is the datatype, not the table name, and the
            // two legitimately differ for an admin-added custom entity
            // (config_service rule 3: datatype `inform_demo`, custom_table
            // `local_inform_demo`). Rows are captured under the datatype, so
            // keying on it keeps the catalog and the rows aligned.
            if (!empty($def['derived'])) {
                continue;
            }
            $table = $def['table'] ?? '';
            if ($table === '') {
                continue;
            }
            // Budget note: the reporting warehouse names each candidate table
            // with a 14-character prefix plus the datatype key, inside Postgres'
            // 63-byte identifier limit, so keys must stay within 49 characters.
            // A longer key would be silently truncated and could collide.
            $allow = self::dyn_schema_column_allow_set($def['columns'] ?? '*');
            try {
                if (!\local_intellistream\services\config_service::table_is_real($table)) {
                    mtrace("local_intellistream: inform_dyn_schema — table '{$table}' absent; skipped.");
                    continue;
                }
                $cols = $DB->get_columns($table);
            } catch (\Throwable $e) {
                mtrace("local_intellistream: inform_dyn_schema introspect '{$table}' failed: " . $e->getMessage());
                continue;
            }

            $fields = [];
            foreach ($cols as $name => $col) {
                if ($allow !== null && !isset($allow[$name])) {
                    continue;
                }
                $fields[$name] = [
                    'type'          => $col->type,
                    'name'          => $name,
                    'original_name' => $name,
                    'max_length'    => $col->max_length,
                    'null'          => empty($col->not_null),
                    'has_default'   => !empty($col->has_default),
                    'default_value' => $col->default_value ?? null,
                    'primary_key'   => !empty($col->primary_key),
                    'unique'        => !empty($col->unique),
                    'keys'          => [],
                ];
            }
            if (!$fields) {
                continue;
            }
            $catalog[$datatype] = ['name' => $datatype, 'fields' => $fields];
        }

        if (!$catalog) {
            return 0;
        }

        $bytes = strlen((string)json_encode($catalog));
        $emitted = buffer::append_record([
            'id'             => self::entity_uuid(config::site_id(), 'inform_dyn_schema'),
            'site_id'        => config::site_id(),
            'captured_at'    => clock::now(),
            'plugin_version' => (int)config::plugin_version(),
            'moodle_version' => isset($CFG->version) ? (int)$CFG->version : null,
            'record_type'    => 'entity_snapshot',
            'entity'         => 'inform_dyn_schema',
            'snapshot_batch' => $batch,
            'entity_data'    => $catalog,
        ]);
        if (!$emitted) {
            // A false return means the record exceeded MAX_EVENT_BYTES (or would
            // not serialise) and nothing was appended. Reported, never swallowed:
            // without a catalog the reporting service offers no candidate tables
            // for this site and reports no error. If this fires, the catalog needs
            // a paged wrapper envelope; see the docblock.
            mtrace('local_intellistream: ALERT inform_dyn_schema NOT emitted — '
                . count($catalog) . " table(s), {$bytes} bytes exceeds the "
                . buffer::MAX_EVENT_BYTES . '-byte per-record cap (or failed to '
                . 'serialise). InForm dynamic tables will NOT be discovered.');
            return 0;
        }
        // Byte count is logged on the success path too, so headroom against the
        // cap is observable in every site's cron log before it becomes a problem.
        mtrace('local_intellistream: inform_dyn_schema — ' . count($catalog)
            . " table(s) catalogued, {$bytes} bytes (batch {$batch}).");
        return count($catalog);
    }

    /**
     * Max primary keys carried in one census record. A table with more than this
     * is paged: N page records + a manifest, unioned by the reconciler. Sized so
     * one page's JSON stays well under buffer::MAX_EVENT_BYTES (1 MiB) and the
     * ingest service's 2 MiB per-message limit.
     */
    const CENSUS_PAGE_SIZE = 50000;

    /**
     * Ids that the census reads per query (CLI setting `censusreadrows` overrides it).
     *
     * On MySQL Moodle copies a query's whole result into PHP memory (see
     * PAGE_ROWS_START), so one id query over a table of tens of millions of rows
     * would hold every id at once. The census reads `id > :lastid ORDER BY id` this
     * many at a time instead.
     */
    public const CENSUS_READ_ROWS = 50000;

    /**
     * Largest census read the setting `censusreadrows` can ask for, so one read
     * never holds more than this many ids in memory.
     */
    public const CENSUS_READ_ROWS_MAX = 1000000;

    /**
     * Emit a delete-reconciliation census: one record per entity listing the
     * complete set of current primary keys, taken from a full table scan.
     *
     * Downstream a deleted row and an unchanged row look the same, so the census
     * tells the reconciler what currently exists. Small tables ship one record
     * (payload {census_entity,count,pks}); tables over CENSUS_PAGE_SIZE ship page
     * records and then a manifest ({census_entity,is_census_manifest,page_count,
     * count}). Every record carries the run batch, so an unchanged pk-set is still
     * sent. Written only after the entity's id scan completed.
     *
     * @param string     $batch    snapshot_batch UUID shared by this run.
     * @param array|null $registry Pre-resolved effective registry.
     * @return int Number of entity census records emitted.
     */
    public static function export_census(string $batch, ?array $registry = null): int {
        global $DB, $CFG;

        $registry = $registry ?? self::registry_with_overrides();
        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $emitted = 0;
        // Page size is the CENSUS_PAGE_SIZE default, overridable via hidden config
        // (operational tuning + lets smoke tests exercise paging on a tiny table).
        $pagesize = (int)get_config('local_intellistream', 'census_page_size');
        if ($pagesize <= 0) {
            $pagesize = self::CENSUS_PAGE_SIZE;
        }
        $readrows = self::tuning_int('censusreadrows', self::CENSUS_READ_ROWS);
        $readrows = $readrows <= 0 ? self::CENSUS_READ_ROWS : min($readrows, self::CENSUS_READ_ROWS_MAX);

        foreach ($registry as $entity => $def) {
            buffer::keepalive(); // See export_entity(); a census is a full table scan per entity.
            // Derived/computed entities are not straight table snapshots keyed
            // on a stable Moodle id — they cannot be delete-reconciled this way.
            if (!empty($def['derived'])) {
                continue;
            }
            $table = $def['table'] ?? null;
            if (!$table) {
                continue;
            }
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                continue;
            }
            $rs = null;
            try {
                // Stream ids in pages so an arbitrarily large table is never
                // materialised whole and each census record stays well under the
                // 1 MiB per-record cap. A table that fits one page ships a single
                // record; a larger one ships N page records + a manifest, which the
                // reconciler unions.
                //
                // Stamped before the scan is issued: a row created after this
                // instant may be missing from the id list, so the reconciler must
                // not read its absence as a delete. Same plugin clock
                // as every row's captured_at, so the two compare without skew.
                $scanstarted = clock::now();
                $page = [];
                $pageno = 0;
                $total = 0;
                $pagesok = true;
                // The ids are read $readrows at a time, each read after the last id
                // of the one before: the same ids, in the same pages, as one query.
                // The reads are separate statements. A row deleted before its read is
                // not listed (it is gone). A row deleted after it is listed, and the
                // next census drops it. A row created after $scanstarted is covered above.
                $lastid = null; // No lower bound on the first read: an id of 0 or below is listed too.
                do {
                    $rs = $lastid === null
                        ? $DB->get_recordset_select($table, '', [], 'id ASC', 'id', 0, $readrows)
                        : $DB->get_recordset_select($table, 'id > :lastid', ['lastid' => $lastid], 'id ASC', 'id', 0, $readrows);
                    $fetched = 0;
                    foreach ($rs as $row) {
                        $fetched++;
                        $lastid = (int)$row->id;
                        $page[] = (string)$row->id;
                        if (count($page) >= $pagesize) {
                            $pageno++;
                            $total += count($page);
                            // A page that did not get written must be remembered.
                            // The manifest below names a page count, so a missing page
                            // makes it describe a set the buffer does not contain.
                            $pagesok = self::emit_census_page(
                                $siteid,
                                $entity,
                                $batch,
                                $pageno,
                                $page,
                                $pluginversion,
                                $moodleversion
                            ) && $pagesok;
                            $page = [];
                        }
                    }
                    $rs->close();
                    $rs = null;
                } while ($fetched >= $readrows);
            } catch (\Throwable $e) {
                // A failed scan emits no manifest, so the reconciler (which only
                // acts on a manifest's named page set) never acts on a partial set.
                mtrace("local_intellistream: census — '{$entity}' scan failed: "
                    . $e->getMessage() . "; census skipped.");
                continue;
            } finally {
                // Close on every exit, not just the happy path: several of these
                // loops throw by design, so a close() after the foreach alone would
                // leak the cursor.
                if ($rs instanceof \moodle_recordset) {
                    $rs->close();
                }
            }

            $refusal = null;
            if ($pageno === 0) {
                // Single-record census (fits one page).
                $written = buffer::append_record([
                    'id'             => self::entity_uuid($siteid, 'entity_census', $entity),
                    'site_id'        => $siteid,
                    'captured_at'    => clock::now(),
                    'plugin_version' => $pluginversion,
                    'moodle_version' => $moodleversion,
                    'record_type'    => 'entity_snapshot',
                    'entity'         => 'entity_census',
                    'snapshot_batch' => $batch,
                    'entity_data'    => [
                        'census_entity'   => $entity,
                        'count'           => count($page),
                        'pks'             => $page,
                        'snapshot_batch'  => $batch,
                        'scan_started_at' => $scanstarted,
                    ],
                ], $refusal);
            } else {
                // Flush the final partial page, then a manifest naming how many
                // pages complete this census for this batch. Manifest is written
                // last and only after a clean scan, so its presence => all pages
                // were emitted this run.
                $pagewritten = true;
                if (!empty($page)) {
                    $pageno++;
                    $total += count($page);
                    $pagewritten = self::emit_census_page(
                        $siteid,
                        $entity,
                        $batch,
                        $pageno,
                        $page,
                        $pluginversion,
                        $moodleversion
                    );
                }
                if (!$pagesok || !$pagewritten) {
                    // Same invariant the catch block above relies on.
                    // The reconciler acts only on a manifest's named page set, so a
                    // manifest missing pages is worse than no manifest at all.
                    // Withhold it; the next census re-emits the entity cleanly.
                    mtrace("local_intellistream: census — '{$entity}' page write failed; "
                        . 'manifest withheld so the reconciler never sees a partial page set.');
                    continue;
                }
                $written = buffer::append_record([
                    'id'             => self::entity_uuid($siteid, 'entity_census', "{$entity}|manifest"),
                    'site_id'        => $siteid,
                    'captured_at'    => clock::now(),
                    'plugin_version' => $pluginversion,
                    'moodle_version' => $moodleversion,
                    'record_type'    => 'entity_snapshot',
                    'entity'         => 'entity_census',
                    'snapshot_batch' => $batch,
                    'entity_data'    => [
                        'census_entity'      => $entity,
                        'is_census_manifest' => true,
                        'page_count'         => $pageno,
                        'count'              => $total,
                        'snapshot_batch'     => $batch,
                        'scan_started_at'    => $scanstarted,
                    ],
                ], $refusal);
            }

            // Only count what was actually written. append_record() returns false
            // without writing when the serialised record exceeds MAX_EVENT_BYTES, or
            // when the buffer cannot take anything right now (the disk cap, a write
            // failure). A census that does not arrive makes the downstream
            // reconciler under-clean, which is safe; reporting it as sent is not.
            if (!$written) {
                mtrace("local_intellistream: census — '{$entity}' record was NOT written ("
                    . ($refusal === buffer::REFUSED_PERMANENT
                        ? 'it exceeded the per-record size cap'
                        : 'the buffer could not take it now')
                    . '); census skipped for this entity (delete reconciliation will '
                    . 'under-clean until a later census arrives).');
                continue;
            }
            $emitted++;
        }

        mtrace("local_intellistream: census — {$emitted} entit(y/ies) censused "
            . "(batch {$batch}).");
        return $emitted;
    }

    /**
     * Emit one census page record for a large, paged entity. A distinct
     * deterministic id per (entity, page) so pages UPSERT independently; carries
     * the run batch so a page whose pk-set returns to a prior value still re-ships
     * and the reconciler can match a page to its manifest's batch.
     *
     * @param string   $siteid
     * @param string   $entity
     * @param string   $batch
     * @param int      $pageno         1-based page index
     * @param string[] $pks            this page's primary keys
     * @param int      $pluginversion
     * @param int|null $moodleversion
     * @return bool false when the record exceeded the per-record size cap and was
     *         not written, so the caller can report the census as skipped rather
     *         than counting a discarded page as emitted
     */
    private static function emit_census_page(
        string $siteid,
        string $entity,
        string $batch,
        int $pageno,
        array $pks,
        int $pluginversion,
        ?int $moodleversion
    ): bool {
        return buffer::append_record([
            'id'             => self::entity_uuid($siteid, 'entity_census', "{$entity}|page_{$pageno}"),
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => $pluginversion,
            'moodle_version' => $moodleversion,
            'record_type'    => 'entity_snapshot',
            'entity'         => 'entity_census',
            'snapshot_batch' => $batch,
            'entity_data'    => [
                'census_entity'  => $entity,
                'page'           => $pageno,
                'pks'            => $pks,
                'snapshot_batch' => $batch,
            ],
        ]);
    }

    /**
     * Parse an effective-registry `columns` value into an allow-set of column
     * names, or null when all columns are exported ('*').
     *
     * @param string $columns Comma/space-separated list, or '*'.
     * @return array<string,true>|null
     */
    protected static function dyn_schema_column_allow_set(string $columns): ?array {
        $columns = trim($columns);
        if ($columns === '' || $columns === '*') {
            return null;
        }
        $allow = [];
        foreach (preg_split('/[\s,]+/', $columns) as $name) {
            $name = trim($name);
            if ($name !== '') {
                $allow[$name] = true;
            }
        }
        return $allow ?: null;
    }

    /**
     * Export one entity, streaming rows in batches.
     *
     * Uses $DB->get_recordset. Without $limit, on PostgreSQL that is a cursor and
     * memory stays flat; on MySQL Moodle buffers the whole result (MYSQLI_STORE_RESULT)
     * either way, so a caller reading a large window passes $limit and pages
     * (export_incremental()).
     * Each row becomes one `entity_snapshot` buffer record. mtrace() reports
     * progress per chunk.
     *
     * @param string $entity Registry key.
     * @param string $batch snapshot_batch UUID shared by this export run.
     * @param array|null $registry Pre-resolved effective registry; when null
     *        it is resolved via registry_with_overrides() so a standalone call
     *        still honours admin overrides / custom datatypes.
     * @param string $select Optional WHERE clause (no "WHERE" keyword) for an
     *        incremental export, e.g. "timemodified > :cdcwm". Empty = full table.
     * @param array|null $params Bound params for $select.
     * @param array|null $outcome Out-param. Receives
     *        ['complete' => bool, 'rows' => int, 'dropped' => int, 'error' => ?string,
     *        'stopped' => bool, 'lastid' => ?int, 'maxwm' => string|int|float|null,
     *        'fetched' => int, 'resultbytes' => int, 'maxwmcap' => string|int|float|null,
     *        'lastfetched' => ?int]
     *        (`fetched`, `resultbytes`: see $limit; `maxwmcap`: see $wmcap; `lastfetched`:
     *        the id of the last row the query returned, settled or not).
     *        `complete` is true only when the scan ran to the end, which is what
     *        export_incremental() requires before it may advance a watermark past
     *        this window. `stopped` is true when $stoponrefusal ended the scan
     *        early; `lastid` is the highest id the scan settled (accepted, or
     *        refused for good) before that point. `maxwm` is the highest value of
     *        the entity's watermark column over those same settled rows, raw and
     *        uncast, ignoring NULL/0 (clockless) values; null when the entity has
     *        no watermark column or no settled row carried one. It lets a
     *        watermarked caller take its next mark from the rows this scan read
     *        instead of a separate SELECT MAX(). Optional.
     * @param bool $stoponrefusal End the scan at the first record the buffer
     *        refuses for a transient reason (full, unpaired, write failure)
     *        instead of skipping past it. Only for callers that resume from
     *        `lastid` (export_incremental) or re-run the whole scan
     *        (targeted_refetch, via export_entity_range()); every other caller
     *        keeps the skip-and-continue behaviour, which ships the rows accepted
     *        after the buffer drains. Permanent refusals are always skipped.
     * @param int $limit At most this many rows, in id order; 0 for no limit (every
     *        caller but a paged one). With a limit, $outcome also reports `fetched`
     *        (rows the query returned, settled or not) and `resultbytes` (memory the
     *        query's result took, 0 when not measurable), and `complete` means this
     *        page was read to its end: a caller pages on while `fetched` equals
     *        $limit.
     * @param int|string|null $wmcap When set, $outcome also reports `maxwmcap`: the
     *        highest watermark value at or below $wmcap over the settled rows (the
     *        incremental lane passes the bound a full first page would freeze at).
     * @param bool|null $bulk Whether this read is part of a whole-table scan (derived
     *        values are then map-cached, see buffer_entity_row()). Null: only when
     *        $select is empty. A caller that pages a whole table passes true.
     * @return int Rows exported for this entity.
     */
    public static function export_entity(
        string $entity,
        string $batch,
        ?array $registry = null,
        string $select = '',
        ?array $params = null,
        ?array &$outcome = null,
        bool $stoponrefusal = false,
        int $limit = 0,
        $wmcap = null,
        ?bool $bulk = null
    ): int {
        global $DB, $CFG;

        $outcome = ['complete' => false, 'rows' => 0, 'dropped' => 0, 'error' => null,
            'stopped' => false, 'lastid' => null, 'maxwm' => null, 'fetched' => 0, 'resultbytes' => 0,
            'maxwmcap' => null, 'lastfetched' => null];

        // Every bulk export path funnels through here, so this is the one place
        // that keeps a long run's buffer file from looking abandoned. See
        // buffer::keepalive(): an entity that yields no rows appends nothing, and
        // a stretch of those is enough for the shipper's sweeper to take the file
        // out from under a writer that is still holding it open.
        buffer::keepalive();

        $registry = $registry ?? self::registry_with_overrides();
        if (!isset($registry[$entity])) {
            mtrace("local_intellistream: unknown entity '{$entity}' — skipped.");
            return 0;
        }

        $table = $registry[$entity]['table'];
        // Derived entities (see registry()) carry no 'columns' key — their own
        // task computes and sends them; they are not streamed as a table snapshot.
        $columns = $registry[$entity]['columns'] ?? null;

        // Tolerate a table missing on a given site (optional sub-plugins,
        // older Moodle) rather than aborting the whole run.
        if (!\local_intellistream\services\config_service::table_is_real($table)) {
            mtrace("local_intellistream: table '{$table}' absent — entity '{$entity}' skipped.");
            return 0;
        }

        // Derived entities are not table snapshots: each is kept and sent by its own
        // task, so here they are only asked to send everything again. Resolved after
        // this branch so a derived entity's backing table is never introspected.
        if (!empty($registry[$entity]['derived'])) {
            $outcome['complete'] = self::request_derived($entity);
            $outcome['error'] = $outcome['complete'] ? null : 'no task keeps this derived entity';
            return 0;
        }

        // Narrow the declared list to what this Moodle actually has, so one
        // absent column costs that column instead of the whole entity.
        $resolved = self::resolve_entity_columns(
            $entity,
            $table,
            $columns,
            $registry[$entity]['wmcol'] ?? null
        );
        $columns = $resolved['columns'];
        // Reported back through $outcome as `maxwm`. watermark_column() derives it
        // from the surviving list, so it is a column this SELECT returns.
        $wmcol = $resolved['wmcol'];
        if ($columns === null) {
            mtrace("local_intellistream: entity '{$entity}' — no declared column of "
                . "'{$table}' exists on this site (table-name collision?) — skipped.");
            return 0;
        }

        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $chunk = config::export_batch_size();

        // An empty $select is the only case that streams the whole table (a whole-table
        // entity of the verification sweep, and the first page of export_entity_range()'s
        // whole-table read). Everything else is a bounded window (the incremental lane,
        // or a targeted re-fetch) where a derived value is point-read: building a
        // whole-table lookup map to name a few changed rows on every run would cost far
        // more than it saves. A caller that pages a whole table says so through $bulk.
        $bulk = $bulk ?? ($select === '');

        $rows = 0;
        $sincechunk = 0;

        // The role entity needs Moodle's display-name resolution (see below);
        // load accesslib once up front rather than per row.
        if ($entity === 'role') {
            require_once($CFG->libdir . '/accesslib.php');
        }

        $dropped = 0;
        $rs = null;
        try {
            $before = memory_get_usage();
            $rs = $DB->get_recordset_select($table, $select, $params, 'id ASC', $columns, 0, max(0, $limit));
            $outcome['resultbytes'] = max(0, memory_get_usage() - $before);
            foreach ($rs as $row) {
                $outcome['fetched']++;
                $outcome['lastfetched'] = (int)$row->id;
                if (
                    !self::buffer_entity_row(
                        $siteid,
                        $entity,
                        $row,
                        $pluginversion,
                        $moodleversion,
                        $batch,
                        $bulk,
                        $reason
                    )
                ) {
                    // Refused by the buffer — over-size record, disk cap, or an
                    // unpaired site. buffer records its own durable marker; what
                    // matters here is not counting it as shipped.
                    if ($stoponrefusal && $reason !== buffer::REFUSED_PERMANENT) {
                        // The buffer cannot take rows right now. Stop here rather
                        // than read on past a row nobody will retry: the caller
                        // resumes from `lastid`, which is below this row.
                        $outcome['stopped'] = true;
                        break;
                    }
                    $dropped++;
                    if ($reason === buffer::REFUSED_PERMANENT) {
                        $outcome['lastid'] = (int)$row->id;   // Settled: can never be written.
                        self::note_maxwm($outcome, $row, $wmcol, $wmcap);
                    }
                    continue;
                }
                $outcome['lastid'] = (int)$row->id;
                self::note_maxwm($outcome, $row, $wmcol, $wmcap);
                $rows++;
                $sincechunk++;

                // Yield between chunks to give the host a breather; this is a
                // background job, not the hot path. It does not bound memory: on
                // MySQL the result is already in memory (see $limit).
                if ($sincechunk >= $chunk) {
                    $sincechunk = 0;
                    mtrace("local_intellistream: {$entity} — {$rows} rows buffered...");
                    usleep(1000);
                }
            }
            // Reached only when the scan ran to the end, or was stopped above.
            $outcome['complete'] = !$outcome['stopped'];
        } catch (\Throwable $e) {
            $outcome['error'] = $e->getMessage();
            mtrace("local_intellistream: entity '{$entity}' export error: " . $e->getMessage());
        } finally {
            // Close on every exit, not just the happy path: several of these
            // loops throw by design, so a close() after the foreach alone would
            // leak the cursor.
            if ($rs instanceof \moodle_recordset) {
                $rs->close();
            }
        }

        $outcome['rows'] = $rows;
        $outcome['dropped'] = $dropped;
        if ($dropped > 0) {
            // Reported, but deliberately not folded into `complete`. A permanent
            // refusal (unserialisable, or over MAX_EVENT_BYTES) would fail on
            // every retry, so a caller that refused to advance past it would wedge
            // the lane; losing a named, counted record is bounded. A transient
            // refusal is counted here only for callers that did not ask to stop
            // on one (see $stoponrefusal) — the verification sweep, the historical
            // backfill — and each of those, like a targeted re-fetch, reads
            // buffer::transient_refusals() so it does not report itself complete.
            // buffer sets its own durable capacity marker, which is what surfaces
            // a full buffer on the status page.
            mtrace("local_intellistream: {$entity} — {$dropped} row(s) REFUSED by the buffer "
                . 'and not retried by this pass.');
        }

        // A bounded read that found nothing (the incremental lane's window on an idle
        // entity, most of every incremental run) says nothing: that run prints one
        // summary line instead, and these lines land in task_log.output. A whole-table
        // read, anything refused, a stop or an error is always reported.
        if (!($rows === 0 && $dropped === 0 && $select !== '' && empty($outcome['stopped']) && $outcome['error'] === null)) {
            mtrace("local_intellistream: {$entity} — {$rows} rows exported (batch {$batch}).");
        }
        return $rows;
    }

    /**
     * Raise $outcome['maxwm'] to this settled row's watermark value.
     *
     * Called exactly where `lastid` is set, so the mark covers the same rows a
     * resume point does: accepted, or refused for good. A row refused for now is
     * not settled and must not raise it. NULL and 0 are skipped (clockless rows
     * move the id lane, never the timestamp one). Compared as a float, never as
     * a string, and stored raw: task_log.timeend is fractional and
     * export_task_state() keeps its full precision.
     *
     * @param array $outcome export_entity()'s out-param, updated in place.
     * @param \stdClass $row Row as fetched.
     * @param string|null $wmcol Resolved watermark column, or null for none.
     * @param int|string|null $cap When set, also raise `maxwmcap` to this row's mark
     *        if it is at or below $cap (see export_entity()'s $wmcap).
     * @return void
     */
    private static function note_maxwm(array &$outcome, \stdClass $row, ?string $wmcol, $cap = null): void {
        if ($wmcol === null || !isset($row->{$wmcol}) || !is_numeric($row->{$wmcol})) {
            return;
        }
        $value = $row->{$wmcol};
        if ((float)$value > 0 && ($outcome['maxwm'] === null || (float)$value > (float)$outcome['maxwm'])) {
            $outcome['maxwm'] = $value;
        }
        if (
            $cap !== null && (float)$value > 0 && (float)$value <= (float)$cap
            && (($outcome['maxwmcap'] ?? null) === null || (float)$value > (float)$outcome['maxwmcap'])
        ) {
            $outcome['maxwmcap'] = $value;
        }
    }

    /**
     * Whether this row is clockless — carrying a watermark the incremental
     * predicate can never satisfy.
     *
     * Reads a property off a row the caller already fetched (no query). NULL and 0
     * make `wmcol > :since` unsatisfiable for ever, so the incremental lane never
     * sees such a row. The verification sweep tallies the result (sweep_window()).
     *
     * @param \stdClass $row Row as fetched.
     * @param string $wmcol Resolved watermark column.
     * @return bool True when the watermark is NULL or 0.
     */
    private static function is_clockless_row(\stdClass $row, string $wmcol): bool {
        $wmvalue = isset($row->{$wmcol}) ? $row->{$wmcol} : null;
        return $wmvalue === null || (int)$wmvalue === 0;
    }

    /**
     * Export one entity restricted to a [from, to] time window (targeted
     * re-fetch), read in pages by id (export_range_pages()).
     *
     * The window is applied on the entity's detected change-timestamp column —
     * the same column watermark_column() drives incremental export on
     * (timemodified/timecreated/… or a per-entity `wmcol` override). Entities
     * with no such column cannot be windowed, so the whole table is re-emitted (a
     * full re-fetch); deterministic uuid5 ids and downstream de-duplication make
     * the overlap harmless. A derived entity is not read here: its own task sends
     * every total again (see request_derived()).
     *
     * Reads/writes no watermark config: this is a repair emit, independent of
     * the incremental (`cdc_state`) and historical-backfill (`backfill_*`) state.
     *
     * @param string $entity Registry key.
     * @param string $batch snapshot_batch UUID shared by this run.
     * @param array|null $registry Pre-resolved effective registry (or null).
     * @param int|null $from Window start (epoch seconds), inclusive.
     * @param int|null $to Window end (epoch seconds), inclusive.
     * @param bool $stoponrefusal Passed to export_entity(): end the scan at the
     *        first record the buffer refuses for now, rather than reading the rest
     *        of the table to have every row refused too. For targeted_refetch,
     *        which reports the run incomplete and is run again whole.
     * @return int Rows exported for this entity.
     */
    public static function export_entity_range(
        string $entity,
        string $batch,
        ?array $registry = null,
        ?int $from = null,
        ?int $to = null,
        bool $stoponrefusal = false
    ): int {
        buffer::keepalive(); // See export_entity().
        $registry = $registry ?? self::registry_with_overrides();
        if (!isset($registry[$entity])) {
            mtrace("local_intellistream: unknown entity '{$entity}' — skipped.");
            return 0;
        }
        $def = $registry[$entity];

        // A derived aggregate has no window. Its task sends every total again. A re-fetch
        // never counts it again: that would replace the totals with what the log holds now,
        // which is lower after a log purge, and a retried re-fetch would repeat it.
        if (!empty($def['derived'])) {
            if (self::request_derived($entity)) {
                mtrace("local_intellistream: '{$entity}' totals are sent again by its own task.");
            }
            return 0;
        }
        // An entity with no recognised change-timestamp column cannot be time-windowed:
        // re-emit the whole table. Resolve against the live schema first: a drifted
        // entity whose declared timestamp column is absent would otherwise build a WHERE
        // on a column that is not there and return 0 rows, so a requested re-fetch
        // would do nothing. Falling through to the whole table both re-fetches
        // everything and reports the drift once.
        $select = '';
        $params = [];
        $after = null;
        if ($from !== null && $to !== null) {
            $wmcol = self::resolve_entity_columns(
                $entity,
                $def['table'],
                $def['columns'] ?? null,
                $def['wmcol'] ?? null
            )['wmcol'];
            if ($wmcol !== null) {
                $select = "{$wmcol} >= :ib_from AND {$wmcol} <= :ib_to";
                $params = ['ib_from' => $from, 'ib_to' => $to];
                // Start the first page at the window's first id, as the incremental lane
                // does: without a lower id bound a planner can walk the primary key from
                // the table's first row to reach a recent window (seconds on a large
                // table, instead of milliseconds with the bound).
                $first = self::window_first_id($def['table'], $wmcol, $select, $params);
                if ($first === false) {
                    return 0; // Nothing in the window.
                }
                $after = $first === null ? null : $first - 1;
            }
        }
        return self::export_range_pages($entity, $batch, $registry, $select, $params, $stoponrefusal, $after);
    }

    /**
     * Read one entity's rows that match $select in pages by id, for export_entity_range().
     *
     * On MySQL, Moodle holds a whole query result in memory, so one read of a large
     * table can pass the memory limit. Each page is limited in rows and continues after
     * the last id the previous page returned; its size follows the memory the last page
     * took (next_page_rows()). A whole-table read keeps its map-cached derived values.
     * The read ends at the last page, on an error, or at a refusal that $stoponrefusal
     * stops on.
     *
     * @param string $entity Registry key.
     * @param string $batch snapshot_batch UUID shared by this run.
     * @param array $registry Effective registry.
     * @param string $select WHERE clause without the page bound ('' for the whole table).
     * @param array $params Bound params for $select.
     * @param bool $stoponrefusal See export_entity().
     * @param int|null $after Read only ids above this one (null: from the first row).
     * @return int Rows exported.
     */
    private static function export_range_pages(
        string $entity,
        string $batch,
        array $registry,
        string $select,
        array $params,
        bool $stoponrefusal,
        ?int $after = null
    ): int {
        $rows = 0;
        $lastid = $after;
        $pagerows = self::PAGE_ROWS_START;
        while (true) {
            $where = $select;
            $bound = $params;
            if ($lastid !== null) {
                $where = ($select === '' ? '' : "({$select}) AND ") . 'id > :ib_lastid';
                $bound['ib_lastid'] = $lastid;
            }
            $outcome = null;
            $rows += self::export_entity(
                $entity,
                $batch,
                $registry,
                $where,
                $bound,
                $outcome,
                $stoponrefusal,
                $pagerows,
                null,
                $select === ''
            );
            if (
                $outcome['error'] !== null || $outcome['stopped']
                    || $outcome['fetched'] < $pagerows || $outcome['lastfetched'] === null
            ) {
                return $rows;
            }
            if ($lastid !== null && $outcome['lastfetched'] <= $lastid) {
                return $rows; // The id did not move: reading on would read the same rows again.
            }
            $lastid = $outcome['lastfetched'];
            $pagerows = self::next_page_rows($outcome['fetched'], $outcome['resultbytes'], $pagerows);
        }
    }

    /**
     * Build and buffer one entity_snapshot record for a Moodle row.
     *
     * Shared so the whole-table export (export_entity), the verification sweep and
     * the resumable historical backfill (export_entity_window) emit byte-identical
     * payloads: same deterministic id, same envelope keys/order, same role
     * display-name resolution. A test checks this shape; keep the callers' payloads
     * identical.
     *
     * @param string $siteid the site's pairing id
     * @param string $entity registry key
     * @param \stdClass $row raw table row (must carry ->id)
     * @param int $pluginversion plugin version code
     * @param int|null $moodleversion Moodle $CFG->version, or null
     * @param string $batch shared snapshot_batch UUID
     * @param bool $bulk Whether the caller is streaming a whole table. Affects
     *        only how a derived value is fetched (map-cached vs point read),
     *        never what is emitted: the payload is identical either way, which
     *        is what keeps the three callers byte-identical.
     * @param string|null $reason Out-param: buffer::REFUSED_PERMANENT or
     *        buffer::REFUSED_TRANSIENT on refusal, null on acceptance.
     * @return bool Whether the buffer accepted the record. A refused record (over
     *         size, or a full buffer) returns false, so the caller never counts it
     *         as shipped or advances a watermark past it.
     */
    private static function buffer_entity_row(
        string $siteid,
        string $entity,
        \stdClass $row,
        int $pluginversion,
        ?int $moodleversion,
        string $batch,
        bool $bulk = true,
        ?string &$reason = null
    ): bool {
        return self::buffer_entity_data(
            $siteid,
            $entity,
            $row->id,
            self::entity_row_data($entity, $row, $bulk),
            $pluginversion,
            $moodleversion,
            $batch,
            $reason
        );
    }

    /**
     * The `entity_data` a row is shipped as: the row plus the derived display values
     * some entities carry. Split out of buffer_entity_row() so the verification sweep
     * fingerprints exactly what would be sent, without sending it.
     *
     * @param string $entity registry key
     * @param \stdClass $row raw table row
     * @param bool $bulk See buffer_entity_row().
     * @return array
     */
    private static function entity_row_data(string $entity, \stdClass $row, bool $bulk): array {
        $data = (array)$row;
        if ($entity === 'role') {
            $data['name'] = self::role_display_name($row);
        } else if ($entity === 'modules') {
            // The modules table has no display-name column; resolve it from the
            // module's own language pack, so a localised site ships the display name.
            [$data['title'], $data['titleplural']] =
                self::module_display_names((string)($row->name ?? ''));
        } else if ($entity === 'course_modules') {
            // The activity's own title, resolved against whatever module-instance
            // table this row points at — including module types the registry does
            // not export (core `subsection`/`qbank`, and every third-party module).
            $data['instancename'] = self::activity_instance_name($row, $bulk);
        }
        return $data;
    }

    /**
     * Buffer one entity_snapshot record from already-built `entity_data`.
     *
     * @param string $siteid the site's pairing id
     * @param string $entity registry key
     * @param mixed $rowid the row's id
     * @param array $data from entity_row_data()
     * @param int $pluginversion plugin version code
     * @param int|null $moodleversion Moodle $CFG->version, or null
     * @param string $batch shared snapshot_batch UUID
     * @param string|null $reason See buffer_entity_row().
     * @return bool Whether the buffer accepted the record.
     */
    private static function buffer_entity_data(
        string $siteid,
        string $entity,
        $rowid,
        array $data,
        int $pluginversion,
        ?int $moodleversion,
        string $batch,
        ?string &$reason = null
    ): bool {
        $payload = [
            'id'             => self::entity_uuid($siteid, $entity, $rowid),
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => $pluginversion,
            'moodle_version' => $moodleversion,
            'record_type'    => 'entity_snapshot',
            'entity'         => $entity,
            'snapshot_batch' => $batch,
            'entity_data'    => $data,
        ];
        if (buffer::append_record($payload, $reason)) {
            return true;
        }
        // Only a record refused for good can be made to fit; the normal path above
        // encodes each record once.
        if ($reason !== buffer::REFUSED_PERMANENT) {
            return false;
        }
        $changed = self::shrink_to_fit($payload, $oversize);
        if (!$oversize) {
            return false;   // Refused for another reason (it would not encode).
        }
        $written = $changed && buffer::append_record($payload, $reason);
        if ($written || $reason === buffer::REFUSED_PERMANENT) {
            // A transient refusal is retried by the caller; it is reported once it settles.
            self::report_shrunk($entity, $rowid, $written);
        }
        return $written;
    }

    /**
     * Record that an over-size row was shortened, and whether it was then exported.
     *
     * In the plugin log on every path (the observer path included, where nothing is
     * printed), and in the task output when run from cron.
     *
     * @param string $entity Registry key.
     * @param mixed $rowid The row's id.
     * @param bool $written Whether the shortened record was buffered.
     * @return void
     */
    private static function report_shrunk(string $entity, $rowid, bool $written): void {
        $id = is_scalar($rowid) ? (string)$rowid : '?';
        // The log row holds data only; the action names what happened.
        $data = ['rowid' => $id, 'capbytes' => buffer::MAX_EVENT_BYTES];
        if ($written) {
            $data['shortenedtochars'] = self::SHRINK_FIELD_CHARS;
        }
        \local_intellistream\services\log_service::record(
            \local_intellistream\services\log_service::TYPE_EXPORT,
            $entity,
            $written ? \local_intellistream\services\log_service::ACTION_SHORTENED
                : \local_intellistream\services\log_service::ACTION_OVERSIZE,
            json_encode($data, JSON_UNESCAPED_SLASHES)
        );
        if (defined('CLI_SCRIPT') && CLI_SCRIPT) {
            mtrace("local_intellistream: {$entity} — row id {$id} was over the " . buffer::MAX_EVENT_BYTES
                . '-byte record cap; ' . ($written
                    ? 'its largest text fields were shortened to ' . self::SHRINK_FIELD_CHARS
                        . ' characters or emptied, and the row was exported.'
                    : 'it could not be shortened to fit, and it was not exported.'));
        }
    }

    /**
     * Make a record that is over buffer::MAX_EVENT_BYTES fit, instead of losing the row.
     *
     * The buffer refuses an over-size record for good, so a row with one huge text
     * field (a page with embedded images, a course summary) would vanish from every
     * report. Only text fields over SHRINK_MIN_BYTES are touched (never `id` or a
     * numeric value), largest first: the first pass cuts plain text to
     * SHRINK_FIELD_CHARS and empties JSON values (a cut one is invalid JSON); the
     * second pass empties the fields. Stops as soon as the record fits.
     *
     * @param array $payload Record from buffer_entity_data(); `entity_data` is changed in place.
     * @param bool|null $oversize Set to whether the record was over the cap to begin with.
     * @return bool Whether any field was changed.
     */
    private static function shrink_to_fit(array &$payload, ?bool &$oversize = null): bool {
        $json = json_encode($payload, buffer::JSON_FLAGS);
        $oversize = $json !== false && strlen($json) > buffer::MAX_EVENT_BYTES;
        if (!$oversize) {
            return false;   // Not refused for its size: nothing to shorten.
        }
        $sizes = [];
        foreach ($payload['entity_data'] as $key => $value) {
            if ($key !== 'id' && is_string($value) && strlen($value) > self::SHRINK_MIN_BYTES && !is_numeric($value)) {
                $sizes[$key] = strlen($value);
            }
        }
        arsort($sizes);
        $changed = false;
        foreach ([true, false] as $firstpass) {
            foreach (array_keys($sizes) as $key) {
                $value = $payload['entity_data'][$key];
                if ($value === null) {
                    continue;
                }
                if ($firstpass && !self::looks_like_json($value)) {
                    if (\core_text::strlen($value) <= self::SHRINK_FIELD_CHARS) {
                        continue;
                    }
                    $payload['entity_data'][$key] = \core_text::substr($value, 0, self::SHRINK_FIELD_CHARS);
                } else {
                    $payload['entity_data'][$key] = null;
                }
                $changed = true;
                $json = json_encode($payload, buffer::JSON_FLAGS);
                if ($json !== false && strlen($json) <= buffer::MAX_EVENT_BYTES) {
                    return true;
                }
            }
        }
        return $changed;
    }

    /**
     * Whether a text value is shaped like a JSON object or array.
     *
     * By its outer characters only: decoding a value of up to a megabyte just to
     * classify it would cost several times its size in memory.
     *
     * @param string $value
     * @return bool
     */
    private static function looks_like_json(string $value): bool {
        $value = trim($value);
        if ($value === '') {
            return false;
        }
        $first = $value[0];
        $last = $value[strlen($value) - 1];
        if (!(($first === '{' && $last === '}') || ($first === '[' && $last === ']'))) {
            return false;
        }
        // JSON continues with a quote, a bracket, a number or a literal; BBCode such
        // as `[quote]`, `[table]` or `[font=...]` does not.
        return preg_match('/^[\[{]\s*(["{\[\]}\-\d]|true\b|false\b|null\b)/', $value) === 1;
    }

    /**
     * Hot-path-safe, mtrace-free capture of the entity rows matching $conditions.
     *
     * Used by the per-entity event observers (see \local_intellistream\observer)
     * to snapshot a changed definition row the instant Moodle fires its
     * create/update event, so timestamp-less tables (course_modules, etc.) are sent
     * by ship_events instead of waiting for the verification sweep. Same payload as
     * the sweep (buffer_entity_row()). Unlike export_entity() it prints nothing and
     * swallows all throwables, because it runs on the page render path. Deletes are
     * reconciled by the entity_census, not here.
     *
     * @param string $entity Registry key (non-derived table entity).
     * @param array  $conditions Equality conditions passed to get_records (e.g. ['id' => 5]).
     */
    public static function capture_entity_match(string $entity, array $conditions): void {
        global $DB, $CFG;
        try {
            if (!config::enabled()) {
                return;
            }
            $siteid = config::site_id();
            if ($siteid === '') {
                return; // Unpaired — buffer would no-op anyway.
            }
            $registry = self::registry_with_overrides();
            if (!isset($registry[$entity]) || !empty($registry[$entity]['derived'])) {
                return;
            }
            $table = $registry[$entity]['table'] ?? null;
            if ($table === null) {
                return;
            }
            $columns = $registry[$entity]['columns'] ?? '*';
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                return;
            }
            // Same narrowing as export_entity(), so an entity that is drifted on
            // this site still captures its surviving columns here instead of
            // throwing into the catch below and losing the row entirely. Costs one
            // get_columns() on one table — cached after the first observed event.
            $columns = self::resolve_entity_columns(
                $entity,
                $table,
                $columns,
                $registry[$entity]['wmcol'] ?? null
            )['columns'];
            if ($columns === null) {
                return;
            }
            $pluginversion = (int) config::plugin_version();
            $moodleversion = isset($CFG->version) ? (int) $CFG->version : null;
            $batch = \core\uuid::generate();
            // Recordset, not get_records(): the observers all match a small indexed
            // set, but this method is public and its $conditions are the caller's,
            // so a wide match must not hold every row in memory at once.
            // Streaming bounds the memory whatever is asked for.
            $rs = $DB->get_recordset($table, $conditions, '', $columns);
            try {
                foreach ($rs as $row) {
                    // Not bulk: this is the page-render observer path, matching a small
                    // indexed set. It must point-read a derived value, never build a
                    // whole-table map. Same payload as the bulk path either way.
                    self::buffer_entity_row(
                        $siteid,
                        $entity,
                        $row,
                        $pluginversion,
                        $moodleversion,
                        $batch,
                        false
                    );
                }
            } finally {
                $rs->close();
            }
        } catch (\Throwable $e) {
            // Observer hot path: capture must never break the host page render.
            return;
        }
    }

    /**
     * Convenience wrapper: capture a single entity row by primary key.
     *
     * @param string $entity Registry key.
     * @param int|string $pk Primary key of the row to capture.
     */
    public static function capture_entity_row(string $entity, $pk): void {
        self::capture_entity_match($entity, ['id' => $pk]);
    }

    /**
     * Resumable historical-backfill export of one keyset entity.
     *
     * Streams the whole table in `id ASC` order past a persisted per-entity
     * watermark (`id > :backfillwm`), in pages (see PAGE_ROWS_START). The watermark
     * is advanced per chunk only after the backlog has been checked against half
     * the disk cap, so an interrupted run resumes where it stopped and the backfill
     * cannot fill the buffer to the point where capture is refused.
     *
     * On a mid-stream error, or a refused append, the progress so far is persisted
     * and the exception is re-thrown, so the caller does not mark the entity
     * complete (unlike export_entity(), which swallows errors). With $deadline the
     * read also stops between pages once that time has passed, with RUN_LIMIT_CODE;
     * such a run never ships inline (see backfill_apply_backpressure()).
     *
     * @param string $entity Registry key (must be a non-derived, id-keyed table).
     * @param string $batch  snapshot_batch UUID shared by this backfill campaign.
     * @param array|null $registry Pre-resolved effective registry.
     * @param string $wmkey  Config key holding this entity's watermark.
     * @param bool $seed Whether to stage block fingerprints from what is sent (a
     *        new install, see change_ledger).
     * @param int|null $deadline Unix time after which no further page is read; null
     *        reads to the end of the table (the CLI backfill).
     * @param int|null $buffered Out: rows buffered by this call, set also when it throws.
     * @return int Rows buffered on this run.
     */
    public static function export_entity_window(
        string $entity,
        string $batch,
        ?array $registry,
        string $wmkey,
        bool $seed = false,
        ?int $deadline = null,
        ?int &$buffered = null
    ): int {
        global $DB, $CFG;
        $buffered = 0;

        buffer::keepalive(); // See export_entity().
        $registry = $registry ?? self::registry_with_overrides();
        if (!isset($registry[$entity])) {
            mtrace("local_intellistream: unknown entity '{$entity}' — skipped.");
            return 0;
        }

        $table = $registry[$entity]['table'];
        $columns = $registry[$entity]['columns'] ?? null;

        // Derived entities have no monotonic id — the caller must route them to
        // export_entity()/request_derived(). Guard defensively so a mis-route is
        // a no-op-with-warning, not a broken keyset query.
        if (!empty($registry[$entity]['derived'])) {
            mtrace("local_intellistream: derived entity '{$entity}' is not keyset-resumable — skipped.");
            return 0;
        }

        if (!\local_intellistream\services\config_service::table_is_real($table)) {
            mtrace("local_intellistream: table '{$table}' absent — entity '{$entity}' skipped.");
            return 0;
        }

        // As export_entity(). Returns 0 rather than throwing: $lastid never
        // advances, so the caller correctly leaves the entity not-done, and one
        // permanently unresolvable entity cannot abort the whole backfill campaign.
        $resolvedcols = self::resolve_entity_columns(
            $entity,
            $table,
            $columns,
            $registry[$entity]['wmcol'] ?? null
        );
        $columns = $resolvedcols['columns'];
        if ($columns === null) {
            mtrace("local_intellistream: entity '{$entity}' — no declared column of "
                . "'{$table}' exists on this site (table-name collision?) — skipped.");
            return 0;
        }
        $fpskip = self::fp_skip_column($registry[$entity], $resolvedcols['wmcol']);

        $siteid = config::site_id();
        // Defensive twin of the guard in backfill::run(). Every append is refused
        // while unpaired, so scanning here could only produce a run of transient
        // refusals, the first of which stops the scan below. Returning 0 before the
        // first read leaves the watermark exactly where it was without reading
        // anything, the same contract the two unresolvable-entity returns above use.
        if ($siteid === '') {
            mtrace("local_intellistream: site id not set (unpaired) — entity '{$entity}' "
                . 'not scanned and its watermark left untouched.');
            return 0;
        }
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $chunk = config::export_batch_size();

        // The role entity needs Moodle's display-name resolution; load once.
        if ($entity === 'role') {
            require_once($CFG->libdir . '/accesslib.php');
        }

        $startwm = (int)get_config(config::COMPONENT, $wmkey);
        $lastid = $startwm;
        $rows = 0;
        $refused = 0;
        $sincechunk = 0;

        // Fingerprint seeding (new installs): hash each block from the very data sent,
        // as the verification sweep would, so its first pass does not send the table
        // again. Only blocks this call saw from their first id to their last are kept;
        // the block a resume starts inside, and one a stop cuts, are left for the sweep.
        $n = change_ledger::FP_SIZE;
        $seedprefix = $seed ? change_ledger::fingerprint() . "\0" . $entity . "\0" : '';
        $seedentries = '';
        $seedctx = null;
        $seedblock = null;
        $seedskip = $startwm > 0 ? intdiv($startwm, $n) : null;   // Partly sent before.
        $seedfresh = $startwm === 0;   // The first call of a campaign starts the set afresh.

        $rs = null;
        try {
            // Keyset pages: `id > :backfillwm ORDER BY id LIMIT n`, each page starting
            // after the last id settled. On MySQL, Moodle copies a whole result into
            // memory before the first row (see PAGE_ROWS_START), so one unbounded read
            // of a large table would fail on memory_limit. The rows, their order, the
            // refusal handling and the seeding are exactly those of one pass: a page
            // only ends where the next begins.
            if ($deadline !== null) {
                // A time-limited run checks the backlog before it reads anything: the
                // check below comes only after a chunk, so with the destination down each
                // run would add one more chunk to a buffer already over half its cap.
                self::backfill_apply_backpressure(false);
            }
            $pagerows = min(self::PAGE_ROWS_START, self::page_rows_max());
            do {
                $pagefrom = $lastid;
                $before = memory_get_usage();
                $rs = $DB->get_recordset_select(
                    $table,
                    'id > :backfillwm',
                    ['backfillwm' => $lastid],
                    'id ASC',
                    $columns,
                    0,
                    $pagerows
                );
                $resultbytes = max(0, memory_get_usage() - $before);
                $fetched = 0;
                foreach ($rs as $row) {
                    $fetched++;
                    // Honour the refusal: a refused row must not move the resume point
                    // past it. The buffer says which refusal it was ($reason); re-measuring
                    // the buffer here could disagree with it.
                    //
                    // Transient (the buffer is full, the site is unpaired, a write failed):
                    // throw, which takes the mid-stream path below. Progress is persisted up
                    // to the last accepted id and the entity is left not done, so the next
                    // run resumes exactly here.
                    // Permanent (a single row over MAX_EVENT_BYTES, or one that will not
                    // JSON-encode): retrying can never succeed and would wedge the entity on
                    // one row, so count it, report it, and carry on past it, as
                    // export_entity() does.
                    $data = self::entity_row_data($entity, $row, true);
                    if ($seed) {
                        $b = intdiv((int)$row->id, $n);
                        if ($b !== $seedblock) {
                            if ($seedctx !== null) {
                                $seedentries .= change_ledger::entry($seedblock, hash_final($seedctx, true));
                                if (strlen($seedentries) >= change_ledger::SEED_ROW_BLOCKS * change_ledger::ENTRY_BYTES) {
                                    // Staged a row at a time, the size a sweep window reads, so a large
                                    // table never builds one huge string and a window never reads a big row.
                                    self::seed_stage($entity, $seedentries, $seedfresh);
                                    $seedfresh = false;
                                    $seedentries = '';
                                }
                            }
                            $seedblock = $b;
                            $seedctx = ($b === $seedskip) ? null : hash_init('sha256');
                            if ($seedctx !== null) {
                                hash_update($seedctx, $seedprefix);
                            }
                        }
                        if ($seedctx !== null) {
                            self::hash_row($seedctx, $data, $fpskip);
                        }
                    }
                    $reason = null;
                    if (
                        !self::buffer_entity_data(
                            $siteid,
                            $entity,
                            $row->id,
                            $data,
                            $pluginversion,
                            $moodleversion,
                            $batch,
                            $reason
                        )
                    ) {
                        // Anything but a permanent refusal stops here: it is a state of the
                        // site, not of the row, so it may not advance the watermark.
                        if ($reason !== buffer::REFUSED_PERMANENT) {
                            throw new exception\export_exception(
                                'buffer would not accept a row while backfilling \'' . $entity . '\' at id '
                                . (isset($row->id) ? (int)$row->id : '?')
                                . ' — the buffer is at its disk cap, the site is unpaired, or the write '
                                . 'failed. Stopped short of that row so the watermark cannot pass it. '
                                . 'Resumable: the next pass continues from the watermark.',
                                self::REFUSED_TRANSIENT_CODE
                            );
                        }
                        $refused++;
                        mtrace("local_intellistream: {$entity} — row id "
                            . (isset($row->id) ? (int)$row->id : '?')
                            . ' REFUSED by the buffer and NOT exported (over-size record, or it would '
                            . 'not encode). Permanent for this row, so the backfill continues past it '
                            . 'rather than stalling; the row is reported here because nothing else '
                            . 'will surface it.');
                        // The watermark must advance past a permanently-refused row, or
                        // the next pass re-reads it, refuses it again and never gets
                        // further — the same wedge, just quieter. This is the one case
                        // where moving the watermark past an unexported row is correct,
                        // and it is correct only because the line above makes it visible.
                        if (isset($row->id) && (int)$row->id > $lastid) {
                            $lastid = (int)$row->id;
                        }
                        continue;
                    }
                    $rows++;
                    $sincechunk++;
                    if (isset($row->id) && (int)$row->id > $lastid) {
                        $lastid = (int)$row->id;
                    }

                    if ($sincechunk >= $chunk) {
                        $sincechunk = 0;
                        // Keep the backlog well under the disk cap before advancing the
                        // watermark, so the backfill never fills the buffer to the point
                        // where live capture is refused. May pause (host load) or throw
                        // (cannot drain) — then the caller resumes later.
                        self::backfill_apply_backpressure($deadline === null);
                        if ($lastid > (int)get_config(config::COMPONENT, $wmkey)) {
                            set_config($wmkey, $lastid, config::COMPONENT);
                        }
                        mtrace("local_intellistream: {$entity} — {$rows} rows buffered (watermark id {$lastid})...");
                    }
                }
                $rs->close();
                $rs = null;
                $pagesize = $pagerows;
                $pagerows = self::next_page_rows($fetched, $resultbytes, $pagerows);
                if ($fetched >= $pagesize && $lastid <= $pagefrom) {
                    // A full page that settled nothing past its start cannot be paged
                    // on from: stop rather than read the same page for ever. Not done,
                    // so the caller retries the entity on a later run.
                    throw new exception\export_exception("backfill of '{$entity}' read a full page after id {$pagefrom} "
                        . 'without settling a row past it.');
                }
                if ($deadline !== null && $fetched >= $pagesize && time() >= $deadline) {
                    // More pages remain, and this run's time is up: stop here, between two
                    // pages, so the next run continues after the last row sent.
                    throw new exception\export_exception("backfill of '{$entity}' stopped at the run time limit after id "
                        . "{$lastid}; the next run continues from there.", self::RUN_LIMIT_CODE);
                }
            } while ($fetched >= $pagesize);
        } catch (\Throwable $e) {
            // Persist progress so the re-run resumes past it, then propagate so
            // the caller leaves the entity not done.
            if ($lastid > $startwm) {
                set_config($wmkey, $lastid, config::COMPONENT);
            }
            if ($seed) {
                // The block being read when it stopped is cut: not kept.
                self::seed_stage($entity, $seedentries, $seedfresh);
            }
            $buffered = $rows;
            if ($e->getCode() === self::RUN_LIMIT_CODE) {
                mtrace('local_intellistream: ' . $e->getMessage());
            } else {
                mtrace("local_intellistream: entity '{$entity}' backfill error: " . $e->getMessage());
            }
            throw $e;
        } finally {
            // Close on every exit, not just the happy path: several of these
            // loops throw by design, so a close() after the foreach alone would
            // leak the cursor.
            if ($rs instanceof \moodle_recordset) {
                $rs->close();
            }
        }

        // Tail page: advance the watermark to the final id scanned.
        if ($lastid > $startwm) {
            set_config($wmkey, $lastid, config::COMPONENT);
        }
        if ($seed) {
            if ($seedctx !== null) {
                $seedentries .= change_ledger::entry($seedblock, hash_final($seedctx, true));
            }
            self::seed_stage($entity, $seedentries, $seedfresh);
        }

        if ($refused > 0) {
            mtrace("local_intellistream: {$entity} — {$refused} row(s) PERMANENTLY REFUSED by the "
                . 'buffer and not exported. The watermark has moved past them because retrying '
                . 'cannot help; they will not appear downstream until the row itself is smaller.');
        }
        mtrace("local_intellistream: {$entity} — {$rows} rows backfilled (batch {$batch}).");
        $buffered = $rows;
        return $rows;
    }

    /**
     * Exception code for "the buffer refused a record it may take later", or "the
     * backlog could not be drained" (backfill_apply_backpressure()). A state of the
     * site (full, unpaired, a failed write, a loaded host), not of the entity, so the backfill
     * does not count it towards giving up on the entity (backfill::note_no_progress()).
     */
    const REFUSED_TRANSIENT_CODE = 1520;

    /**
     * Exception code for "this backfill run reached its time limit" (export_entity_window()
     * with a deadline). Progress, not a stall: the watermark has moved, and the next run
     * continues after it.
     */
    public const RUN_LIMIT_CODE = 1521;

    /**
     * Stage the fingerprints one backfill call collected, as pending for the entity.
     * The first call of a campaign (watermark 0) starts the entity's pending set afresh,
     * so parts always follow block order.
     *
     * @param string $entity
     * @param string $entries Packed entries in block order.
     * @param bool $fresh First call of the campaign for this entity.
     * @return void
     */
    private static function seed_stage(string $entity, string $entries, bool $fresh): void {
        try {
            if ($fresh) {
                change_ledger::clear_entity($entity);
            }
            change_ledger::stage($entity, $entries, (int)get_config(config::COMPONENT, 'backfill_t0_' . $entity));
        } catch (\Throwable $e) {
            // Seeding is an optimisation: without it the sweep's first pass sends the
            // table again. Never fail the backfill for it.
            change_ledger::clear_entity($entity);
            mtrace("local_intellistream: {$entity} — fingerprint seeding skipped: " . $e->getMessage());
        }
    }

    /**
     * Whether the verification sweep may keep writing: false when the buffer backlog is
     * over the gate (same test the historical backfill applies) and, with $ship, one
     * shipping pass could not drain it.
     *
     * @param bool $ship Whether to drive that shipping pass; false (the verification sweep
     *        and a time-limited backfill run) answers on the backlog alone.
     * @return bool
     */
    public static function sweep_backpressure_ok(bool $ship = true): bool {
        try {
            self::backfill_apply_backpressure($ship);
            return true;
        } catch (\RuntimeException $e) {
            return false;
        }
    }

    /**
     * Backfill flow-control: keep the backlog under half of config::max_buffer_bytes(),
     * so the backfill never fills the buffer to where live capture is refused.
     * Without $ship it does not ship: it throws when an exact measurement finds the
     * backlog over half the cap, and the ship_events task sends it (that task bypasses
     * its load gate only when closed files are over half the cap, so under sustained
     * load the backfill can wait while ship_events stays gated). With $ship it drives
     * one shipping pass (gated by host load) and throws if that did not clear the
     * backlog, rather than holding a cron slot; the backfill resumes from its watermark.
     *
     * @param bool $ship Whether to drive one shipping pass before giving up (false for
     *        the verification sweep and a time-limited backfill run).
     * @return void
     */
    private static function backfill_apply_backpressure(bool $ship = true): void {
        $dir  = config::buffer_dir();
        $cap  = config::max_buffer_bytes();
        $safe = (int)($cap / 2); // Keep the backlog under half the cap.

        // Judged on what buffer::have_capacity() counts (active + closed + `.pulled`
        // + parked), so "keep streaming" and "accept this append" agree. It reads the
        // shipper's published measurement plus everything this process has appended
        // since (see backlog_within()), so a large backlog does not make every call a
        // full directory scan; it measures only after the inline ship below, and only
        // when that measurement is unusable.
        if (self::backlog_within($dir, $safe, false)) {
            return; // Headroom — keep streaming.
        }
        if (!$ship) {
            // The estimate above can only say yes: with no fresh published measurement
            // (ship_events not run yet, disabled or failing) it is unknown, and this
            // process's own-bytes count keeps every byte since its last exact measure,
            // shipped or not. Measure before pausing, so a run pauses only on a real
            // backlog; measure() also restarts that count.
            if (buffer::measure($dir) <= $safe) {
                return;
            }
            buffer::flush(); // Its rows become shippable for the ship_events task.
            throw new exception\export_exception(
                'backfill paused: buffer backlog is over half the disk cap — the ship_events task '
                . 'sends it, and the run continues from the watermark on the next pass.',
                self::REFUSED_TRANSIENT_CODE
            );
        }

        // Drive a shipping pass (gated by host load, exactly like the
        // ship_events task). Flush our own open file first so its rows
        // become shippable rather than lingering until age-rotation. The pass
        // republishes the measurement and restarts this process's own count.
        buffer::flush();
        shipper::run();
        if (self::backlog_within($dir, $safe, true)) {
            return;
        }

        throw new exception\export_exception(
            'backfill paused: buffer backlog is over half the disk cap and one shipping pass '
            . 'could not drain it (host load over the ship gate, or object storage unreachable) '
            . '— the run is resumable and continues from the watermark on the next pass.',
            self::REFUSED_TRANSIENT_CODE
        );
    }

    /**
     * Whether the buffer is at most $safe bytes, as far as this process can tell.
     *
     * A fresh published measurement, projected forward at the shipper's observed
     * inflow, plus every byte this process appended since that measurement: the
     * projection is the rest of the site's inflow, and a long writer like this one
     * can add far more than that on its own between two ship runs, so its own
     * output is counted too (buffer::own_bytes_since()). A stale or missing
     * measurement is unknown, never headroom: without $exact the answer is then
     * "no" (the caller ships first); with it, the buffer is measured.
     *
     * @param string $dir Buffer directory.
     * @param int $safe Bytes the buffer may hold.
     * @param bool $exact Measure when the published measurement cannot answer.
     * @return bool
     */
    private static function backlog_within(string $dir, int $safe, bool $exact): bool {
        $state = buffer::fresh_capacity($dir);
        $own = $state !== null ? buffer::own_bytes_since($state['at']) : null;
        if ($own !== null) {
            return buffer::projected_bytes($state) + $own <= $safe;
        }
        return $exact && buffer::measure($dir) <= $safe;
    }

    /**
     * Resolve a role's display name exactly as Moodle renders it.
     *
     * `mdl_role.name` is empty for the standard archetype roles; Moodle resolves
     * their label ("Teacher", …) from the language pack, so a raw snapshot would
     * carry an empty name. role_get_name() with no context returns the custom name
     * when set and the localised default otherwise. accesslib.php is loaded once
     * by the caller.
     *
     * @param \stdClass $role A row from {role} (has id, name, shortname, archetype).
     * @return string The display name; falls back to the raw name, then shortname.
     */
    private static function role_display_name(\stdClass $role): string {
        try {
            $name = trim((string) role_get_name($role));
            if ($name !== '') {
                return $name;
            }
        } catch (\Throwable $e) {
            // Reachable from an observer on a web request: see buffer::trace().
            buffer::trace('local_intellistream: role_get_name failed for role '
                . ($role->id ?? '?') . ': ' . $e->getMessage());
        }
        $raw = trim((string)($role->name ?? ''));
        return $raw !== '' ? $raw : (string)($role->shortname ?? '');
    }

    /**
     * Resolve a module type's display name and plural, localised, exactly as
     * Moodle renders them.
     *
     * `mdl_modules` stores only the frankenstyle shortname (`forum`, `quiz`). Uses
     * get_string('modulename'/'modulenameplural'), each guarded by string_exists(),
     * so a module with no language pack degrades to its shortname instead of a
     * `[[mod_x]]` placeholder. No language is forced: `modules` is exported only from
     * cron, which resolves strings in the site language.
     *
     * @param string $shortname The `mdl_modules.name` frankenstyle shortname.
     * @return array{0:string,1:string} [title, titleplural]; both fall back to $shortname.
     */
    private static function module_display_names(string $shortname): array {
        $title = $plural = $shortname;
        if ($shortname === '') {
            return [$title, $plural];
        }
        try {
            $sm = get_string_manager();
            $component = 'mod_' . $shortname;
            if ($sm->string_exists('modulename', $component)) {
                $title = (string) get_string('modulename', $component);
            }
            if ($sm->string_exists('modulenameplural', $component)) {
                $plural = (string) get_string('modulenameplural', $component);
            }
        } catch (\Throwable $e) {
            // A broken language pack must never cost the row; ship shortnames.
            return [$shortname, $shortname];
        }
        // A string file can legitimately resolve to ''; never ship a blank where
        // the shortname is a usable last resort.
        $title = trim($title) !== '' ? $title : $shortname;
        $plural = trim($plural) !== '' ? $plural : $shortname;
        return [$title, $plural];
    }

    /**
     * Above this many rows a module's instance table is looked up per row instead
     * of being map-cached, so one pathological table cannot blow up memory.
     */
    const INSTANCE_NAME_MAP_MAX = 100000;

    /**
     * Ceiling on total cached instance names across every module type.
     *
     * The per-table cap alone does not bound memory: twenty module types just under
     * {@see INSTANCE_NAME_MAP_MAX} would still cache millions of names, and the
     * historical backfill runs at MEMORY_EXTRA on the grounds that every path
     * streams rows and memory stays flat. Once the budget is spent, further module
     * types fall back to point reads, which is slower but cannot run out of memory.
     * 200k names is roughly 20 MB, against a MEMORY_EXTRA of at least 384 MB on
     * 64-bit PHP.
     */
    const INSTANCE_NAME_TOTAL_MAX = 200000;

    /**
     * Names currently held across all of {@see $instancenames}.
     *
     * @var int
     */
    private static $instancenamesheld = 0;

    /**
     * `mdl_modules.id` => frankenstyle shortname, loaded once per process.
     *
     * @var array<int,string>|null
     */
    private static $moduleshortnames = null;

    /**
     * module shortname => whether its instance table can be read for a name at all
     * (it exists and has `id` + `name`). Independent of how we read it, so unlike
     * the map below this verdict is valid for every caller.
     *
     * @var array<string, bool>
     */
    private static $instancetableok = [];

    /**
     * module shortname => (instance id => name) map. Populated only on a
     * whole-table scan, and only while {@see INSTANCE_NAME_TOTAL_MAX} allows; a
     * table absent from here is point-read, which is always correct, just slower.
     *
     * Deliberately not used to memoise "don't map this one": that verdict depends
     * on the caller ($bulk) and on the remaining budget, so caching it under a
     * table-name key would let one bounded incremental pass poison the next full
     * snapshot in the same cron process.
     *
     * @var array<string, array<int,string>>
     */
    private static $instancenames = [];

    /**
     * module shortnames not mapped on this run (too large, or the budget
     * was already spent), so the size check runs once per type instead of once per
     * row — a per-row `count_records()` would cost more than the point read it is
     * deciding about.
     *
     * Written only on the whole-table path, so a bounded incremental pass can never
     * leave a verdict behind for a later whole-table read in the same cron process.
     *
     * @var array<string, true>
     */
    private static $instancenomap = [];

    /**
     * Drop the per-process module/instance name caches.
     *
     * Only matters to a process that exports twice (CLI, tests); each cron run is
     * its own process and so starts cold.
     */
    public static function reset_instance_name_cache(): void {
        self::$moduleshortnames = null;
        self::$instancetableok = [];
        self::$instancenames = [];
        self::$instancenomap = [];
        self::$instancenamesheld = 0;
    }

    /**
     * Resolve a course-module's activity instance name (the title a teacher typed).
     *
     * The name lives on the per-module-type table (`mdl_forum.name`, …), read here
     * for whatever table the row points at, so modules outside the registry (core
     * `subsection`/`qbank`, third-party modules) are named too. Only `id` and `name`
     * are read. With $bulk (whole-table reads) one cached `get_records_menu` per
     * module type; without it (the observers) a single point read. Both return the
     * same value.
     *
     * @param \stdClass $cm A `course_modules` row (needs ->module and ->instance).
     * @param bool $bulk Whether the caller is streaming a whole table.
     * @return string|null The instance name, or null when it cannot be resolved.
     */
    private static function activity_instance_name(\stdClass $cm, bool $bulk): ?string {
        global $DB;

        $moduleid = (int)($cm->module ?? 0);
        $instance = (int)($cm->instance ?? 0);
        if ($moduleid <= 0 || $instance <= 0) {
            return null;
        }

        try {
            $table = self::module_shortname($moduleid, $bulk);
            if ($table === null) {
                return null;
            }

            // Respect an explicit operator decision. This lane deliberately reads module
            // tables the registry has no entry for — that is the whole point, since core
            // `subsection`/`qbank` and every third-party module are otherwise unnameable.
            // But a curated datatype an admin has switched off is a different case: the
            // operator has said "do not export this", and reading its titles here would
            // route around that toggle exactly as it would route around the credential
            // filter. Absent from the registry entirely = never decided = export;
            // present but dropped by the overrides = decided against = skip.
            $curated = self::registry();
            if (isset($curated[$table]) && !isset(self::registry_with_overrides()[$table])) {
                return null;
            }

            // Decided once per module type, for every caller: is there a name to read?
            if (!array_key_exists($table, self::$instancetableok)) {
                self::$instancetableok[$table] = self::instance_table_usable($table);
            }
            if (!self::$instancetableok[$table]) {
                return null;
            }

            // Map-cache only on a whole-table scan, and only within budget. Both
            // outcomes are recorded, so the decision costs one query per module type
            // rather than a count_records() on every row.
            if (
                $bulk
                    && !isset(self::$instancenames[$table])
                    && !isset(self::$instancenomap[$table])
            ) {
                $map = self::build_instance_name_map($table);
                if ($map !== null) {
                    self::$instancenames[$table] = $map;
                    self::$instancenamesheld += count($map);
                } else {
                    self::$instancenomap[$table] = true;
                }
            }

            if (isset(self::$instancenames[$table])) {
                return self::filtered_instance_name(self::$instancenames[$table][$instance] ?? null, $table);
            }

            // Point read: the observer path, a bounded incremental window, or a
            // table we declined to map.
            $name = $DB->get_field($table, 'name', ['id' => $instance], IGNORE_MISSING);
            return self::filtered_instance_name($name === false ? null : $name, $table);
        } catch (\Throwable $e) {
            // Never cost the row: an activity is then sent without a name.
            return null;
        }
    }

    /**
     * Resolve a `course_modules.module` id to its frankenstyle shortname.
     *
     * Split out because the two callers have opposite cost profiles, and one of them is
     * the page-render observer path. Reading the whole `modules` menu there would be an
     * unbounded read on the hot path: small on typical sites, but a PHP memory-limit
     * exhaustion is a fatal error, not a \Throwable, so the blanket catch in
     * {@see capture_entity_match()} would not honour the "never break the host page
     * render" guarantee. A primary-key lookup cannot fail that way.
     *
     * @param int $moduleid `course_modules.module`.
     * @param bool $bulk Whether the caller is streaming a whole table.
     * @return string|null The shortname, or null when it cannot be resolved.
     */
    private static function module_shortname(int $moduleid, bool $bulk): ?string {
        global $DB;

        if (!$bulk) {
            $name = $DB->get_field('modules', 'name', ['id' => $moduleid], IGNORE_MISSING);
            return ($name === false || $name === null || $name === '') ? null : (string) $name;
        }
        if (self::$moduleshortnames === null) {
            self::$moduleshortnames = $DB->get_records_menu('modules', null, '', 'id, name');
        }
        $name = self::$moduleshortnames[$moduleid] ?? null;
        return ($name === null || $name === '') ? null : (string) $name;
    }

    /**
     * Apply the credential filter to a resolved activity instance name.
     *
     * {@see activity_instance_name()} reads a module table directly rather than through
     * {@see registry_with_overrides()}, so it does not inherit that funnel. The rule is
     * that every export lane resolves through the same filter: the legacy-migration
     * lane does it with {@see strip_forbidden_row_keys()}, and so does this one. The
     * column is always `name`, which no entry forbids; routing it through anyway
     * means a future addition to {@see FORBIDDEN_COLUMNS} or
     * {@see FORBIDDEN_TABLE_COLUMNS} cannot be silently bypassed here.
     *
     * @param mixed $name Raw value read from the module's instance table.
     * @param string $table Module shortname, which is also its table name.
     * @return string|null The name, or null when absent, empty or filtered.
     */
    private static function filtered_instance_name($name, string $table): ?string {
        if ($name === null || $name === '') {
            return null;
        }
        $kept = self::strip_forbidden_row_keys(['name' => $name], $table);
        return array_key_exists('name', $kept) ? (string) $kept['name'] : null;
    }

    /**
     * Whether a module's instance table can yield a name at all.
     *
     * Caller-independent, so it is safe to memoise for the whole process: the
     * table either exists with `id` + `name` or it does not.
     *
     * @param string $table Module shortname, which is also its table name.
     * @return bool
     */
    private static function instance_table_usable(string $table): bool {
        global $DB;

        try {
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                return false;
            }
            $columns = $DB->get_columns($table);
        } catch (\Throwable $e) {
            return false;
        }
        return isset($columns['id']) && isset($columns['name']);
    }

    /**
     * Load one module type's (instance id => name) map, or null to decline.
     *
     * Declining is not an error — the caller then point-reads, which is always
     * correct. We decline when this one table is pathologically large, or when the
     * whole-cache budget is spent, so the optimisation can never turn into an OOM.
     * The table's existence and shape were already settled by
     * {@see instance_table_usable()}.
     *
     * @param string $table Module shortname, which is also its table name.
     * @return array<int,string>|null
     */
    private static function build_instance_name_map(string $table): ?array {
        global $DB;

        if (self::$instancenamesheld >= self::INSTANCE_NAME_TOTAL_MAX) {
            return null;
        }
        // What this one map may hold: the per-table cap, or whatever is left of the
        // process-wide budget — whichever binds first.
        $budget = min(
            self::INSTANCE_NAME_MAP_MAX,
            self::INSTANCE_NAME_TOTAL_MAX - self::$instancenamesheld
        );
        try {
            // Cheap decline first, so a table far over budget costs one count
            // rather than a read of every row in it.
            if ($DB->count_records($table) > $budget) {
                return null;
            }
            // That count is a separate query from the read below, so by the time we
            // use it it is only an estimate — rows inserted in between would let the
            // map, and the budget the caller adds count($map) to, run past the cap.
            // Asking for one row more than the budget bounds the fetch itself, so
            // the overrun is detectable from the result without a second count.
            $map = $DB->get_records_menu($table, null, '', 'id, name', 0, $budget + 1);
            return count($map) > $budget ? null : $map;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ask the task that keeps a derived entity to send it again. Nothing is read here,
     * and nothing is counted again: a recount is the CLI's (cli/userlogins_recount.php).
     *
     * @param string $entity Registry key of a `'derived' => true` entity.
     * @return bool False when no task keeps this entity.
     */
    public static function request_derived(string $entity): bool {
        switch ($entity) {
            case userlogins::ENTITY:
                userlogins::request_reship();
                return true;
            default:
                mtrace("local_intellistream: derived entity '{$entity}' has no task that keeps it — skipped.");
                return false;
        }
    }

    /**
     * One `userlogins` record: the row shape (`id`, `logins`) of the legacy `userlogins`
     * datatype, under the same deterministic id on every send.
     *
     * @param string $siteid
     * @param string $batch snapshot_batch of the sending round.
     * @param int $userid
     * @param int $logins
     * @param int $pluginversion
     * @param int|null $moodleversion
     * @return array
     */
    public static function userlogins_payload(
        string $siteid,
        string $batch,
        int $userid,
        int $logins,
        int $pluginversion,
        ?int $moodleversion
    ): array {
        return [
            'id'             => self::entity_uuid($siteid, userlogins::ENTITY, $userid),
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => $pluginversion,
            'moodle_version' => $moodleversion,
            'record_type'    => 'entity_snapshot',
            'entity'         => userlogins::ENTITY,
            'snapshot_batch' => $batch,
            'entity_data'    => [
                'id'     => $userid,
                'logins' => $logins,
            ],
        ];
    }

    /**
     * Effective registry with admin overrides applied.
     *
     * Export code calls this, not {@see registry()}. Applies the per-datatype rows of
     * `local_intellistream_config` through
     * {@see \local_intellistream\services\config_service::apply_overrides_to_registry()}
     * (disabled rows dropped, custom_columns, custom_table entities), then
     * strip_forbidden_columns(). Memoised for the request, because
     * capture_entity_match() calls it for every observed event; invalidated by
     * {@see reset_registry_cache()}, which config_repository (the only writer of the
     * table) calls on save and delete.
     *
     * @return array<string, array{table:string, columns?:string, derived?:bool}>
     */
    public static function registry_with_overrides(): array {
        if (self::$registrycache !== null && (self::$registryretryat === 0 || time() < self::$registryretryat)) {
            return self::$registrycache;
        }
        $service = new \local_intellistream\services\config_service();
        self::$registryincomplete = false;
        self::$registrycache = static::strip_forbidden_columns(
            $service->apply_overrides_to_registry(self::registry())
        );
        // A registry that left an entity out is reused only briefly, so a column read
        // that failed once is tried again soon, while one that keeps failing costs a
        // rebuild a minute rather than one per observer call.
        self::$registryretryat = self::$registryincomplete ? time() + self::INCOMPLETE_REGISTRY_TTL : 0;
        return self::$registrycache;
    }

    /**
     * Drop the memoised effective registry.
     *
     * Called by config_repository::save()/delete() so a write is visible to any
     * later read in the same request — the control webhook does exactly that when
     * it saves datatype config and then reports the resulting catalogue back.
     * Also the hook a test uses between fixtures.
     *
     * @return void
     */
    public static function reset_registry_cache(): void {
        self::$registrycache = null;
        self::$registryretryat = 0;
    }

    /**
     * Final, unconditional credential filter over a resolved registry.
     *
     * Covers the curated registry, admin overrides, custom tables and discovered
     * tables in one pass ({@see FORBIDDEN_COLUMNS}). An explicit SELECT list loses
     * its forbidden tokens, order kept. For `'*'` the live table is introspected
     * (from Moodle's metadata cache): `'*'` stays unless a forbidden column exists,
     * in which case it becomes the list of the remaining columns. Derived entities
     * are passed through untouched.
     *
     * @param array $registry Entity => ['table' => string, 'columns' => ?string, 'derived' => ?bool].
     * @return array The registry with forbidden columns removed, same shape.
     */
    protected static function strip_forbidden_columns(array $registry): array {
        $global = array_map('strtolower', self::FORBIDDEN_COLUMNS);

        // Read once, not per entity: this runs on the observer path.
        $bodiesallowed = (bool) (int) get_config(config::COMPONENT, 'exportmessagebodies');

        foreach ($registry as $datatype => $def) {
            if (!empty($def['derived'])) {
                continue;
            }
            $table = $def['table'] ?? '';
            $columns = isset($def['columns']) ? trim((string) $def['columns']) : '';
            $forbidden = $global;

            // Per-table credential columns whose bare name is too generic to
            // blocklist globally. Keyed on the table, not the datatype, so an
            // admin `custom_table` row or a discovered entry aimed at the same
            // table is filtered too, whatever it chose to call itself.
            foreach (self::FORBIDDEN_TABLE_COLUMNS[strtolower((string) $table)] ?? [] as $col) {
                $forbidden[] = strtolower($col);
            }

            // Message bodies are opt-in, so absent the setting they behave exactly
            // like a forbidden column and are stripped by the same pass.
            if (!$bodiesallowed && in_array($datatype, self::MESSAGE_ENTITIES, true)) {
                foreach (self::MESSAGE_BODY_COLUMNS as $col) {
                    $forbidden[] = strtolower($col);
                }
            }

            if ($columns === '' || $columns === '*') {
                // Whole row: introspect, and only rewrite if there is something to strip.
                if ($table === '') {
                    continue;
                }
                $live = static::live_columns($table);
                if ($live === []) {
                    // An absent table is left alone: the export path re-checks
                    // table_exists() before selecting. But a real table whose columns
                    // could not be read (a failed query: MySQL returns no columns, other
                    // drivers throw) cannot be checked for credentials, so it is not
                    // exported whole: leave it out, and reuse this registry only briefly
                    // (see registry_with_overrides()).
                    if (\local_intellistream\services\config_service::table_is_real($table)) {
                        unset($registry[$datatype]);
                        self::$registryincomplete = true;
                        self::log_columns_unreadable($datatype, $table);
                    }
                    continue;
                }
                $safe = [];
                $stripped = false;
                foreach ($live as $col) {
                    if (in_array(strtolower((string) $col), $forbidden, true)) {
                        $stripped = true;
                        continue;
                    }
                    $safe[] = $col;
                }
                if ($stripped) {
                    // Same fallback as the explicit-list branch below: if every live
                    // column turned out to be forbidden, narrow to the primary key
                    // rather than leaving '*' in place, so a table whose every column
                    // is a credential never keeps `SELECT *`.
                    $registry[$datatype]['columns'] = $safe ? implode(', ', $safe) : 'id';
                }
                continue;
            }

            $safe = [];
            $stripped = false;
            foreach (preg_split('/\s*,\s*/', $columns) as $col) {
                $col = trim($col);
                if ($col === '') {
                    continue;
                }
                if (in_array(strtolower($col), $forbidden, true)) {
                    $stripped = true;
                    continue;
                }
                $safe[] = $col;
            }
            if ($stripped) {
                // An entry whose every column was forbidden would leave an empty
                // SELECT list, which is not valid SQL. Fall back to the primary
                // key so the entity still reconciles (and deletes still work)
                // without carrying any payload.
                $registry[$datatype]['columns'] = $safe ? implode(', ', $safe) : 'id';
            }
        }

        return $registry;
    }

    /**
     * Record that an entity is left out because its columns could not be read.
     *
     * At most once an hour per entity: on the web the registry is built in every
     * request, so a lasting failure would otherwise add a log row per request.
     *
     * @param string $datatype Registry key.
     * @param string $table Its table.
     * @return void
     */
    private static function log_columns_unreadable(string $datatype, string $table): void {
        global $DB;
        try {
            $logged = $DB->record_exists_select(
                \local_intellistream\services\log_service::TABLE,
                'type = :type AND timecreated > :since AND datatype = :datatype AND action = :action',
                [
                    'type' => \local_intellistream\services\log_service::TYPE_EXPORT,
                    'since' => time() - HOURSECS,
                    'datatype' => $datatype,
                    'action' => \local_intellistream\services\log_service::ACTION_COLUMNS_UNREADABLE,
                ]
            );
            if ($logged) {
                return;
            }
        } catch (\Throwable $e) {
            return;   // The log feed is a diagnostic aid; never fail the export for it.
        }
        \local_intellistream\services\log_service::record(
            \local_intellistream\services\log_service::TYPE_EXPORT,
            $datatype,
            \local_intellistream\services\log_service::ACTION_COLUMNS_UNREADABLE,
            json_encode(['table' => $table], JSON_UNESCAPED_SLASHES)
        );
    }

    /**
     * The live column names of a table, or an empty array when they cannot be read.
     *
     * MySQL returns no columns for a failed read; other drivers throw.
     *
     * @param string $table Table name without prefix.
     * @return string[]
     */
    protected static function live_columns(string $table): array {
        global $DB;
        try {
            return array_keys($DB->get_columns($table));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Strip forbidden keys from an already-fetched row.
     *
     * For the path that bypasses {@see strip_forbidden_columns()}: the
     * legacy-migration task reads `SELECT *` from `local_intelliboard_*` tables and
     * buffers whole rows. Message bodies are not considered here: the legacy tables
     * carry no message text, and this filter has no entity name to key that rule on.
     *
     * @param array  $row   fetched row as an associative array
     * @param string $table source table name, for the per-table rules
     * @return array the same row minus any forbidden key
     */
    public static function strip_forbidden_row_keys(array $row, string $table = ''): array {
        $forbidden = array_map('strtolower', self::FORBIDDEN_COLUMNS);
        foreach (self::FORBIDDEN_TABLE_COLUMNS[strtolower($table)] ?? [] as $col) {
            $forbidden[] = strtolower($col);
        }
        foreach (array_keys($row) as $key) {
            if (in_array(strtolower((string) $key), $forbidden, true)) {
                unset($row[$key]);
            }
        }
        return $row;
    }

    /**
     * How the verification sweep treats an entity.
     *
     * 'blocks'  a table with a numeric `id`: walked in fixed id blocks and fingerprinted;
     * 'atomic'  a table without an `id` column: sent whole once per cycle (no
     *           fingerprint, no census);
     * 'absent'  its table does not exist on this site, or the entity is derived (its own
     *           task keeps and sends it, see request_derived()).
     *
     * @param array $def Its effective registry definition.
     * @return string
     */
    public static function sweep_kind(array $def): string {
        global $DB;
        if (!empty($def['derived'])) {
            return 'absent';
        }
        $table = $def['table'] ?? '';
        if ($table === '') {
            return 'absent';
        }
        try {
            if (!\local_intellistream\services\config_service::table_is_real($table)) {
                return 'absent';
            }
            $cols = $DB->get_columns($table);
        } catch (\Throwable $e) {
            return 'absent';
        }
        return isset($cols['id']) ? 'blocks' : 'atomic';
    }

    /**
     * Highest block number an entity's table currently reaches (-1 when empty).
     *
     * @param array $def Effective registry definition of a 'blocks' entity.
     * @return int
     */
    public static function sweep_max_block(array $def): int {
        global $DB;
        $max = $DB->get_field_sql('SELECT MAX(id) FROM {' . $def['table'] . '}');
        return ($max === null || $max === false) ? -1 : intdiv((int)$max, change_ledger::BLOCK_SIZE);
    }

    /**
     * One save window of the verification sweep for one entity: up to $maxblocks fixed
     * id blocks (floor(id / BLOCK_SIZE)) starting at the first existing id at or above
     * block $nextblock, fingerprinted per change_ledger::FP_SIZE ids.
     *
     * Each block is fingerprinted exactly as it would be sent (entity_row_data(), the
     * same envelope-free data the buffer carries, length-prefixed per row, seeded with
     * the ledger fingerprint and the entity). A block is sent only when its fingerprint
     * differs from the confirmed one for this window's range (change_ledger::trusted_range(),
     * read here so only that range is in memory) or it has none — it is then read again and
     * the fingerprint recorded is that of the rows actually sent, so a change between the
     * reads is never masked. With no confirmed entry in the range at all, every block is
     * sent as it is read.
     *
     * Nothing here is persisted: the caller writes the census page, stages the returned
     * entries and advances the position together, so a run killed part-way redoes the
     * window and loses nothing.
     *
     * @param string $entity Registry key.
     * @param array $def Effective registry definition ('blocks' kind).
     * @param int $nextblock First block to consider.
     * @param int $maxblocks Blocks in this window.
     * @param string $batch snapshot_batch of the pass.
     * @param int|null $pass Pass number, for the periodic re-send (change_ledger::trusted_range()).
     * @return array{status:string, entries:string, ids:int[], nextblock:int, rows:int,
     *     blocks:int, sent:int, dropped:int, clk:array{0:int,1:int,2:int,3:bool}, error:?string}
     *     status: 'ok', 'end' (no id at or above $nextblock), 'stopped' (the buffer refused
     *     a record it may take later; nothing to persist), 'error'.
     */
    public static function sweep_window(
        string $entity,
        array $def,
        int $nextblock,
        int $maxblocks,
        string $batch,
        ?int $pass = null
    ): array {
        global $DB, $CFG;
        $n = change_ledger::BLOCK_SIZE;       // Windows and census pages.
        $f = change_ledger::FP_SIZE;          // Fingerprint units.
        $out = ['status' => 'ok', 'entries' => '', 'ids' => [], 'nextblock' => $nextblock, 'rows' => 0,
            'blocks' => 0, 'sent' => 0, 'dropped' => 0, 'clk' => [0, 0, 0, false], 'error' => null];
        $table = $def['table'];
        $resolved = self::resolve_entity_columns($entity, $table, $def['columns'] ?? null, $def['wmcol'] ?? null);
        $columns = $resolved['columns'];
        if ($columns === null) {
            // None of its declared columns exists here. Never an empty pass: that would
            // end in an empty census, i.e. "every row was deleted". A failure instead,
            // which parks the entity and sends an id-only census built from its real ids.
            $out['status'] = 'error';
            $out['error'] = "no declared column of '{$table}' exists on this site";
            return $out;
        }
        if ($columns === '') {
            $columns = '*';
        }
        $wmcol = $resolved['wmcol'];
        $fpskip = self::fp_skip_column($def, $wmcol);
        $siteid = config::site_id();
        $pluginversion = (int)config::plugin_version();
        $moodleversion = isset($CFG->version) ? (int)$CFG->version : null;
        $seed = change_ledger::fingerprint() . "\0" . $entity . "\0";

        try {
            $first = $DB->get_field_sql('SELECT MIN(id) FROM {' . $table . '} WHERE id >= :lo', ['lo' => $nextblock * $n]);
        } catch (\Throwable $e) {
            $out['status'] = 'error';
            $out['error'] = $e->getMessage();
            return $out;
        }
        if ($first === null || $first === false) {
            $out['status'] = 'end';
            return $out;
        }
        $startblock = intdiv((int)$first, $n);
        $endblock = $startblock + max(1, $maxblocks);   // Exclusive.
        $out['nextblock'] = $endblock;

        $trusted = change_ledger::trusted_range($entity, intdiv($startblock * $n, $f), intdiv($endblock * $n, $f), $pass);
        $send = $trusted === '';
        $tn = change_ledger::entries($trusted);
        $ti = 0;
        $tosend = [];       // Blocks to send: block => true.
        $hashes = [];       // Block => raw hash, in block order.
        $ctx = null;
        $cur = null;
        $rs = null;
        $stopped = false;
        $clk = [0, 0, 0, false];
        try {
            $rs = $DB->get_recordset_select(
                $table,
                'id >= :lo AND id < :hi',
                ['lo' => $startblock * $n, 'hi' => $endblock * $n],
                'id ASC',
                $columns
            );
            foreach ($rs as $row) {
                $b = intdiv((int)$row->id, $f);
                if ($b !== $cur) {
                    if ($ctx !== null) {
                        $hashes[$cur] = substr(hash_final($ctx, true), 0, change_ledger::HASH_BYTES);
                    }
                    $ctx = hash_init('sha256');
                    hash_update($ctx, $seed);
                    $cur = $b;
                }
                $data = self::entity_row_data($entity, $row, true);
                self::hash_row($ctx, $data, $fpskip);
                $out['ids'][] = (int)$row->id;
                $isclockless = $wmcol !== null && self::is_clockless_row($row, $wmcol);
                if ($isclockless) {
                    $clk[0]++;
                }
                if (!$send) {
                    if ($isclockless) {
                        // Not re-sent: its block was confirmed delivered.
                        $clk[1]++;
                        if (!$clk[3]) {
                            $clk[2] = max($clk[2], (int)$row->id);
                        }
                    }
                    continue;
                }
                $reason = null;
                if (
                    !self::buffer_entity_data(
                        $siteid,
                        $entity,
                        $row->id,
                        $data,
                        $pluginversion,
                        $moodleversion,
                        $batch,
                        $reason
                    )
                ) {
                    if ($isclockless) {
                        $clk[3] = true;
                    }
                    if ($reason !== buffer::REFUSED_PERMANENT) {
                        $stopped = true;
                        break;
                    }
                    $out['dropped']++;   // Settled: fingerprinted, never retried.
                    continue;
                }
                $out['rows']++;
                if ($isclockless) {
                    $clk[1]++;
                    if (!$clk[3]) {
                        $clk[2] = max($clk[2], (int)$row->id);
                    }
                }
            }
            if ($ctx !== null && !$stopped) {
                $hashes[$cur] = substr(hash_final($ctx, true), 0, change_ledger::HASH_BYTES);
            }
        } catch (\Throwable $e) {
            $out['status'] = 'error';
            $out['error'] = $e->getMessage();
            return $out;
        } finally {
            if ($rs instanceof \moodle_recordset) {
                $rs->close();
            }
        }
        if ($stopped) {
            $out['status'] = 'stopped';
            return $out;
        }

        if (!$send) {
            // Decide each block against the trusted set (both in block order).
            foreach ($hashes as $b => $h) {
                while ($ti < $tn && change_ledger::read($trusted, $ti)[0] < $b) {
                    $ti++;
                }
                if (
                    $ti >= $tn || change_ledger::read($trusted, $ti)[0] !== $b
                        || !hash_equals(change_ledger::read($trusted, $ti)[1], $h)
                ) {
                    $tosend[$b] = true;
                }
            }
            // Send the changed blocks, re-read, and record what was actually sent.
            foreach (array_keys($tosend) as $b) {
                $ctx = hash_init('sha256');
                hash_update($ctx, $seed);
                $brs = null;
                $present = false;
                try {
                    $brs = $DB->get_recordset_select(
                        $table,
                        'id >= :lo AND id < :hi',
                        ['lo' => $b * $f, 'hi' => ($b + 1) * $f],
                        'id ASC',
                        $columns
                    );
                    foreach ($brs as $row) {
                        $present = true;
                        $data = self::entity_row_data($entity, $row, true);
                        self::hash_row($ctx, $data, $fpskip);
                        $reason = null;
                        if (
                            !self::buffer_entity_data(
                                $siteid,
                                $entity,
                                $row->id,
                                $data,
                                $pluginversion,
                                $moodleversion,
                                $batch,
                                $reason
                            )
                        ) {
                            if ($reason !== buffer::REFUSED_PERMANENT) {
                                $stopped = true;
                                break;
                            }
                            $out['dropped']++;
                            continue;
                        }
                        $out['rows']++;
                    }
                } catch (\Throwable $e) {
                    $out['status'] = 'error';
                    $out['error'] = $e->getMessage();
                    return $out;
                } finally {
                    if ($brs instanceof \moodle_recordset) {
                        $brs->close();
                    }
                }
                if ($stopped) {
                    $out['status'] = 'stopped';
                    return $out;
                }
                if ($present) {
                    $hashes[$b] = substr(hash_final($ctx, true), 0, change_ledger::HASH_BYTES);
                } else {
                    unset($hashes[$b]);   // Emptied between the reads.
                }
                $out['sent']++;
            }
        } else {
            $out['sent'] = count($hashes);
        }

        foreach ($hashes as $b => $h) {
            $out['entries'] .= change_ledger::entry($b, $h);
        }
        $out['blocks'] = count($hashes);
        $out['clk'] = $clk;
        return $out;
    }

    /**
     * Write one census page of a verification pass. Pages of consecutive passes go to
     * alternating slots, so a pass in progress never overwrites the pages of the last
     * complete one; the manifest (see sweep_census_manifest()) names the batch whose
     * pages form the census, which is how the server matches them.
     *
     * The ids go in whichever form is smaller: the JSON list (`pks`), or a bitmap
     * (`lo`, `n`, `bits`: base64, bit i of the bitmap — least significant bit first in
     * each byte — set when id lo+i exists). A dense window of 50,000 ids is ~8 KB as a
     * bitmap against ~300 KB as a list; a sparse one stays a list.
     *
     * @param string $entity
     * @param int $slot 0 or 1.
     * @param string $batch Pass batch.
     * @param int $pageno 1-based.
     * @param int[] $ids Ascending.
     * @return bool Whether the buffer accepted it.
     */
    public static function sweep_census_page(string $entity, int $slot, string $batch, int $pageno, array $ids): bool {
        global $CFG;
        $siteid = config::site_id();
        $page = ['census_entity' => $entity, 'page' => $pageno];
        $bits = self::census_bits($ids);
        if ($bits !== null) {
            $page += $bits;
        } else {
            $page['pks'] = array_map('strval', $ids);
        }
        $page['snapshot_batch'] = $batch;
        return buffer::append_record([
            'id'             => self::entity_uuid($siteid, 'entity_census', "{$entity}|page_{$pageno}|g{$slot}"),
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => (int)config::plugin_version(),
            'moodle_version' => isset($CFG->version) ? (int)$CFG->version : null,
            'record_type'    => 'entity_snapshot',
            'entity'         => 'entity_census',
            'snapshot_batch' => $batch,
            'entity_data'    => $page,
        ]);
    }

    /**
     * The bitmap form of an ascending id list, or null when the JSON list is smaller.
     *
     * @param int[] $ids Ascending, at least one.
     * @return array{lo:int, n:int, bits:string}|null
     */
    public static function census_bits(array $ids): ?array {
        if (!$ids) {
            return null;
        }
        $lo = (int)$ids[0];
        $n = (int)end($ids) - $lo + 1;
        $bytes = intdiv($n + 7, 8);
        $listlen = 2;
        foreach ($ids as $id) {
            $listlen += strlen((string)$id) + 3;   // Quotes and comma, as json_encode writes strings.
        }
        if (4 * intdiv($bytes + 2, 3) >= $listlen) {
            return null;
        }
        $map = str_repeat("\0", $bytes);
        foreach ($ids as $id) {
            $i = (int)$id - $lo;
            $map[$i >> 3] = chr(ord($map[$i >> 3]) | (1 << ($i & 7)));
        }
        return ['lo' => $lo, 'n' => $n, 'bits' => base64_encode($map)];
    }

    /**
     * Close a verification pass's census: the manifest naming its batch and page count,
     * under the same record id as every earlier manifest of this entity. An empty table
     * gets the single-record form with no ids.
     *
     * @param string $entity
     * @param string $batch
     * @param int $pages
     * @param int $count
     * @param string $scanstarted Plugin-clock stamp taken when the pass began.
     * @param string|null $reason Out-param, see buffer::append_record().
     * @return bool
     */
    public static function sweep_census_manifest(
        string $entity,
        string $batch,
        int $pages,
        int $count,
        string $scanstarted,
        ?string &$reason = null
    ): bool {
        global $CFG;
        $siteid = config::site_id();
        $base = [
            'site_id'        => $siteid,
            'captured_at'    => clock::now(),
            'plugin_version' => (int)config::plugin_version(),
            'moodle_version' => isset($CFG->version) ? (int)$CFG->version : null,
            'record_type'    => 'entity_snapshot',
            'entity'         => 'entity_census',
            'snapshot_batch' => $batch,
        ];
        if ($pages === 0) {
            return buffer::append_record($base + [
                'id'          => self::entity_uuid($siteid, 'entity_census', $entity),
                'entity_data' => ['census_entity' => $entity, 'count' => 0, 'pks' => [],
                    'snapshot_batch' => $batch, 'scan_started_at' => $scanstarted],
            ], $reason);
        }
        // Format 2: pages may be bitmaps, so the page count goes in `page_total`, not
        // `page_count`. A server that cannot decode them sees no page count and skips the
        // entity (no deletes) rather than reading a bitmap page as "these ids are gone".
        return buffer::append_record($base + [
            'id'          => self::entity_uuid($siteid, 'entity_census', "{$entity}|manifest"),
            'entity_data' => ['census_entity' => $entity, 'is_census_manifest' => true, 'format' => '2',
                'page_total' => $pages, 'count' => $count, 'snapshot_batch' => $batch,
                'scan_started_at' => $scanstarted],
        ], $reason);
    }

    /**
     * Merge one entity's clockless observation from a completed pass into the detected
     * set the incremental lane reads. A merge, never a replace: other entities' entries
     * are left as they are.
     *
     * @param string $entity
     * @param int $seen Clockless rows the pass read.
     * @param int $maxid Highest clockless id delivered, below any refusal.
     * @return void
     */
    public static function sweep_note_clockless(string $entity, int $seen, int $maxid): void {
        $set = self::clockless_detected();
        $before = $set;
        if ($seen > 0) {
            $set[$entity] = $maxid;
        } else {
            unset($set[$entity]);
        }
        if ($set === $before) {
            return;
        }
        ksort($set);
        $payload = json_encode($set);
        if ($payload !== false) {
            set_config(self::CLOCKLESS_DETECTED_KEY, $payload, config::COMPONENT);
        }
    }

    /**
     * The column an entity leaves out of its sweep fingerprint, if any.
     *
     * Only the column the registry names in `fpskipwm`, and only while it is this
     * site's resolved watermark column: the incremental lane then sends every change to
     * it row by row. Anywhere that does not hold (an admin wmcol override, schema
     * drift, no watermark) the whole row is hashed, so a change to that column is still
     * caught by the sweep.
     *
     * @param array $def Registry definition.
     * @param string|null $wmcol The entity's resolved watermark column on this site.
     * @return string|null
     */
    private static function fp_skip_column(array $def, ?string $wmcol): ?string {
        $want = isset($def['fpskipwm']) ? strtolower((string)$def['fpskipwm']) : '';
        return ($want !== '' && $wmcol !== null && strtolower($wmcol) === $want) ? $wmcol : null;
    }

    /**
     * Feed one row's shipped data into a block fingerprint. Length-prefixed so row
     * boundaries are part of what is hashed; encoded with the buffer's own flags.
     *
     * @param \HashContext|resource $ctx
     * @param array $data From entity_row_data().
     * @param string|null $skip A column left out of the fingerprint (see fp_skip_column()).
     * @return void
     */
    private static function hash_row($ctx, array $data, ?string $skip = null): void {
        if ($skip !== null) {
            unset($data[$skip]);
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = "\0unencodable";
        }
        hash_update($ctx, strlen($json) . ':' . $json);
    }
}
