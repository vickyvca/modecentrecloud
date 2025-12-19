<?php
// FILE: payroll/payroll_shift_setup.php (Lokasi: /payroll/payroll_shift_setup.php)

session_start();
require_once __DIR__ . '/payroll_lib.php'; 
require_once __DIR__ . '/../config.php'; 

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Akses ditolak. Khusus admin.');
}

// --- FUNGSI UTILITY SHIFT SETUP ---
$TEAMS_FILE = __DIR__ . '/../employee_teams.json'; 

function load_teams(): array {
    global $TEAMS_FILE;
    if (is_file($TEAMS_FILE)) {
        $data = json_decode(file_get_contents($TEAMS_FILE), true);
        return is_array($data) ? $data : ['team_A' => [], 'team_B' => []];
    }
    return ['team_A' => [], 'team_B' => []];
}

function save_teams(array $teams): bool {
    global $TEAMS_FILE;
    $result = file_put_contents($TEAMS_FILE, json_encode($teams, JSON_PRETTY_PRINT), LOCK_EX);
    return $result !== false; 
}

// --- FETCH DATA ---

$selected_employees = get_selected_employees();
$all_niks = array_column($selected_employees, 'nik');

$sales_info = [];
if (!empty($all_niks)) {
    $placeholders = implode(',', array_fill(0, count($all_niks), '?'));
    global $conn; 
    $sql = "SELECT KODESL, NAMASL FROM T_SALES WHERE KODESL IN ({$placeholders})";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($all_niks);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sales_info[$row['KODESL']] = htmlspecialchars($row['NAMASL']);
        }
    } catch (PDOException $e) {
        $sales_info = [];
    }
}

$current_teams = load_teams();

// --- PROSES POST (SIMPAN) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_shifts'])) {
    $new_teams = [
        'team_A' => $_POST['team_A'] ?? [],
        'team_B' => $_POST['team_B'] ?? [],
    ];

    $new_teams['team_A'] = array_unique(array_filter($new_teams['team_A'], 'trim'));
    $new_teams['team_B'] = array_unique(array_filter($new_teams['team_B'], 'trim'));

    $intersection = array_intersect($new_teams['team_A'], $new_teams['team_B']);
    if (!empty($intersection)) {
        $msg = "❌ Error: NIK " . implode(', ', $intersection) . " terdaftar di Team A dan Team B. Silakan perbaiki.";
    } else {
        if (save_teams($new_teams)) {
            $total_assigned = count($new_teams['team_A']) + count($new_teams['team_B']);
            $msg = "✅ Konfigurasi Shift berhasil disimpan! (Total {$total_assigned} pegawai terdaftar)";
            $current_teams = $new_teams; 
        } else {
            $msg = "❌ Gagal menyimpan file employee_teams.json. Periksa izin tulis pada file di folder root.";
        }
    }
    $_SESSION['shift_msg'] = $msg;
    header('Location: payroll_shift_setup.php'); 
    exit;
}

// --- PEMISAHAN DATA UNTUK TAMPILAN ---
$team_status = [
    'team_A' => [],
    'team_B' => [],
    'unassigned' => [],
];

foreach ($selected_employees as $spg) {
    $nik = $spg['nik'];
    $name = $sales_info[$nik] ?? $spg['nama'];
    $display_name = "{$nik} - {$name}";

    if (in_array($nik, $current_teams['team_A'])) {
        $team_status['team_A'][$nik] = $display_name;
    } elseif (in_array($nik, $current_teams['team_B'])) {
        $team_status['team_B'][$nik] = $display_name;
    } else {
        $team_status['unassigned'][$nik] = $display_name;
    }
}

$message = $_SESSION['shift_msg'] ?? null;
unset($_SESSION['shift_msg']);
?>
<!doctype html>
<html>
<head>
    <link rel="stylesheet" href="../assets/css/ui.css">
    <script defer src="../assets/js/ui.js"></script>
