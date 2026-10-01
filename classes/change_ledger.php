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
 * Per-entity block fingerprints for the verification sweep.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * What each id block of each entity looked like when it was last confirmed delivered.
 *
 * The verification sweep (see \local_intellistream\sweep) reads every entity in
 * windows of BLOCK_SIZE-id blocks and fingerprints each FP_SIZE-id unit exactly as it
 * would be sent (a "block" below). A block whose fingerprint matches the confirmed one
 * is not sent again. A fingerprint only counts once the data it describes has left the site:
 *
 *   - a pass writes its fingerprints as PENDING, part by part as it goes;
 *   - when that entity's NEXT pass starts, the pending set is promoted to COMMITTED
 *     only if none of the pass's buffer files is still on the host and nothing that
 *     could have lost a buffered record happened since the pass began (a
 *     disk-cap eviction by an earlier release, privacy erasure, pull export,
 *     purge — see loss_since());
 *   - otherwise the blocks it saw change go into the SUSPECT set and keep being
 *     treated as unknown (sent again) until a pass is confirmed.
 *
 * All of this is per entity, because each entity's pass runs on its own schedule.
 * A change of site id or delivery destination invalidates everything (fingerprint).
 *
 * Entries are packed ENTRY_BYTES strings in ascending block order (never PHP arrays:
 * a large table has hundreds of thousands of blocks). A set is stored as rows, each
 * keyed (`part`) by the first block it holds, so any block range is read from the rows
 * that cover it alone: memory stays at a few rows whatever the table's size.
 */
class change_ledger {
    /** Table holding the packed sets. */
    const TABLE = 'local_intellistream_blkhash';

    /** Bump when what is hashed changes: every stored fingerprint then stops matching. */
    const FORMAT = 4;

    /** Ids per block: the unit of the sweep's windows and census pages. */
    const BLOCK_SIZE = 1000;

    /**
     * Ids per fingerprint: a changed row costs a re-send of this many ids, not a whole
     * block (measured on prod: 30-48 % fewer rows re-sent than at 1,000).
     */
    const FP_SIZE = 100;

    /** Stored bytes of each fingerprint (truncated sha256). */
    const HASH_BYTES = 16;

    /** Bytes per packed entry: 4-byte block number + fingerprint. */
    const ENTRY_BYTES = 20;

    /** Most blocks one stored row holds, so no row exceeds a bounded size (~530 KB). */
    const PART_BLOCKS = 20000;

    /**
     * Blocks per row the backfill seeds: one sweep window (sweep::WINDOW_BLOCKS blocks of
     * BLOCK_SIZE ids), so the first pass after a backfill reads rows no bigger than it needs.
     */
    const SEED_ROW_BLOCKS = sweep::WINDOW_BLOCKS * self::BLOCK_SIZE / self::FP_SIZE;

    /** Transient kind: a suspect set being rebuilt (see suspect_pending()). */
    const FOLDING = 'folding';

    /** Confirmed delivered. */
    const COMMITTED = 'committed';

    /** Written by a pass, not yet confirmed. */
    const PENDING = 'pending';

    /** Seen to differ by a pass that could not be confirmed. */
    const SUSPECT = 'suspect';

    /** Seconds pull_export waits between two `last_pull_time` writes. */
    const PULL_NOTE_SEC = 300;

    /**
     * Longest a finished pass waits for its buffer files to ship before its entity's
     * fingerprints are dropped instead (see settle()). With a pass of up to ~22 h this
     * keeps two census manifests well inside the server's 48 h census-age limit.
     */
    const SHIP_WAIT_SEC = 43200;

    /** Config key: when reset() last ran, JSON by entity ("*" = every entity). */
    const RESET_KEY = 'fingerprint_reset_times';

    /** Default of the `sweeprefreshpasses` setting (see refresh_passes()). */
    const DEFAULT_REFRESH_PASSES = 0;

    /**
     * Every confirmed entry is ignored once in this many passes, staggered by unit, so
     * each unit is sent again at least that often (at 7, about 1/7 of a table a day):
     * the repair for a record lost after it reached object storage, which a fingerprint
     * alone never notices. Setting `sweeprefreshpasses`; 0, the default, turns it off,
     * because the re-read and re-send is a standing daily cost on every site. Losses on
     * the site itself (loss_since()) still clear the fingerprints either way.
     *
     * @return int 0 = off.
     */
    public static function refresh_passes(): int {
        return max(0, (int)config::get('sweeprefreshpasses', self::DEFAULT_REFRESH_PASSES));
    }

