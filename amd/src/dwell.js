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
 * Page-dwell / time-on-task capture for IntelliStream.
 *
 * Records when the page became visible and, when the page is hidden or
 * unloaded, sends a one-shot beacon carrying the elapsed time to the plugin's
 * dwell endpoint. Uses navigator.sendBeacon so the request survives unload.
 *
 * The beacon is best-effort and fire-and-forget: it never blocks navigation
 * and any failure is silently ignored. The endpoint appends a page_dwell
 * record to the same on-disk buffer the event observer writes to.
 *
 * @module     local_intellistream/dwell
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {

    'use strict';

    /**
     * Initialise dwell capture for the current page.
     *
     * @param {Object} params Page descriptor supplied by the PHP footer hook.
     * @param {String} params.endpoint Absolute URL of the dwell endpoint.
     * @param {Number} params.contextid Moodle context id.
     * @param {Number} params.contextlevel Moodle context level.
     * @param {Number} params.contextinstanceid Context instance id.
     * @param {Number|null} params.courseid Course id, or null outside a course.
     * @param {String} params.page Coarse page kind: course|module|user|site|other.
     * @param {String} [params.url] Relative URL of the current page. Sent only when the
     *     page element (#local-intellistream-dwell, data-url) was not written.
     * @param {String} params.sesskey Moodle session key for the beacon.
     */
    var init = function(params) {

        // Guard: a malformed descriptor disables capture rather than throwing.
        if (!params || !params.endpoint) {
            return;
        }

        // The page URL comes from a data- attribute on the page element written by
        // the PHP top-of-body hook, not from params: a long URL would push the
        // js_call_amd() arguments over core's 1024-character limit.
        // Page content can carry an element with the same id; only one with data-url counts.
        var urlElement = document.getElementById('local-intellistream-dwell');
        var pageUrl = (urlElement && urlElement.hasAttribute('data-url'))
            ? urlElement.getAttribute('data-url') : (params.url || '');

        // High-resolution start time, falling back to Date for old browsers.
        var hasPerf = (typeof window.performance !== 'undefined' &&
            typeof window.performance.now === 'function');
        var startMs = hasPerf ? window.performance.now() : Date.now();
        var startedAt = new Date().toISOString();

        // Only ship one beacon per page lifetime.
        var sent = false;

        /**
         * Elapsed visible time on the page, in whole milliseconds.
         *
         * @returns {Number}
         */
        var elapsedMs = function() {
            var nowMs = hasPerf ? window.performance.now() : Date.now();
            var delta = Math.round(nowMs - startMs);
            return delta > 0 ? delta : 0;
        };

        /**
         * Send the dwell beacon exactly once.
         */
        var sendBeacon = function() {
            if (sent) {
                return;
            }
            sent = true;

            // The snake_case keys are the dwell endpoint's wire field names.
            var payload = {
                sesskey: params.sesskey,
                contextid: params.contextid,
                contextlevel: params.contextlevel,
                contextinstanceid: params.contextinstanceid,
                courseid: (typeof params.courseid === 'undefined') ? null : params.courseid,
                page: params.page,
                url: pageUrl,
                timespent_ms: elapsedMs(), // eslint-disable-line camelcase
                started_at: startedAt // eslint-disable-line camelcase
            };

            var body;
            try {
                body = JSON.stringify(payload);
            } catch (e) {
                return;
            }

            // Preferred path: sendBeacon survives page unload.
            if (typeof navigator !== 'undefined' &&
                    typeof navigator.sendBeacon === 'function') {
                try {
                    var blob = new Blob([body], {type: 'application/json'});
                    if (navigator.sendBeacon(params.endpoint, blob)) {
                        return;
                    }
                } catch (e2) {
                    // Fall through to the fetch fallback below.
                    sent = true;
                }
            }

            // Fallback for browsers without sendBeacon: keepalive fetch.
            if (typeof window.fetch === 'function') {
                try {
                    window.fetch(params.endpoint, {
                        method: 'POST',
                        body: body,
                        headers: {'Content-Type': 'application/json'},
                        keepalive: true,
                        credentials: 'same-origin'
                    }).catch(function() {
                        return;
                    });
                } catch (e3) {
                    return;
                }
            }
        };

        // The pagehide event fires on real navigation and on bfcache stores.
        window.addEventListener('pagehide', sendBeacon, {capture: true});

        // A visibilitychange to hidden covers tab switches and mobile background.
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                sendBeacon();
            }
        }, {capture: true});

        // Final safety net for browsers that miss pagehide.
        window.addEventListener('beforeunload', sendBeacon, {capture: true});

        // Per-segment media playback tracking.
        // Only wires up when params.trackmedia=1 (set by the PHP footer hook
        // from config::trackmedia_enabled()). For each <video>/<audio> on the
        // page, emit a media_segment beacon every `bucketSec` while playing.
        if (params.trackmedia) {
            var bucketSec = Math.max(5, parseInt(params.trackmediabucketsec, 10) || 30);
            var elements = document.querySelectorAll('video, audio');
            for (var i = 0; i < elements.length; i++) {
                // The `index` is passed in alongside `el` rather than closing over
                // the loop's `i`. The IIFE runs synchronously, so `i` would still
                // be correct here, but referencing it from inside a function
                // declared in a loop trips eslint's no-loop-func rule.
                (function(el, index) {
                    var mediaId = el.id || el.src || ('media-' + index);
                    var kind = (el.tagName || '').toLowerCase() === 'audio' ? 'audio' : 'video';
                    var lastEmittedBucket = -1;
                    var bucketStartedAt = new Date().toISOString();
                    var bucketStartMs = hasPerf ? window.performance.now() : Date.now();

                    var emitSegment = function() {
                        var nowMs = hasPerf ? window.performance.now() : Date.now();
                        var elapsed = Math.round(nowMs - bucketStartMs);
                        if (elapsed <= 0) {
                            return;
                        }
                        // The snake_case keys are the dwell endpoint's wire field names.
                        var seg = {
                            sesskey: params.sesskey,
                            contextid: params.contextid,
                            contextlevel: params.contextlevel,
                            contextinstanceid: params.contextinstanceid,
                            courseid: (typeof params.courseid === 'undefined') ? null : params.courseid,
                            page: params.page,
                            url: pageUrl,
                            timespent_ms: elapsed, // eslint-disable-line camelcase
                            started_at: bucketStartedAt, // eslint-disable-line camelcase
                            record_type: 'media_segment', // eslint-disable-line camelcase
                            media_id: mediaId, // eslint-disable-line camelcase
                            media_kind: kind, // eslint-disable-line camelcase
                            media_pos_sec: Math.floor(el.currentTime || 0), // eslint-disable-line camelcase
                            bucket_sec: bucketSec // eslint-disable-line camelcase
                        };
                        var body;
                        try {
                            body = JSON.stringify(seg);
                        } catch (e) {
                            return;
                        }
                        if (typeof navigator !== 'undefined' &&
                                typeof navigator.sendBeacon === 'function') {
                            try {
                                var blob = new Blob([body], {type: 'application/json'});
                                navigator.sendBeacon(params.endpoint, blob);
                            } catch (e2) {
                                // Fall through: a lost segment beacon is not retried.
                            }
                        }
                        bucketStartedAt = new Date().toISOString();
                        bucketStartMs = nowMs;
                    };

                    el.addEventListener('timeupdate', function() {
                        var bucket = Math.floor((el.currentTime || 0) / bucketSec);
                        if (bucket !== lastEmittedBucket && lastEmittedBucket !== -1) {
                            emitSegment();
                        }
                        lastEmittedBucket = bucket;
                    });
                    el.addEventListener('pause', emitSegment);
                    el.addEventListener('ended', emitSegment);
                })(elements[i], i);
            }
        }
    };

    return {
        init: init
    };
});
