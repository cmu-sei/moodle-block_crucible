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
 * Sync Keycloak users task.
 *
 * @package    block_crucible
 * @copyright  2025 Carnegie Mellon University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_crucible\task;

use block_crucible\local\org_roles;
use block_crucible\local\profile_fields;
use core\http_client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

defined('MOODLE_INTERNAL') || die();

/**
 * Scheduled task to sync users from Keycloak.
 */
class sync_keycloak_users extends \core\task\scheduled_task {
    /** @var int Maximum time to establish a connection to Keycloak. */
    const CONNECT_TIMEOUT_SECONDS = 5;

    /** @var int Maximum total duration of a Keycloak request. */
    const TIMEOUT_SECONDS = 30;

    /** @var int Results per page for every paged admin API call. */
    const PAGE_SIZE = 200;

    /**
     * Get task name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_sync_keycloak_users', 'block_crucible');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $pagesize    = self::PAGE_SIZE;
        $onlyenabled = 1;

        // Find issuer
        $issuerid = get_config('block_crucible', 'issuerid');
        if (!$issuerid) {
            $issuers = \core\oauth2\api::get_all_issuers();
            foreach ($issuers as $cand) {
                if (stripos($cand->get('name'), 'keycloak') !== false) {
                    $issuerid = $cand->get('id');
                    break;
                }
            }
        }
        if (!$issuerid) {
            mtrace('[crucible] no issuer found');
            return;
        }

        $issuer       = \core\oauth2\api::get_issuer($issuerid);
        $clientid     = $issuer->get('clientid');
        $clientsecret = $issuer->get('clientsecret');

        $endpoints = \core\oauth2\api::get_endpoints($issuer);

        $tokenurl = null;
        if (isset($endpoints['token_endpoint'])) {
            $tokenurl = rtrim($endpoints['token_endpoint']->get('url'), '/');
        } else {
            foreach ($endpoints as $name => $ep) {
                $epname = is_object($ep) ? $ep->get('name') : (is_string($name) ? $name : '');
                if ($epname === 'token_endpoint') {
                    $tokenurl = rtrim($ep->get('url'), '/');
                    break;
                }
            }
        }

        if (!$tokenurl) {
            mtrace('[crucible] token_endpoint not found on issuer');
            return;
        }

        // Derive realm URL and admin base from token endpoint.
        $realmurl = preg_replace('#/protocol/openid-connect/token/?$#', '', rtrim($tokenurl, '/'));
        if ($realmurl === $tokenurl) {
            mtrace('[crucible] token endpoint did not match expected KC pattern');
            return;
        }
        if (!preg_match('#/realms/[^/]+$#', $realmurl)) {
            mtrace('[crucible] realmurl does not end with /realms/{realm}');
            return;
        }
        $adminbase = preg_replace('#/realms/#', '/admin/realms/', $realmurl, 1);

        // Fetch token
        $token = $this->fetch_token($tokenurl, $clientid, $clientsecret);
        if (!$token) {
            mtrace('[crucible] could not obtain Keycloak token.');
            return;
        }

        // /users carries attributes but not group membership, so ask the groups
        // endpoint instead: a few paged calls for the whole realm, rather than the one
        // call per user that /users/{id}/groups would cost.
        $groupmembers = $this->fetch_group_membership($adminbase, $token);
        if ($groupmembers === null) {
            mtrace('[crucible] group membership fetch failed - leaving ssogroups untouched this run '
                . 'so a transient error cannot revoke every role.');
        }

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $first   = 0;
        $seen    = [];
        $touched = [];
        $fetchfailed = false;

        do {
            $users = $this->fetch_kc_users($adminbase, $token, $first, $pagesize, $onlyenabled);
            if ($users === null) {
                // Distinct from an empty page: a failed page must not be read as
                // "these users are gone from Keycloak".
                $fetchfailed = true;
                break;
            }
            $count = count($users);

            foreach ($users as $kc) {
                $email      = strtolower(trim($kc['email'] ?? ''));
                $username   = strtolower(trim($kc['username'] ?? ''));
                $enabled    = !empty($kc['enabled']);
                $firstname  = $kc['firstName'] ?? '';
                $lastname   = $kc['lastName'] ?? '';
                $kcid       = $kc['id'] ?? null;

                // --- Skip service/system accounts ---
                if (
                    ($username && substr($username, 0, 16) === 'service-account-') ||
                    isset($kc['serviceAccountClientId']) ||
                    (!$email && !$firstname && !$lastname)
                ) {
                    $skipped++;
                    continue;
                }

                if (!$kcid) {
                    $skipped++;
                    continue;
                }
                if ($onlyenabled && !$enabled) {
                    $skipped++;
                    continue;
                }

                $seen[$kcid] = true;

                // Keycloak attributes and group membership -> custom profile fields.
                // Multi-valued attributes are kept whole; taking only the first value
                // made a user's org silently switch when the first one was removed.
                $fields = [
                    profile_fields::ROLE => $this->kc_attr_text($kc, 'moodle_roles'),
                    profile_fields::ORG => org_roles::join_list($this->kc_attr_values($kc, 'organization')),
                    profile_fields::TEAM => $this->kc_attr_text($kc, 'team'),
                    profile_fields::WORKROLE => $this->kc_attr_text($kc, 'work_role'),
                ];
                if ($groupmembers !== null) {
                    $fields[profile_fields::GROUPS] = org_roles::join_list($groupmembers[$kcid] ?? []);
                }

                $existing = $DB->get_record('user', ['idnumber' => $kcid, 'deleted' => 0], '*', IGNORE_MISSING);

                if ($existing) {
                    $needs = false;
                    $u = (object)['id' => $existing->id];

                    if ($firstname && $existing->firstname !== $firstname) {
                        $u->firstname = $firstname;
                        $needs = true;
                    }
                    if ($lastname  && $existing->lastname !== $lastname) {
                        $u->lastname  = $lastname;
                        $needs = true;
                    }

                    // Custom profile fields. Writing '' for an attribute Keycloak no
                    // longer has is the point: skipping the write left a deleted
                    // organization in place forever, and the role with it.
                    $pf = profile_user_record($existing->id, false) ?: new \stdClass();
                    $profilechanged = false;
                    foreach ($fields as $short => $val) {
                        $current = isset($pf->$short) ? (string)$pf->$short : '';
                        if ($val !== $current) {
                            $u->{'profile_field_' . $short} = $val;
                            $needs = true;
                            $profilechanged = true;
                        }
                    }

                    if ($needs) {
                        user_update_user($u, false, false);
                        profile_save_data($u);
                        $updated++;
                        if ($profilechanged) {
                            $touched[] = (int)$existing->id;
                        }
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                // ---- No idnumber match, create a brand-new user ----
                if ($username && $DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
                    $username = $username . '.' . substr($kcid, 0, 8);
                }

                $new = (object)[
                    'auth'        => 'oauth2',
                    'username'    => $username ?: ($email ?: ('kc-' . $kcid)),
                    'email'       => $email ?: ('noemail+' . $kcid . '@invalid.local'),
                    'firstname'   => $firstname ?: '-',
                    'lastname'    => $lastname ?: '-',
                    'idnumber'    => $kcid,
                    'confirmed'   => 1,
                    'suspended'   => 0,
                    'mnethostid'  => $CFG->mnet_localhost_id,
                    'password'    => \core\uuid::generate(),
                ];
                foreach ($fields as $short => $val) {
                    $new->{'profile_field_' . $short} = $val;
                }

                try {
                    $newid = user_create_user($new, false, false);
                    $new->id = $newid;
                    profile_save_data($new);
                    $created++;
                    $touched[] = (int)$newid;
                    $nameafter = trim(($new->firstname ?? '') . ' ' . ($new->lastname ?? ''));
                    mtrace('[crucible] created user'
                        . ' username=' . $new->username
                        . ' name="'    . ($nameafter !== '' ? $nameafter : '-'));
                } catch (\Throwable $e) {
                    mtrace('[crucible] create failed for KC id ' . $kcid . ' : ' . $e->getMessage());
                }
            }

            $first += $count;
        } while ($count === $pagesize);

        $deprovisioned = 0;
        if ($fetchfailed) {
            mtrace('[crucible] a /users page failed - skipping the deprovision pass this run.');
        } else {
            $deprovisioned = $this->deprovision_missing_users(array_keys($seen), $touched);
        }

        mtrace("[crucible] sync complete: created={$created} updated={$updated} skipped={$skipped} "
            . "deprovisioned={$deprovisioned}");

        // Reconcile the users whose org data actually moved, rather than leaving every
        // change to wait for the hourly role sync.
        if ($touched && org_roles::is_enabled()) {
            $counts = org_roles::reconcile_users($touched);
            mtrace("[crucible] org roles: +{$counts['assigned']} assigned, -{$counts['unassigned']} removed.");
        }
    }

    /**
     * Strip the Keycloak-derived state of users Keycloak no longer lists.
     *
     * A user who has been deleted or disabled in Keycloak keeps working in Moodle
     * otherwise: clearing the sso* fields is what makes the role reconcile take their
     * category roles back. Suspending the account as well is a separate, off-by-default
     * choice, because some deployments keep the account for its grades and logs.
     *
     * @param string[] $seenkcids Keycloak ids present in this run's responses
     * @param int[] $touched collects the ids of users changed here, by reference
     * @return int number of users deprovisioned
     */
    private function deprovision_missing_users(array $seenkcids, array &$touched): int {
        global $DB;

        $seen = array_fill_keys($seenkcids, true);
        $suspend = (bool)get_config('block_crucible', 'suspendmissingusers');
        $shortnames = array_keys(profile_fields::all());

        $candidates = $DB->get_records_select(
            'user',
            "auth = :auth AND deleted = 0 AND idnumber <> :empty",
            ['auth' => 'oauth2', 'empty' => ''],
            '',
            'id, idnumber, suspended'
        );

        $count = 0;
        foreach ($candidates as $candidate) {
            if (isset($seen[$candidate->idnumber])) {
                continue;
            }

            $current = profile_user_record((int)$candidate->id, false) ?: new \stdClass();
            $u = (object)['id' => (int)$candidate->id];
            $changed = false;
            foreach ($shortnames as $short) {
                if (isset($current->$short) && (string)$current->$short !== '') {
                    $u->{'profile_field_' . $short} = '';
                    $changed = true;
                }
            }

            $suspending = $suspend && !$candidate->suspended;
            if (!$changed && !$suspending) {
                continue;
            }

            if ($suspending) {
                $u->suspended = 1;
                user_update_user($u, false, false);
            }
            profile_save_data($u);
            $touched[] = (int)$candidate->id;
            $count++;
        }

        return $count;
    }

