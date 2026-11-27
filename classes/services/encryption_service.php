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
 * Encryption service for local_intellistream.
 *
 * @package    local_intellistream
 * @copyright  2026 IntelliBoard, Inc
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_intellistream\services;

use local_intellistream\config;

/**
 * In-transit payload encryption for outgoing batches (S3 puts and pull responses).
 *
 * This is not at-rest encryption: `buffer::append()` writes plaintext JSONL, so
 * buffer files under dataroot hold cleartext personal data whether this is on or
 * off; OS-level disk encryption covers that staging area.
 * Off by default, and off is the supported state: the receiving service cannot
 * decrypt, so the shipper holds shipping while this is on.
 * Wire format: `'intellistream-enc:v1:' . base64(nonce) . ':' . base64(ciphertext)`,
 * written with libsodium `sodium_crypto_secretbox` (authenticated). Without sodium,
 * `encrypt()` throws rather than downgrade. `'intellistream-enc:v1-aes:'`
 * (AES-256-CBC) blobs are decrypt-only. The 32-byte random key is created by the
 * first encrypting run (`ensure_key()`) and stored base64 in mdl_config_plugins
 * (`encryption_key`, a masked admin setting); reads never create a key.
 */
class encryption_service {
    /** Wire-format prefix for libsodium-encrypted blobs. */
    const PREFIX_SODIUM = 'intellistream-enc:v1:';

    /** Wire-format prefix of legacy AES-256-CBC blobs (decrypt-only). */
    const PREFIX_AES = 'intellistream-enc:v1-aes:';

    /** Legacy AES algorithm (decrypt-only). */
    const AES_ALG = 'aes-256-cbc';

    /** Key length, bytes (256 bits for both code paths). */
    const KEY_BYTES = 32;

    /**
     * Whether in-transit payload encryption is currently enabled.
     *
     * Pure read: this predicate performs no key generation and no writes, so
     * it is safe to call from any read path (web services, status pages).
     *
     * @return bool
     */
    public function is_enabled(): bool {
        return (bool)(int)config::get('encryption_enabled', 0);
    }

    /**
     * Read the stored key as raw bytes. Pure read — never generates or stores
     * anything (key creation lives in {@see ensure_key}, which only the write
     * path calls). Empty string means no valid key is stored.
     *
     * @return string raw bytes, length KEY_BYTES, or '' if no key
     */
    private function key(): string {
        $stored = (string)config::get('encryption_key', '');
        if ($stored === '') {
            return '';
        }
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) !== self::KEY_BYTES) {
            return '';
        }
        return $raw;
    }

    /**
     * Return the active key, generating and persisting one if none is stored.
     * This is the explicit initialiser: it is called only from `encrypt()` —
     * i.e. from the shipping task, a write context — never from a predicate or
     * a web-service read.
     *
     * @return string raw bytes, length KEY_BYTES
     * @throws \RuntimeException when no key exists and one cannot be generated
     */
    private function ensure_key(): string {
        $raw = $this->key();
        if ($raw !== '') {
            return $raw;
        }
        $raw = random_bytes(self::KEY_BYTES);
        set_config('encryption_key', base64_encode($raw), config::COMPONENT);
        return $raw;
    }

    /**
     * Encrypt a payload. Returns the input unchanged when encryption is
     * disabled, so callers can wrap every payload unconditionally.
     *
     * @param string $payload
     * @return string
     * @throws \local_intellistream\exception\export_exception when encryption is enabled but cannot be
     *         performed (no sodium, no key source). Loud by design: an admin
     *         who enabled encryption must not have data shipped plaintext
     *         because of a silent capability downgrade.
     */
    public function encrypt(string $payload): string {
        if (!$this->is_enabled()) {
            return $payload;
        }
        if (!function_exists('sodium_crypto_secretbox')) {
            throw new \local_intellistream\exception\export_exception(
                'local_intellistream: payload encryption is enabled but the sodium '
                . 'extension is not available. Install/enable ext-sodium (bundled '
                . 'with PHP 7.2+) or disable encryption explicitly.'
            );
        }
        $key = $this->ensure_key();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ct = sodium_crypto_secretbox($payload, $nonce, $key);
        return self::PREFIX_SODIUM . base64_encode($nonce) . ':' . base64_encode($ct);
    }

    /**
     * Decrypt a ciphertext. Returns the input unchanged when it does not start
     * with one of the wire-format prefixes (e.g. a file written while encryption
     * was off).
     *
     * Returns false on auth-tag failure / bad input.
     *
     * @param string $ciphertext
     * @return string|false
     */
    public function decrypt(string $ciphertext) {
        if (strncmp($ciphertext, self::PREFIX_SODIUM, strlen(self::PREFIX_SODIUM)) === 0) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                return false;
            }
            $body = substr($ciphertext, strlen(self::PREFIX_SODIUM));
            $parts = explode(':', $body, 2);
            if (count($parts) !== 2) {
                return false;
            }
            $nonce = base64_decode($parts[0], true);
            $ct = base64_decode($parts[1], true);
            if ($nonce === false || $ct === false) {
                return false;
            }
            $key = $this->key();
            if ($key === '') {
                return false;
            }
            $pt = sodium_crypto_secretbox_open($ct, $nonce, $key);
            return $pt === false ? false : $pt;
        }

        if (strncmp($ciphertext, self::PREFIX_AES, strlen(self::PREFIX_AES)) === 0) {
            $body = substr($ciphertext, strlen(self::PREFIX_AES));
            $parts = explode(':', $body, 2);
            if (count($parts) !== 2) {
                return false;
            }
            $iv = base64_decode($parts[0], true);
            $ct = base64_decode($parts[1], true);
            if ($iv === false || $ct === false) {
                return false;
            }
            $key = $this->key();
            if ($key === '') {
                return false;
            }
            $pt = openssl_decrypt($ct, self::AES_ALG, $key, OPENSSL_RAW_DATA, $iv);
            return $pt === false ? false : $pt;
        }

        // Blob is not encrypted in our format: pass through. This is the path
        // taken when encryption is disabled, or for buffer files written
        // before encryption was turned on.
        return $ciphertext;
    }
}
