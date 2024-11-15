<?php

error_reporting(E_ALL);
ini_set('display_errors', 'on');

include_once 'DatabaseInfo.php';


function connect()
{
    $dbInfo = getDatabaseInfo();

    // Create connection
    $dsn = "mysql:host={$dbInfo->serverName};dbname={$dbInfo->dbName};charset=utf8mb4";
    $pdo = new PDO($dsn, $dbInfo->userName, $dbInfo->password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

function generateSalt($length = 16) {
    // Generate random bytes
    $randomBytes = random_bytes($length);

    // Encode the random bytes using base64
    $salt = base64_encode($randomBytes);

    // Remove characters that are not URL-safe
    $salt = str_replace(['+', '/', '='], ['-', '_', ''], $salt);

    // Truncate the salt to the desired length
    $salt = substr($salt, 0, $length);

    return $salt;
}

function addUser($login, $password, $email, $first, $last)
{
    // Create a connection
    $pdo = connect();

    // Generate a salt
    $salt = generateSalt();
    // Hash the password with the salt
    $hashedPassword = hash('sha256', $password . $salt);

    // Prepare and execute the SQL statement
    $stmt = $pdo->prepare('INSERT INTO Users (login, display_name, password, salt, first_name, last_name, email, active, visible) '
            . 'VALUES (:login, :display_name, :password, :salt, :first_name, :last_name, :email, true, true)');
    $stmt->bindValue(':login', $login);
    $stmt->bindValue(':password', $hashedPassword);
    $stmt->bindValue(':salt', $salt);
    $stmt->bindValue(':display_name', $login);
    $stmt->bindValue(':email', $email);
    $stmt->bindValue(':first_name', $first);
    $stmt->bindValue(':last_name', $last);
    $stmt->execute();

    return $pdo->lastInsertId();
}

function deleteUser($login) {

    if ($login == "admin") {
        echo 'Cannot delete admin for safety reasons.';
        return;
    }

    $pdo = connect();

    // Retrieve the user id
    $sql = "SELECT id FROM Users WHERE login = :login";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login', $login);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row)
    {
        $user_id = $row["id"];

        // Prepare and execute the SQL statement
        $stmt = $pdo->prepare('DELETE FROM Schedule WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $user_id);
        $stmt->execute();

        // Prepare and execute the SQL statement
        $stmt = $pdo->prepare('DELETE FROM OutlineSchedule WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $user_id);
        $stmt->execute();

        // Prepare and execute the SQL statement
        $stmt = $pdo->prepare('DELETE FROM PasswordTokens WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $user_id);
        $stmt->execute();
    }

    // Prepare and execute the SQL statement
    $stmt = $pdo->prepare('DELETE FROM Users WHERE login = :login');
    $stmt->bindValue(':login', $login);
    $stmt->execute();
}

function addEvent($type, $title, $date)
{
    // Create a connection
    $pdo = connect();

    // Prepare and execute the SQL statement
    $stmt = $pdo->prepare('INSERT INTO Events (type, title, date) VALUES (:type, :title, :date)');
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':title', $title);
    $stmt->bindValue(':date', $date);
    $stmt->execute();

    $eventId = $pdo->lastInsertId();

    // now, check the outline schedule and add all users that have the correct day set

    // database has days from 0-6 (monday-sunday)
    // date() creates days from 0-6 (sunday-saturday), so we have to convert
    $day = date('w', strtotime($date)) - 1;
    if ($day == -1) $day = 6;

    $stmt = $pdo->prepare('SELECT user_id from OutlineSchedule
            WHERE day = :day');

    $stmt->bindValue(':day', $day);
    $stmt->execute();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sql = "INSERT IGNORE INTO Schedule (user_id, event_id, deliberate)
            VALUES (:user_id, :event_id, false)";

        $stmt2 = $pdo->prepare($sql);
        $stmt2->bindValue(":user_id", $row["user_id"]);
        $stmt2->bindValue(":event_id", $eventId);
        $stmt2->execute();
    }

    return $eventId;
}

function updateEvent($event_id, $type, $title, $description, $time, $end_time, $minUsers, $organizer, $venue, $address)
{
    // Create a connection
    $pdo = connect();

    // Prepare the SQL statement
    $sql = "UPDATE Events
            SET description = :description,
                type = :type,
                title = :title,
                time = :time,
                end_time = :end_time,
                minimum_users = :minimum_users,
                organizer = :organizer,
                venue = :venue,
                address = :address
            WHERE id = :id";

    // Prepare the statement and bind the parameters
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $event_id);
    $stmt->bindParam(':type', $type);
    $stmt->bindParam(':title', $title);
    $stmt->bindParam(':description', $description);
    $stmt->bindParam(':time', $time);
    $stmt->bindParam(':end_time', $end_time);
    $stmt->bindParam(':minimum_users', $minUsers);
    $stmt->bindParam(':organizer', $organizer);
    $stmt->bindParam(':venue', $venue);
    $stmt->bindParam(':address', $address);

    // Execute the statement
    if ($stmt->execute()) {
        echo "Event updated successfully.";
    } else {
        echo "Error updating event.";
    }
}

function updateOutlineDay($userId, $day, $active)
{
    // Create a connection
    $pdo = connect();

    // Prepare the SQL statement
    $sql = "";
    if ($active) {
        $sql = "INSERT IGNORE INTO OutlineSchedule (user_id, day)
            VALUES (:id, :day);";
    } else {
        $sql = "DELETE IGNORE FROM OutlineSchedule
            WHERE user_id = :id AND day = :day;";
    }

    // Prepare the statement and bind the parameters
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':id', $userId);
    $stmt->bindParam(':day', $day);

    // Execute the statement
    if ($stmt->execute()) {
        echo "Outline schedule updated successfully.";
    } else {
        echo "Error updating outline schedule.";
    }


    // Now, update all events of today or in the future with the new outline day
    $sql = "SELECT id, date FROM Events
        WHERE date >= CURDATE() AND DAYOFWEEK(date) = :day;";

    // $day is 0 -> 6 for mon -> sun
    // we must convert it to 1 -> 7 for sun -> sat
    $sqlDay = $day + 2;
    if ($sqlDay == 8) $sqlDay = 1;

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(":day", $sqlDay);
    $stmt->execute();

    if ($active) {
        $sql = "INSERT IGNORE INTO Schedule (user_id, event_id, deliberate)
            VALUES (:user_id, :event_id, false)";

    } else {
        $sql = "DELETE IGNORE FROM Schedule
            WHERE user_id = :user_id AND event_id = :event_id AND deliberate = false;";
    }


    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stmt2 = $pdo->prepare($sql);
        $stmt2->bindValue(":user_id", $userId);
        $stmt2->bindValue(":event_id", $row["id"]);
        $stmt2->execute();
    }
}

function updateEventSchedule($userId, $eventId, $deliberate, $active)
{
    // Create a connection
    $pdo = connect();

    // Prepare the SQL statement
    if ($active) {
        $sql = "INSERT INTO Schedule (user_id, event_id, deliberate)
            VALUES (:user_id, :event_id, :deliberate)
            ON DUPLICATE KEY UPDATE deliberate = :deliberate;";
    } else {
        $sql = "DELETE IGNORE FROM Schedule
            WHERE user_id = :user_id AND event_id = :event_id;";
    }

    // Prepare the statement and bind the parameters
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':user_id', $userId);
    $stmt->bindParam(':event_id', $eventId);

    if ($active) {
        $stmt->bindParam(':deliberate', $deliberate);
    }

    // Execute the statement
    if ($stmt->execute()) {
        echo "Schedule updated successfully.";
    } else {
        echo "Error updating schedule.";
    }
}

