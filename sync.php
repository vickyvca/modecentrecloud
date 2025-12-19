<!DOCTYPE html>
<html>
<head>
    <title>Database Sync</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background-color: #f9f9f9;
        }

        h1 {
            margin-bottom: 20px;
            text-align: center;
            color: #333;
        }

        button {
            padding: 10px 20px;
            background-color: #4CAF50;
            color: #fff;
            border: none;
            cursor: pointer;
            font-size: 16px;
            border-radius: 5px;
        }

        #progress-container {
            margin: 20px 0;
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 5px;
            overflow: hidden;
            height: 20px;
        }

        #progress-bar {
            height: 100%;
            background-color: #4CAF50;
        }

        #result {
            margin: 20px 0;
            padding: 10px;
            background-color: #fff;
            border: 1px solid #ccc;
            border-radius: 5px;
            color: #333;
            font-size: 14px;
            line-height: 1.5;
        }

        .table-row {
            margin-bottom: 5px;
        }

        .error {
            color: #d50000;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <h1>Database Synchronization</h1>
    <button onclick="startSync()">Start Sync</button>
    <div id="progress-container">
        <div id="progress-bar"></div>
    </div>
    <div id="result"></div>

    <script>
        function startSync() {
            document.getElementById("progress-bar").style.width = "0%";
            document.getElementById("result").innerHTML = "Syncing in progress...";
            fetch("sync.php", {
                method: "POST",
                body: "sync=true"
            })
            .then(response => response.text())
            .then(data => {
                document.getElementById("result").innerHTML = data;
            })
            .catch(error => console.error(error));
        }
    </script>
</body>
</html>

<?php

// Database credentials for MODE_A
$modeA_host = '192.168.4.99';
$modeA_user = 'sa';
$modeA_pass = 'mode1234ABC';
$modeA_db = 'MODECENTRE';

// Database credentials for MODE_B
$modeB_host = '192.168.4.8';
$modeB_user = 'sa';
$modeB_pass = 'mode1234ABC';
$modeB_db = 'MODECENTRE';

class Database
{
    private $host;
    private $user;
    private $pass;
    private $db;
    private $conn;

    public function __construct($host, $user, $pass, $db)
    {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->db = $db;
    }

    public function connect()
    {
        $connectionOptions = array(
            "Database" => $this->db,
            "Uid" => $this->user,
            "PWD" => $this->pass
        );

        $this->conn = sqlsrv_connect($this->host, $connectionOptions);

        if ($this->conn === false) {
            echo "Connection failed: " . print_r(sqlsrv_errors(), true);
            return false;
        }

        return true;
    }

    public function query($query)
    {
        return sqlsrv_query($this->conn, $query);
    }
}

function syncTableData($source, $destination, $table)
{
    $sourceQuery = $source->query("SELECT * FROM $table");
    $columns = [];
    $values = [];

    while ($row = sqlsrv_fetch_array($sourceQuery, SQLSRV_FETCH_ASSOC)) {
        $columns = array_keys($row);
        $values[] = array_values($row);
    }

    $destinationQuery = $destination->query("SELECT COUNT(*) as count FROM $table");

    if (sqlsrv_fetch($destinationQuery) && sqlsrv_get_field($destinationQuery, 0) > 0) {
        // Table already has data, update the existing rows
        $updateQuery = "UPDATE $table SET ";
        foreach ($columns as $column) {
            $updateQuery .= "$column = ?, ";
        }
        $updateQuery = rtrim($updateQuery, ', ');
        $updateQuery .= " WHERE ID = ?";
        $stmt = sqlsrv_prepare($destination->conn, $updateQuery);

        foreach ($values as $row) {
            $row[] = $row[0]; // Add ID to the end for the WHERE clause
            unset($row[0]); // Remove ID from the beginning
            sqlsrv_execute($stmt, $row);
        }
    } else {
        // Table is empty, insert all rows
        $insertQuery = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES ";

        $placeholders = array_fill(0, count($columns), '?');
        $insertQuery .= "(" . implode(', ', $placeholders) . "),";
        $insertQuery = rtrim($insertQuery, ',');
        $stmt = sqlsrv_prepare($destination->conn, $insertQuery);

        foreach ($values as $row) {
            sqlsrv_execute($stmt, $row);
        }
    }

    return true;
}

if (isset($_POST['sync']) && $_POST['sync'] === 'true') {
    $modeA = new Database($modeA_host, $modeA_user, $modeA_pass, $modeA_db);
    $modeB = new Database($modeB_host, $modeB_user, $modeB_pass, $modeB_db);

    if ($modeA->connect() && $modeB->connect()) {
        $tablesQuery = $modeA->query("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'");
        $tables = [];

        while ($row = sqlsrv_fetch_array($tablesQuery, SQLSRV_FETCH_ASSOC)) {
            $tables[] = $row['TABLE_NAME'];
        }

        $totalTables = count($tables);
        $processedTables = 0;
        $result = '';

        foreach ($tables as $table) {
            $processedTables++;
            $result .= "Syncing table $table... ";

            try {
                syncTableData($modeA, $modeB, $table);
                $result .= "Done!";
            } catch (PDOException $e) {
                $result .= "Error syncing table $table: " . $e->getMessage();
            }

            $result .= "<br>";
            $progress = ($processedTables / $totalTables) * 100;
            echo "<script>document.getElementById('progress-bar').style.width = '$progress%';</script>";
            echo "<script>document.getElementById('result').innerHTML = '$result';</script>";
            ob_flush();
            flush();
        }
    } else {
        echo "Database connection failed for MODE_A or MODE_B.";
    }
}
?>
