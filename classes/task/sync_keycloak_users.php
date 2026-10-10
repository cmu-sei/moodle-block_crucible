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

use block_crucible\local\keycloak;
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
     * Profile field shortname => the Keycloak user attribute that feeds it.
     *
     * ssogroups is not here: it comes from group membership, not an attribute. ssorole is
     * not here either, deliberately - see the note where $fields is built.
     *
     * @var array<string, string>
     */
    const ATTRIBUTE_FIELDS = [
        profile_fields::ORG => 'organization',
        profile_fields::TEAM => 'team',
        profile_fields::WORKROLE => 'work_role',
    ];

    /**
     * Largest share of the site's linked users a single run may deprovision.
     *
     * A short page, a realm rebuild or a changed client scope can all make Keycloak look
     * like it has lost most of its users. Deprovisioning is destructive enough that it is
     * better to do nothing and say so loudly than to strip the whole site.
     *
     * Half is deliberately loose, and it is only sized for what the pass does today: clear
     * ssogroups, which the next good run puts back along with the roles. A response that
     * loses 40% of a large site still goes through, and that is tolerable precisely because
     * it is reversible. Anything irreversible - deleting accounts, dropping enrolments,
     * clearing a field Keycloak is not the only writer of - needs its own, much tighter
     * limit; do not add it behind this one.
     *
     * @var float
     */
    const MAX_DEPROVISION_SHARE = 0.5;

    /**
     * Linked-user count below which the share guard is not meaningful and is skipped.
     *
     * Counts the site's oauth2 users with an idnumber, not the number to be deprovisioned:
     * on a five-user site "all five are gone" is as likely to be true as not, and a guard
     * that blocked it would leave a small deployment unable to deprovision at all. At
     * exactly this many linked users the guard does apply.
     *
     * @var int
     */
    const DEPROVISION_GUARD_FLOOR = 10;

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
        $suspendmissing = (bool)get_config('block_crucible', 'suspendmissingusers');

        // Where the realm is and what to authenticate to it with. Shared with the login
        // path, so the two cannot disagree about which Keycloak they are talking to.
        $realm = keycloak::realm();
        if ($realm === null) {
            mtrace('[crucible] no usable Keycloak issuer is configured - check the issuer setting '
                . 'and that its token endpoint is a realm URL.');
            return;
        }
        $adminbase = $realm['adminbase'];

        // Fetch token. A failure here is thrown rather than traced: without a token the run
        // does nothing at all, and returning quietly left Moodle recording the task as having
        // succeeded, so a service account missing its realm-management roles looked exactly
        // like a healthy site with no changes to make.
        $token = $this->fetch_token($realm['tokenurl'], $realm['clientid'], $realm['clientsecret']);
        if (!$token) {
            throw new \moodle_exception('errorkeycloaktoken', 'block_crucible');
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
        $attrseen = [];
        $pendingclears = [];
        $unstorablevalues = [];

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
                //
                // ssorole is deliberately absent and must stay so. An OAuth 2 login field
                // mapping fills it from the moodle_roles token claim, which is a client-role
                // mapper, not a user attribute - so the sync would read nothing and blank it.
                // Sites build cohort rules on it, including the one that grants admin, so
                // blanking it takes that away from everyone on the next run.
                $fields = [];
                foreach (self::ATTRIBUTE_FIELDS as $short => $attribute) {
                    if (!$this->kc_has_attr($kc, $attribute)) {
                        // The attribute is absent, which is not the same as empty. Keycloak
                        // drops the key entirely when the last value is removed, so absence
                        // alone cannot tell "this org was deleted" from "this realm has
                        // never populated this attribute". Defer the clear and decide after
                        // the whole run, once we know whether any user carries it at all.
                        $pendingclears[$kcid][$short] = true;
                        continue;
                    }
                    $attrseen[$attribute] = true;
                    if ($short !== profile_fields::ORG) {
                        $fields[$short] = $this->kc_attr_text($kc, $attribute);
                        continue;
                    }

                    // The org list is delimiter-wrapped, so a value containing the
                    // delimiter cannot be stored and join_list() drops it. Writing the
                    // result regardless is the absent-is-not-empty fault again in another
                    // guise: Keycloak sent a real organization and we would store ''.
                    $values = $this->kc_attr_values($kc, $attribute);
                    $unstorable = org_roles::unstorable_values($values);
                    foreach ($unstorable as $value) {
                        $unstorablevalues[$value] = ($unstorablevalues[$value] ?? 0) + 1;
                    }
                    $encoded = org_roles::join_list($values);
                    if ($encoded === '' && $unstorable) {
                        // Nothing storable survived. Leave the field exactly as it is -
                        // and not via $pendingclears either, because the attribute is
                        // present and managed, so that resolution does not apply here.
                        continue;
                    }
                    // A partial drop still writes the survivors: those values are accurate,
                    // and an unstorable one grants nothing in any case, because once it is
                    // dropped here nothing reads it.
                    $fields[profile_fields::ORGLIST] = $encoded;
                    $fields[$short] = org_roles::join_display($encoded);
                }
                if ($groupmembers !== null) {
                    // Group membership is not an attribute: an empty list means the user is
                    // in none of the mapped groups, which is a fact, so write it. No
                    // unstorable-value guard is needed here, unlike the org above:
                    // fetch_group_membership() only records groups whose name is a
                    // group_role_map() key, and a key cannot contain the delimiter even now
                    // the map is configured - the delimiter is what separates the two halves
                    // of a mapping line, so no line expresses such a name.
                    $encoded = org_roles::join_list($groupmembers[$kcid] ?? []);
                    $fields[profile_fields::GROUPSLIST] = $encoded;
                    $fields[profile_fields::GROUPS] = org_roles::join_display($encoded);
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

                    // Mirror of the suspend in deprovision_missing_users(). Reaching here
                    // means Keycloak returned this user as enabled, so the condition that
                    // justified suspending them is gone and the account has to come back -
                    // otherwise disabling a user in Keycloak once locks them out for good.
                    // Gated on the same setting that authorises suspending, so a deployment
                    // that never opted in never has its manual suspensions touched. It
                    // cannot tell a sync suspension from a manual one, which is the cost of
                    // not tracking who suspended the account.
                    if ($existing->suspended && $suspendmissing) {
                        $u->suspended = 0;
                        $needs = true;
                    }

                    // Custom profile fields. Writing '' for an attribute Keycloak still
                    // carries but has emptied is the point: skipping the write left a
                    // deleted organization in place forever, and the role with it. Fields
                    // whose attribute was absent entirely are not here - see $pendingclears.
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

        $this->report_unstorable_values($unstorablevalues);

        $cleared = 0;
        if (!$fetchfailed) {
            $cleared = $this->apply_pending_clears($pendingclears, $attrseen, $touched);
        }

        $deprovisioned = 0;
        if ($fetchfailed) {
            mtrace('[crucible] a /users page failed - skipping the deprovision pass this run.');
        } else {
            $deprovisioned = $this->deprovision_missing_users(array_keys($seen), $touched);
        }

        mtrace("[crucible] sync complete: created={$created} updated={$updated} skipped={$skipped} "
            . "cleared={$cleared} deprovisioned={$deprovisioned}");

        // Reconcile the users whose org data actually moved, rather than leaving every
        // change to wait for the hourly role sync.
        if ($touched && org_roles::is_enabled()) {
            $counts = org_roles::reconcile_users($touched);
            mtrace("[crucible] org roles: +{$counts['assigned']} assigned, -{$counts['unassigned']} removed.");
        }
    }

    /**
     * Name the values this storage cannot hold, in the task log.
     *
     * join_list() reports these through debugging(), which is silent on any site that is
     * not in developer mode - so the one place they mattered, production, never saw them.
     * One line per distinct value with the number of users carrying it, rather than one
     * line per user, because a single renamed organization would otherwise flood the log.
     *
     * The message states what happened and stops there. It deliberately does not tell an
     * administrator to rename the value in Keycloak: these names are unit hierarchies that
     * the planned organization-to-category mapping is meant to resolve as they stand, so
     * advising a rename would send someone to edit the realm for nothing.
     *
     * @param array $unstorablevalues value => number of users carrying it
     */
    private function report_unstorable_values(array $unstorablevalues): void {
        if (!$unstorablevalues) {
            return;
        }

        mtrace('[crucible] ' . count($unstorablevalues) . ' Keycloak value(s) contain a "'
            . org_roles::DELIM . '" and cannot be stored in this plugin\'s list format, so they'
            . ' were not written. A profile field that already held a value keeps it; one that'
            . ' did not is still empty. Either way these values grant no roles, because the'
            . ' organization is matched against a course category name:');
        arsort($unstorablevalues);
        foreach ($unstorablevalues as $value => $users) {
            mtrace('[crucible]   "' . $value . '" (' . $users . ' user(s))');
        }
    }

    /**
     * Clear the fields whose attribute was absent, but only where that means something.
     *
     * An attribute no user in the realm carries is one Keycloak does not manage, so
     * blanking the profile field would destroy whatever else populated it - an OAuth 2
     * login field mapping, an import, a hand edit. An attribute some users carry and
     * others do not is managed, and absence there really does mean "removed", so the
     * clear goes ahead.
     *
     * Known limit: when the *last* user carrying an attribute has it removed, no user in
     * that run carries it, so the attribute reads as unmanaged and that user keeps a stale
     * value until someone carries it again. There is no way to tell that case apart from a
     * realm that never had the attribute, and erring the other way is what blanked ssorole
     * site-wide - a stale value on one user is the cheaper mistake. The trace line names
     * the attribute so an admin can see it happening.
     *
     * @param array $pendingclears Keycloak id => [profile field shortname => true]
     * @param array $attrseen Keycloak attribute names carried by at least one user
     * @param int[] $touched collects the ids of users changed here, by reference
     * @return int number of profile fields cleared
     */
    private function apply_pending_clears(array $pendingclears, array $attrseen, array &$touched): int {
        global $DB;

        $unmanaged = [];
        foreach (self::ATTRIBUTE_FIELDS as $short => $attribute) {
            if (!isset($attrseen[$attribute])) {
                $unmanaged[$short] = $attribute;
            }
        }
        if ($unmanaged) {
            mtrace('[crucible] no Keycloak user carries ' . implode(', ', $unmanaged)
                . ' - leaving the matching profile field(s) alone rather than blanking them.');
        }
        if (!$pendingclears) {
            return 0;
        }

        $cleared = 0;
        foreach ($pendingclears as $kcid => $shortnames) {
            $shortnames = array_diff_key($shortnames, $unmanaged);
            if (!$shortnames) {
                continue;
            }

            $user = $DB->get_record('user', ['idnumber' => $kcid, 'deleted' => 0], 'id', IGNORE_MISSING);
            if (!$user) {
                continue;
            }

            $current = profile_user_record((int)$user->id, false) ?: new \stdClass();
            $u = (object)['id' => (int)$user->id];
            $changed = false;
            foreach ($this->with_matching_fields(array_keys($shortnames)) as $short) {
                if (isset($current->$short) && (string)$current->$short !== '') {
                    $u->{'profile_field_' . $short} = '';
                    $changed = true;
                    $cleared++;
                }
            }
            if ($changed) {
                profile_save_data($u);
                $touched[] = (int)$user->id;
            }
        }

        return $cleared;
    }

    /**
     * Add the matching counterpart of every display field in a list.
     *
     * Clearing a display field on its own would leave the matching field - the one role
     * granting and the cohort rules read - still holding the value that was just removed.
     *
     * @param string[] $shortnames display field shortnames
     * @return string[] those shortnames plus their matching counterparts
     */
    private function with_matching_fields(array $shortnames): array {
        $all = [];
        foreach ($shortnames as $short) {
            $all[] = $short;
            if (isset(profile_fields::MATCHING[$short])) {
                $all[] = profile_fields::MATCHING[$short];
            }
        }

        return $all;
    }

    /**
     * Take back the roles of users Keycloak no longer lists.
     *
     * Only the group fields are cleared, and that is what revokes the roles: with no groups
     * org_roles grants nothing, so the reconcile removes every managed assignment. The
     * organization, team and work role are left in place on purpose. "Absent from
     * Keycloak" does not mean "has no organization", those fields are shown to the user
     * and used by the reports, and once cleared they cannot be recovered - the values
     * only exist in a Keycloak that no longer lists the account.
     *
     * Suspending the account as well is a separate, off-by-default choice, because some
     * deployments keep the account for its grades and logs.
     *
     * @param string[] $seenkcids Keycloak ids present in this run's responses
     * @param int[] $touched collects the ids of users changed here, by reference
     * @return int number of users deprovisioned
     */
    private function deprovision_missing_users(array $seenkcids, array &$touched): int {
        global $DB;

        $seen = array_fill_keys($seenkcids, true);
        $suspend = (bool)get_config('block_crucible', 'suspendmissingusers');

        $candidates = $DB->get_records_select(
            'user',
            "auth = :auth AND deleted = 0 AND idnumber <> :empty",
            ['auth' => 'oauth2', 'empty' => ''],
            '',
            'id, idnumber, suspended'
        );

        $missing = [];
        foreach ($candidates as $candidate) {
            if (!isset($seen[$candidate->idnumber])) {
                $missing[] = $candidate;
            }
        }
        if (!$missing) {
            return 0;
        }

        // Refuse to strip most of the site on the strength of one API response.
        $total = count($candidates);
        if (
            $total >= self::DEPROVISION_GUARD_FLOOR
            && count($missing) > $total * self::MAX_DEPROVISION_SHARE
        ) {
            mtrace('[crucible] ' . count($missing) . ' of ' . $total . ' linked users are missing from'
                . ' Keycloak, which is over the ' . (int)(self::MAX_DEPROVISION_SHARE * 100)
                . '% guard - skipping the deprovision pass. Check the realm, the client scopes and the'
                . ' service account, then re-run the task.');
            return 0;
        }

        $count = 0;
        foreach ($missing as $candidate) {
            $current = profile_user_record((int)$candidate->id, false) ?: new \stdClass();
            $u = (object)['id' => (int)$candidate->id];
            $changed = false;

            foreach ($this->with_matching_fields([profile_fields::GROUPS]) as $groups) {
                if (isset($current->$groups) && (string)$current->$groups !== '') {
                    $u->{'profile_field_' . $groups} = '';
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

        // /groups is paged like every other admin collection, so read it to the end: a
        // realm with more top-level groups than one page would otherwise lose the mapped
        // ones that sort last, and the only symptom is the "does not exist" line below.
        //
        // Only top-level groups resolve here. Keycloak 23+ stopped populating subGroups on
        // this endpoint - children come from /groups/{id}/children - so a mapped group
        // nested under a parent needs that endpoint walking instead. None of the mapped
        // groups are nested today; this is the thing to change when one is.
        $groupids = [];
        $first = 0;
        do {
            $page = $this->fetch_list(
                $adminbase . '/groups?briefRepresentation=true&first=' . $first . '&max=' . self::PAGE_SIZE,
                $token,
                '/groups'
            );
            if ($page === null) {
                return null;
            }

            foreach ($page as $node) {
                if (isset($node['name'], $node['id']) && in_array($node['name'], $wanted, true)) {
                    $groupids[$node['name']] = $node['id'];
                }
            }

            $count = count($page);
            $first += $count;
        } while ($count === self::PAGE_SIZE);

        // Recorded as well as traced. A cron line is the only thing that ever reported a
        // mistyped group name, and that mistake revokes every role the mapping granted - so
        // the settings page reads this back instead of asking Keycloak on every page render.
        // Safe to treat as complete: a failed page returns above, so reaching here means the
        // whole group list was read.
        $missinggroups = array_values(array_diff($wanted, array_keys($groupids)));
        org_roles::record_missing_groups($missinggroups);
        foreach ($missinggroups as $missing) {
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
     * Whether a Keycloak user record carries an attribute at all.
     *
     * Distinct from the attribute being empty. Keycloak removes the key when the last
     * value goes, so this cannot tell "deleted" from "never set" on its own - the caller
     * decides by looking at whether any user in the realm carries it.
     *
     * @param array $kc Keycloak user record
     * @param string $name Attribute name
     * @return bool true when the key is present, whatever its value
     */
    private function kc_has_attr(array $kc, string $name): bool {
        return is_array($kc['attributes'] ?? null) && array_key_exists($name, $kc['attributes']);
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