function updateUserPassword($login, $new_password)
{
    // Create a connection
    $pdo = connect();

    // Prepare the SQL statement
    $sql = "UPDATE Users
            SET password = :password,
                salt = :salt
            WHERE login = :login";

    // Generate a salt
    $salt = generateSalt();
    // Hash the password with the salt
    $hashedPassword = hash('sha256', $new_password . $salt);

    // Prepare the statement and bind the parameters
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login', $login);
    $stmt->bindValue(':password', $hashedPassword);
    $stmt->bindValue(':salt', $salt);
    $stmt->execute();

    // Execute the statement
    if ($stmt->execute()) {
        echo "User password updated successfully.";
    } else {
        echo "Error updating user password.";
    }
}

function updateUserProfileData($login, $display_name, $first_name, $last_name, $email)
{
    // Create a connection
    $pdo = connect();

    // Prepare the SQL statement
    $sql = "UPDATE Users
            SET display_name = :display_name,
                first_name = :first_name,
                last_name = :last_name,
                email = :email
            WHERE login = :login";

    // Prepare the statement and bind the parameters
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login', $login);
    $stmt->bindValue(':display_name', $display_name);
    $stmt->bindValue(':email', $email);
    $stmt->bindValue(':first_name', $first_name);
    $stmt->bindValue(':last_name', $last_name);
    $stmt->execute();

    // Execute the statement
    if ($stmt->execute()) {
        echo "User updated successfully.";
    } else {
        echo "Error updating user.";
    }
}

