<h2>Update User's Name For Publications</h2>
<p>User names are taking from the ORCID profile. They are updated each time the user logs in.
    People are inconsistent in the way they have given their names in ORCID and may change it from time to time.
    This makes it impossible to get everones name correct when we publish to GBIF or Zenodo 
    - despite having tried to do this with code we now have a field we can maintain manually that
    isn't ever overwritten.</p>

<table>
<tr>
    <th>ID</th>
    <th>Role</th>
    <th>ORCID Name</th>
    <th>Canonical Name</th>
    <th>Save</th>
</tr>

<?php
$response = $mysqli->query("SELECT * FROM `users` ORDER BY `name`;");

while($row = $response->fetch_assoc()){
    echo '<form method="POST" action="index.php">';
    echo "<input type=\"hidden\" name=\"action\" value=\"update_user_name\"/>";
    echo "<input type=\"hidden\" name=\"user_id\" value=\"{$row['id']}\"/>";
    echo "<tr>\n";
    echo "<td>{$row['id']}</td>";
    echo "<td>{$row['role']}</td>";
    echo "<td><a href=\"https://orcid.org/{$row['orcid_id']}\" target=\"orcid\">{$row['name']}</a></td>";
    echo "<td><input type=\"text\" size=\"40\" name=\"new_name\" value=\"{$row['name_canonical']}\" onkeyup=\"this.form.submit.disabled = false;\"/></td>";
    echo '<td><input name="submit" type="submit" value="Update" disabled /></td>';
    echo "</tr>\n";
    echo '</form>';
}

?>


</table>

