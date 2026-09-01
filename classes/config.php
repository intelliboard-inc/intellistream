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
 * Config accessors for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Typed accessors over the plugin's mdl_config_plugins entries.
 *
 * get_config() reads from Moodle's config cache after first load, so these
 * accessors do not hit the database on the hot path.
 */
class config {
    /** Plugin component / config-plugin name. */
    const COMPONENT = 'local_intellistream';

    /**
     * Every setting whose declared default in settings.php is not empty.
     *
     * Core applies a plugin's declared defaults only during a CORE install:
     * admin_apply_default_settings() is called from the installer, and the
     * web-UI plugin installer never calls it. So a site that installs this
     * plugin into an existing Moodle and configures it over the control webhook
     * — writing individual keys, never saving the settings page — ends up with
     * no stored value for any of these, while its own settings page displays the
     * declared default. `enabled` is the sharp end of that: the page shows the
     * master switch on while config::enabled() reads its 0 fallback and captures
     * nothing.
     *
     * Seeded by db/install.php on a fresh install and, on an existing one, by the
     * 2026080306 upgrade block and again by 2026082000 for keys added since. Both
     * only ever write a key that is absent, so a value an administrator or the
     * control plane chose is never touched.
     *
     * `bufferdir` is deliberately absent: its default is computed per site and
     * buffer_dir() already resolves it, so storing a path here would freeze one.
     *
     * @var array<string, mixed>
     */
    const DECLARED_DEFAULTS = [
        'enabled'                    => 1,
        'region'                     => 'us-ashburn-1',
        'prefix'                     => 'events',
        'webhook_require_https'      => 1,
        'rotatesizemb'               => 64,
        'rotateagesec'               => 120,
        'maxbuffergb'                => 5,
        'loadgatefactor'             => 0.7,
        'dwellenabled'               => 1,
        'dwellmaxms'                 => 14400000,
        'dwellmaxperminute'          => 60,
        'trackadmin'                 => 1,
        'trackmediabucketsec'        => 30,
        'exportbatchsize'            => 500,
        'dynamic_discovery_prefixes' => 'local_intellicart_',
        'ltipagelayout'              => 'base',
        'custommenuitem'             => 0,
    ];

    /**
     * Write every declared default that has no stored value yet.
     *
     * @return string[] Names of the settings actually seeded.
     */
    public static function seed_declared_defaults(): array {
        $seeded = [];
        foreach (self::DECLARED_DEFAULTS as $name => $value) {
            if (get_config(self::COMPONENT, $name) === false) {
                set_config($name, $value, self::COMPONENT);
                $seeded[] = $name;
            }
        }
        return $seeded;
    }

    /**
     * Raw config getter with a default.
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(string $key, $default = null) {
        $val = get_config(self::COMPONENT, $key);
        return ($val === false || $val === null) ? $default : $val;
    }

    /**
     * Master switch.
     *
     * @return bool
     */
    public static function enabled(): bool {
        return (bool)(int)self::get('enabled', 0);
    }

    /**
     * Root of the plugin's own subtree inside moodledata, no trailing slash.
     *
     * Single source of truth: the default buffer path, the uninstall purge
     * (see buffer::purge_all) and settings.php all derive from this, so the
     * literal appears exactly once.
     *
     * @return string
     */
    public static function buffer_root(): string {
        global $CFG;
        // Moodledata is always inside PHP's open_basedir and writable by the
        // web user; /var/lib/* is root-owned and outside open_basedir on many
        // managed hosts, where capture then fails silently.
        return rtrim($CFG->dataroot, '/') . '/intellistream';
    }

    /**
     * Default buffer directory, no trailing slash.
     *
     * @return string
     */
    public static function default_buffer_dir(): string {
        return self::buffer_root() . '/buffer';
    }

    /**
     * Canonical form of an absolute path, for safe prefix comparison.
     *
     * Two passes:
     *  - lexical — collapse duplicate slashes and drop '.' segments, so
     *    `<dataroot>//filedir` and `<dataroot>/./filedir` reduce to the same
     *    string as `<dataroot>/filedir`. ('..' is rejected by the caller
     *    before this runs, so it is not resolved here.)
     *  - physical — realpath() the deepest part of the path that exists, so a
     *    symlink planted inside moodledata cannot point the buffer somewhere
     *    the string comparison would consider contained. The buffer directory
     *    itself usually does not exist yet on a fresh site, hence resolving
     *    the nearest existing ancestor rather than the whole path.
     *
     * @param string $path Absolute path, no trailing slash.
     * @return string Canonical absolute path, no trailing slash.
     */
    private static function canonical_path(string $path): string {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            $segments[] = $segment;
        }
        $path = '/' . implode('/', $segments);

