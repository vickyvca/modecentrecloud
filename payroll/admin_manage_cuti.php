<?php
// FILE: payroll/admin_manage_cuti.php (Admin Manager Cuti - FINAL FIX DRIVER)

session_start();
require_once __DIR__ . '/payroll_lib.php'; 
require_once __DIR__ . '/../config.php'; 

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Akses ditolak. Khusus admin.');
}

global $conn;

$action = $_GET['action'] ?? null;
$id = $_GET['id'] ?? null;
$new_status = $_GET['new_status'] ?? null;
$admin_nik = $_SESSION['nik'] ?? 'ADMIN'; 
$message = '';
$message_type = '';

// --- FUNGSI UTILITY ---
function get_sales_name(PDO $conn, $nik) {
    try {
        $stmt = $conn->prepare("SELECT TOP 1 NAMASL FROM T_SALES WHERE KODESL = :nik");
        $stmt->execute([':nik' => $nik]);
        return $stmt->fetchColumn() ?? 'N/A';
    } catch (PDOException $e) { return 'DB Error'; }
}

// --- LOGIKA PERSETUJUAN/PENOLAKAN ---
if ($action === 'update_status' && $id && $new_status && in_array($new_status, ['APPROVED', 'REJECTED'])) {
    try {
        // Mulai transaksi
        $conn->beginTransaction();

        // 1. Update status pengajuan di T_PENGAJUAN_LIBUR
        $sql = "UPDATE T_PENGAJUAN_LIBUR SET STATUS = :status, TGL_PERSETUJUAN = GETDATE(), ADMIN_NIK = :admin_nik WHERE ID = :id AND STATUS = 'PENDING'";
        $stmt = $conn->prepare($sql);
        $stmt->execute([
            ':status' => $new_status,
            ':admin_nik' => $admin_nik,
            ':id' => $id
        ]);
        
        $status_updated = $stmt->rowCount() > 0;

        if ($status_updated && $new_status === 'APPROVED') {
            // 2. Ambil detail pengajuan
            $stmt_detail = $conn->prepare("SELECT KODESL, TGL_MULAI, TGL_SELESAI, JENIS_CUTI, KETERANGAN FROM T_PENGAJUAN_LIBUR WHERE ID = ?");
            $stmt_detail->execute([$id]);
            $detail = $stmt_detail->fetch(PDO::FETCH_ASSOC);

            if ($detail) {
                $kodesl = $detail['KODESL'];
                $tgl_mulai = new DateTime($detail['TGL_MULAI']); 
                $tgl_selesai = new DateTime($detail['TGL_SELESAI']);
                $jenis_cuti = $detail['JENIS_CUTI'];

                // RULE: maksimal 2 libur disetujui per tanggal.
                $current_date_chk = clone $tgl_mulai; $violates=false; $violated_day=null;
                while ($current_date_chk <= $tgl_selesai) {
                    $dkey = $current_date_chk->format('Y-m-d');
                    $qcnt = $conn->prepare("SELECT COUNT(*) FROM T_PENGAJUAN_LIBUR WHERE STATUS='APPROVED' AND TGL_MULAI <= :d1 AND TGL_SELESAI >= :d2");
                    $qcnt->execute([':d1'=>$dkey, ':d2'=>$dkey]);
                    $cnt = (int)$qcnt->fetchColumn();
                    if ($cnt > 2) { $violates=true; $violated_day=$dkey; break; }
                    $current_date_chk->modify('+1 day');
                }
                if ($violates) {
                    // Batalkan approve -> tolak
                    $stmt = $conn->prepare("UPDATE T_PENGAJUAN_LIBUR SET STATUS='REJECTED', TGL_PERSETUJUAN=GETDATE(), ADMIN_NIK=:admin WHERE ID=:id");
                    $stmt->execute([':admin'=>$admin_nik, ':id'=>$id]);
                    $message = 'Pengajuan ditolak otomatis. Kuota libur tanggal '.htmlspecialchars($violated_day).' sudah penuh (2 orang).';
                    $message_type = 'warn';
                    $conn->commit();
                    header('Location: admin_manage_cuti.php?filter=' . urlencode($_GET['filter'] ?? 'PENDING'));
                    exit;
                }

                $current_date = clone $tgl_mulai;
                // Deteksi partner dari keterangan: format [PARTNER:NIK]
                $partner_nik = null;
                if (!empty($detail['KETERANGAN'])) {
                    if (preg_match('/\[PARTNER:([A-Za-z0-9_\-]+)\]/', $detail['KETERANGAN'], $m)) {
                        $partner_nik = $m[1];
                    }
                }
                $sql_batch = ''; // Inisialisasi string batch

                // 3. Iterasi setiap hari dan BUAT STRING SQL BATCH
                while ($current_date <= $tgl_selesai) {
                    $tgl_absen = $current_date->format('Y-m-d');
                    
                    // Amankan nilai string menggunakan quote()
                    $q_kodesl = $conn->quote($kodesl);
                    $q_tgl = $conn->quote($tgl_absen);
                    $q_status = $conn->quote($jenis_cuti);
                    
                    // a/b. UPSERT: Update jika ada, jika tidak ada insert
                    // Catatan: Jangan ubah SHIFT_JADWAL ke jenis cuti untuk menghindari dampak ke logika shift.
                    $sql_batch .= "IF EXISTS (SELECT 1 FROM T_ABSENSI WHERE KODESL = {$q_kodesl} AND TGL = {$q_tgl}) 
                                      BEGIN 
                                        UPDATE T_ABSENSI 
                                          SET STATUS_HARI = {$q_status}, 
                                              SHIFT_MASUK = NULL, 
                                              SHIFT_PULANG = NULL, 
                                              OVERTIME_BONUS_FLAG = 0 
                                          WHERE KODESL = {$q_kodesl} AND TGL = {$q_tgl}; 
                                      END 
                                      ELSE 
                                      BEGIN 
                                        INSERT INTO T_ABSENSI (KODESL, TGL, STATUS_HARI, SHIFT_JADWAL) 
                                        VALUES ({$q_kodesl}, {$q_tgl}, {$q_status}, 'S1'); 
                                      END; ";

                    // Jika ada partner, set partner lembur (OVERTIME) untuk tanggal tsb
                    if ($partner_nik) {
                        $q_partner = $conn->quote($partner_nik);
                        $sql_batch .= "IF EXISTS (SELECT 1 FROM T_ABSENSI WHERE KODESL = {$q_partner} AND TGL = {$q_tgl}) 
                                         BEGIN 
                                            UPDATE T_ABSENSI 
                                               SET OVERTIME_BONUS_FLAG = 1,
                                                   OVERTIME_NOTES = CONCAT(ISNULL(OVERTIME_NOTES,''), CASE WHEN ISNULL(OVERTIME_NOTES,'')='' THEN '' ELSE ' | ' END, 'OT_PARTNER')
                                             WHERE KODESL = {$q_partner} AND TGL = {$q_tgl};
                                         END 
                                         ELSE 
                                         BEGIN 
                                            INSERT INTO T_ABSENSI (KODESL, TGL, STATUS_HARI, SHIFT_JADWAL, OVERTIME_BONUS_FLAG, OVERTIME_NOTES)
                                            VALUES ({$q_partner}, {$q_tgl}, 'HADIR', 'S1', 1, 'OT_PARTNER');
                                         END; ";
                    }
                    
                    $current_date->modify('+1 day');
                }
                
                // 4. EKSEKUSI BATCH SQL TUNGGAL
                if (!empty($sql_batch)) {
                    $conn->exec($sql_batch); // Gunakan exec() untuk batch query
                }
                
                // --- AKHIR LOGIKA BATCH EXECUTION ---
            }
            $message = "✅ Pengajuan ID #{$id} disetujui. Data absensi telah diperbarui.";
        } elseif ($status_updated && $new_status === 'REJECTED') {
            $message = "✅ Pengajuan ID #{$id} ditolak.";
        } elseif (!$status_updated) {
            $message = "⚠️ Pengajuan ID #{$id} tidak ditemukan atau status sudah diubah.";
        } else {
             $message = "⚠️ Pengajuan diproses dengan status tak terduga.";
        }
        $message_type = 'success';
        
        $conn->commit(); // Commit transaksi jika semua berhasil

        // Kirim WA konfirmasi approve (jika applicable) setelah commit
        if (($status_updated ?? false) && ($new_status === 'APPROVED') && isset($detail)) {
            $wa_req = get_pegawai_wa($detail['KODESL'] ?? '');
            $nm_req = get_sales_name($conn, $detail['KODESL'] ?? '');
            $periode_text = date('d/m/Y', strtotime($detail['TGL_MULAI'])) . ' - ' . date('d/m/Y', strtotime($detail['TGL_SELESAI'])) ;
            if ($wa_req) {
                $pesan_ok = "Halo $nm_req, pengajuan ".($detail['JENIS_CUTI'] ?? 'LIBUR')." Anda telah DISETUJUI. Periode: $periode_text.";
                kirimWATeksFonnte($wa_req, $pesan_ok);
            }
            // Deteksi partner dari keterangan, kirim pemberitahuan
            $partner_nik = null;
            if (!empty($detail['KETERANGAN']) && preg_match('/\[PARTNER:([A-Za-z0-9_\-]+)\]/', $detail['KETERANGAN'], $m)) {
                $partner_nik = $m[1];
            }
            if (!empty($partner_nik)) {
                $wa_partner = get_pegawai_wa($partner_nik);
                if ($wa_partner) {
                    $nm_partner = get_sales_name($conn, $partner_nik);
                    $pesan_p = "Halo $nm_partner, Anda dijadwalkan menggantikan $nm_req pada periode $periode_text. (OT Partner)";
                    kirimWATeksFonnte($wa_partner, $pesan_p);
                }
            }
        }

    } catch (PDOException $e) {
        $conn->rollBack(); // Rollback jika ada error
        // Memberikan pesan error yang lebih jelas di sisi Admin
        $message = "❌ Gagal memproses pengajuan: DB Error " . htmlspecialchars($e->getMessage());
        $message_type = 'error';
    }
}

