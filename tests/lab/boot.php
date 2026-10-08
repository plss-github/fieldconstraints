<?php
chdir('/var/www/glpi');
if (!file_exists('/var/www/glpi/src/Glpi/Kernel/Kernel.php')) {
    define('GLPI_ROOT', '/var/www/glpi');
    include GLPI_ROOT . '/inc/includes.php';
} else {
    require '/var/www/glpi/vendor/autoload.php';
    (new \Glpi\Kernel\Kernel('production'))->boot();
}
function s(array $in): array { return version_compare(GLPI_VERSION, '11.0', '<') ? \Glpi\Toolbox\Sanitizer::sanitize($in) : $in; }
$auth = new Auth();
$auth->user = new User();
$auth->user->getFromDBbyName('glpi');
$auth->auth_succeded = true;
Session::init($auth);
Session::changeActiveEntities(0, true);
function messages(): string {
    $out = [];
    foreach ($_SESSION['MESSAGE_AFTER_REDIRECT'] ?? [] as $list) { foreach ($list as $m) { $out[] = strip_tags($m); } }
    $_SESSION['MESSAGE_AFTER_REDIRECT'] = [];
    return implode(' // ', $out);
}
$fails = 0;
function check(string $label, bool $ok, string $extra = ''): void { global $fails; echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($ok || $extra === '' ? '' : " -- $extra"), "\n"; $fails += $ok ? 0 : 1; }
function ensure(string $class, array $crit, array $input): int {
    $o = new $class();
    if ($o->getFromDBByCrit(s($crit))) return $o->getID();
    return (int) $o->add(s($input + $crit));
}
