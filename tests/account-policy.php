<?php
// WordPress capability fixtures only; no account or network operations.
define('ABSPATH', __DIR__);
$GLOBALS['roles'] = [
    'subscriber' => ['name' => 'Subscriber', 'capabilities' => ['read' => true, 'level_0' => true]],
    'leagueflow_player' => ['name' => 'Player', 'capabilities' => ['read' => true, 'leagueflow_manage_profile' => true]],
    'leagueflow_team_manager' => ['name' => 'Manager', 'capabilities' => ['read' => true, 'leagueflow_manage_team' => true]],
    'administrator' => ['name' => 'Administrator', 'capabilities' => ['read' => true, 'manage_options' => true]],
];
$GLOBALS['multisite'] = false;
class WP_User { public function __construct(public array $allcaps, public int $ID = 1) {} }
function wp_roles() { return (object) ['roles' => $GLOBALS['roles']]; }
function get_role($name) { return isset($GLOBALS['roles'][$name]) ? (object) $GLOBALS['roles'][$name] : null; }
function sanitize_key($name) { return strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '', $name)); }
function user_can($user, $cap) { return !empty($user->allcaps[$cap]); }
function is_multisite() { return $GLOBALS['multisite']; }
function is_super_admin($id) { return $id === 99; }
require dirname(__DIR__) . '/includes/Security/AccountPolicy.php';
use ClerkWPSync\Security\AccountPolicy as Policy;
$checks = 0;
function check($condition, $label) { global $checks; if (!$condition) { throw new RuntimeException($label); } $checks++; }
check(count(Policy::eligible_roles()) === 3, 'only supported self-service roles eligible');
check(Policy::registration_role('administrator') === 'subscriber', 'admin registration rejected');
check(Policy::registration_role('leagueflow_team_manager') === 'leagueflow_team_manager', 'safe manager role retained');
check(!Policy::is_privileged(new WP_User(['subscriber' => true, 'read' => true])), 'role label is not an administrative grant');
check(Policy::is_privileged(new WP_User(['manage_options' => true])), 'admin capability protected');
check(Policy::is_privileged(new WP_User(['custom_staff_capability' => true])), 'direct custom capability protected');
check(!Policy::is_privileged(new WP_User(['read' => true, 'edit_posts' => false])), 'disabled capability does not confer privilege');
$GLOBALS['roles']['leagueflow_team_manager']['capabilities']['edit_posts'] = true;
check(!isset(Policy::eligible_roles()['leagueflow_team_manager']), 'mutated privileged role excluded');
check(Policy::registration_role('leagueflow_team_manager') === 'subscriber', 'unsafe role falls back safely');
$GLOBALS['multisite'] = true;
check(Policy::is_privileged(new WP_User(['read' => true], 99)), 'multisite super administrator protected');
unset($GLOBALS['roles']['subscriber'], $GLOBALS['roles']['leagueflow_player']);
check(Policy::registration_role('administrator') === '', 'no eligible roles fails closed');
echo "$checks account policy checks passed.\n";