    /**
     * Whether fingerprints are kept: wherever the sweep runs, i.e. the plugin is on and
     * its records can leave the site (config::destination_ready()). A pull-only site
     * trusts its fingerprints too; on a site that also ships to object storage a pull is
     * a loss marker (loss_since()).
     *
     * @return bool
     */
    public static function active(): bool {
        return config::enabled() && config::destination_ready();
    }

    /**
     * What a stored set is valid for: this site, this destination, this format.
     *
     * @return string sha256 hex.
     */
    public static function fingerprint(): string {
        return hash('sha256', implode("\0", [
            'v' . self::FORMAT,
            config::site_id(),
            config::endpoint(),
            config::bucket(),
            config::prefix(),
            // Encrypted objects are quarantined on arrival, so what was sent while
            // encryption was on must be sent again once it is off.
            (new services\encryption_service())->is_enabled() ? 'enc' : 'plain',
        ]));
    }

    /**
     * Confirm, or distrust, what an entity's previous pass wrote. Called when the
     * entity's next pass starts, before it reads anything.
     *
     * A buffer file from that pass still on the host with no loss marker means "not
     * shipped yet", not "lost": the caller waits ('wait') and asks again next run, up to
     * SHIP_WAIT_SEC after the pass finished. Past that the entity's fingerprints are
     * dropped, so a file that never ships cannot hold confirmation back for ever.
     *
     * @param string $entity
     * @param int $pendstart Start of the pass that wrote the pending set (0 = none).
     * @param int $pendend Last write of that pass (0 = it never completed).
     * @return string What happened: 'none', 'promoted', 'wait', 'suspect', 'cleared'.
     */
    public static function settle(string $entity, int $pendstart, int $pendend): string {
        global $DB;
        $fp = self::fingerprint();
        // Rows for an older destination never match again.
        $DB->delete_records_select(self::TABLE, 'entity = :e AND fingerprint <> :fp', ['e' => $entity, 'fp' => $fp]);
        if (!$DB->record_exists(self::TABLE, ['fingerprint' => $fp, 'entity' => $entity, 'kind' => self::PENDING])) {
            return 'none';
        }
        $since = self::oldest_start($fp, $entity, $pendstart);
        if (($loss = self::loss_since($since)) === null && self::reset_time($entity) >= $since) {
            $loss = 'a reset was requested';
        }
        if ($loss !== null) {
            self::clear_entity($entity);
            mtrace("local_intellistream: {$entity} — fingerprints cleared ({$loss}); its next pass sends everything.");
            return 'cleared';
        }
        if ($pendend <= 0) {
            self::suspect_pending($fp, $entity);
            return 'suspect';
        }
        $left = buffer::files_written_between($since, $pendend);
        if ($left > 0) {
            if (time() - $pendend < self::SHIP_WAIT_SEC) {
                return 'wait';
            }
            self::clear_entity($entity);
            mtrace("local_intellistream: {$entity} — fingerprints cleared ({$left} buffer file(s) from its "
                . 'last pass not shipped within ' . intdiv(self::SHIP_WAIT_SEC, 3600) . ' h); its next pass sends everything.');
            return 'cleared';
        }
        $tx = $DB->start_delegated_transaction();
        try {
            $DB->delete_records(self::TABLE, ['fingerprint' => $fp, 'entity' => $entity, 'kind' => self::COMMITTED]);
            $DB->delete_records(self::TABLE, ['fingerprint' => $fp, 'entity' => $entity, 'kind' => self::SUSPECT]);
            $DB->set_field(
                self::TABLE,
                'kind',
                self::COMMITTED,
                ['fingerprint' => $fp, 'entity' => $entity, 'kind' => self::PENDING]
            );
            $tx->allow_commit();
        } catch (\Throwable $e) {
            $tx->rollback($e);
        }
        return 'promoted';
    }

