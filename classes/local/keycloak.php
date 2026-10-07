<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Reads one user's organization and groups from Keycloak while they log in.
 *
 * @package    block_crucible
 * @copyright  2026 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\local;

use core\http_client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * The single-user half of the Keycloak sync, for the login path.
 *
 * sync_keycloak_users reads the whole realm on a schedule and is the authority. This asks
 * about one account, so that a user logging in for the first time does not sit without any
 * organization data until the next hourly run. Everything here fails soft: a Keycloak that
 * is slow, down or missing the account leaves the profile fields exactly as they are and the
 * login carries on.
 *
 * Two things follow from asking about one account rather than the realm:
 *
 *  - An absent attribute cannot be read as "removed". The scheduled task can tell those
 *    apart because it sees whether any user in the realm carries the attribute; one record
 *    carries no such evidence, so an absent attribute leaves the field alone.
 *  - The budget is a page load, not a cron slot. One read is up to three requests - a token,
 *    when the cached one has expired, then the user, then the user's groups - so they share
 *    one deadline rather than each carrying its own timeout, and nothing is retried.
 */
class keycloak {
    /** @var int Maximum time to establish a connection, in seconds. */
    const CONNECT_TIMEOUT_SECONDS = 2;

    /** @var int Maximum total duration of one request, in seconds. */
    const TIMEOUT_SECONDS = 3;

    /** @var int Maximum total duration of one user's whole read, in seconds. */
    const BUDGET_SECONDS = 3;

    /** @var int How long to stop asking Keycloak after a failure, in seconds. */
    const FAILURE_BACKOFF_SECONDS = 300;

    /** @var int Longest a token is reused for, whatever lifetime Keycloak gave it. */
    const TOKEN_CACHE_SECONDS = 300;

    /** @var int Stop reusing a token this long before it expires. */
    const TOKEN_MARGIN_SECONDS = 30;

    /** @var int How long the realm's group list is reused for, in seconds. */
    const GROUPS_CACHE_SECONDS = 600;

    /** @var int Maximum total duration of a whole group-list read, in seconds. */
    const GROUPS_BUDGET_SECONDS = 10;

    /** @var int Groups to ask Keycloak for at a time. */
    const GROUPS_PAGE_SIZE = 200;

    /** @var float Unix time the read in progress must be finished by; 0.0 outside a read. */
    private float $deadline = 0.0;

    /**
     * Where the configured Keycloak realm is, and what to authenticate to it with.
     *
     * The OAuth 2 issuer is the only place this is configured, and the realm and admin API
     * bases are derived from its token endpoint rather than asked for separately, so there is
     * nothing for an administrator to get out of step.
     *
     * @return array|null ['tokenurl', 'adminbase', 'clientid', 'clientsecret'], or null when
     *                    no usable Keycloak issuer is configured
     */
    public static function realm(): ?array {
        $issuerid = get_config('block_crucible', 'issuerid');
        if (!$issuerid) {
            foreach (\core\oauth2\api::get_all_issuers() as $candidate) {
                if (stripos($candidate->get('name'), 'keycloak') !== false) {
                    $issuerid = $candidate->get('id');
                    break;
                }
            }
        }
        if (!$issuerid) {
            debugging('block_crucible: no Keycloak OAuth 2 issuer is configured.', DEBUG_DEVELOPER);

            return null;
        }

        $issuer = \core\oauth2\api::get_issuer($issuerid);
        if (!$issuer) {
            debugging('block_crucible: the configured OAuth 2 issuer no longer exists.', DEBUG_DEVELOPER);

            return null;
        }

        // Keyed by name on some releases and numerically on others; read the name either way.
        $tokenurl = null;
        foreach (\core\oauth2\api::get_endpoints($issuer) as $key => $endpoint) {
            $name = is_object($endpoint) ? $endpoint->get('name') : (string)$key;
            if ($name === 'token_endpoint') {
                $tokenurl = rtrim($endpoint->get('url'), '/');
                break;
            }
        }
        if (!$tokenurl) {
            debugging('block_crucible: the OAuth 2 issuer has no token_endpoint.', DEBUG_DEVELOPER);

            return null;
        }

        $realmurl = preg_replace('#/protocol/openid-connect/token/?$#', '', $tokenurl);
        if ($realmurl === $tokenurl || !preg_match('#/realms/[^/]+$#', $realmurl)) {
            debugging('block_crucible: the issuer token endpoint is not a Keycloak realm URL.', DEBUG_DEVELOPER);

            return null;
        }

        return [
            'tokenurl' => $tokenurl,
            'adminbase' => preg_replace('#/realms/#', '/admin/realms/', $realmurl, 1),
            'clientid' => (string)$issuer->get('clientid'),
            'clientsecret' => (string)$issuer->get('clientsecret'),
        ];
    }

