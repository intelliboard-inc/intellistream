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
 * Minimal S3 client for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream;

/**
 * Hand-rolled AWS Signature v4 client for S3-compatible object storage.
 *
 * Path-style addressing only (https://endpoint/bucket/key), which every
 * S3-compatible provider supports. No bundled AWS SDK.
 */
class s3_client {
    /** @var string Endpoint origin, no trailing slash, e.g. https://host. */
    private $endpoint;

    /** @var string Endpoint host (for the Host header / SigV4). */
    private $host;

    /** @var string Bucket name. */
    private $bucket;

    /** @var string Region (SigV4 credential scope). */
    private $region;

    /** @var string Access key. */
    private $accesskey;

    /** @var string Secret key. */
    private $secretkey;

    /**
     * Hold the destination and the credential used to sign each request.
     *
     * @param string $endpoint Object-storage endpoint URL.
     * @param string $bucket Destination bucket.
     * @param string $region Region used in the SigV4 credential scope.
     * @param string $accesskey Access key id.
     * @param string $secretkey Secret key.
     */
    public function __construct(
        string $endpoint,
        string $bucket,
        string $region,
        string $accesskey,
        string $secretkey
    ) {
        $this->endpoint = rtrim($endpoint, '/');
        $this->host = (string)parse_url($this->endpoint, PHP_URL_HOST);
        $this->bucket = $bucket;
        $this->region = $region !== '' ? $region : 'us-east-1';
        $this->accesskey = $accesskey;
        $this->secretkey = $secretkey;
    }

    /**
     * Build a client from plugin config.
     *
     * @return self
     */
    public static function from_config(): self {
        return new self(
            config::endpoint(),
            config::bucket(),
            config::region(),
            config::access_key(),
            config::secret_key()
        );
    }

