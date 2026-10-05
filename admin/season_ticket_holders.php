<?php
declare(strict_types=1);

$query = trim((string) ($_GET['q'] ?? ''));
$target = '/club_people.php';
if ($query !== '') {
    $target .= '?q=' . rawurlencode($query);
}
header('Location: ' . $target, true, 302);
exit;