<meta charset="utf-8">
<title>Setup Shift Admin</title>
<style>
/* CSS Sederhana untuk Admin */
body { font-family: sans-serif; background: #121218; color: #e0e0e0; margin: 0; padding: 0;}
.header { display: flex; justify-content: space-between; align-items: center; padding: 15px 25px; background: #1e1e24; border-bottom: 1px solid #333; }
.brand { font-size: 1.5em; font-weight: bold; color: #d32f2f; }
.btn { background: #d32f2f; color: white; padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 0.9em; transition: background 0.2s; }
.btn:hover { background: #b71c1c; }
.main { max-width: 1000px; margin: 20px auto; padding: 0 20px; }
.card { background: #1e1e24; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #333; }
.message { padding: 10px; margin-bottom: 15px; border-radius: 4px; font-weight: bold; }
.message.success { background: #4caf50; color: white; }
.message.error { background: #e74c3c; color: white; }
.team-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
.team-column { border: 1px solid #4a4a58; padding: 10px; border-radius: 6px; }
.team-column h4 { margin-top: 0; border-bottom: 1px solid #333; padding-bottom: 5px; }
.team-list { list-style: none; padding: 0; margin: 0; min-height: 150px; }
.team-list li { 
    background: #33333d; 
    padding: 8px; 
    margin-bottom: 5px; 
    border-radius: 4px;
    cursor: pointer; 
    display: flex;
    justify-content: space-between;
    align-items: center;
    user-select: none;
}
.team-list li small { color: #8b8b9a; }

/* Drag and Drop Styling */
.drop-zone { min-height: 100px; border: 2px dashed transparent; }
.drop-zone.drag-over { border-color: #4fc3f7; background: #25252b; }
.item-hidden { display: none; }
</style>
</head>
<body>
<div class="container">
  <div class="grid grid-auto justify-end mb-2">
    <a href="../dashboard_admin.php" class="btn btn-secondary">Kembali ke Dashboard</a>
  </div>
</div>
    <div class="header">
        <div class="brand">Shift Setup Admin</div>
        <a class="btn" href="/dashboard_admin.php">Kembali Dashboard</a>
    </div>

    <main class="main">
        <?php if ($message): ?>
            <div class="message <?= strpos($message, '✅') !== false ? 'success' : 'error' ?>">
                <?= $message ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <h3>Konfigurasi Rotasi Shift (Team A vs Team B)</h3>
            <p>Klik NIK untuk memindahkannya ke kolom "Belum Terdaftar". **Gunakan Drag & Drop atau Tombol Tambah**.</p>
            <form method="post" id="shiftForm">
                <div class="team-grid">
                    
                    <div class="team-column">
                        <h4>Belum Terdaftar (<?= count($team_status['unassigned']) ?>)</h4>
                        <ul id="unassigned" class="team-list drop-zone" data-team="unassigned">
                            <?php foreach ($team_status['unassigned'] as $nik => $name): ?>
                                <li draggable="true" data-nik="<?= $nik ?>" data-name="<?= $name ?>">
                                    <?= $name ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>

                    <div class="team-column">
                        <h4>Team A (Shift Rotasi) - (<?= count($team_status['team_A']) ?>)</h4>
                        <ul id="teamA" class="team-list drop-zone" data-team="teamA">
                            <?php foreach ($team_status['team_A'] as $nik => $name): ?>
                                <li draggable="true" data-nik="<?= $nik ?>" data-name="<?= $name ?>">
                                    <?= $name ?>
                                    <input type="hidden" name="team_A[]" value="<?= $nik ?>">
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div style="margin-top: 10px; text-align: center;">
                            <button type="button" class="btn" onclick="moveAllFromUnassigned('teamA')">Tambah Semua</button>
                        </div>
                    </div>

                    <div class="team-column">
                        <h4>Team B (Shift Rotasi) - (<?= count($team_status['team_B']) ?>)</h4>
                        <ul id="teamB" class="team-list drop-zone" data-team="teamB">
                            <?php foreach ($team_status['team_B'] as $nik => $name): ?>
                                <li draggable="true" data-nik="<?= $nik ?>" data-name="<?= $name ?>">
                                    <?= $name ?>
                                    <input type="hidden" name="team_B[]" value="<?= $nik ?>">
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <div style="margin-top: 10px; text-align: center;">
                            <button type="button" class="btn" onclick="moveAllFromUnassigned('teamB')">Tambah Semua</button>
                        </div>
                    </div>

                </div>
                <div style="margin-top:20px; text-align: center;">
                    <button type="submit" name="save_shifts" class="btn" id="submitBtn">Simpan Konfigurasi Shift</button>
                </div>
            </form>
        </div>
    </main>

    <script>
        const unassigned_list = document.getElementById('unassigned');
        const teamA_list = document.getElementById('teamA');
        const teamB_list = document.getElementById('teamB');
        const lists = document.querySelectorAll('.team-list');
        const shiftForm = document.getElementById('shiftForm');
        let draggedItem = null;

        /**
         * Logika inti untuk memindahkan item dan mengupdate input hidden.
         */
        function moveItem(item, newList) {
            const targetTeamId = newList.id;
            
            item.style.border = '';
            item.classList.remove('selected-for-move');

            newList.appendChild(item);
            
            // Pindahkan logika update input ke fungsi terpisah agar bisa dipanggil paksa
            updateHiddenInput(item, targetTeamId);
        }
        
        /**
         * Fungsi untuk memastikan input hidden ada di item yang sesuai
         */
        function updateHiddenInput(item, targetTeamId) {
            let input = item.querySelector('input[type="hidden"]');
            const nik = item.dataset.nik;
            
            // Hapus input lama
            if (input) input.remove();
            
            // Tambahkan input baru hanya untuk Team A dan Team B
            if (targetTeamId === 'teamA' || targetTeamId === 'teamB') {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = targetTeamId + '[]';
                input.value = nik;
                item.appendChild(input);
            }
        }
        
        /**
         * Logika untuk Tombol Massal ("Tambah Semua")
         */
        function moveAllFromUnassigned(targetTeamId) {
            const targetList = document.getElementById(targetTeamId);
            const itemsToMove = Array.from(unassigned_list.querySelectorAll('li'));
            
            itemsToMove.forEach(item => {
                moveItem(item, targetList); 
            });
        }
        window.moveAllFromUnassigned = moveAllFromUnassigned; 
        
        // --- LOGIKA DRAG AND DROP ---
        lists.forEach(list => {
            list.addEventListener('dragstart', (e) => {
                if (e.target.tagName === 'LI') {
                    draggedItem = e.target;
                    e.dataTransfer.setData('text/plain', e.target.dataset.nik);
                    setTimeout(() => { e.target.classList.add('item-hidden'); }, 0);
                }
            });

            list.addEventListener('dragend', (e) => {
                e.target.classList.remove('item-hidden');
                draggedItem = null;
            });
            
            list.addEventListener('dragover', (e) => { e.preventDefault(); list.classList.add('drag-over'); });
            list.addEventListener('dragleave', () => { list.classList.remove('drag-over'); });

            list.addEventListener('drop', (e) => {
                e.preventDefault();
                list.classList.remove('drag-over');
                
                if (draggedItem) {
                    moveItem(draggedItem, list); 
                }
            });
        });
        
        // --- KLIK LISTENER (Move back to Unassigned) ---
        document.addEventListener('click', (e) => {
            const li = e.target.closest('li[data-nik]');
            if (!li) return;

            const currentList = li.closest('.team-list');
            
            if (currentList.id === 'teamA' || currentList.id === 'teamB') {
                moveItem(li, unassigned_list);
            }
        });
        
        /**
         * FINAL SAFETY CHECK: Memastikan semua input hidden dibuat sebelum form submit.
         * Ini mengatasi masalah jika JS gagal membuat input saat Drag/Drop.
         */
        shiftForm.addEventListener('submit', function(e) {
            // Hapus input lama yang mungkin tersisa
            this.querySelectorAll('input[type="hidden"]').forEach(input => input.remove());

            // Buat ulang input untuk Team A
            teamA_list.querySelectorAll('li[data-nik]').forEach(item => {
                updateHiddenInput(item, 'teamA');
            });
            
            // Buat ulang input untuk Team B
            teamB_list.querySelectorAll('li[data-nik]').forEach(item => {
                updateHiddenInput(item, 'teamB');
            });

            // Lanjutkan submit form
        });
    </script>
</body>
</html>