    /**
     * The confirmed entries of an entity for blocks [$lo, $hi), minus blocks a later
     * unconfirmed pass saw differ. Reads only the stored rows that cover the range.
     *
     * Given the pass number, the units due for their periodic re-send in that pass are
     * left out too ((block + pass + entity offset) % refresh_passes() == 0): the pass number
     * moves by one each pass, so every unit comes due once in refresh_passes() passes
     * whatever the schedule; the per-entity offset spreads small tables (all in block 0)
     * over the days instead of re-sending them all on the same pass.
     *
     * @param string $entity
     * @param int $lo First block.
     * @param int $hi End block (exclusive).
     * @param int|null $pass Pass number (sweep `passgen`); null = no periodic re-send.
     * @return string Packed entries in block order.
     */
    public static function trusted_range(string $entity, int $lo, int $hi, ?int $pass = null): string {
        $fp = self::fingerprint();
        $packed = self::load_range($fp, $entity, self::COMMITTED, $lo, $hi);
        if ($packed === '') {
            return '';
        }
        $packed = self::minus_suspect($packed, self::load_range($fp, $entity, self::SUSPECT, $lo, $hi));
        $every = self::refresh_passes();
        if ($pass === null || $every === 0) {
            return $packed;
        }
        $out = '';
        $n = self::entries($packed);
        $offset = self::refresh_offset($entity, $every);
        for ($i = 0; $i < $n; $i++) {
            if ((self::read($packed, $i)[0] + $pass + $offset) % $every !== 0) {
                $out .= substr($packed, $i * self::ENTRY_BYTES, self::ENTRY_BYTES);
            }
        }
        return $out;
    }

    /**
     * The entity's fixed offset in the periodic re-send cycle.
     *
     * @param string $entity
     * @param int $every refresh_passes(), > 0.
     * @return int
     */
    public static function refresh_offset(string $entity, int $every): int {
        return (int)(sprintf('%u', crc32($entity)) % $every);
    }

    /**
     * Committed entries, less those a suspect entry of the same block contradicts.
     *
     * @param string $packed
     * @param string $suspect
     * @return string
     */
    private static function minus_suspect(string $packed, string $suspect): string {
        $sn = self::entries($suspect);
        if ($sn === 0) {
            return $packed;
        }
        $out = '';
        $si = 0;
        $n = self::entries($packed);
        for ($i = 0; $i < $n; $i++) {
            [$block, $hash] = self::read($packed, $i);
            while ($si < $sn && self::read($suspect, $si)[0] < $block) {
                $si++;
            }
            if ($si < $sn && self::read($suspect, $si)[0] === $block && self::read($suspect, $si)[1] !== $hash) {
                continue;
            }
            $out .= substr($packed, $i * self::ENTRY_BYTES, self::ENTRY_BYTES);
        }
        return $out;
    }

    /**
     * Append entries to the entity's pending set, as rows of at most PART_BLOCKS keyed by
     * their first block. Anything already staged from that block on is replaced first, so
     * a window redone after a restart does not stage twice. Runs inside the caller's
     * transaction (with the sweep position).
     *
     * @param string $entity
     * @param string $packed Entries in block order, none below those already kept.
     * @param int $passstart
     * @return void
     */
    public static function stage(string $entity, string $packed, int $passstart): void {
        global $DB;
        $n = self::entries($packed);
        if ($n === 0) {
            return;
        }
        $fp = self::fingerprint();
        $first = self::read($packed, 0)[0];
        // Its own transaction (nested in the sweep's, where it has one): the delete, the
        // trim and the inserts land together, so a crash never leaves the set unsorted.
        $tx = $DB->start_delegated_transaction();
        try {
            $DB->delete_records_select(self::TABLE, 'fingerprint = :fp AND entity = :e AND kind = :k AND part >= :p', [
                'fp' => $fp, 'e' => $entity, 'k' => self::PENDING, 'p' => $first,
            ]);
            // The row before may reach into this range (a window redone from a later first id).
            $prev = $DB->get_records_select(
                self::TABLE,
                'fingerprint = :fp AND entity = :e AND kind = :k AND part < :p',
                ['fp' => $fp, 'e' => $entity, 'k' => self::PENDING, 'p' => $first],
                'part DESC',
                'id, blocks',
                0,
                1
            );
            foreach ($prev as $row) {
                $bin = (string)base64_decode((string)$row->blocks, true);
                $keep = self::lower_bound($bin, $first);
                if ($keep < self::entries($bin)) {
                    $kept = base64_encode(substr($bin, 0, $keep * self::ENTRY_BYTES));
                    $DB->set_field(self::TABLE, 'blocks', $kept, ['id' => $row->id]);
                }
            }
            foreach (str_split($packed, self::PART_BLOCKS * self::ENTRY_BYTES) as $chunk) {
                self::insert_row($fp, $entity, self::PENDING, $chunk, $passstart);
            }
            $tx->allow_commit();
        } catch (\Throwable $e) {
            $tx->rollback($e);   // Rethrows: the caller decides what a failed stage means.
        }
    }

