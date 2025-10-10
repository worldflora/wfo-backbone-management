<?php

$target_file_path = '../bulk/csv/' . basename($_FILES["incoming_file"]["name"]);

if (move_uploaded_file($_FILES["incoming_file"]["tmp_name"], $target_file_path)) {
    header('Location: index.php?action=view&phase=csv');
    exit;
} else {
    echo "Sorry, there was an error uploading your file.";
    exit;
}