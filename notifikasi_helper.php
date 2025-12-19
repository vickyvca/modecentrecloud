<?php
function buatNotifikasi($conn, $penerimaID, $tipePenerima, $pesan, $link = null) {
    try {
        $sql = "INSERT INTO T_NOTIFIKASI (PenerimaID, TipePenerima, Pesan, Link) VALUES (?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$penerimaID, $tipePenerima, $pesan, $link]);
        return true;
    } catch (PDOException $e) {
        error_log("Gagal membuat notifikasi: " . $e->getMessage());
        return false;
    }
}
?>