    /**
     * One packed entry.
     *
     * @param int $block
     * @param string $hash Raw hash bytes (truncated to HASH_BYTES).
     * @return string
     */
    public static function entry(int $block, string $hash): string {
        return pack('N', $block) . str_pad(substr($hash, 0, self::HASH_BYTES), self::HASH_BYTES, "\0");
    }

    /**
     * Entry $i of a packed string.
     *
     * @param string $packed
     * @param int $i
     * @return array{0:int, 1:string} [block, hash]
     */
    public static function read(string $packed, int $i): array {
        $off = $i * self::ENTRY_BYTES;
        return [unpack('N', substr($packed, $off, 4))[1], substr($packed, $off + 4, self::HASH_BYTES)];
    }

    /**
     * Index of the first entry whose block is at or above $block (entries() if none).
     *
     * @param string $packed Entries in block order.
     * @param int $block
     * @return int
     */
    public static function lower_bound(string $packed, int $block): int {
        $lo = 0;
        $hi = self::entries($packed);
        while ($lo < $hi) {
            $mid = ($lo + $hi) >> 1;
            if (unpack('N', substr($packed, $mid * self::ENTRY_BYTES, 4))[1] < $block) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Number of entries in a packed string.
     *
     * @param string $packed
     * @return int
     */
    public static function entries(string $packed): int {
        return intdiv(strlen($packed), self::ENTRY_BYTES);
    }

    /**
     * Drop one entity's fingerprints (its next pass sends everything).
     *
     * @param string $entity
     * @return void
     */
    public static function clear_entity(string $entity): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['entity' => $entity]);
    }

    /**
     * Drop one entity's fingerprints, or every entity's, on request from the control
     * plane (the reset_fingerprints command): the repair for records lost after they
     * reached object storage. The next pass of each entity sends it in full. Prints
     * nothing, so it is safe inside a web request.
     *
     * @param string $entity Registry key; '' = every entity.
     * @return int Stored rows removed.
     */
    public static function reset(string $entity = ''): int {
        global $DB;
        // Recorded first: a sweep window running right now read its trusted entries
        // before this delete and stages them again, so settle() discards any pass that
        // began before the reset (reset_time()). No entity lock needed.
        $marks = json_decode((string)config::get(self::RESET_KEY, ''), true);
        $marks = is_array($marks) ? $marks : [];
        $marks[$entity === '' ? '*' : $entity] = time();
        set_config(self::RESET_KEY, json_encode($marks), config::COMPONENT);
        $where = $entity === '' ? [] : ['entity' => $entity];
        $n = $DB->count_records(self::TABLE, $where);
        $DB->delete_records(self::TABLE, $where);
        return $n;
    }

    /**
     * When the entity's fingerprints were last reset on request (reset()), or 0.
     *
     * @param string $entity
     * @return int
     */
    private static function reset_time(string $entity): int {
        $marks = json_decode((string)config::get(self::RESET_KEY, ''), true);
        if (!is_array($marks)) {
            return 0;
        }
        return max((int)($marks[$entity] ?? 0), (int)($marks['*'] ?? 0));
    }

    /**
     * Drop every fingerprint. Callers: purge, privacy erasure.
     *
     * @param string $reason Logged.
     * @return void
     */
    public static function clear_all(string $reason): void {
        global $DB;
        try {
            if (!$DB->record_exists(self::TABLE, [])) {
                return;
            }
            $DB->delete_records(self::TABLE);
        } catch (\Throwable $e) {
            return; // Table absent (mid-upgrade / uninstall): nothing to clear.
        }
        // Reachable on a web request (uninstall, a privacy erasure, the webhook): see buffer::trace().
        buffer::trace("local_intellistream: fingerprints cleared ({$reason}); every entity's next pass sends everything.");
    }

