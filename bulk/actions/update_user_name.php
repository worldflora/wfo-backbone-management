<?php

$name_safe = $mysqli->real_escape_string($_POST['new_name']);
$user_id = (int)$_POST['user_id'];

$mysqli->query("UPDATE `users` SET `name_canonical` = '{$name_safe}' WHERE `id` = {$user_id};");

?>
<script>window.location = 'index.php?action=view&phase=update_user_name';</script>
