<?php
// FILE: pengajuan_libur.php
session_start();
require_once 'config.php';
require_once __DIR__ . '/payroll/payroll_lib.php';

if (!isset($_SESSION['nik']) || $_SESSION['role'] !== 'pegawai') {
    die("Akses ditolak.");
}

global $conn;
$nik = $_SESSION['nik'];
$message = '';
$message_type = '';

// Logika untuk memproses form saat disubmit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tgl_mulai = $_POST['tgl_mulai'] ?? null;
    $tgl_selesai = $_POST['tgl_selesai'] ?? null;
    $jenis_cuti = $_POST['jenis_cuti'] ?? null;
    $keterangan = $_POST['keterangan'] ?? null;

    $partner = $_POST['partner'] ?? '';
    if (!$tgl_mulai || !$tgl_selesai || !$jenis_cuti) {
        $message = 'Mohon lengkapi semua field yang diperlukan.';
        $message_type = 'error';
    } elseif ($jenis_cuti === 'LIBUR' && !$partner) {
        $message = 'Pilih partner yang akan menggantikan saat libur.';
        $message_type = 'error';
    } else {
        try {
            // Sisipkan partner ke kolom keterangan agar bisa diproses saat approval
            if ($partner) {
                $keterangan = '[PARTNER:' . $partner . '] ' . (string)$keterangan;
            }
            // Cek kuota libur per tanggal (maks 2 APPROVED). Jika penuh, tolak saat submit.
            $___start = new DateTime($tgl_mulai); $___end = new DateTime($tgl_selesai); $___full=false; $___day=null;
            try {
                while ($___start <= $___end) {
                    $___d = $___start->format('Y-m-d');
                    $___q = $conn->prepare("SELECT COUNT(*) FROM T_PENGAJUAN_LIBUR WHERE STATUS='APPROVED' AND TGL_MULAI <= :d1 AND TGL_SELESAI >= :d2");
                    $___q->execute([':d1'=>$___d, ':d2'=>$___d]);
                    if ((int)$___q->fetchColumn() >= 2) { $___full=true; $___day=$___d; break; }
                    $___start->modify('+1 day');
                }
            } catch (PDOException $e) { /* abaikan */ }
            if ($___full) { $message = 'Kuota libur tanggal ' . date('d/m/Y', strtotime($___day)) . ' sudah penuh (2 orang). Silakan pilih tanggal lain.'; $message_type='error'; }

            if (!$___full) {
            $sql = "INSERT INTO T_PENGAJUAN_LIBUR (KODESL, TGL_PENGAJUAN, TGL_MULAI, TGL_SELESAI, JENIS_CUTI, KETERANGAN, STATUS)
                    VALUES (:nik, GETDATE(), :tgl_mulai, :tgl_selesai, :jenis, :ket, 'PENDING')";
            $stmt = $conn->prepare($sql);
            $stmt->execute([':nik' => $nik, ':tgl_mulai' => $tgl_mulai, ':tgl_selesai' => $tgl_selesai, ':jenis' => $jenis_cuti, ':ket' => $keterangan]);
            $message = 'Pengajuan berhasil dikirim! Menunggu persetujuan Admin.';
            $message_type = 'success';

            // Kirim WA ke admin (jika ada)
            $admins = get_admin_wa_list();
            if (!empty($admins)) {
                $nama = $nik;
                try { $stn=$conn->prepare("SELECT NAMASL FROM T_SALES WHERE KODESL=:nik"); $stn->execute([':nik'=>$nik]); $n=$stn->fetchColumn(); if($n) $nama=$n; } catch(Exception $e){}
                $msg = "Pengajuan " . strtoupper($jenis_cuti) . " baru\n".
                       "Pegawai: $nama ($nik)\n".
                       "Periode: ".date('d/m/Y', strtotime($tgl_mulai))." - ".date('d/m/Y', strtotime($tgl_selesai))."\n".
                       ($partner? ("Partner: $partner\n") : '').
                       "Catatan: ".trim((string)$_POST['keterangan']);
                foreach ($admins as $wa) { kirimWATeksFonnte($wa, $msg); }
            }
            }
        } catch (PDOException $e) {
            $message = '❌ Gagal mengirim pengajuan: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Mengambil data libur beserta nama pegawai
$booked_dates_with_names = [];
try {
    $sql_libur = "SELECT p.TGL_MULAI, p.TGL_SELESAI, s.NAMASL
                  FROM T_PENGAJUAN_LIBUR p
                  JOIN T_SALES s ON p.KODESL = s.KODESL
                  WHERE p.STATUS = 'APPROVED'";
    $stmt_libur = $conn->prepare($sql_libur);
    $stmt_libur->execute();
    $results = $stmt_libur->fetchAll(PDO::FETCH_ASSOC);

    foreach ($results as $row) {
        $start = new DateTime($row['TGL_MULAI']);
        $end = new DateTime($row['TGL_SELESAI']);
        $end->modify('+1 day');
        $nama_pegawai = trim($row['NAMASL']);

        $period = new DatePeriod($start, new DateInterval('P1D'), $end);
        foreach ($period as $date) {
            $date_str = $date->format('Y-m-d');
            if (!isset($booked_dates_with_names[$date_str])) {
                $booked_dates_with_names[$date_str] = [];
            }
            $booked_dates_with_names[$date_str][] = $nama_pegawai;
        }
    }
} catch (PDOException $e) {
    // Biarkan array kosong jika ada error DB
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="assets/css/ui.css">
    <script defer src="assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <title>Pengajuan Libur/Sakit</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --bg: #121218; --card: #1e1e24; --text: #e0e0e0; --border: #33333d;
            --green: #58d68d; --red: #e74c3c; --blue: #4fc3f7; --text-muted: #8b8b9a;
        }
        body { font-family: 'Segoe UI', sans-serif; background: var(--bg); color: var(--text); padding: 20px; }
        .page-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; max-width: 1000px; margin: 20px auto; }
        @media (max-width: 800px) { .page-grid { grid-template-columns: 1fr; } }
        .container { background: var(--card); padding: 30px; border-radius: 12px; border: 1px solid var(--border); }
        h2 { display: flex; align-items: center; gap: 10px; }
        .back-link { color: var(--blue); margin-bottom: 20px; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
        .form-group { margin-bottom: 18px; }
        label { display: block; margin-bottom: 8px; font-weight: 600; font-size: 14px; }
        input[type="date"], select, textarea { width: 100%; padding: 12px; border: 1px solid var(--border); background: #2a2a32; color: var(--text); border-radius: 8px; box-sizing: border-box; }
        .btn-submit { background: var(--green); color: #121218; padding: 12px 20px; border: none; border-radius: 8px; cursor: pointer; font-weight: bold; width: 100%; font-size: 16px; }
        .message { padding: 12px; margin-bottom: 20px; border-radius: 8px; font-weight: 600; }
        .message.success { background: rgba(88, 214, 141, 0.2); color: var(--green); }
        .message.error { background: rgba(231, 76, 60, 0.2); color: var(--red); }
        
        #calendar-container { padding-top: 10px; }
        .calendar-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
        #monthYear { font-size: 1.2em; font-weight: 600; }
        .calendar-nav { border: 1px solid var(--border); background: #2a2a32; color: var(--text); padding: 5px 10px; border-radius: 6px; cursor: pointer; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 5px; }
        .calendar-day { text-align: center; padding: 8px; border-radius: 6px; }
        .day-name { font-weight: bold; color: var(--text-muted); font-size: 0.9em; }
        .day-number.booked { background-color: var(--red); color: white; font-weight: bold; cursor: pointer; }
        .day-number.today { border: 2px solid var(--blue); }
        .day-number:not(.empty) { cursor: default; }

        /* BARU: CSS untuk panel info */
        #leave-info-panel {
            margin-top: 20px;
            padding: 15px;
            background-color: #2a2a32;
            border-left: 4px solid var(--blue);
            border-radius: 8px;
            min-height: 50px;
            transition: opacity 0.3s ease;
        }
        #leave-info-panel strong {
            display: block;
            margin-bottom: 5px;
            color: var(--blue);
        }
    </style>
</head>
<body>
<div class="container">
  <div class="store-header">
    <img src="assets/img/modecentre.png" alt="Mode Centre" class="logo">
    <div class="info">
      <div class="addr">Jl Pemuda Komp Ruko Pemuda 13 - 21 Banjarnegara Jawa Tengah</div>
      <div class="contact">Contact: 0813-9983-9777</div>
    </div>
  </div>
  <div class="grid grid-auto justify-end mb-2">
    <a href="dashboard_pegawai.php" class="btn btn-secondary">Kembali ke Dashboard</a>
  </div>
</div>
    <div class="page-grid">
        <div class="container">
            <h2><i class="fa-solid fa-paper-plane"></i> Form Pengajuan</h2>
            <a href="dashboard_pegawai.php" class="back-link"><i class="fa-solid fa-arrow-left"></i> Kembali ke Dashboard</a>
            <?php if ($message): ?>
                <div class="message <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <form method="POST">
                <div class="form-group">
                    <label for="jenis_cuti">Jenis Pengajuan:</label>
                    <select id="jenis_cuti" name="jenis_cuti" required>
                        <option value="" disabled selected>-- Pilih Jenis --</option>
                        <option value="LIBUR">Libur</option>
                        <option value="SAKIT">Sakit</option>
                    </select>
                </div>
                <div class="form-group"><label for="tgl_mulai">Tanggal Mulai:</label><input type="date" id="tgl_mulai" name="tgl_mulai" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group"><label for="tgl_selesai">Tanggal Selesai:</label><input type="date" id="tgl_selesai" name="tgl_selesai" required value="<?= date('Y-m-d') ?>"></div>
                <div class="form-group" id="partner-group" style="display:none;">
                    <label for="partner">Pilih Partner (wajib untuk Libur):</label>
                    <select id="partner" name="partner">
                        <option value="">-- Pilih Partner --</option>
                        <?php
                          $selected = get_selected_employees();
                          foreach ($selected as $emp) {
                              if (($emp['nik'] ?? '') === $nik) continue;
                              $pnik = htmlspecialchars($emp['nik'] ?? '');
                              $pnm  = htmlspecialchars($emp['nama'] ?? $emp['nik']);
                              echo '<option value="' . $pnik . '">' . $pnm . ' (' . $pnik . ')</option>';
                          }
                        ?>
                    </select>
                </div>
                <div class="form-group"><label for="keterangan">Keterangan:</label><textarea id="keterangan" name="keterangan" rows="3"></textarea></div>
                <button type="submit" class="btn-submit">Kirim Pengajuan</button>
            </form>
        </div>

        <div class="container">
            <h2><i class="fa-solid fa-calendar-check"></i> Jadwal Libur Disetujui</h2>
            <div id="calendar-container">
                <div class="calendar-header">
                    <button id="prevMonth" class="calendar-nav">&lt;</button>
                    <div id="monthYear"></div>
                    <button id="nextMonth" class="calendar-nav">&gt;</button>
                </div>
                <div class="calendar-grid day-names">
                    <div class="calendar-day day-name">Min</div><div class="calendar-day day-name">Sen</div><div class="calendar-day day-name">Sel</div><div class="calendar-day day-name">Rab</div><div class="calendar-day day-name">Kam</div><div class="calendar-day day-name">Jum</div><div class="calendar-day day-name">Sab</div>
                </div>
                <div class="calendar-grid" id="calendar-days"></div>
            </div>
            <div id="leave-info-panel">Klik tanggal merah untuk melihat detail libur.</div>
        </div>
    </div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bookedData = <?= json_encode($booked_dates_with_names) ?>;
    const monthYearEl = document.getElementById('monthYear');
    const calendarDaysEl = document.getElementById('calendar-days');
    const prevMonthBtn = document.getElementById('prevMonth');
    const nextMonthBtn = document.getElementById('nextMonth');
    const infoPanel = document.getElementById('leave-info-panel'); // Ambil elemen panel info
    
    let currentDate = new Date();

    function renderCalendar() {
        const year = currentDate.getFullYear();
        const month = currentDate.getMonth();
        const today = new Date();
        const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;

        monthYearEl.textContent = new Intl.DateTimeFormat('id-ID', { year: 'numeric', month: 'long' }).format(currentDate);
        calendarDaysEl.innerHTML = '';
        
        const firstDayOfMonth = new Date(year, month, 1).getDay();
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        for (let i = 0; i < firstDayOfMonth; i++) {
            calendarDaysEl.innerHTML += `<div class="calendar-day day-number empty"></div>`;
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            let classes = 'calendar-day day-number';
            let dataAttr = ''; // Atribut data-names untuk menyimpan nama

            if (bookedData[dateStr]) {
                classes += ' booked';
                const names = bookedData[dateStr].join(', ');
                // Simpan nama di atribut data-names
                dataAttr = `data-names="${names}"`;
            }

            if (dateStr === todayStr) { classes += ' today'; }
            
            calendarDaysEl.innerHTML += `<div class="${classes}" ${dataAttr}>${day}</div>`;
        }
    }

    prevMonthBtn.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() - 1);
        renderCalendar();
    });

    nextMonthBtn.addEventListener('click', () => {
        currentDate.setMonth(currentDate.getMonth() + 1);
        renderCalendar();
    });

    // BARU: Tambahkan event listener untuk klik pada kalender
    calendarDaysEl.addEventListener('click', function(event) {
        // Cek apakah elemen yang diklik adalah tanggal yang 'booked'
        if (event.target.classList.contains('booked')) {
            const names = event.target.getAttribute('data-names');
            const day = event.target.textContent;
            const fullDateStr = monthYearEl.textContent.replace(currentDate.getFullYear(), `${day}, ${currentDate.getFullYear()}`);

            // Tampilkan informasi di panel
            infoPanel.innerHTML = `<strong>${fullDateStr}</strong> ${names}`;
        }
    });

    renderCalendar();
});
</script>
<script>
// Tampilkan field partner saat jenis = LIBUR
document.addEventListener('DOMContentLoaded', function(){
  const jenis = document.getElementById('jenis_cuti');
  const partnerGroup = document.getElementById('partner-group');
  function sync(){ partnerGroup.style.display = (jenis.value === 'LIBUR') ? '' : 'none'; }
  jenis.addEventListener('change', sync);
  sync();
});
</script>
</body>
</html>








