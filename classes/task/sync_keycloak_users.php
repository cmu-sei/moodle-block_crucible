<?php
namespace block_crucible\task;

defined('MOODLE_INTERNAL') || die();

class sync_keycloak_users extends \core\task\scheduled_task {
    public function get_name(): string {
        return get_string('task_sync_keycloak_users', 'block_crucible');
    }

    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot.'/user/lib.php');
        require_once($CFG->libdir.'/filelib.php');
        require_once($CFG->dirroot.'/user/profile/lib.php');
        $pagesize    = 200;
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
        if (!$issuerid) { mtrace('[crucible] no issuer found'); return; }

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

        if (!$tokenurl) { mtrace('[crucible] token_endpoint not found on issuer'); return; }

        // Derive realm URL and admin base from token endpoint.
        $realmurl = preg_replace('#/protocol/openid-connect/token/?$#', '', rtrim($tokenurl, '/'));
        if ($realmurl === $tokenurl) { mtrace('[crucible] token endpoint did not match expected KC pattern'); return; }
        if (!preg_match('#/realms/[^/]+$#', $realmurl)) { mtrace('[crucible] realmurl does not end with /realms/{realm}'); return; }
        $adminbase = preg_replace('#/realms/#', '/admin/realms/', $realmurl, 1);

        // Fetch token
        $insecure = (bool)preg_match('#\.dev/#', $tokenurl);
        $token = $this->fetch_token($tokenurl, $clientid, $clientsecret, $insecure);
        if (!$token) { mtrace('[crucible] could not obtain Keycloak token.'); return; }

        $created = 0; $updated = 0; $skipped = 0;
        $first   = 0;

        do {
            $users = $this->fetch_kc_users($adminbase, $token, $first, $pagesize, $onlyenabled, $insecure);
            $count = count($users);

            foreach ($users as $kc) {
                $email      = strtolower(trim($kc['email'] ?? ''));
                $username   = strtolower(trim($kc['username'] ?? ''));
                $enabled    = !empty($kc['enabled']);
                $firstname  = $kc['firstName'] ?? '';
                $lastname   = $kc['lastName'] ?? '';
                $kcid       = $kc['id'] ?? null;

                // Keycloak attributes -> Moodle custom profile fields
                $kcrole     = $this->kc_attr($kc, 'moodle_roles'); // -> profile_field_ssorole
                $kcorg      = $this->kc_attr($kc, 'organization'); // -> profile_field_ssoorg
                $kcteam     = $this->kc_attr($kc, 'team');         // -> profile_field_ssoteam
                $kcworkrole = $this->kc_attr($kc, 'work_role');    // -> profile_field_ssoworkrole

                // --- Skip service/system accounts ---
                if (
                    ($username && substr($username, 0, 16) === 'service-account-') ||
                    isset($kc['serviceAccountClientId']) ||
                    (!$email && !$firstname && !$lastname)
                ) {
                    $skipped++;
                    continue;
                }

                if (!$kcid) { $skipped++; continue; }
                if ($onlyenabled && !$enabled) { $skipped++; continue; }

                $existing = $DB->get_record('user', ['idnumber' => $kcid, 'deleted' => 0], '*', IGNORE_MISSING);

                if ($existing) {
                    $needs = false;
                    $u = (object)['id' => $existing->id];

                    if ($firstname && $existing->firstname !== $firstname) { $u->firstname = $firstname; $needs = true; }
                    if ($lastname  && $existing->lastname  !== $lastname)  { $u->lastname  = $lastname;  $needs = true; }

                    // Custom profile fields
                    $pf = profile_user_record($existing->id, false) ?: new \stdClass();
                    $map = [
                        'profile_field_ssorole'     => $kcrole,
                        'profile_field_ssoorg'      => $kcorg,
                        'profile_field_ssoteam'     => $kcteam,
                        'profile_field_ssoworkrole' => $kcworkrole,
                    ];
                    foreach ($map as $field => $val) {
                        $short   = substr($field, strlen('profile_field_'));
                        $current = isset($pf->$short) ? (string)$pf->$short : null;
                        if ($val !== null && $val !== $current) { $u->$field = $val; $needs = true; }
                    }

                    if ($needs) {
                        user_update_user($u, false, false);
                        profile_save_data($u);
                        $updated++;
                    } else {
                        $skipped++;
                    }
                    continue;
                }

                // ---- No idnumber match, create a brand-new user ----
                if ($username && $DB->record_exists('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id])) {
                    $username = $username.'.'.substr($kcid, 0, 8);
                }

                $new = (object)[
                    'auth'        => 'oauth2',
                    'username'    => $username ?: ($email ?: ('kc-'.$kcid)),
                    'email'       => $email ?: ('noemail+'.$kcid.'@invalid.local'),
                    'firstname'   => $firstname ?: '-',
                    'lastname'    => $lastname ?: '-',
                    'idnumber'    => $kcid,
                    'confirmed'   => 1,
                    'suspended'   => 0,
                    'mnethostid'  => $CFG->mnet_localhost_id,
                    'password'    => \core\uuid::generate(),
                    // custom profile fields
                    'profile_field_ssorole'      => $kcrole,
                    'profile_field_ssoorg'       => $kcorg,
                    'profile_field_ssoteam'      => $kcteam,
                    'profile_field_ssoworkrole'  => $kcworkrole,
                ];

                try {
                    $newid = user_create_user($new, false, false);
                    $new->id = $newid;
                    profile_save_data($new);
                    $created++;
                } catch (\Throwable $e) {
                    mtrace('[crucible] create failed for KC id '.$kcid.' : '.$e->getMessage());
                }
            }

            $first += $count;
        } while ($count === $pagesize);

        mtrace("[crucible] sync complete: created={$created} updated={$updated} skipped={$skipped}");
    }

    private function fetch_token(string $tokenurl, string $clientid, string $clientsecret, bool $insecure = false): ?string {
        $data = http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $clientid,
            'client_secret' => $clientsecret,
        ], '', '&');

        $ch = curl_init($tokenurl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if ($insecure) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $resp = curl_exec($ch);
        if ($resp === false) {
            mtrace('[crucible] token curl error: '.curl_error($ch));
            curl_close($ch);
            return null;
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http >= 400) {
            mtrace('[crucible] token HTTP '.$http.' from '.$tokenurl.' body: '.$resp);
            return null;
        }

        $data = json_decode($resp, true);
        if (!$data || empty($data['access_token'])) {
            mtrace('[crucible] token response missing access_token');
            return null;
        }
        return $data['access_token'];
    }

    private function fetch_kc_users(string $adminbase, string $token, int $first, int $max, int $onlyenabled, bool $insecure = false): array {
        $url = rtrim($adminbase, '/').'/users?first='.$first.'&max='.$max.'&briefRepresentation=false';
        if ($onlyenabled) { $url .= '&enabled=true'; }

        $opts = $insecure ? ['ignore_ssl_errors' => true] : [];
        $curl = new \curl($opts);
        $curl->setHeader('Authorization: Bearer '.$token);
        $curl->setHeader('Accept: application/json');

        $resp = $curl->get($url);
        if ($resp === false) {
            mtrace('[crucible] KC /users curl error');
            return [];
        }

        if (stripos($resp, '<html') !== false) {
            mtrace('[crucible] KC /users returned HTML (wrong URL or auth?)');
            return [];
        }

        $data = json_decode($resp, true);
        if (!is_array($data)) {
            mtrace('[crucible] KC /users non-JSON or non-array payload');
            return [];
        }

        if (isset($data['error']) || isset($data['errorMessage'])) {
            mtrace('[crucible] KC /users returned error object');
            return [];
        }

        $islist = array_keys($data) === range(0, count($data) - 1);
        if (!$islist) {
            mtrace('[crucible] KC /users payload is not a list');
            return [];
        }

        return $data;
    }

    private function kc_attr(array $kc, string $name): ?string {
        if (!isset($kc['attributes'][$name])) { return null; }
        $v = $kc['attributes'][$name];
        if (is_array($v)) { $v = reset($v); }
        $v = trim((string)$v);
        return ($v === '') ? null : $v;
    }
}