    /**
     * Entities whose last pass could not be confirmed delivered (a suspect set is kept):
     * the sign of a site whose shipping is not getting through.
     *
     * @return int
     */
    public static function unconfirmed(): int {
        global $DB;
        try {
            return (int)$DB->count_records_sql(
                'SELECT COUNT(DISTINCT entity) FROM {' . self::TABLE . '} WHERE kind = :k',
                ['k' => self::SUSPECT]
            );
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Whether any fingerprint is stored, i.e. whether loss markers are worth writing.
     *
     * @return bool
     */
    public static function armed(): bool {
        global $DB;
        try {
            return $DB->record_exists(self::TABLE, []);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The first thing since $since that could have lost a record after the buffer
     * accepted it, or null.
     *
     * Checked over [pass start, now] on purpose: a file can be removed after the pass
     * finished writing and before the entity's next pass settles it.
     *
     * `last_drop_time` is written by no current code: the shipper no longer evicts
     * undelivered files at the disk cap. It is still honoured, because a site that
     * upgraded straight after an earlier release evicted can hold a pending pass
     * whose files were among the ones deleted.
     *
     * A refused record is not a loss here: it was never delivered, so the block it
     * belongs to fingerprints differently next pass and is sent again.
     *
     * @param int $since Unix seconds; 0 means nothing to invalidate.
     * @return string|null
     */
    public static function loss_since(int $since): ?string {
        if ($since <= 0) {
            return null;
        }
        if ((int)config::get('last_drop_time', 0) >= $since) {
            return 'the buffer evicted un-shipped files at its disk cap';
        }
        if ((int)config::get('last_erasure_time', 0) >= $since) {
            return 'a privacy erasure or purge removed buffered records';
        }
        // Written only by a pull on a site that also ships to object storage (decided when
        // the pull happens, see pull_export::note_pull()).
        if ((int)config::get('last_pull_time', 0) >= $since - self::PULL_NOTE_SEC) {
            return 'the pull export delivered (and deleted) buffered records';
        }
        return null;
    }

    /**
     * One entity's stored entries of a kind for blocks [$lo, $hi), in block order: the
     * row holding $lo (the last row starting at or below it) and every row starting
     * below $hi, read one at a time.
     *
     * @param string $fp
     * @param string $entity
     * @param string $kind
     * @param int $lo
     * @param int $hi Exclusive.
     * @return string
     */
    private static function load_range(string $fp, string $entity, string $kind, int $lo, int $hi): string {
        global $DB;
        $params = ['fp' => $fp, 'e' => $entity, 'k' => $kind, 'lo' => $lo];
        $first = $DB->get_field_sql(
            'SELECT MAX(part) FROM {' . self::TABLE . '} WHERE fingerprint = :fp AND entity = :e AND kind = :k AND part <= :lo',
            $params
        );
        $params['from'] = ($first === null || $first === false) ? $lo : (int)$first;
        $params['hi'] = $hi;
        $out = '';
        $rs = $DB->get_recordset_select(
            self::TABLE,
            'fingerprint = :fp AND entity = :e AND kind = :k AND part >= :from AND part < :hi',
            $params,
            'part ASC',
            'id, blocks'
        );
        try {
            foreach ($rs as $row) {
                $bin = base64_decode((string)$row->blocks, true);
                if ($bin !== false) {
                    $out .= substr($bin, 0, self::entries($bin) * self::ENTRY_BYTES);
                }
            }
        } finally {
            $rs->close();
        }
        $a = self::lower_bound($out, $lo);
        return substr($out, $a * self::ENTRY_BYTES, (self::lower_bound($out, $hi) - $a) * self::ENTRY_BYTES);
    }

    /**
     * Store one row of entries (at most PART_BLOCKS), keyed by its first block.
     *
     * @param string $fp
     * @param string $entity
     * @param string $kind
     * @param string $packed Non-empty, block order.
     * @param int $passstart
     * @return void
     */
    private static function insert_row(string $fp, string $entity, string $kind, string $packed, int $passstart): void {
        global $DB;
        $DB->insert_record(self::TABLE, (object)[
            'fingerprint' => $fp,
            'entity' => $entity,
            'kind' => $kind,
            'part' => self::read($packed, 0)[0],
            'passstart' => $passstart,
            'blocks' => base64_encode($packed),
            'timemodified' => time(),
        ]);
    }

    /**
     * Start of the oldest unconfirmed pass for an entity (pending or suspect).
     *
     * @param string $fp
     * @param string $entity
     * @param int $fallback
     * @return int
     */
    private static function oldest_start(string $fp, string $entity, int $fallback): int {
        global $DB;
        $min = $DB->get_field_sql(
            'SELECT MIN(passstart) FROM {' . self::TABLE . '}
            WHERE fingerprint = :fp AND entity = :e AND kind IN (:p, :s)',
            ['fp' => $fp, 'e' => $entity, 'p' => self::PENDING, 's' => self::SUSPECT]
        );
        $min = (int)$min;
        if ($min <= 0) {
            return $fallback;
        }
        return $fallback > 0 ? min($min, $fallback) : $min;
    }

    /**
     * The previous pass could not be confirmed: fold what it saw change into the
     * suspect set, then drop its pending set.
     *
     * Walks the committed set one stored row at a time and reads only the pending and
     * suspect entries of that row's block range, so memory stays at a few rows. The new
     * suspect set is written as FOLDING rows and swapped in at the end, in one transaction.
     *
     * @param string $fp
     * @param string $entity
     * @return void
     */
    private static function suspect_pending(string $fp, string $entity): void {
        global $DB;
        $start = self::oldest_start($fp, $entity, 0);
        $key = ['fingerprint' => $fp, 'entity' => $entity];
        $tx = $DB->start_delegated_transaction();
        try {
            $DB->delete_records(self::TABLE, $key + ['kind' => self::FOLDING]);
            // One committed row at a time, fetched by its key: a recordset over all of them is
            // buffered whole by some drivers (mysqli), which is the memory this avoids.
            $parts = $DB->get_fieldset_sql(
                'SELECT part FROM {' . self::TABLE . '} WHERE fingerprint = :fp AND entity = :e AND kind = :k ORDER BY part',
                ['fp' => $fp, 'e' => $entity, 'k' => self::COMMITTED]
            );
            foreach ($parts as $part) {
                $blocks = $DB->get_field(self::TABLE, 'blocks', $key + ['kind' => self::COMMITTED, 'part' => $part]);
                $committed = base64_decode((string)$blocks, true);
                $cn = $committed === false ? 0 : self::entries($committed);
                if ($cn === 0) {
                    continue;
                }
                $lo = self::read($committed, 0)[0];
                $hi = self::read($committed, $cn - 1)[0] + 1;
                $merged = self::merge_suspect(
                    substr($committed, 0, $cn * self::ENTRY_BYTES),
                    self::load_range($fp, $entity, self::PENDING, $lo, $hi),
                    self::load_range($fp, $entity, self::SUSPECT, $lo, $hi)
                );
                if ($merged !== '') {
                    self::insert_row($fp, $entity, self::FOLDING, $merged, $start);
                }
            }
            $DB->delete_records(self::TABLE, $key + ['kind' => self::SUSPECT]);
            $DB->delete_records(self::TABLE, $key + ['kind' => self::PENDING]);
            $DB->set_field(self::TABLE, 'kind', self::SUSPECT, $key + ['kind' => self::FOLDING]);
            $tx->allow_commit();
        } catch (\Throwable $e) {
            $tx->rollback($e);
        }
    }

    /**
     * The suspect set after folding in one more unconfirmed pass: for every committed
     * block, keep an entry whenever an unconfirmed pass saw it differ.
     *
     * @param string $committed
     * @param string $pending
     * @param string $suspect
     * @return string
     */
    private static function merge_suspect(string $committed, string $pending, string $suspect): string {
        $cn = self::entries($committed);
        $pn = self::entries($pending);
        $sn = self::entries($suspect);
        $pi = 0;
        $si = 0;
        $out = '';
        for ($ci = 0; $ci < $cn; $ci++) {
            [$block, $chash] = self::read($committed, $ci);
            while ($pi < $pn && self::read($pending, $pi)[0] < $block) {
                $pi++;
            }
            while ($si < $sn && self::read($suspect, $si)[0] < $block) {
                $si++;
            }
            if ($pi < $pn && self::read($pending, $pi)[0] === $block && self::read($pending, $pi)[1] !== $chash) {
                $out .= substr($pending, $pi * self::ENTRY_BYTES, self::ENTRY_BYTES);
            } else if ($si < $sn && self::read($suspect, $si)[0] === $block) {
                $out .= substr($suspect, $si * self::ENTRY_BYTES, self::ENTRY_BYTES);
            }
        }
        return $out;
    }
}