function updateUserStatus($id, $visible, $active)
{
    if (!$active && $id == 1) {
        echo 'Cannot deactivate admin for safety reasons.';
        return;
    }

    $pdo = connect();

    // Prepare the SQL statement
    $sql = "UPDATE Users
            SET active = :active,
                visible = :visible
            WHERE id = :id";

    // Prepare the statement and bind the parameters
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id);
    $stmt->bindValue(':visible', $visible ? 1 : 0);
    $stmt->bindValue(':active', $active ? 1 : 0);
    $stmt->execute();

    // Execute the statement
    if ($stmt->execute()) {
        echo "User updated successfully.";
    } else {
        echo "Error updating user.";
    }
}

function deleteEvent($id)
{
    // Create a connection
    $pdo = connect();

    $stmt = $pdo->prepare('DELETE FROM Events WHERE id = :id');
    $stmt->bindValue(':id', $id);
    $stmt->execute();

    if ($stmt->rowCount() === 1) {
        echo 'Event deleted successfully.';
    } else {
        echo 'Error deleting event.';
    }
}

function checkLogin($login, $password)
{
    $pdo = connect();

    // Retrieve the salt from the database
    $sql = "SELECT password, id, salt FROM Users WHERE login = :login";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':login', $login);
    $stmt->execute();

    $loginCorrect = false;
    $id = 0;
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row)
    {
        $hashedPassword = $row["password"];
        $id = $row["id"];
        $salt = $row["salt"];

        // Verify the password
        $hashedInput = hash('sha256', $password . $salt);

        $loginCorrect = ($hashedInput === $hashedPassword);
    }

    return array($loginCorrect, $id);
}

function getUserActive($user_id)
{
    $pdo = connect();

    // Retrieve the outline schedule
    $sql = "SELECT active
            FROM Users
            WHERE id = :user_id;";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue("user_id", $user_id);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row['active'];
}

function initializeUser($login, $email, $password)
{
    // Create a connection
    $pdo = connect();

    // check if there is already a user with that name
    $stmt = $pdo->prepare('SELECT id, email, display_name, first_name, last_name FROM Users WHERE login = :login');
    $stmt->bindValue(':login', $login);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $user_id = $row["id"];
        $display_name = $row["display_name"];
        $first_name = $row["first_name"];
        $last_name = $row["last_name"];

        updateUserProfileData($login, $display_name, $first_name, $last_name, $email);
    } else {
        $user_id = addUser($login, $password, $email, "", "");
    }

    // remove any previous tokens
    $stmt = $pdo->prepare('DELETE FROM PasswordTokens WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', $user_id);
    $stmt->execute();

    // Encode some random bytes using base64
    $token = base64_encode(random_bytes(32));

    // Remove characters that are not URL-safe
    $token = str_replace(['+', '/', '='], ['-', '_', ''], $token);

    // create a registration token to change the users password
    $stmt = $pdo->prepare('INSERT INTO PasswordTokens (user_id, token) '
            . 'VALUES (:user_id, :token)');
    $stmt->bindValue(':user_id', $user_id);
    $stmt->bindValue(':token', $token);
    $stmt->execute();

    return array($token, $pdo->lastInsertId());
}

function fetchInitializationToken($token)
{
    $pdo = connect();

    $sql = "SELECT user_id FROM PasswordTokens WHERE token = :token";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':token', $token);
    $stmt->execute();

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row)
    {
        $user_id = $row["user_id"];

        $sql = "SELECT login, email FROM Users WHERE id = :user_id";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':user_id', $user_id);
        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row)
        {
            $login = $row["login"];
            $email = $row["email"];

            return array($login, $email);
        }
    }
}

