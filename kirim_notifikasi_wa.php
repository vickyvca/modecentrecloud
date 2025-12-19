<?php
// FILE: kirim_notifikasi_wa.php (VERSI FINAL - LEBIH SEDERHANA & ANDAL)

set_time_limit(0); 
require_once __DIR__ . '/config.php';

global $conn;

try {
    // 1. Ambil notifikasi PENDING yang memiliki ReferensiID
    $sql_get = "SELECT TOP 10
                    n.ID, n.PenerimaID, n.ReferensiID,
                    s.WA_1, s.WA_2, s.WA_3, s.WA_4, s.WA_5
                FROM T_NOTIFIKASI n
                JOIN T_SUPLIER s ON n.PenerimaID = s.KODESP
                WHERE (n.StatusKirimWA = 'PENDING' OR n.StatusKirimWA IS NULL) 
                  AND n.TipePenerima = 'supplier' AND n.ReferensiID IS NOT NULL
                ORDER BY n.WaktuDibuat ASC";
    
    $stmt_get = $conn->prepare($sql_get);
    $stmt_get->execute();
    $notifikasi_list = $stmt_get->fetchAll(PDO::FETCH_ASSOC);

    if (empty($notifikasi_list)) {
        echo "Tidak ada notifikasi baru untuk dikirim.\n";
        exit;
    }

    echo "Menemukan " . count($notifikasi_list) . " notifikasi...\n";

    foreach ($notifikasi_list as $notif) {
        $id_notifikasi = $notif['ID'];
        $kodesp = $notif['PenerimaID'];
        $no_bayar = $notif['ReferensiID']; // Langsung gunakan ReferensiID yang akurat

        // 2. Kumpulkan detail transaksi berdasarkan No. Referensi yang benar
        $stmt_header = $conn->prepare("SELECT TGL FROM HIS_BAYARHUTANG WHERE NONOTA = :nobayar");
        $stmt_header->execute([':nobayar' => $no_bayar]);
        $header = $stmt_header->fetch(PDO::FETCH_ASSOC);
        if (!$header) continue;

        $stmt_detail = $conn->prepare("SELECT D.NILAI, D.JENIS, K.KET FROM HIS_DTBAYARHUTANG D JOIN T_KETHUTANG K ON D.JENIS = K.JENIS WHERE D.NOTABAYAR = :nobayar");
        $stmt_detail->execute([':nobayar' => $no_bayar]);
        $details = $stmt_detail->fetchAll(PDO::FETCH_ASSOC);
        
        // 3. Hitung total dan format pesan
        $notas_dibayar = []; $potongan_list = []; $total_kredit = 0; $total_debet = 0;
        foreach ($details as $d) {
            if ($d['JENIS'] == 1) { // KREDIT
                $notas_dibayar[] = ['nomor' => $no_bayar, 'nominal' => (float)$d['NILAI']];
                $total_kredit += (float)$d['NILAI'];
            } else { // DEBET
                $potongan_list[] = $d['KET'];
                $total_debet += (float)$d['NILAI'];
            }
        }
        
        $net_total = $total_kredit - $total_debet;
        $potongan_text = !empty($potongan_list) ? implode(', ', array_unique($potongan_list)) : 'Tidak ada';

        $detailUntukWA = [
            'notas'    => $notas_dibayar, 'potongan' => $potongan_text,
            'total'    => $net_total, 'tanggal'  => $header['TGL']
        ];
        
        // 4. Kirim WA
        $nomor_penerima = [$notif['WA_1'], $notif['WA_2'], $notif['WA_3'], $notif['WA_4'], $notif['WA_5']];
        foreach ($nomor_penerima as $nomor) {
            if (!empty($nomor)) {
                echo "Mengirim notifikasi ID $id_notifikasi (Ref: $no_bayar) ke $nomor... ";
                kirimWAPembayaranFonnte($nomor, $detailUntukWA);
                echo "OK\n";
                sleep(1);
            }
        }

        // 5. Tandai sebagai TERKIRIM
        $stmt_update = $conn->prepare("UPDATE T_NOTIFIKASI SET StatusKirimWA = 'SENT' WHERE ID = :id");
        $stmt_update->execute([':id' => $id_notifikasi]);
        echo "Notifikasi ID $id_notifikasi ditandai TERKIRIM.\n\n";
    }

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage();
    error_log("WA Notifier Error: " . $e->getMessage());
}