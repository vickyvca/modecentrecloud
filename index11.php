<!DOCTYPE html>
<html>
<head>
    <title>CEK STOK</title>
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body, html {
            height: 100%;
            margin: 0;
            font-family: Arial, sans-serif;
        }
        .container {
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 20px;
            border: 1px solid #ddd;
        }
        th, td {
            text-align: left;
            padding: 15px;
            border-bottom: 1px solid #ddd;
        }
        @media screen and (max-width: 767px) {
            body {
                font-size: calc(10px + 2vw);
            }
        }
    </style>
</head>
<body>
    <div class="container text-center">
        <h1>Cek Stok</h1>
        <form method="post">
            <label for="KODEBRG">Masukan Kode Barang:</label>
            <input type="text" name="MasukanKodeBarang" id="KODEBRG">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>

        <?php
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $MasukanKodeBarang = $_POST['MasukanKodeBarang'];
            $serverName = "MODESERVER";
            $connectionOptions = array("Database" => "MODECENTRE", "Uid" => "sa", "PWD" => "mode1234ABC");
            $conn = sqlsrv_connect($serverName, $connectionOptions);
            if ($conn) {
                $sql = "SELECT T_STOK.ID, T_BARANG.KODEBRG, T_BARANG.NAMABRG, T_BARANG.ARTIKELBRG, T_BARANG.HGJUAL, T_BARANG.DISC, T_STOK.ST01, T_STOK.ST03 
                        FROM T_BARANG
                        JOIN T_STOK ON T_BARANG.id = T_STOK.id
                        WHERE T_BARANG.KODEBRG = ?";
                $stmt = sqlsrv_prepare($conn, $sql, array($MasukanKodeBarang));
                if ($stmt) {
                    if (sqlsrv_execute($stmt)) {
                        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                            $hargaNetto = $row['HGJUAL'] - ($row['HGJUAL'] * ($row['DISC'] / 100));
                            echo "<table>";
                            echo "<tr><th>Kode Barang</th><td>" . $row['KODEBRG'] . "</td></tr>";
                            echo "<tr><th>Nama Barang</th><td>" . $row['NAMABRG'] . "</td></tr>";
                            echo "<tr><th>Artikel</th><td>" . $row['ARTIKELBRG'] . "</td></tr>";
                            echo "<tr><th>Harga</th><td>Rp. " . number_format($row['HGJUAL'], 0, '.', ',') . "</td></tr>";
                            echo "<tr><th>Diskon</th><td>" . $row['DISC'] . "%</td></tr>";
                            echo "<tr><th>Harga Netto</th><td>Rp. " . number_format($hargaNetto, 0, '.', ',') . "</td></tr>";
                            echo "<tr><th>Dipayuda</th><td>" . $row['ST01'] . "</td></tr>";
                            echo "<tr><th>Pemuda</th><td>" . $row['ST03'] . "</td></tr>";
                            echo "</table>";
                        }
                    } else {
                        echo "Error executing the SQL statement: " . print_r(sqlsrv_errors(), true);
                    }
                    sqlsrv_free_stmt($stmt);
                } else {
                    echo "Error preparing the SQL statement: " . print_r(sqlsrv_errors(), true);
                }
                sqlsrv_close($conn);
            } else {
                echo "Error: Unable to connect to the database.";
            }
        }
        ?>
        <a href="index.php" class="btn btn-secondary">Back to Home</a>
    </div>
</body>
</html>
