<?php
// FILE: payroll/payroll_lib.php

require_once __DIR__.'/../config.php'; 

/* ============ Util (Tidak Diubah) ============ */
function fmt_idr($v){
    if($v===null || $v==='') return '-';
    return number_format((float)$v,0,',','.');
}
function ym_first_last_day(string $ym): array {
    $start = $ym.'-01';
    $end   = date('Y-m-t', strtotime($start));
    return [$start, $end];
}
function ym_now(): string {
    return date('Y-m');
}

/* ============ Target & Pegawai (Tidak Diubah) ============ */
function get_target_per_orang(string $ym): float {
    $file = dirname(__DIR__).'/target_manual.json';
    if (is_file($file)) {
        $all = json_decode(file_get_contents($file), true);
        return (float)($all[$ym]['per_orang'] ?? 0);
    }
    return 0;
}
function get_target_total(string $ym): float {
    $file = dirname(__DIR__).'/target_manual.json';
    if (is_file($file)) {
        $all = json_decode(file_get_contents($file), true);
        return (float)($all[$ym]['total'] ?? 0);
    }
    return 0;
}
function get_selected_employees(): array {
    $file = dirname(__DIR__).'/target_selected.json';
    $list = [];
    if (is_file($file)) {
        $raw = json_decode(file_get_contents($file), true);
        if (is_array($raw)) {
            foreach ($raw as $row) {
                $parts = explode(' - ', (string)$row, 2);
                $nik   = trim($parts[0] ?? '');
                $nama  = trim($parts[1] ?? $nik);
                if ($nik !== '') $list[] = ['nik'=>$nik,'nama'=>$nama];
            }
        }
    }
    return $list;
}

/**
 * Hitung jadwal shift berdasarkan team rotasi (employee_teams.json) dan tanggal.
 * Team A: genap = S1, ganjil = S2. Team B kebalikannya.
 */
function get_expected_shift_for_date(string $nik, string $date): ?string {
    $team_file = __DIR__ . '/../employee_teams.json';
    if (!is_file($team_file)) return null;
    $teams = json_decode(@file_get_contents($team_file), true);
    if (!is_array($teams)) return null;

    $teamA = $teams['team_A'] ?? [];
    $teamB = $teams['team_B'] ?? [];

    try {
        $base = new DateTime('2025-01-01');
        $target = new DateTime($date);
    } catch (Exception $e) {
        return null;
    }

    $is_odd_day = ((int)$base->diff($target)->days % 2) === 1;

    if (in_array($nik, $teamA, true)) {
        return $is_odd_day ? 'S2' : 'S1';
    }
    if (in_array($nik, $teamB, true)) {
        return $is_odd_day ? 'S1' : 'S2';
    }
    return null;
}

