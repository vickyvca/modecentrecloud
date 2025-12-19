<?php
// Start the session
session_start();

// Check if the user is not logged in, redirect to the login page
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header("Location: login.php");
    exit();
}

// Connect to the database
$serverName = "MODESERVER";
$databaseName = "MODECENTRE"; // Replace with your actual database name
$username = "sa"; // Replace with your actual username
$password = "mode1234ABC"; // Replace with your actual password

// Establish the database connection
$conn = new PDO("sqlsrv:Server=$serverName;Database=$databaseName", $username, $password);

// Check if the connection is established successfully
if (!$conn) {
    die(print_r(sqlsrv_errors(), true));
}

// Prepare the SQL query
$sql = "SELECT KODEJN, JENIS, KODESP, NAMASP, KODEBRG, BARANG, SUM(QTY) AS QTY, SUM(BRUTO) AS BRUTO, SUM(DISKON) AS DISKON, SUM(NETTO) AS NETTO FROM (
        SELECT B.KODEJN, F.KETERANGAN AS JENIS, TGL, B.KODESP, NAMASP, KODEBRG, NAMABRG + ' ' + ARTIKELBRG AS BARANG, QTY, BRUTO, HITDISC1 + HITDISC2 AS DISKON, NETTO
        FROM HIS_JUAL J
        INNER JOIN HIS_DTJUAL D ON D.NONOTA = J.NONOTA
        INNER JOIN T_BARANG B ON B.ID = D.ID 
        LEFT OUTER JOIN T_SUPLIER S ON S.KODESP = B.KODESP
        LEFT OUTER JOIN REF_JENIS F ON F.KODEJN = B.KODEJN
        ) AS TB
        WHERE (TGL >= '2022-01-01' AND TGL <= '2022-11-30') AND KODESP = ? 
        GROUP BY KODESP, NAMASP, KODEBRG, BARANG, KODEJN, JENIS ORDER BY KODEBRG";

// Prepare the statement
$stmt = $conn->prepare($sql);

// Bind the parameter
$stmt->bindParam(1, $_SESSION['KODESP']);

// Execute the statement
$stmt->execute();

// Store the results
$result = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Check if there are any rows returned
if ($result->num_rows > 0) {
    // Display the results in a table
    echo "<table style='border-collapse: collapse;'>";
    echo "<tr><th style='padding: 5px; border: 1px solid black;'>KODESP</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>NAMASP</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>KODEBRG</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>BARANG</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>KODEJN</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>JENIS</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>QTY</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>BRUTO</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>DISKON</th>";
    echo "<th style='padding: 5px; border: 1px solid black;'>NETTO</th></tr>";
	while ($row = $result->fetch_assoc()) {
    echo "<tr>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["KODESP"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["NAMASP"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["KODEBRG"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["BARANG"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["KODEJN"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["JENIS"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["QTY"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["BRUTO"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["DISKON"] . "</td>";
    echo "<td style='padding: 5px; border: 1px solid black;'>" . $row["NETTO"] . "</td>";
    echo "</tr>";
}
echo "</table>";
} else
{
echo "No data found.";
}

// Close the statement and the database connection
$stmt->close();
$conn->close();
?>

<!-- login.php -->
<!-- You will need to create this file and add your login form code to it -->
<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
</head>
<body>
    <h1>Login</h1>
    <form method="post" action="authenticate.php">
        <label>Username:</label>
        <input type="text" name="username">
        <br><br>
        <label>Password:</label>
        <input type="password" name="password">
        <br><br>
        <input type="submit" value="Login">
    </form>
</body>
</html>