    /**
     * Bring one user's organization and group fields up to date from Keycloak.
     *
     * @param int $userid
     * @return bool whether anything was written
     */
    public function refresh_user(int $userid): bool {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/user/profile/lib.php');

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], 'id, auth, idnumber', IGNORE_MISSING);
        if (!$user || $user->auth !== org_roles::AUTH || trim((string)$user->idnumber) === '') {
            return false;
        }

        $state = $this->user_lists(trim((string)$user->idnumber));
        if ($state === null) {
            return false;
        }

        $fields = [];
        // A null organization means the attribute was absent, which one record cannot tell
        // from "removed" - see the class comment.
        if ($state['org'] !== null) {
            $encoded = org_roles::join_list($state['org']);
            if ($encoded !== '' || !org_roles::unstorable_values($state['org'])) {
                $fields[profile_fields::ORGLIST] = $encoded;
                $fields[profile_fields::ORG] = org_roles::join_display($encoded);
            }
        }
        // Group membership is not an attribute: an empty list is the fact that the user is in
        // none of the mapped groups, so it is written, and the reconcile that follows takes
        // the matching roles back.
        $encoded = org_roles::join_list($state['groups']);
        $fields[profile_fields::GROUPSLIST] = $encoded;
        $fields[profile_fields::GROUPS] = org_roles::join_display($encoded);

        $current = profile_user_record($userid, false) ?: new \stdClass();
        $update = (object)['id' => $userid];
        $changed = false;
        foreach ($fields as $shortname => $value) {
            $held = isset($current->$shortname) ? (string)$current->$shortname : '';
            if ($value !== $held) {
                $update->{'profile_field_' . $shortname} = $value;
                $changed = true;
            }
        }
        if ($changed) {
            profile_save_data($update);
            // profile_save_data() fires no event, and tool_dynamic_cohorts' realtime
            // processing reacts to user_created and user_updated only - not to the
            // user_loggedin this is running under. Without this the roles are granted during
            // the login, because reconcile_user() assigns them directly, while cohort
            // membership - and any enrolment made through a cohort - waits for that plugin's
            // own scheduled run. Only on a real change, so an ordinary login fires nothing.
            \core\event\user_updated::create_from_userid($userid)->trigger();
        }

        return $changed;
    }

    /**
     * The organization values and mapped group names Keycloak holds for one account.
     *
     * @param string $kcid Keycloak user id, which is the Moodle idnumber
     * @return array|null ['org' => string[]|null, 'groups' => string[]], or null when Keycloak
     *                    could not be asked or does not know the account
     */
    public function user_lists(string $kcid): ?array {
        if ($kcid === '' || $this->recently_failed()) {
            return null;
        }

        $realm = self::realm();
        if ($realm === null) {
            return null;
        }

        $this->deadline = microtime(true) + self::BUDGET_SECONDS;

        $token = $this->token($realm);
        if ($token === null) {
            $this->note_failure();

            return null;
        }

        $base = rtrim($realm['adminbase'], '/') . '/users/' . urlencode($kcid);
        $user = $this->get_json($base, $token, $status);
        if ($status === 404) {
            // The account is not in this realm. Nothing to say about it, and nothing wrong
            // with Keycloak, so do not back off for everyone else.
            return null;
        }
        $groups = $user === null ? null : $this->get_json($base . '/groups?briefRepresentation=true', $token);
        if ($user === null || $groups === null) {
            $this->note_failure();

            return null;
        }

        $wanted = array_keys(org_roles::group_role_map());
        $names = [];
        foreach ($groups as $group) {
            if (isset($group['name']) && in_array($group['name'], $wanted, true)) {
                $names[] = $group['name'];
            }
        }

        $attributes = is_array($user['attributes'] ?? null) ? $user['attributes'] : [];

        return [
            'org' => array_key_exists('organization', $attributes)
                ? $this->attribute_values($attributes['organization'])
                : null,
            'groups' => $names,
        ];
    }

    /**
     * Timeout options for the next request, out of what is left of the read's budget.
     *
     * A per-request timeout is not a bound on what a login pays: three requests carrying
     * three seconds each cost nine. Sharing one deadline bounds the whole read, and whichever
     * request finds the budget spent is not made at all. That only happens when Keycloak's
     * admin API is slow while its login still works, which is the case this guards.
     *
     * @return array|null Guzzle options, or null when there is no time left to make a request
     */
    private function timeout_options(): ?array {
        $remaining = $this->deadline > 0.0 ? $this->deadline - microtime(true) : (float)self::TIMEOUT_SECONDS;
        if ($remaining <= 0.0) {
            debugging('block_crucible: no time left in the Keycloak read budget.', DEBUG_DEVELOPER);

            return null;
        }

        return [
            RequestOptions::CONNECT_TIMEOUT => min((float)self::CONNECT_TIMEOUT_SECONDS, $remaining),
            RequestOptions::TIMEOUT => min((float)self::TIMEOUT_SECONDS, $remaining),
        ];
    }

    /**
     * Every top-level group name in the realm, for an administrator to choose from.
     *
     * Cached, because this runs while a settings page renders and a realm's group list
     * changes far less often than that page is opened. The refresh is the page's own button:
     * without one, a group just created in Keycloak would not be selectable until the cache
     * expired, and nothing would distinguish that from a group that does not exist.
     *
     * The login path's failure stamp is deliberately not consulted or set here. They are
     * different concerns, and letting them share would mean one administrator opening a
     * settings page could suppress the login-time read for everybody.
     *
     * @param bool $refresh Ignore any cached list and ask Keycloak again.
     * @return string[]|null Sorted group names, or null when the realm could not be read.
     */
    public function group_names(bool $refresh = false): ?array {
        $cache = \cache::make('block_crucible', 'keycloak');

        if (!$refresh) {
            $cached = $cache->get('groups');
            if (is_array($cached) && ($cached['expires'] ?? 0) > time()) {
                return $cached['names'];
            }
        }

        $realm = self::realm();
        if ($realm === null) {
            return null;
        }

        $this->deadline = microtime(true) + self::GROUPS_BUDGET_SECONDS;

        $token = $this->token($realm);
        if ($token === null) {
            return null;
        }

        $base = rtrim($realm['adminbase'], '/') . '/groups?briefRepresentation=true';
        $names = [];
        $first = 0;
        do {
            $page = $this->get_json($base . '&first=' . $first . '&max=' . self::GROUPS_PAGE_SIZE, $token);
            if ($page === null) {
                // Including when the budget ran out mid-walk. Returning what was read so far
                // would be worse than returning nothing: a group that exists would be absent
                // from the list, and the page would present that as "no such group".
                return null;
            }

            foreach ($page as $node) {
                if (!empty($node['name'])) {
                    $names[] = (string)$node['name'];
                }
            }

            $count = count($page);
            $first += $count;
        } while ($count === self::GROUPS_PAGE_SIZE);

        $names = array_values(array_unique($names));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        $cache->set('groups', ['names' => $names, 'expires' => time() + self::GROUPS_CACHE_SECONDS]);

        return $names;
    }

    /**
     * Trimmed, unique values of one Keycloak attribute.
     *
     * @param mixed $raw attribute value as Keycloak represented it
     * @return string[]
     */
    private function attribute_values($raw): array {
        $values = [];
        foreach (is_array($raw) ? $raw : [$raw] as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * A client credentials token, reusing a cached one while it lasts.
     *
     * Cached because the login path needs a token for every user who logs in, and a token
     * request is the slowest part of this. The cached value is a realm admin credential at
     * rest in the application cache, so it is held for minutes rather than its full lifetime,
     * and the expiry is checked here as well as left to the cache's own ttl.
     *
     * @param array $realm as returned by realm()
     * @return string|null
     */
    private function token(array $realm): ?string {
        $cache = \cache::make('block_crucible', 'keycloak');

        $cached = $cache->get('token');
        if (is_array($cached) && !empty($cached['token']) && ($cached['expires'] ?? 0) > time()) {
            return $cached['token'];
        }

        $timeouts = $this->timeout_options();
        if ($timeouts === null) {
            return null;
        }

        try {
            $response = $this->create_http_client()->post($realm['tokenurl'], $timeouts + [
                RequestOptions::FORM_PARAMS => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $realm['clientid'],
                    'client_secret' => $realm['clientsecret'],
                ],
            ]);
        } catch (GuzzleException $e) {
            debugging('block_crucible: Keycloak token request failed: ' . $e->getMessage(), DEBUG_DEVELOPER);

            return null;
        }

        if ($response->getStatusCode() >= 400) {
            debugging(
                'block_crucible: Keycloak token request returned HTTP ' . $response->getStatusCode(),
                DEBUG_DEVELOPER
            );

            return null;
        }

        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data) || empty($data['access_token'])) {
            debugging('block_crucible: Keycloak token response carried no access token.', DEBUG_DEVELOPER);

            return null;
        }

        $lifetime = min((int)($data['expires_in'] ?? 0) ?: self::TOKEN_CACHE_SECONDS, self::TOKEN_CACHE_SECONDS);
        $cache->set('token', [
            'token' => $data['access_token'],
            'expires' => time() + max(1, $lifetime - self::TOKEN_MARGIN_SECONDS),
        ]);

        return $data['access_token'];
    }

    /**
     * GET a Keycloak admin endpoint expected to answer with JSON.
     *
     * @param string $url
     * @param string $token
     * @param int|null $status set to the HTTP status, or 0 when the request did not complete
     * @return array|null decoded payload, or null on any failure
     */
    private function get_json(string $url, string $token, ?int &$status = null): ?array {
        $status = 0;

        $timeouts = $this->timeout_options();
        if ($timeouts === null) {
            return null;
        }

        try {
            $response = $this->create_http_client()->get($url, $timeouts + [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            debugging('block_crucible: Keycloak request failed: ' . $e->getMessage(), DEBUG_DEVELOPER);

            return null;
        }

        $status = $response->getStatusCode();
        if ($status >= 400) {
            debugging('block_crucible: Keycloak request to ' . $url . ' returned HTTP ' . $status, DEBUG_DEVELOPER);

            return null;
        }

        $decoded = json_decode((string)$response->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether Keycloak failed recently enough that it is not worth asking again.
     *
     * Without this, a Keycloak that is refusing connections would add the connect timeout to
     * every login on the site for as long as it stayed down.
     *
     * @return bool
     */
    private function recently_failed(): bool {
        $failed = (int)\cache::make('block_crucible', 'keycloak')->get('failed');

        return $failed > 0 && (time() - $failed) < self::FAILURE_BACKOFF_SECONDS;
    }

    /**
     * Record that Keycloak could not be read, so the next few logins do not try.
     */
    private function note_failure(): void {
        \cache::make('block_crucible', 'keycloak')->set('failed', time());
    }

    /**
     * Drop the cached token and failure stamp.
     *
     * Called from settings.php when the issuer changes, and by tests.
     */
    public static function purge_cache(): void {
        \cache::make('block_crucible', 'keycloak')->purge();
    }

    /**
     * Create the HTTP client used for Keycloak requests.
     *
     * Core's Guzzle client rather than raw PHP cURL or the older \curl wrapper: these requests
     * carry a realm admin bearer token, and this client verifies the peer certificate by default,
     * drops the Authorization header on a cross-origin redirect, and honours the site's proxy and
     * blocked-host settings. Raw cURL honours none of those, and \curl does not verify.
     *
     * @param array $extraconfig Client configuration to apply over the defaults below.
     * @return http_client
     */
    protected function create_http_client(array $extraconfig = []): http_client {
        return new http_client($extraconfig + [
            // These run while a user waits for a login to complete, so an unreachable
            // Keycloak must cost a couple of seconds, not a timeout a cron job would accept.
            // A default: each request narrows it to what is left of the read's whole budget.
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
            // Statuses are reported by the callers rather than raised.
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }
}