    /**
     * Fetch OAuth token from Keycloak.
     *
     * @param string $tokenurl Token endpoint URL
     * @param string $clientid Client ID
     * @param string $clientsecret Client secret
     * @return string|null Access token or null on failure
     */
    private function fetch_token(string $tokenurl, string $clientid, string $clientsecret): ?string {
        try {
            $response = $this->create_http_client()->post($tokenurl, [
                RequestOptions::FORM_PARAMS => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $clientid,
                    'client_secret' => $clientsecret,
                ],
            ]);
        } catch (GuzzleException $e) {
            mtrace('[crucible] token request error: ' . $e->getMessage());
            return null;
        }

        $http = $response->getStatusCode();
        $resp = (string) $response->getBody();

        if ($http >= 400) {
            mtrace('[crucible] token HTTP ' . $http . ' from ' . $tokenurl . ' body: ' . $resp);
            return null;
        }

        $data = json_decode($resp, true);
        if (!$data || empty($data['access_token'])) {
            mtrace('[crucible] token response missing access_token');
            return null;
        }
        return $data['access_token'];
    }

    /**
     * Fetch users from Keycloak admin API.
     *
     * @param string $adminbase Admin API base URL
     * @param string $token Access token
     * @param int $first Pagination offset
     * @param int $max Maximum results
     * @param int $onlyenabled Only fetch enabled users
     * @return array|null User records, or null when the request failed
     */
    private function fetch_kc_users(string $adminbase, string $token, int $first, int $max, int $onlyenabled): ?array {
        $url = rtrim($adminbase, '/') . '/users?first=' . $first . '&max=' . $max . '&briefRepresentation=false';
        if ($onlyenabled) {
            $url .= '&enabled=true';
        }

        return $this->fetch_list($url, $token, '/users');
    }

    /**
     * Build Keycloak id => mapped group names for every group in the role mapping.
     *
     * Group membership is not part of the /users representation, and asking
     * /users/{id}/groups would be one request per user. Asking each mapped group for its
     * members is a handful of paged requests for the whole realm instead.
     *
     * @param string $adminbase Admin API base URL
     * @param string $token Access token
     * @return array|null keycloak user id => group names, or null when any request failed
     */
    private function fetch_group_membership(string $adminbase, string $token): ?array {
        $wanted = array_keys(org_roles::group_role_map());
        $adminbase = rtrim($adminbase, '/');

        $tree = $this->fetch_list(
            $adminbase . '/groups?briefRepresentation=true&max=' . self::PAGE_SIZE,
            $token,
            '/groups'
        );
        if ($tree === null) {
            return null;
        }

        // Mapped groups may be nested under a parent, so walk the whole tree.
        $groupids = [];
        $walk = function (array $nodes) use (&$walk, &$groupids, $wanted): void {
            foreach ($nodes as $node) {
                if (isset($node['name'], $node['id']) && in_array($node['name'], $wanted, true)) {
                    $groupids[$node['name']] = $node['id'];
                }
                if (!empty($node['subGroups']) && is_array($node['subGroups'])) {
                    $walk($node['subGroups']);
                }
            }
        };
        $walk($tree);

        foreach (array_diff($wanted, array_keys($groupids)) as $missing) {
            mtrace("[crucible] Keycloak group '{$missing}' does not exist - nobody matches it.");
        }

        $membership = [];
        foreach ($groupids as $name => $groupid) {
            $first = 0;
            do {
                $page = $this->fetch_list(
                    $adminbase . '/groups/' . urlencode($groupid)
                    . '/members?briefRepresentation=true&first=' . $first . '&max=' . self::PAGE_SIZE,
                    $token,
                    '/groups/{id}/members'
                );
                if ($page === null) {
                    return null;
                }
                foreach ($page as $member) {
                    if (!empty($member['id'])) {
                        $membership[$member['id']][] = $name;
                    }
                }
                $count = count($page);
                $first += $count;
            } while ($count === self::PAGE_SIZE);
        }

        return $membership;
    }

    /**
     * GET a Keycloak admin endpoint expected to answer with a JSON list.
     *
     * @param string $url Absolute URL
     * @param string $token Access token
     * @param string $label Endpoint name for log lines
     * @return array|null the list, or null on any failure
     */
    private function fetch_list(string $url, string $token, string $label): ?array {
        try {
            $response = $this->create_http_client()->get($url, [
                RequestOptions::HEADERS => [
                    'Authorization' => 'Bearer ' . $token,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (GuzzleException $e) {
            mtrace('[crucible] KC ' . $label . ' request error: ' . $e->getMessage());
            return null;
        }

        $resp = (string) $response->getBody();
        if ($response->getStatusCode() >= 400) {
            mtrace('[crucible] KC ' . $label . ' HTTP ' . $response->getStatusCode());
            return null;
        }

        if (stripos($resp, '<html') !== false) {
            mtrace('[crucible] KC ' . $label . ' returned HTML (wrong URL or auth?)');
            return null;
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            mtrace('[crucible] KC ' . $label . ' non-JSON or non-array payload');
            return null;
        }

        if (isset($data['error']) || isset($data['errorMessage'])) {
            mtrace('[crucible] KC ' . $label . ' returned error object');
            return null;
        }

        // array_is_list() rather than comparing against range(0, count - 1): for an empty
        // payload that range is [0, -1], so a legitimately empty page read as a failure.
        if (!array_is_list($data)) {
            mtrace('[crucible] KC ' . $label . ' payload is not a list');
            return null;
        }

        return $data;
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
            // A scheduled task shares Moodle's cron worker with unrelated work. Do not allow an
            // unavailable Keycloak to hold it indefinitely.
            RequestOptions::CONNECT_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            RequestOptions::TIMEOUT => self::TIMEOUT_SECONDS,
            // Statuses are reported by the callers rather than raised.
            RequestOptions::HTTP_ERRORS => false,
        ]);
    }

    /**
     * Get every value of an attribute on a Keycloak user record.
     *
     * Keycloak attributes are always multi-valued in the representation. Returning only
     * the first value meant deleting one value silently replaced the user's org with
     * whichever value happened to be next.
     *
     * @param array $kc Keycloak user record
     * @param string $name Attribute name
     * @return string[] trimmed, non-empty, unique values; empty when the attribute is absent
     */
    private function kc_attr_values(array $kc, string $name): array {
        if (!isset($kc['attributes'][$name])) {
            return [];
        }

        $values = $kc['attributes'][$name];
        if (!is_array($values)) {
            $values = [$values];
        }

        $out = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value !== '' && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * An attribute rendered for a free-text profile field nothing matches against.
     *
     * Unlike ssoorg and ssogroups these are informational, so they stay readable rather
     * than being wrapped in the list delimiters an exact-element match needs.
     *
     * @param array $kc Keycloak user record
     * @param string $name Attribute name
     * @return string
     */
    private function kc_attr_text(array $kc, string $name): string {
        return implode(', ', $this->kc_attr_values($kc, $name));
    }
}