function removeInitializationToken($userId, $token)
{
    $pdo = connect();

    // Prepare and execute the SQL statement
    $stmt = $pdo->prepare('DELETE FROM PasswordTokens WHERE token = :token');
    $stmt->bindValue(':token', $token);
    $stmt->execute();

    // Prepare and execute the SQL statement
    $stmt = $pdo->prepare('DELETE FROM PasswordTokens WHERE user_id = :user_id');
    $stmt->bindValue(':user_id', $userId);
    $stmt->execute();
}

function getNumUsersAtEvent($eventId)
{
    $pdo = connect();

    // Retrieve the outline schedule
    $sql = "SELECT COUNT(*) as row_count
            FROM Schedule
            WHERE event_id = :event_id;";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue("event_id", $eventId);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row['row_count'];
}

function getMinUsersAtEvent($eventId)
{
    $pdo = connect();

    // Retrieve the outline schedule
    $sql = "SELECT minimum_users
            FROM Events
            WHERE id = :event_id;";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue("event_id", $eventId);
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row['minimum_users'];
}

function respondEvents($startDate, $endDate) {
    $pdo = connect();

    // Retrieve the salt from the database
    $sql = "SELECT *
            FROM Events
            WHERE date >= :start AND date <= :end;";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':start', $startDate);
    $stmt->bindValue(':end', $endDate);
    $stmt->execute();

    echoEvents($pdo, $stmt);
}

function respondEvent($id) {
    $pdo = connect();

    // Retrieve the salt from the database
    $sql = "SELECT *
            FROM Events
            WHERE id = :id;";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id);
    $stmt->execute();

    echoEvents($pdo, $stmt);
}

function echoEvents($pdo, $stmt)
{
    $rows = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Retrieve the outline schedule
        $sql = "SELECT user_id, deliberate
                FROM Schedule
                WHERE event_id = :event_id;";

        $stmt2 = $pdo->prepare($sql);
        $stmt2->bindValue("event_id", $row["id"]);
        $stmt2->execute();

        $row["users"] = array();
        while ($usersRow = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $row["users"][] = $usersRow;
        }

        $rows[] = $row;
    }

    echo json_encode($rows);
}

function respondUsers() {
    $pdo = connect();

    // Retrieve the data from the database
    $sql = "SELECT id, login, display_name, first_name, last_name, email, visible, active
            FROM Users;";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $rows = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Retrieve the outline schedule
        $sql = "SELECT day
                FROM OutlineSchedule
                WHERE user_id = :user_id;";

        $stmt2 = $pdo->prepare($sql);
        $stmt2->bindValue("user_id", $row["id"]);
        $stmt2->execute();

        for ($i = 0; $i < 7; $i++) {
            $row["day_".$i] = false;
        }

        while ($dayRow = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $row["day_".$dayRow["day"]] = true;
        }

        $rows[] = $row;
    }

    echo json_encode($rows);
}

function dumpUsers() {
    $pdo = connect();

    // Retrieve the data from the database
    $sql = "SELECT * FROM Users;";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $rows = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Retrieve the outline schedule
        $sql = "SELECT day
                FROM OutlineSchedule
                WHERE user_id = :user_id;";

        $stmt2 = $pdo->prepare($sql);
        $stmt2->bindValue("user_id", $row["id"]);
        $stmt2->execute();

        for ($i = 0; $i < 7; $i++) {
            $row["day_".$i] = false;
        }

        while ($dayRow = $stmt2->fetch(PDO::FETCH_ASSOC)) {
            $row["day_".$dayRow["day"]] = true;
        }

        $rows[] = $row;
    }

    echo '<table>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $key => $value) {
            echo "<td> $key $value </td>";
        }
        echo '</tr>';
    }
    echo '</table>';
}

function dumpPendingUsers() {
    $pdo = connect();

    // Retrieve the data from the database
    $sql = "SELECT * FROM PasswordTokens;";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();

    $rows = array();
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $rows[] = $row;
    }

    echo '<table>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($row as $key => $value) {
            echo "<td> $key $value </td>";
        }
        echo '</tr>';
    }
    echo '</table>';
}


?>
