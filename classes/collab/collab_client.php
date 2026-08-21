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
 * This file is part of the local_intellistream plugin for Moodle.
 *
 * Blackboard Collaborate REST API client (attendance pull, Part B).
 * Ported from the legacy local_intelliboard bb_collaborate adapter/service.
 * All network calls are OUTBOUND to the Blackboard cloud; the client no-ops
 * unless the three collab_* credentials are configured.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\collab;

/**
 * Thin client for the Blackboard Collaborate REST API.
 *
 * Auth: build an HS256 JWT signed with the consumer secret, exchange it at
 * {endpoint}/token for an OAuth bearer access token, then call the sessions
 * endpoints with that token. Mirrors the legacy IntelliBoard integration.
 */
class collab_client {
    /** @var int Access-token lifetime (seconds) baked into the JWT exp claim. */
    const TOKEN_LIFETIME = 290;

    /** @var string */
    private $url;
    /** @var string */
    private $consumerkey;
    /** @var string */
    private $secret;
    /** @var \curl */
    private $http;
    /** @var string */
    private $accesstoken = '';

    /**
     * Read the three collab_* settings and prepare the HTTP client.
     *
     * No network call happens here, so constructing this is safe on a site that
     * has not configured Collaborate — the credentials are simply empty and
     * every request path no-ops.
     */
    public function __construct() {
        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');

        $this->url = rtrim((string)get_config('local_intellistream', 'collab_api_endpoint'), '/');
        $this->consumerkey = trim((string)get_config('local_intellistream', 'collab_consumer_key'));
        $this->secret = trim((string)get_config('local_intellistream', 'collab_secret'));
        $this->http = new \curl();
    }

    /**
     * True only when all three Collaborate credentials are set. The sync task
     * checks this and skips entirely when false (feature is opt-in).
     *
     * @return bool
     */
    public function has_credentials(): bool {
        return $this->url !== '' && $this->consumerkey !== '' && $this->secret !== '';
    }

    /**
     * Build the HS256 JWT used to request an access token.
     *
     * @return string
     */
    private function generate_jwt(): string {
        $exp = time() + self::TOKEN_LIFETIME;
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode(['iss' => $this->consumerkey, 'sub' => $this->consumerkey, 'exp' => $exp]);

        $b64h = $this->base64url($header);
        $b64p = $this->base64url($payload);
        $sig = hash_hmac('sha256', $b64h . '.' . $b64p, $this->secret, true);
        $b64s = $this->base64url($sig);

        return $b64h . '.' . $b64p . '.' . $b64s;
    }

    /**
     * Base64url-encode, as JWT requires: '+' and '/' swapped for '-' and '_'
     * and the '=' padding dropped.
     *
     * @param string $data
     * @return string
     */
    private function base64url(string $data): string {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Exchange the JWT for an OAuth access token. Cached for the call lifetime.
     *
     * @return string Access token, or '' on failure.
     */
    public function get_access_token(): string {
        if ($this->accesstoken !== '') {
            return $this->accesstoken;
        }
        if (!$this->has_credentials()) {
            return '';
        }
        $headers = ['Content-Type: application/x-www-form-urlencoded'];
        $params = [
            'grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion=' . $this->generate_jwt(),
        ];
        $raw = $this->request('post', $this->url . '/token', $headers, implode('&', $params));
        $resp = json_decode($raw, true);
        if (is_array($resp) && isset($resp['access_token'])) {
            $this->accesstoken = (string)$resp['access_token'];
        }
        return $this->accesstoken;
    }

    /**
     * GET the instances of a session.
     *
     * @param string $sessionuid
     * @return array
     */
    public function get_session_instances(string $sessionuid): array {
        if ($sessionuid === '') {
            return [];
        }
        $url = sprintf('%s/sessions/%s/instances', $this->url, rawurlencode($sessionuid));
        $resp = json_decode($this->request('get', $url, $this->auth_headers()), true);
        return (is_array($resp) && isset($resp['results'])) ? $resp['results'] : [];
    }

    /**
     * GET the attendees of one session instance.
     *
     * @param string $sessionuid
     * @param string $instanceid
     * @return array
     */
    public function get_session_attendees(string $sessionuid, string $instanceid): array {
        $url = sprintf(
            '%s/sessions/%s/instances/%s/attendees',
            $this->url,
            rawurlencode($sessionuid),
            rawurlencode($instanceid)
        );
        $resp = json_decode($this->request('get', $url, $this->auth_headers()), true);
        return (is_array($resp) && isset($resp['results'])) ? $resp['results'] : [];
    }

    /**
     * Headers carrying the current bearer token.
     *
     * @return string[]
     */
    private function auth_headers(): array {
        return ['Content-Type: application/json', 'Authorization: Bearer ' . $this->accesstoken];
    }

    /**
     * Perform an HTTP request via Moodle's curl wrapper.
     *
     * @param string $method 'get' or 'post'
     * @param string $url
     * @param array $headers
     * @param string $body POST body (ignored for GET)
     * @return string Raw response body ('' on error).
     */
    private function request(string $method, string $url, array $headers, string $body = ''): string {
        $this->http->resetHeader();
        foreach ($headers as $h) {
            $this->http->setHeader($h);
        }
        $opts = ['CURLOPT_TIMEOUT' => 30, 'CURLOPT_CONNECTTIMEOUT' => 15];
        if (strtolower($method) === 'post') {
            $result = $this->http->post($url, $body, $opts);
        } else {
            $result = $this->http->get($url, [], $opts);
        }
        if ($this->http->get_errno()) {
            return '';
        }
        return is_string($result) ? $result : '';
    }
}
