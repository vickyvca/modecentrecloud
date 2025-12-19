<?php
// Check if the KODEBRG was submitted via POST
if (isset($_POST['KODEBRG'])) {
    $serverName = "MODESERVER";
    $connectionOptions = array(
        "Database" => "MODECENTRE",
        "Uid" => "sa",
        "PWD" => "mode1234ABC"
    );
    // Establishes the connection
    $conn = sqlsrv_connect($serverName, $connectionOptions);

    // Check if the connection to the database was successful
    if ($conn === false) {
        echo "Unable to connect.</br>";
        die(print_r(sqlsrv_errors(), true));
    }

    $kodebrg = $_POST['KODEBRG'];

    // Prepare the SQL query
    $tsql = "SELECT T_STOK.ID, KODEBRG, NAMABRG, ARTIKELBRG, ST01, ST02, ST03, ST04
        FROM T_BARANG
        JOIN T_STOK ON T_BARANG.id = T_STOK.id
        WHERE T_BARANG.KODEBRG = ?";

    $params = array($kodebrg);

    // Execute the query
    $stmt = sqlsrv_query($conn, $tsql, $params);

    // Check if the query execution was successful
    if ($stmt === false) {
        echo "Error in query preparation/execution.</br>";
        die(print_r(sqlsrv_errors(), true));
    }

    // Check if any rows were returned
    if (sqlsrv_has_rows($stmt)) {
        // Display the results in a table
        echo "<div class='container'>
                <div class='row justify-content-center'>
                    <div class='col-md-10'>
                        <table class='table table-striped'>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>KODEBRG</th>
                                    <th>NAMABRG</th>
                                    <th>ARTIKELBRG</th>
                                    <th>ST01</th>
                                    <th>ST02</th>
                                    <th>ST03</th>
                                    <th>ST04</th>
                                </tr>
                            </thead>
                            <tbody>";

        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            echo "<tr>
                    <td>{$row['ID']}</td>
                    <td>{$row['KODEBRG']}</td>
                    <td>{$row['NAMABRG']}</td>
                    <td>{$row['ARTIKELBRG']}</td>
                    <td>{$row['ST01']}</td>
                    <td>{$row['ST02']}</td>
                    <td>{$row['ST03']}</td>
                    <td>{$row['ST04']}</td>
                </tr>";
        }

        echo "          </tbody>
                        </table>
                    </div>
                </div>
            </div>";
    } else {
        echo "No results found.";
    }

    // Close the connection
    sqlsrv_free_stmt($stmt);
    sqlsrv_close($conn);
}
?>