/* ============ Omzet & JSON (Tidak Diubah) ============ */
function get_sales_total_by_nik(PDO $conn, string $ym): array {
    [$d1, $d2] = ym_first_last_day($ym);
    $sql = "SELECT KODESL AS NIK, SUM(NETTO) AS TOTAL FROM dbo.V_JUAL WHERE TGL BETWEEN :d1 AND :d2 GROUP BY KODESL";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':d1'=>$d1, ':d2'=>$d2]);
    } catch (PDOException $e) { return []; }
    $map = [];
    while($r = $stmt->fetch(PDO::FETCH_ASSOC)){
        $map[(string)$r['NIK']] = (float)($r['TOTAL'] ?? 0);
    }
    return $map;
}
function payroll_file_for(string $ym): string {
    global $PAYROLL_DATA_DIR;
    return $PAYROLL_DATA_DIR . '/' . $ym . '.json';
}
function save_payroll_json(string $ym, array $data): bool {
    $file = payroll_file_for($ym);
    $data['__meta__'] = [ 'version' => '2.1', 'saved_at' => date('c') ];
    if (!is_dir(dirname($file))) @mkdir(dirname($file), 0777, true);
    return (bool)file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
function load_payroll_json(string $ym): array {
    $file = payroll_file_for($ym);
    if (is_file($file)) {
        $js = json_decode(file_get_contents($file), true);
        return is_array($js) ? $js : [];
    }
    return ['bulan' => $ym, 'items' => [], 'params' => ['currency' => 'IDR']];
}

/* ============ Builder item (Tidak Diubah) ============ */
function ensure_item(array &$payroll, string $nik, string $nama): void {
    foreach($payroll['items'] as $i => $it){
        if(($it['nik'] ?? '') === $nik){
            $payroll['items'][$i]['nama'] = $nama;
            return;
        }
    }
    $payroll['items'][] = [
        'nik'=>$nik, 'nama'=>$nama, 'jabatan'=>'',
        'gapok'=>0, 'komisi'=>0, 'lembur'=>0, 'bonus_absensi'=>0, 'bonus'=>0,
        'tunj_jabatan'=>0, 'tunj_bpjs'=>0, 'potongan'=>0, 'catatan'=>'',
        'absen_disetujui_days' => 0, 'absen_ot_days' => 0, 
        'total'=>0, 'penjualan'=>0
    ];
}

/* ==================================================================== */
/* =================== OTAK PERHITUNGAN UTAMA (FIXED) ================= */
/* ==================================================================== */

function recalc_items(&$payroll, $sales_map, $ym) {
    global $conn;
    if (!$conn) return;

    [$d1, $d2] = ym_first_last_day($ym);

    // Definisikan nilai bonus agar mudah diubah
    define('BONUS_KEHADIRAN_FULL', 50000);
    define('BONUS_KEHADIRAN_HALF', 25000);
    define('BONUS_PER_OT', 5000); // DIUBAH MENJADI 5000

    // --- Persiapan bonus penjualan berbasis target kolektif/individu ---
    $selected = get_selected_employees();
    $selected_niks = array_column($selected, 'nik');
    $jumlah_spg = count($selected_niks);
    $target_total = get_target_total($ym);

    // Level kolektif thresholds dan peta persen
    $target_levels = [
        5  => $target_total,
        10 => round($target_total * 1.10),
        15 => round($target_total * 1.15),
        20 => round($target_total * 1.20),
    ];
    $bonus_persen_map = [ 5 => 0.005, 10 => 0.010, 15 => 0.015, 20 => 0.020 ];

    // Hitung total kolektif dari sales_map
    $total_kolektif = 0.0;
    if ($jumlah_spg > 0) {
        foreach ($selected_niks as $nikSel) {
            $total_kolektif += (float)($sales_map[$nikSel] ?? 0);
        }
    }

    // Tentukan level kolektif
    $level_kolektif = 0;
    if ($target_total > 0) {
        foreach ([20, 15, 10, 5] as $lvl) {
            if ($total_kolektif >= ($target_levels[$lvl] ?? 0)) { $level_kolektif = $lvl; break; }
        }
    }

    foreach ($payroll['items'] as &$it) {
        $nik = $it['nik'];

        // --- 1. Perhitungan Absensi & Lembur (bisa manual override) ---
        if (!($it['manual_attendance'] ?? false)) {
            // Menghitung dari T_PENGAJUAN_LIBUR
            $total_absen_disetujui = 0;
            try {
                $sql_libur = "SELECT TGL_MULAI, TGL_SELESAI FROM T_PENGAJUAN_LIBUR 
                              WHERE KODESL = :nik AND STATUS = 'APPROVED'
                              AND TGL_MULAI <= :end_date AND TGL_SELESAI >= :start_date";
                $stmt_libur = $conn->prepare($sql_libur);
                $stmt_libur->execute([':nik' => $nik, ':start_date' => $d1, ':end_date' => $d2]);
                $leaves = $stmt_libur->fetchAll(PDO::FETCH_ASSOC);

                foreach ($leaves as $leave) {
                    $start = new DateTime(max($d1, $leave['TGL_MULAI']));
                    $end = new DateTime(min($d2, $leave['TGL_SELESAI']));
                    $total_absen_disetujui += $start->diff($end)->days + 1;
                }
            } catch (PDOException $e) { /* Abaikan jika error */ }

            // Menghitung OT dari T_ABSENSI; bedakan OT partner vs reguler
            $total_ot = 0; $total_ot_partner = 0; $total_ot_reg = 0;
            try {
                $sql_ot = "SELECT 
                                SUM(CASE WHEN OVERTIME_BONUS_FLAG = 1 THEN 1 ELSE 0 END) AS OT_ALL,
                                SUM(CASE WHEN OVERTIME_BONUS_FLAG = 1 AND OVERTIME_NOTES LIKE '%OT_PARTNER%' THEN 1 ELSE 0 END) AS OT_PARTNER,
                                SUM(CASE WHEN OVERTIME_BONUS_FLAG = 1 AND (OVERTIME_NOTES NOT LIKE '%OT_PARTNER%' OR OVERTIME_NOTES IS NULL) THEN 1 ELSE 0 END) AS OT_REG
                           FROM T_ABSENSI
                           WHERE KODESL = :nik AND TGL BETWEEN :start_date AND :end_date";
                $stmt_ot = $conn->prepare($sql_ot);
                $stmt_ot->execute([':nik' => $nik, ':start_date' => $d1, ':end_date' => $d2]);
                $row_ot = $stmt_ot->fetch(PDO::FETCH_ASSOC) ?: [];
                $total_ot = (int)($row_ot['OT_ALL'] ?? 0);
                $total_ot_partner = (int)($row_ot['OT_PARTNER'] ?? 0);
                $total_ot_reg = (int)($row_ot['OT_REG'] ?? 0);
            } catch (PDOException $e) { /* Abaikan jika error */ }

            // Kalkulasi bonus kehadiran (otomatis)
            $bonus_kehadiran = 0;
            if ($total_absen_disetujui == 0) $bonus_kehadiran = BONUS_KEHADIRAN_FULL;
            elseif ($total_absen_disetujui == 1) $bonus_kehadiran = BONUS_KEHADIRAN_HALF;

            $it['absen_disetujui_days'] = $total_absen_disetujui;
            $it['absen_ot_days'] = $total_ot;
            $it['bonus_absensi'] = $bonus_kehadiran;
            $it['lembur'] = ($total_ot_reg * BONUS_PER_OT) + ($total_ot_partner * 10000);
        }

        // --- 2. Perhitungan Penjualan (Komisi & Bonus Penjualan baru) ---
        $realisasi = (float)($sales_map[$nik] ?? 0);
        $it['penjualan'] = $realisasi;
        $it['komisi'] = round($realisasi * 0.01);

        // Hitung level individu berdasarkan target_levels dibagi jumlah_spg
        $level_individu = 0;
        if ($jumlah_spg > 0) {
            foreach ([20, 15, 10, 5] as $lvl) {
                $target_level_total = $target_levels[$lvl] ?? 0;
                if ($target_level_total > 0) {
                    $ind_target = $target_level_total / $jumlah_spg;
                    if ($realisasi >= $ind_target) { $level_individu = $lvl; break; }
                }
            }
        }

        $level_final = max($level_kolektif, $level_individu);
        $percent_final    = $level_final    > 0 ? (float)($bonus_persen_map[$level_final]    ?? 0) : 0.0;
        $percent_individu = $level_individu > 0 ? (float)($bonus_persen_map[$level_individu] ?? 0) : 0.0;

        // Breakdown bonus: individu vs kolektif
        $bonus_individu = round($realisasi * $percent_individu);
        $bonus_kolektif_base = 0;
        if ($percent_final > $percent_individu) {
            $bonus_kolektif_base = round($realisasi * ($percent_final - $percent_individu));
        }
        $bonus_kolektif_extra = 0;
        if ($level_kolektif >= 5 && $level_individu > 0) {
            $bonus_kolektif_extra = round($realisasi * 0.005);
        }
        $it['bonus_individu'] = $bonus_individu;
        $it['bonus_kolektif'] = $bonus_kolektif_base + $bonus_kolektif_extra;
        $it['bonus'] = $it['bonus_individu'] + $it['bonus_kolektif'];

        // --- 3. Hitung Total Akhir ---
        $it['total'] = 
            ($it['gapok'] ?? 0) + 
            ($it['komisi'] ?? 0) + 
            ($it['bonus'] ?? 0) +          // Bonus Penjualan
            ($it['lembur'] ?? 0) +          // Bonus Lembur OT
            ($it['bonus_absensi'] ?? 0) +   // Bonus Kehadiran
            ($it['tunj_jabatan'] ?? 0) +
            ($it['tunj_bpjs'] ?? 0) -
            ($it['potongan'] ?? 0);
    }
    unset($it); // Penting untuk unset reference
}

/* ============ WA Pegawai & Admin (JSON) ============ */
function wa_pegawai_file(): string { global $PAYROLL_DATA_DIR; return $PAYROLL_DATA_DIR . '/pegawai_wa.json'; }
function wa_admin_file(): string { global $PAYROLL_DATA_DIR; return $PAYROLL_DATA_DIR . '/admin_wa.json'; }
function get_pegawai_wa_map(): array {
    $f = wa_pegawai_file(); if (!is_file($f)) return [];
    $js = json_decode(@file_get_contents($f), true); return is_array($js)?$js:[];
}
function save_pegawai_wa_map(array $map): bool {
    $f = wa_pegawai_file(); if (!is_dir(dirname($f))) @mkdir(dirname($f),0777,true);
    return (bool)file_put_contents($f, json_encode($map, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}
function get_pegawai_wa(string $nik): ?string {
    $m = get_pegawai_wa_map(); return isset($m[$nik]) && $m[$nik] !== '' ? (string)$m[$nik] : null;
}
function set_pegawai_wa(string $nik, string $wa): bool {
    $wa = preg_replace('/[^0-9]/','',$wa);
    $m = get_pegawai_wa_map(); $m[$nik] = $wa; return save_pegawai_wa_map($m);
}
function get_admin_wa_list(): array {
    $f = wa_admin_file(); if (!is_file($f)) return [];
    $js = json_decode(@file_get_contents($f), true); return is_array($js)?array_values(array_filter($js)):[];
}
function save_admin_wa_list(array $arr): bool {
    $f = wa_admin_file(); if (!is_dir(dirname($f))) @mkdir(dirname($f),0777,true);
    $clean = [];
    foreach ($arr as $wa) {
        $wa = preg_replace('/[^0-9]/','', (string)$wa);
        if ($wa !== '') $clean[] = $wa;
    }
    return (bool)file_put_contents($f, json_encode($clean, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

/* ============ Template Pesan WA ============ */
function wa_tpl_leave_submitted_to_admin(string $nama, string $nik, string $jenis, string $mulai, string $selesai, ?string $partner = null, string $catatan = ''): string {
    $text = "[PENGAJUAN $jenis]"
          . "\nPegawai : $nama ($nik)"
          . "\nPeriode : " . date('d/m/Y', strtotime($mulai)) . " - " . date('d/m/Y', strtotime($selesai));
    if ($partner) { $text .= "\nPartner : $partner"; }
    if (trim($catatan) !== '') { $text .= "\nCatatan : ".trim($catatan); }
    return $text;
}

function wa_tpl_leave_approved_to_employee(string $nama, string $jenis, string $mulai, string $selesai): string {
    return "[KONFIRMASI $jenis]"
         . "\nHalo $nama,"
         . "\nPengajuan $jenis Anda DISETUJUI."
         . "\nPeriode: " . date('d/m/Y', strtotime($mulai)) . " - " . date('d/m/Y', strtotime($selesai));
}

function wa_tpl_leave_rejected_quota_to_employee(string $nama, string $jenis, string $tanggal): string {
    return "[INFO $jenis]"
         . "\nHalo $nama,"
         . "\nPengajuan $jenis ditolak otomatis karena kuota libur tanggal " . date('d/m/Y', strtotime($tanggal)) . " sudah penuh (2 orang).";
}

function wa_tpl_shift_swap_submitted_admin(string $tanggal, string $pemohon_nama, string $pemohon_nik, string $partner_nama, string $partner_nik): string {
    return "[PENGAJUAN TUKAR SHIFT]"
         . "\nTanggal : " . date('d/m/Y', strtotime($tanggal))
         . "\nPemohon : $pemohon_nama ($pemohon_nik)"
         . "\nPartner : $partner_nama ($partner_nik)";
}

function wa_tpl_reset_password_request_admin(string $nik, string $nama, string $catatan = ''): string {
    $txt = "[PERMINTAAN RESET PASSWORD]"
         . "\nNIK  : $nik"
         . "\nNama : $nama";
    if (trim($catatan) !== '') { $txt .= "\nCatatan: ".trim($catatan); }
    return $txt;
}