// Bulk delete duplicates within a month range
if ($action === 'delete_duplicates') {
    $ym = $_GET['bulan'] ?? date('Y-m');
    [$d1, $d2] = ym_first_last_day($ym);
    try {
        // Delete rows where ROW_NUMBER() over the partition > 1
        $sql = "WITH d AS (
                    SELECT ID,
                           ROW_NUMBER() OVER (PARTITION BY KODESL, TGL_MULAI, TGL_SELESAI, JENIS_CUTI ORDER BY ID) AS rn
                    FROM T_PENGAJUAN_LIBUR
                    WHERE TGL_MULAI <= :end AND TGL_SELESAI >= :start
               )
               DELETE FROM T_PENGAJUAN_LIBUR WHERE ID IN (SELECT ID FROM d WHERE rn > 1)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':start'=>$d1, ':end'=>$d2]);
        $message = 'Duplikat pada bulan ' . htmlspecialchars($ym) . ' telah dihapus.';
        $message_type = 'success';
    } catch (PDOException $e) {
        $message = 'Gagal hapus duplikat: ' . htmlspecialchars($e->getMessage());
        $message_type = 'error';
    }
}

// Preview duplicate entries for the selected month
if ($action === 'preview_duplicates') {
    $ym = $_GET['bulan'] ?? date('Y-m');
    [$d1, $d2] = ym_first_last_day($ym);
    $dupes = [];
    try {
        $sql = "SELECT t.* FROM T_PENGAJUAN_LIBUR t
                JOIN (
                    SELECT KODESL, TGL_MULAI, TGL_SELESAI, JENIS_CUTI, COUNT(*) AS cnt
                    FROM T_PENGAJUAN_LIBUR
                    WHERE TGL_MULAI <= :end AND TGL_SELESAI >= :start
                    GROUP BY KODESL, TGL_MULAI, TGL_SELESAI, JENIS_CUTI
                    HAVING COUNT(*) > 1
                ) d
                ON t.KODESL = d.KODESL AND t.TGL_MULAI = d.TGL_MULAI AND t.TGL_SELESAI = d.TGL_SELESAI AND t.JENIS_CUTI = d.JENIS_CUTI
                WHERE t.TGL_MULAI <= :end AND t.TGL_SELESAI >= :start
                ORDER BY t.KODESL, t.TGL_MULAI, t.ID";
        $st = $conn->prepare($sql);
        $st->execute([':start'=>$d1, ':end'=>$d2]);
        $dupes = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { $dupes = []; }
}

// Hapus pengajuan (untuk menangani duplikat)
if ($action === 'delete' && $id) {
    try {
        $stmtDel = $conn->prepare("DELETE FROM T_PENGAJUAN_LIBUR WHERE ID = :id");
        $stmtDel->execute([':id' => $id]);
        $message = "Pengajuan ID #{$id} telah dihapus.";
        $message_type = 'success';
    } catch (PDOException $e) {
        $message = "Gagal menghapus: " . htmlspecialchars($e->getMessage());
        $message_type = 'error';
    }
}


// --- AMBIL DATA PENGAJUAN (Tidak diubah) ---
$filter = $_GET['filter'] ?? 'PENDING';
$sql_filter = $filter === 'ALL' ? '' : "WHERE TPL.STATUS = :filter";

$sql = "
    SELECT 
        TPL.*,
        TS.NAMASL 
    FROM T_PENGAJUAN_LIBUR TPL
    LEFT JOIN T_SALES TS ON TPL.KODESL = TS.KODESL
    {$sql_filter}
    ORDER BY TPL.CREATED_AT DESC
";

$stmt = $conn->prepare($sql);
if ($filter !== 'ALL') {
    $stmt->execute([':filter' => $filter]);
} else {
    $stmt->execute();
}
$pengajuan_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_SESSION['absen_msg'])) {
    $message = $_SESSION['absen_msg'];
    $message_type = strpos($message, '✅') !== false ? 'success' : 'error';
    unset($_SESSION['absen_msg']);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="stylesheet" href="../assets/css/ui.css">
    <script defer src="../assets/js/ui.js"></script>
    <meta charset="UTF-8">
    <title>Admin Kelola Cuti</title>
</head>
<body>
<div class="container">
  <div class="store-header">
    <img src="../assets/img/modecentre.png" alt="Mode Centre" class="logo">
    <div class="info">
      <div class="addr">Jl Pemuda Komp Ruko Pemuda 13 - 21 Banjarnegara Jawa Tengah</div>
      <div class="contact">Contact: 0813-9983-9777</div>
    </div>
  </div>
  <div class="bar mb-2">
    <h1>Kelola Pengajuan Cuti & Libur</h1>
    <div class="grid" style="grid-template-columns: repeat(2, auto); gap:8px;">
      <a href="payroll_dashboard.php" class="btn"><i class="fa-solid fa-arrow-left"></i> Kembali ke Payroll</a>
      <a href="../dashboard_admin.php" class="btn btn-secondary"><i class="fa-solid fa-gauge"></i> Dashboard Utama</a>
    </div>
  </div>

  <?php if ($message): ?>
    <div class="<?= $message_type === 'success' ? 'notif-bar success' : 'notif-bar warn' ?>"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>

  <div class="card">
    <form method="get" class="form" style="flex-direction:row; align-items:center; gap:10px;">
      <label for="filter" class="muted">Tampilkan Status</label>
      <select name="filter" id="filter" onchange="this.form.submit()">
        <option value="PENDING" <?= $filter === 'PENDING' ? 'selected' : '' ?>>PENDING</option>
        <option value="APPROVED" <?= $filter === 'APPROVED' ? 'selected' : '' ?>>APPROVED</option>
        <option value="REJECTED" <?= $filter === 'REJECTED' ? 'selected' : '' ?>>REJECTED</option>
        <option value="ALL" <?= $filter === 'ALL' ? 'selected' : '' ?>>SEMUA</option>
      </select>
    </form>
  </div>

  <div class="card">
    <div class="table-wrap">
      <div class="bar" style="display:flex; justify-content:space-between; align-items:center; padding:8px 12px;">
        <div class="muted">Kelola Pengajuan Libur/Sakit</div>
        <div>
          <form method="get" style="display:inline">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <input type="hidden" name="bulan" value="<?= htmlspecialchars($_GET['bulan'] ?? date('Y-m')) ?>">
            <input type="hidden" name="action" value="delete_duplicates">
            <button class="btn btn-danger" onclick="return confirm('Hapus entri duplikat pada bulan ini?')"><i class="fa-solid fa-broom"></i> Hapus Duplikat (Bulan Ini)</button>
          </form>
          <form method="get" style="display:inline; margin-left:8px;">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <input type="hidden" name="bulan" value="<?= htmlspecialchars($_GET['bulan'] ?? date('Y-m')) ?>">
            <input type="hidden" name="action" value="preview_duplicates">
            <button class="btn"><i class="fa-solid fa-list"></i> Preview Duplikat</button>
          </form>
          <button class="btn" onclick="document.getElementById('admin-wa-modal').style.display='block'"><i class="fa-brands fa-whatsapp"></i> WA Admin</button>
          <form method="get" style="display:inline; margin-left:8px;">
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
            <input type="hidden" name="bulan" value="<?= htmlspecialchars($_GET['bulan'] ?? date('Y-m')) ?>">
            <input type="hidden" name="action" value="test_admin_wa">
            <button class="btn" onclick="return confirm('Kirim pesan uji coba ke seluruh nomor WA admin?')"><i class="fa-solid fa-paper-plane"></i> Tes WA Admin</button>
          </form>
        </div>
      </div>
      <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Pegawai (NIK)</th>
                        <th>Jenis</th>
                        <th>Periode</th>
                        <th>Durasi (Hari)</th>
                        <th>Keterangan</th>
                        <th>Tgl Pengajuan</th>
                        <th>Status</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pengajuan_list)): ?>
                        <tr><td colspan="9" style="text-align:center;">Tidak ada pengajuan dengan status <?= htmlspecialchars($filter) ?>.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($pengajuan_list as $p): 
                        $tgl_mulai = new DateTime($p['TGL_MULAI']);
                        $tgl_selesai = new DateTime($p['TGL_SELESAI']);
                        $durasi = $tgl_mulai->diff($tgl_selesai)->days + 1; // +1 karena inklusif
                    ?>
                    <tr>
                        <td><?= $p['ID'] ?></td>
                        <td><?= htmlspecialchars($p['NAMASL'] ?? 'N/A') ?> (<?= $p['KODESL'] ?>)</td>
                        <td><?= $p['JENIS_CUTI'] ?></td>
                        <td><?= date('d/m/Y', $tgl_mulai->getTimestamp()) ?> - <?= date('d/m/Y', $tgl_selesai->getTimestamp()) ?></td>
                        <td><?= $durasi ?></td>
                        <td><?= htmlspecialchars($p['KETERANGAN']) ?></td>
                        <td><?= date('d/m/Y H:i', strtotime($p['CREATED_AT'])) ?></td>
                        <td>
                            <span class="badge badge-<?= strtolower($p['STATUS']) ?>"><?= $p['STATUS'] ?></span>
                        </td>
                        <td>
                            <?php if ($p['STATUS'] === 'PENDING'): ?>
                                <a href="?action=update_status&id=<?= $p['ID'] ?>&new_status=APPROVED" class="btn btn-success" onclick="return confirm('Setujui pengajuan ID <?= $p['ID'] ?>? Ini akan memperbarui status absensi pegawai.')"><i class="fa-solid fa-check"></i> Setujui</a>
                                <a href="?action=update_status&id=<?= $p['ID'] ?>&new_status=REJECTED" class="btn btn-danger" onclick="return confirm('Tolak pengajuan ID <?= $p['ID'] ?>?')"><i class="fa-solid fa-xmark"></i> Tolak</a>
                            <?php endif; ?>
                            <a href="?action=delete&id=<?= $p['ID'] ?>" class="btn btn-danger" onclick="return confirm('Hapus pengajuan ID <?= $p['ID'] ?>? Data ini tidak akan dihitung di payroll.')"><i class="fa-solid fa-trash"></i> Hapus</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
    </div>
  </div>

  <!-- Modal WA Admin -->
  <div id="admin-wa-modal" class="card" style="max-width:640px; margin:16px auto; display:none;">
    <h3 style="margin-top:0"><i class="fa-brands fa-whatsapp"></i> Daftar WA Admin</h3>
    <form method="post" action="?action=save_admin_wa&filter=<?= urlencode($filter) ?>&bulan=<?= urlencode($_GET['bulan'] ?? date('Y-m')) ?>">
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>#</th><th>Nomor WA</th></tr></thead>
          <tbody>
            <?php $admin_was = get_admin_wa_list(); for($i=0;$i<max(3,count($admin_was)+1);$i++): $val=$admin_was[$i]??''; ?>
            <tr>
              <td><?= $i+1 ?></td>
              <td><input class="input" type="text" name="admin_wa[]" value="<?= htmlspecialchars($val) ?>" placeholder="Contoh: 62812xxxxxxx"></td>
            </tr>
            <?php endfor; ?>
          </tbody>
        </table>
      </div>
      <div style="display:flex; gap:8px; justify-content:flex-end; margin-top:8px;">
        <button type="button" class="btn" onclick="document.getElementById('admin-wa-modal').style.display='none'">Tutup</button>
        <button type="submit" class="btn btn-success">Simpan</button>
      </div>
      <div class="muted" style="margin-top:6px;">Nomor disimpan di file JSON internal dan dipakai untuk notifikasi WA otomatis.</div>
    </form>
  </div>

  <?php
    // Kalender Jadwal Libur Pegawai (Approved)
    $ym = $_GET['bulan'] ?? date('Y-m');
    $bulan_label = date('F Y', strtotime($ym.'-01'));
    [$range_start, $range_end] = ym_first_last_day($ym);

    // Ambil libur approved dan petakan per tanggal
    $kal = [];
    try {
        $stmtK = $conn->prepare("SELECT TPL.KODESL, TS.NAMASL, TPL.TGL_MULAI, TPL.TGL_SELESAI, TPL.JENIS_CUTI
                                 FROM T_PENGAJUAN_LIBUR TPL
                                 LEFT JOIN T_SALES TS ON TS.KODESL = TPL.KODESL
                                 WHERE TPL.STATUS='APPROVED' AND TPL.TGL_MULAI <= :end AND TPL.TGL_SELESAI >= :start");
        $stmtK->execute([':start' => $range_start, ':end' => $range_end]);
        while ($r = $stmtK->fetch(PDO::FETCH_ASSOC)) {
            $start = new DateTime(max($range_start, $r['TGL_MULAI']));
            $end   = new DateTime(min($range_end, $r['TGL_SELESAI']));
            $cur = clone $start;
            while ($cur <= $end) {
                $key = $cur->format('Y-m-d');
                $kal[$key][] = ($r['NAMASL'] ?: $r['KODESL']) . ' (' . $r['JENIS_CUTI'] . ')';
                $cur->modify('+1 day');
            }
        }
    } catch (PDOException $e) {}

    // Siapkan kalender grid
    $first = new DateTime($range_start);
    $daysInMonth = (int)date('t', strtotime($range_start));
    $startDow = (int)$first->format('N'); // 1..7 (Mon..Sun)
  ?>

  <div class="card">
    <div class="bar" style="margin-bottom:8px;">
      <h2 class="card-title" style="margin:0;">Kalender Libur Pegawai (<?= htmlspecialchars($bulan_label) ?>)</h2>
      <form method="get" class="form" style="flex-direction:row; gap:8px; align-items:center;">
        <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <input type="month" name="bulan" value="<?= htmlspecialchars($ym) ?>" onchange="this.form.submit()">
      </form>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Sen</th><th>Sel</th><th>Rab</th><th>Kam</th><th>Jum</th><th>Sab</th><th>Min</th>
          </tr>
        </thead>
        <tbody>
        <?php
          $day = 1; $printed = false;
          for ($week=0; $week<6; $week++) {
            echo '<tr>';
            for ($dow=1; $dow<=7; $dow++) {
                if ($week === 0 && $dow < $startDow) {
                    echo '<td class="muted"></td>';
                } elseif ($day > $daysInMonth) {
                    echo '<td></td>';
                } else {
                    $dateKey = date('Y-m-d', strtotime($range_start . ' +' . ($day-1) . ' day'));
                    echo '<td style="vertical-align:top;">';
                    echo '<div style="font-weight:700; margin-bottom:6px;">' . $day . '</div>';
                    if (!empty($kal[$dateKey])) {
                        foreach ($kal[$dateKey] as $item) {
                            echo '<div class="badge" style="display:block; background:#3a3a42; border:1px solid var(--border); margin-bottom:4px;">' . htmlspecialchars($item) . '</div>';
                        }
                    } else {
                        echo '<div class="muted" style="font-size:12px;">&nbsp;</div>';
                    }
                    echo '</td>';
                    $day++;
                }
            }
            echo '</tr>';
            if ($day > $daysInMonth) break;
          }
        ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
</body>
</html>
$wa_msg = null;

// Simpan daftar WA admin
if ($action === 'save_admin_wa' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $list = $_POST['admin_wa'] ?? [];
    if (!is_array($list)) $list = [];
    if (save_admin_wa_list($list)) {
        $message = 'Nomor WA admin berhasil disimpan.'; $message_type='success';
    } else {
        $message = 'Gagal menyimpan daftar WA admin.'; $message_type='error';
    }
}