        $unresolved = [];
        $probe = $path;
        while ($probe !== '' && $probe !== '/') {
            $real = realpath($probe);
            if ($real !== false) {
                $real = rtrim($real, '/');
                return $unresolved ? $real . '/' . implode('/', array_reverse($unresolved)) : $real;
            }
            $unresolved[] = basename($probe);
            $parent = dirname($probe);
            if ($parent === $probe) {
                break;
            }
            $probe = $parent;
        }

        return $path;
    }

    /**
     * Reject a `bufferdir` value that must never hold the buffer.
     *
     * Shared by the admin setting's save-time validate() and by buffer_dir()'s
     * read-time guard, so a value written straight to mdl_config_plugins (or
     * stored before this validation existed) is rejected just the same.
     *
     * Beyond the original containment check this also refuses the
     * dataroot itself and Moodle's own directories inside it. The configurable
     * ones are read from $CFG rather than hardcoded; only core's fixed dataroot
     * children that have no $CFG equivalent are named.
     *
     * @param string $dir Candidate absolute path (trailing slash optional).
     * @return string Empty when acceptable, else an untranslated reason key.
     */
    public static function buffer_dir_problem(string $dir): string {
        global $CFG;

        $dir = rtrim(trim($dir), '/');
        if ($dir === '') {
            // Empty means "use the default", which is always acceptable.
            return '';
        }
        if (strpos($dir, '..') !== false) {
            return 'bufferdir_err_traversal';
        }
        if (strpos($dir, '/') !== 0) {
            return 'bufferdir_err_notabsolute';
        }

        // Canonicalise BOTH sides before comparing. Without this, `//filedir`,
        // `/./filedir` and friends are byte-different from the reserved path
        // yet resolve to it, so they would slip past the prefix checks below
        // and put the buffer inside the file store.
        $dir = self::canonical_path($dir);
        $base = self::canonical_path(rtrim($CFG->dataroot, '/'));
        if ($dir === $base) {
            return 'bufferdir_err_isdataroot';
        }
        if (strpos($dir . '/', $base . '/') !== 0) {
            return 'bufferdir_err_outsidedataroot';
        }

        // Core-owned directories under the dataroot. The first four are
        // relocatable via config.php, so read them from $CFG (lib/setup.php);
        // the rest are core's fixed layout and have no $CFG accessor.
        $reserved = [];
        foreach (['tempdir', 'cachedir', 'localcachedir', 'backuptempdir'] as $key) {
            if (!empty($CFG->$key)) {
                $reserved[] = self::canonical_path(rtrim($CFG->$key, '/'));
            }
        }
        $fixed = [
            'filedir', 'trashdir', 'sessions', 'muc', 'models', 'lang',
            'antivirus_quarantine', 'secret', 'temp', 'cache', 'localcache',
        ];
        foreach ($fixed as $name) {
            $reserved[] = $base . '/' . $name;
        }
        foreach (array_unique($reserved) as $core) {
            // Reject the core directory itself and anything beneath it.
            if ($dir === $core || strpos($dir . '/', $core . '/') === 0) {
                return 'bufferdir_err_coredir';
            }
        }

        return '';
    }

    /**
     * Buffer directory, no trailing slash.
     *
     * The `bufferdir` setting is admin-editable, so an unusable or destructive
     * value must never reach the filesystem: anything buffer_dir_problem()
     * rejects falls back to the default.
     *
     * @return string
     */
    public static function buffer_dir(): string {
        $default = self::default_buffer_dir();
        $dir = rtrim(trim((string)self::get('bufferdir', $default)), '/');
        if ($dir === '' || self::buffer_dir_problem($dir) !== '') {
            return $default;
        }
        // Return the same canonical form the guard just approved, so the path
        // that gets written to is the path that was actually validated.
        return self::canonical_path($dir);
    }

    /**
     * Config key listing the buffer directories this plugin has written into.
     *
     * A JSON array of canonical absolute paths. Deliberately NOT a setting: it
     * is state the plugin maintains about its own filesystem history, there is
     * nothing for an administrator to choose, and keeping it out of
     * settings.php is also what makes it unwritable over the control webhook —
     * plugin_report::is_writable() answers from the settings.php definitions,
     * so a key that is not one of them is refused. That matters here more than
     * it looks: the shipper READS the directories named in this value and sends
     * their contents to object storage, so a remotely writable version of it
     * would be an arbitrary-directory exfiltration primitive.
     */
    const BUFFER_DIRS_KEY = 'bufferdirsused';

    /**
     * Most buffer directories remembered at once.
     *
     * Reached only by a site that has relocated its buffer this many times
     * while shipping was broken throughout — every entry is dropped again as
     * soon as it has been drained. The bound exists because this value is read
     * on the capture path, so it must not be allowed to grow without limit.
     */
    const MAX_BUFFER_DIRS = 8;

    /**
     * Every directory that may hold this plugin's buffer files.
     *
     * The current directory first, then any earlier one still on record. This
     * exists because `bufferdir` is admin-editable and buffer_dir() also falls
     * back to the default whenever the stored value stops validating, so the
     * effective directory can change under a site that is holding un-shipped
     * records. Before this, every consumer resolved buffer_dir() alone, and
     * the moment it changed the previous directory became invisible: its
     * records never shipped, a privacy erasure request did not reach them, and
     * uninstall left them on disk. The status page went on reporting `ok`,
     * because it was looking at the new directory.
     *
     * Each stored path is re-validated with buffer_dir_problem() rather than
     * trusted: a value written straight to mdl_config_plugins, or one that was
     * acceptable under an earlier version of the rules, must not be able to point
     * the plugin at a core directory whose contents this method's callers would
     * then read and send OFF THE HOST — the shipper to object storage, the pull
     * web service to its caller. That is the one thing the filter is for.
     *
     * A rejected entry is NOT dropped from tracking, only from THIS list. Dropping
     * it would strand any of our files still in it: {@see tracked_buffer_dirs()},
     * which the privacy and uninstall paths use, keeps reaching them, and the
     * shipper forgets the entry once it is empty. So use this method only for the
     * off-host read paths; use tracked_buffer_dirs() for anything that erases,
     * counts or purges local files.
     *
     * @return string[] Canonical absolute paths, current directory first, no duplicates.
     */
    public static function buffer_dirs(): array {
        $dirs = [self::buffer_dir()];
        foreach (self::stored_buffer_dirs() as $dir) {
            if (self::buffer_dir_problem($dir) !== '') {
                continue;
            }
            $dir = self::canonical_path(rtrim($dir, '/'));
            if (!in_array($dir, $dirs, true)) {
                $dirs[] = $dir;
            }
        }
        return $dirs;
    }

    /**
     * Directories on record other than the one in use, whether or not they exist.
     *
     * @return string[]
     */
    public static function previous_buffer_dirs(): array {
        $dirs = self::buffer_dirs();
        array_shift($dirs);
        return $dirs;
    }

    /**
     * Every directory that may PHYSICALLY hold this plugin's files, valid or not.
     *
     * The difference from buffer_dirs() is the validity filter, and it matters in
     * exactly the case buffer_dirs()'s filter creates: a path that was valid when
     * a file was written into it but no longer validates — because the site moved
     * its moodledata (every earlier path is now "outside dataroot") or a later
     * version tightened the rules (the reserved-directory rule did this once
     * already). buffer_dirs() drops such an entry so nothing reads it and ships it
     * away; but the files are still ours and still hold personal data, so the
     * local operations that must never miss them — privacy export and erasure via
     * own_files(), the uninstall purge, and the shipper's own prune — enumerate
     * from here instead.
     *
     * Reading these directories locally is safe even when one is now, say, a core
     * directory: every reader goes through safe_files()/safe_open(), which match
     * only this plugin's own file names and refuse anything that is not a plain,
     * single-linked file, and purge_all() only ever unlinks those same names. The
     * filter guards SENDING content off the host, which none of these callers do.
     *
     * @return string[] Canonical absolute paths, current directory first, no duplicates.
     */
    public static function tracked_buffer_dirs(): array {
        $dirs = [self::buffer_dir()];
        foreach (self::stored_buffer_dirs() as $dir) {
            $dir = self::canonical_path(rtrim($dir, '/'));
            if (!in_array($dir, $dirs, true)) {
                $dirs[] = $dir;
            }
        }
        return $dirs;
    }

    /**
     * Tracked directories other than the one in use, valid or not.
     *
     * @return string[]
     */
    public static function previous_tracked_dirs(): array {
        $dirs = self::tracked_buffer_dirs();
        array_shift($dirs);
        return $dirs;
    }

    /**
     * Record that a buffer file has been created in this directory.
     *
     * Called from the write path rather than from the settings page on purpose.
     * A save-time hook would see an administrator editing the field and nothing
     * else — not a value written by set_config(), not the upgrade step that
     * clears an invalid one, and not a change in $CFG that makes a stored value
     * stop validating. All of those move the buffer just as effectively. Noting
     * the directory at the moment a file is actually created in it covers every
     * route by construction, and never records a directory that has nothing in
     * it.
     *
     * Writes only when the set changes, because set_config() purges the plugin
     * config cache that every page render reads. In steady state that is one
     * comparison against a cached value and no write at all.
     *
     * @param string $dir Canonical absolute path, as returned by buffer_dir().
     * @return void
     */
    public static function note_buffer_dir(string $dir): void {
        if ($dir === '' || self::buffer_dir_problem($dir) !== '') {
            return;
        }
        $stored = self::stored_buffer_dirs();
        if (in_array($dir, $stored, true)) {
            return;
        }
        if (count($stored) >= self::MAX_BUFFER_DIRS) {
            // Refuse the newest rather than evict the oldest. The oldest entries
            // hold the records that have been undelivered longest, so dropping one
            // to make room would strand exactly the data most at risk. Refusing
            // costs nothing yet: this directory is the CURRENT one, which
            // buffer_dirs() always returns regardless of what is stored here, so it
            // stays covered until the buffer is relocated again.
            debugging('local_intellistream: not tracking buffer dir "' . $dir . '" — already tracking '
                . count($stored) . ' previous buffer directories, which means earlier ones have never '
                . 'been drained. Check the plugin status page.', DEBUG_NORMAL);
            return;
        }
        $stored[] = $dir;
        set_config(self::BUFFER_DIRS_KEY, json_encode($stored), self::COMPONENT);
    }

    /**
     * Stop tracking a directory, once nothing of ours is left in it.
     *
     * @param string $dir Canonical absolute path.
     * @return void
     */
    public static function forget_buffer_dir(string $dir): void {
        $stored = self::stored_buffer_dirs();
        $kept = array_values(array_filter($stored, function ($path) use ($dir) {
            return $path !== $dir;
        }));
        if (count($kept) === count($stored)) {
            return;
        }
        if ($kept === []) {
            unset_config(self::BUFFER_DIRS_KEY, self::COMPONENT);
            return;
        }
        set_config(self::BUFFER_DIRS_KEY, json_encode($kept), self::COMPONENT);
    }

    /**
     * The raw stored list, decoded defensively.
     *
     * Anything that is not a JSON array of non-empty strings is treated as an
     * empty list: this value is written by the plugin alone, so a malformed one
     * means it has been tampered with or corrupted, and the safe reading is
     * "nothing on record" rather than a partially trusted list.
     *
     * @return string[]
     */
    private static function stored_buffer_dirs(): array {
        $raw = json_decode((string)self::get(self::BUFFER_DIRS_KEY, ''), true);
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $dir) {
            if (is_string($dir) && $dir !== '' && !in_array($dir, $out, true)) {
                $out[] = $dir;
            }
        }
        return array_slice($out, 0, self::MAX_BUFFER_DIRS);
    }

    /**
     * Active-file size rotation threshold, in bytes.
     *
     * @return int
     */
    public static function rotate_size_bytes(): int {
        return max(1, (int)self::get('rotatesizemb', 64)) * 1024 * 1024;
    }

    /**
     * Active-file age rotation threshold, in seconds.
     *
     * Default 120s: a worker's buffer file is closed (and so becomes
     * shippable) within ~2 minutes of its first write even if it never
     * reaches the size threshold, which is what keeps the warehouse
     * near-real-time. Pair with shipper::SHIP_IDLE_SEC, which closes the
     * file of a worker that has gone fully idle.
     *
     * @return int
     */
    public static function rotate_age_sec(): int {
        return max(1, (int)self::get('rotateagesec', 120));
    }

    /**
     * Total buffer directory cap, in bytes.
     *
     * @return int
     */
    public static function max_buffer_bytes(): int {
        return max(1, (int)self::get('maxbuffergb', 5)) * 1024 * 1024 * 1024;
    }

    /**
     * Load gate factor.
     *
     * @return float
     */
    public static function load_gate_factor(): float {
        return (float)self::get('loadgatefactor', 0.7);
    }

    /**
     * Stable site UUID. Generated at install; lazily generated here as a
     * fallback for installs that predate the install hook.
     *
     * @return string
     */
    public static function site_id(): string {
        // The control plane (supernova) MINTS the site id; the operator pastes it into the plugin
        // settings (Site administration -> Plugins -> Local -> IntelliStream -> Site ID). We no longer
        // auto-generate: an empty site id means "not paired yet" and the shipper holds events in
        // the buffer until it is set. Existing installs keep their stored value unchanged.
        return (string)self::get('siteid', '');
    }

    /**
     * Plugin version code (from version.php, cached in config).
     *
     * @return string
     */
    public static function plugin_version(): string {
        return (string)self::get('version', '0');
    }

    /**
     * Whether page-dwell / time-on-task capture is active.
     *
     * Independently gated on top of the master switch: dwell capture adds a
     * footer hook and an AJAX endpoint, so an operator can disable it while
     * leaving event capture and shipping running.
     *
     * @return bool
     */
    public static function dwell_enabled(): bool {
        return self::enabled() && (bool)(int)self::get('dwellenabled', 1);
    }

    /**
     * Whether events emitted by site-admin users should be captured.
     *
     * Default 1 (capture admin events). Many customers want to exclude admin
     * activity from analytics so internal test/configuration clicks don't
     * pollute usage stats. Observer reads this and short-circuits before
     * appending to the buffer.
     *
     * @return bool
     */
    public static function trackadmin_enabled(): bool {
        return (bool)(int)self::get('trackadmin', 1);
    }

    /**
     * Whether per-segment media tracking is active.
     *
     * Default 0 (off). When enabled the dwell.js AMD module attaches
     * timeupdate listeners to <video>/<audio> elements and emits
     * `media_segment` records via sendBeacon → dwell.php, one record per
     * configured segment bucket.
     *
     * @return bool
     */
    public static function trackmedia_enabled(): bool {
        return self::enabled() && (bool)(int)self::get('trackmedia', 0);
    }

    /**
     * Media-segment bucket size in seconds (default 30).
     *
     * Determines how often dwell.js emits a media_segment record while a
     * media element is playing. Smaller = finer granularity but more
     * beacons; larger = coarser stats but lighter load.
     *
     * @return int
     */
    public static function trackmedia_bucket_sec(): int {
        return max(5, (int)self::get('trackmediabucketsec', 30));
    }

    /**
     * Maximum plausible page-dwell time, in milliseconds. Beacons reporting
     * more than this are clamped (a tab left open for days is not signal).
     *
     * @return int
     */
    public static function dwell_max_ms(): int {
        return max(1000, (int)self::get('dwellmaxms', 4 * 60 * 60 * 1000));
    }

    /**
     * Maximum dwell beacons accepted from ONE user per minute.
     *
     * A legitimate client beacons on pagehide / tab-hide, plus one per media
     * bucket, so single digits per minute is the normal rate and the default of 60
     * is far above any real session. It exists to stop an authenticated user
     * driving unbounded growth of the shared buffer, which the disk cap answers by
     * evicting other users' unshipped events.
     *
     * 0 disables the quota, for an operator who needs to rule it out while
     * diagnosing missing dwell data.
     *
     * @return int
     */
    public static function dwell_max_per_minute(): int {
        return max(0, (int)self::get('dwellmaxperminute', 60));
    }

    /**
     * Exception records captured per remote address per minute; 0 disables.
     *
     * The site-wide ceiling is derived from this — see
     * exception_quota::GLOBAL_FACTOR — so one setting governs both windows.
     *
     * @return int
     */
    public static function exception_max_per_minute(): int {
        return max(0, (int)self::get('exceptionmaxperminute', 30));
    }

    /**
     * Rows read (and buffered) per recordset chunk by the entity exporter.
     *
     * @return int
     */
    public static function export_batch_size(): int {
        return max(1, (int)self::get('exportbatchsize', 500));
    }

    // S3 / object storage.

    /**
     * Object-storage endpoint URL. Empty until an operator configures it, which
     * is what keeps a fresh install from attempting to ship anywhere.
     *
     * @return string
     */
    public static function endpoint(): string {
        return (string)self::get('endpoint', '');
    }

    /**
     * Destination bucket name.
     *
     * @return string
     */
    public static function bucket(): string {
        return (string)self::get('bucket', '');
    }

    /**
     * Access key id for the ingest credential. Rotated by the control plane
     * over the signed webhook, never read back into a page.
     *
     * @return string
     */
    public static function access_key(): string {
        return (string)self::get('accesskey', '');
    }

    /**
     * Secret key for the ingest credential. Masked in the admin UI and redacted
     * from the config snapshot the control plane reads.
     *
     * @return string
     */
    public static function secret_key(): string {
        return (string)self::get('secretkey', '');
    }

    /**
     * Region used to sign the request. Defaults to the OCI home region, which
     * is where the shared tenancy lives.
     *
     * @return string
     */
    public static function region(): string {
        return (string)self::get('region', 'us-ashburn-1');
    }

    /**
     * Key prefix every shipped object is written under.
     *
     * @return string
     */
    public static function prefix(): string {
        // A blank setting must fall back to the default, not '' — otherwise the
        // object key becomes /<site_id>/... and the middleware (which scans the
        // 'events/' prefix) never sees the shipped files.
        $p = trim((string)self::get('prefix', 'events'), '/');
        return $p === '' ? 'events' : $p;
    }

    /**
     * Allow a non-https S3 endpoint. Off by default: PII is
     * shipped in the body, so plaintext http is only permitted when an admin
     * explicitly opts in for a trusted internal endpoint (e.g. self-hosted
     * MinIO on a private network).
     *
     * @return bool
     */
    public static function allow_insecure_endpoint(): bool {
        return (bool)(int)self::get('allowinsecureendpoint', 0);
    }

    /**
     * Bypass Moodle's curl security helper for the S3 ship.
     * Off by default. Only needed when the site has configured
     * $CFG->curlsecurityblockedhosts to block private ranges AND the S3
     * endpoint is a self-hosted host on such a range.
     *
     * @return bool
     */
    public static function s3_ignore_curlsecurity(): bool {
        return (bool)(int)self::get('s3ignorecurlsecurity', 0);
    }

    // Control webhook (inbound command channel).

    /**
     * Per-connection shared secret used to verify signed control-plane commands
     * (reset migration / reset datatype / delete ad-hoc). Minted by the control
     * plane (supernova) and pasted here by the operator, exactly like the Site
     * ID. Empty = inbound commands disabled (the webhook rejects everything).
     *
     * @return string
     */
    public static function webhook_secret(): string {
        return (string)self::get('webhook_secret', '');
    }

    /**
     * Whether the control webhook requires HTTPS. Default ON. Turn off only for
     * a co-located / test site served over plain HTTP (never in production).
     *
     * @return bool
     */
    public static function webhook_require_https(): bool {
        return (bool)(int)self::get('webhook_require_https', 1);
    }

    // IntelliBoard LTI launch.

    /**
     * LTI launch endpoint URL (the IntelliBoard tool URL).
     *
     * @return string
     */
    public static function lti_tool_url(): string {
        return (string)self::get('ltitoolurl', '');
    }

    /**
     * OAuth consumer key issued by IntelliBoard.
     *
     * @return string
     */
    public static function lti_consumer_key(): string {
        return (string)self::get('lticonsumerkey', '');
    }

    /**
     * OAuth shared secret issued by IntelliBoard.
     *
     * @return string
     */
    public static function lti_shared_secret(): string {
        return (string)self::get('ltisharedsecret', '');
    }

    /**
     * Whether the LTI debug panel is shown instead of auto-submitting the launch.
     *
     * @return bool
     */
    public static function lti_debug(): bool {
        return (bool)(int)self::get('ltidebug', 0);
    }


    /**
     * LTI navigation node title; falls back to the 'ltimenutitle' string when unset.
     *
     * @return string
     */
    public static function lti_title(): string {
        $title = trim((string)self::get('ltititle', ''));
        return $title !== '' ? $title : get_string('ltimenutitle', self::COMPONENT);
    }

    /**
     * Whether to also surface the dashboard link in the theme's menu bar.
     *
     * Off by default: it changes site-wide navigation chrome, so it is opt-in.
     * See {@see \local_intellistream\helpers\custom_menu_helper} for the two
     * mechanisms this drives and why they differ by Moodle version.
     *
     * @return bool
     */
    public static function lti_custom_menu_item(): bool {
        return (bool)(int)self::get('custommenuitem', 0);
    }

    /**
     * Moodle page layout used to render the embedded LTI page.
     *
     * @return string
     */
    public static function lti_page_layout(): string {
        $layout = trim((string)self::get('ltipagelayout', ''));
        return $layout !== '' ? $layout : 'base';
    }

    /**
     * Configured Moodle role id that carries the LTI capability (role-assignment).
     */
    public static function lti_role_id() {
        return self::get('ibnltirole', '');
    }
}
