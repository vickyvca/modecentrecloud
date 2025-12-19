<?php
header("Content-Type: application/json"); // Set the content type to JSON

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['KodeBarang'])) {
    // Retrieve the value from the GET parameter
    $KodeBarang = $_GET['KodeBarang'];

    // Connect to the database
    $serverName = "MODESERVER";
    $connectionOptions = array("Database" => "MODECENTRE", "Uid" => "sa", "PWD" => "mode1234ABC");
    $conn = sqlsrv_connect($serverName, $connectionOptions);

    if ($conn) {
        $sql = "SELECT T_STOK.ID, T_BARANG.KODEBRG, T_BARANG.NAMABRG, T_BARANG.ARTIKELBRG, T_BARANG.HGJUAL, T_BARANG.DISC, T_STOK.ST01, T_STOK.ST03 
                FROM T_BARANG
                JOIN T_STOK ON T_BARANG.id = T_STOK.id
                WHERE T_BARANG.KODEBRG = ?";
        
        $stmt = sqlsrv_prepare($conn, $sql, array($KodeBarang));

        if ($stmt) {
            if (sqlsrv_execute($stmt)) {
                $result = [];
                while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                    $hargaNetto = $row['HGJUAL'] - ($row['HGJUAL'] * ($row['DISC'] / 100));
                    $row['HargaNetto'] = $hargaNetto;
                    $result[] = $row;
                }
                echo json_encode(["status" => "success", "data" => $result]);
            } else {
                echo json_encode(["status" => "error", "message" => "Error executing the SQL statement"]);
            }
            sqlsrv_free_stmt($stmt);
        } else {
            echo json_encode(["status" => "error", "message" => "Error preparing the SQL statement"]);
        }
        sqlsrv_close($conn);
    } else {
        echo json_encode(["status" => "error", "message" => "Error: Unable to connect to the database."]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Invalid request"]);
}
?>