    /**
     * Whether all four required settings are present.
     *
     * Presence only — it does NOT check the scheme, despite previously saying so.
     * The https requirement is enforced in put(), at the point the request is
     * actually made, which is where it belongs: a misconfigured scheme should stop
     * the transfer rather than make the plugin report itself unconfigured and
     * silently hold every record in the buffer instead.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->endpoint !== '' && $this->bucket !== ''
            && $this->accesskey !== '' && $this->secretkey !== '';
    }

    /**
     * PUT an object.
     *
     * @param string $key object key, '/'-separated
     * @param string $body raw bytes
     * @param string $contenttype
     * @return array{ok:bool,status:int,category:?string,detail:string}
     */
    public function put(string $key, string $body, string $contenttype = 'application/octet-stream', array $headers = []): array {
        // Endpoint must be https unless an admin has explicitly opted in.
        // The body carries PII, so this guards against
        // accidental plaintext egress from a mis-set http:// endpoint. The
        // endpoint is set by a site admin / the control plane, never a user.
        if (stripos($this->endpoint, 'https://') !== 0 && !config::allow_insecure_endpoint()) {
            return self::fail(0, 'tls_error', 'Endpoint must be https:// (enable allowinsecureendpoint to override).');
        }

        $now = time();
        $amzdate = gmdate('Ymd\THis\Z', $now);
        $datestamp = gmdate('Ymd', $now);
        $canonicaluri = '/' . $this->bucket . '/' . self::encode_key($key);
        $payloadhash = hash('sha256', $body);

        // Merge mandatory SigV4 headers with caller-supplied extras (e.g.
        // x-amz-meta-encrypted=1 from the encryption hook). SigV4 requires
        // EVERY x-amz-* header sent to also be signed -- so canonical_headers
        // and SignedHeaders must include them, sorted by lowercase name.
        $allheaders = [
            'host'                 => $this->host,
            'x-amz-content-sha256' => $payloadhash,
            'x-amz-date'           => $amzdate,
        ];
        foreach ($headers as $name => $value) {
            $allheaders[strtolower($name)] = (string)$value;
        }
        ksort($allheaders);
        $canonicalheaders = '';
        foreach ($allheaders as $name => $value) {
            $canonicalheaders .= $name . ':' . $value . "\n";
        }
        $signedheaders = implode(';', array_keys($allheaders));

        $canonicalrequest = "PUT\n" . $canonicaluri . "\n\n"
            . $canonicalheaders . "\n" . $signedheaders . "\n" . $payloadhash;

        $scope = $datestamp . '/' . $this->region . '/s3/aws4_request';
        $stringtosign = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $scope . "\n"
            . hash('sha256', $canonicalrequest);

        $kdate = hash_hmac('sha256', $datestamp, 'AWS4' . $this->secretkey, true);
        $kregion = hash_hmac('sha256', $this->region, $kdate, true);
        $kservice = hash_hmac('sha256', 's3', $kregion, true);
        $ksigning = hash_hmac('sha256', 'aws4_request', $kservice, true);
        $signature = hash_hmac('sha256', $stringtosign, $ksigning);

        $authorization = 'AWS4-HMAC-SHA256 Credential=' . $this->accesskey . '/' . $scope
            . ', SignedHeaders=' . $signedheaders . ', Signature=' . $signature;

        global $CFG;
        require_once($CFG->dirroot . '/lib/filelib.php');

        // Route the PUT through Moodle's \curl wrapper so the
        // ship honours $CFG->proxy* and the curl security helper
        // ($CFG->curlsecurityblockedhosts), matching the plugin's Collaborate
        // client. Only the transport changes — the SigV4 signing above is
        // untouched. A site that blocks private ranges via curlsecurity would
        // also block a self-hosted MinIO on a private IP; `s3ignorecurlsecurity`
        // lets an admin opt that specific endpoint back in.
        $curl = new \curl(['ignoresecurity' => config::s3_ignore_curlsecurity()]);

        // Send every signed header. `host` is signed, so pass it explicitly
        // (curl replaces its auto Host with ours, keeping SigV4 consistent).
        // Content-Length is left to curl (it derives it from the body).
        // `Expect:` disables the 100-continue handshake, as the raw client did.
        $sendheaders = [];
        foreach ($allheaders as $name => $value) {
            if ($name === 'host') {
                continue;
            }
            $sendheaders[] = $name . ': ' . $value;
        }
        $sendheaders[] = 'Host: ' . $this->host;
        $sendheaders[] = 'Authorization: ' . $authorization;
        $sendheaders[] = 'Content-Type: ' . $contenttype;
        $sendheaders[] = 'Expect:';
        $curl->setHeader($sendheaders);

        // Moodle's \curl already defaults RETURNTRANSFER=1 and restricts protocols to
        // http/https. Preserve the raw client's timeouts and TLS verification,
        // and never follow redirects on a signed request.
        $curl->setopt([
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_TIMEOUT'        => 120,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_SSL_VERIFYPEER' => 1,
            'CURLOPT_SSL_VERIFYHOST' => 2,
        ]);

        $resp = $curl->put($this->endpoint . $canonicaluri, $body);
        $info = $curl->get_info();
        $status = (int)($info['http_code'] ?? 0);
        $errno = (int)$curl->get_errno();

        if ($status === 0 || $curl->error !== '') {
            // Transport failure: DNS / TLS / refused / blocked by curlsecurity.
            $tlserrnos = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 83, 91];
            $category = in_array($errno, $tlserrnos, true) ? 'tls_error' : 's3_endpoint_unreachable';
            return self::fail(0, $category, 'curl error ' . $errno . ($curl->error !== '' ? ': ' . $curl->error : ''));
        }
        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'status' => $status, 'category' => null, 'detail' => 'ok'];
        }
        return self::fail($status, self::categorize($status, (string)$resp), 'HTTP ' . $status);
    }

    /**
     * Map an HTTP error status to an actionable category.
     *
     * @param int $status
     * @param string $body
     * @return string
     */
    private static function categorize(int $status, string $body): string {
        if ($status === 403) {
            return 's3_credential_invalid';
        }
        if ($status === 429) {
            return 's3_quota_exceeded';
        }
        if ($status >= 500) {
            return 's3_5xx';
        }
        if (stripos($body, 'quota') !== false || stripos($body, 'slowdown') !== false) {
            return 's3_quota_exceeded';
        }
        return 's3_other_4xx';
    }

    /**
     * Build a failure result in the shape every caller of this class expects.
     *
     * @param int $status
     * @param string $category
     * @param string $detail
     * @return array{ok:bool,status:int,category:string,detail:string}
     */
    private static function fail(int $status, string $category, string $detail): array {
        return ['ok' => false, 'status' => $status, 'category' => $category, 'detail' => $detail];
    }

    /**
     * RFC 3986 encode each path segment, preserving '/' separators.
     *
     * @param string $key
     * @return string
     */
    private static function encode_key(string $key): string {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
