<?php
$pageTitle = 'Beranda - Sistem Informasi Musholla';
require_once __DIR__ . '/template/header.php';

$bulanSekarang = (int) date('n');
$tahunSekarang = (int) date('Y');

$namaBulan = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

// Filter bulan: 0 = semua bulan, default mengikuti bulan berjalan.
$bulanDipilih = isset($_GET['bulan']) ? (int) $_GET['bulan'] : $bulanSekarang;

if ($bulanDipilih < 0 || $bulanDipilih > 12) {
    $bulanDipilih = $bulanSekarang;
}

$filterBulanAktif = $bulanDipilih >= 1 && $bulanDipilih <= 12;
$isBulanBerjalan = $filterBulanAktif && $bulanDipilih === $bulanSekarang;

$whereBulan = $filterBulanAktif
    ? "WHERE MONTH(tanggal) = $bulanDipilih AND YEAR(tanggal) = $tahunSekarang"
    : '';

$whereBulanKegiatan = $filterBulanAktif
    ? "WHERE MONTH(k.tanggal) = $bulanDipilih AND YEAR(k.tanggal) = $tahunSekarang"
    : '';

/* =========================
   RINGKASAN KEUANGAN
========================= */

$DataTotalInfaq = $connect->query(
    "SELECT COALESCE(SUM(nominal), 0) AS total FROM infaq $whereBulan"
);
$totalInfaq = (float) ($DataTotalInfaq ? $DataTotalInfaq->fetch_assoc()['total'] : 0);

$DataTotalShodaqoh = $connect->query(
    "SELECT COALESCE(SUM(nominal), 0) AS total FROM shodaqoh $whereBulan"
);
$totalshodaqoh = (float) ($DataTotalShodaqoh ? $DataTotalShodaqoh->fetch_assoc()['total'] : 0);

$qTotalKegiatan = $connect->query(
    "SELECT COUNT(*) AS total FROM kegiatan $whereBulan"
);
$totalKegiatan = (int) ($qTotalKegiatan ? $qTotalKegiatan->fetch_assoc()['total'] : 0);

$qTotalPengeluaran = $connect->query(
    "SELECT COALESCE(SUM(pengeluaran), 0) AS total FROM kegiatan $whereBulan"
);
$totalPengeluaran = (float) ($qTotalPengeluaran ? $qTotalPengeluaran->fetch_assoc()['total'] : 0);

$qTotalPemasukanKegiatan = $connect->query(
    "SELECT COALESCE(SUM(i.nominal), 0) AS total
     FROM infaq i
     JOIN kegiatan k ON k.id_kegiatan = i.id_kegiatan
     $whereBulanKegiatan"
);
$totalPemasukanKegiatan = (float) ($qTotalPemasukanKegiatan ? $qTotalPemasukanKegiatan->fetch_assoc()['total'] : 0);

$saldoKeuangan = $totalInfaq + $totalshodaqoh - $totalPengeluaran;
$tanggalHariIni = date('Y-m-d');

$awalBulan = $filterBulanAktif
    ? sprintf('%04d-%02d-01', $tahunSekarang, $bulanDipilih)
    : null;
$akhirBulan = $awalBulan !== null ? date('Y-m-t', strtotime($awalBulan)) : null;

/* =========================
   TANGGAL ACUAN JADWAL
========================= */

// Bulan berjalan memakai hari ini. Bulan lain memakai tanggal jadwal
// pertama pada bulan yang dipilih agar data bulan tersebut tetap tampil.
$tanggalAcuanSholat = $tanggalHariIni;
$tanggalAcuanImam = $tanggalHariIni;

if ($filterBulanAktif && !$isBulanBerjalan) {
    $stmtAwalSholat = $connect->prepare(
        "SELECT MIN(tanggal) AS tanggal
         FROM jadwal_sholat
         WHERE MONTH(tanggal) = ?
           AND YEAR(tanggal) = ?
           AND waktu_sholat IN ('Dzuhur', 'Ashar')"
    );
    $stmtAwalSholat->bind_param('ii', $bulanDipilih, $tahunSekarang);
    $stmtAwalSholat->execute();
    $tanggalAcuanSholat = $stmtAwalSholat->get_result()->fetch_assoc()['tanggal'] ?? null;
    $stmtAwalSholat->close();

    $stmtAwalImam = $connect->prepare(
        "SELECT MIN(tanggal) AS tanggal
         FROM jadwal_imam
         WHERE MONTH(tanggal) = ?
           AND YEAR(tanggal) = ?"
    );
    $stmtAwalImam->bind_param('ii', $bulanDipilih, $tahunSekarang);
    $stmtAwalImam->execute();
    $tanggalAcuanImam = $stmtAwalImam->get_result()->fetch_assoc()['tanggal'] ?? null;
    $stmtAwalImam->close();
}

$judulJadwalPertama = ($filterBulanAktif && !$isBulanBerjalan)
    ? $namaBulan[$bulanDipilih]
    : 'Hari Ini';

/* =========================
   JADWAL IMAM
========================= */

$jadwalImamHariIni = null;

if ($tanggalAcuanImam !== null) {
    $stmtJadwalImamHariIni = $connect->prepare(
        "SELECT
            jadwal_imam.id_imam,
            jadwal_imam.tanggal,
            jadwal_imam.waktu_sholat,
            guru.nama_guru
         FROM jadwal_imam
         JOIN guru ON jadwal_imam.id_guru = guru.id_guru
         WHERE jadwal_imam.tanggal = ?
         ORDER BY FIELD(jadwal_imam.waktu_sholat, 'Dzuhur', 'Ashar'),
                  jadwal_imam.id_imam ASC"
    );
    $stmtJadwalImamHariIni->bind_param('s', $tanggalAcuanImam);
    $stmtJadwalImamHariIni->execute();
    $jadwalImamHariIni = $stmtJadwalImamHariIni->get_result();
}

$tanggalImamBerikutnya = null;
$jadwalImamBerikutnya = null;

if ($tanggalAcuanImam !== null) {
    if ($filterBulanAktif) {
        $stmtTanggalImamBerikutnya = $connect->prepare(
            "SELECT MIN(tanggal) AS tanggal
             FROM jadwal_imam
             WHERE tanggal > ?
               AND tanggal <= ?"
        );
        $stmtTanggalImamBerikutnya->bind_param('ss', $tanggalAcuanImam, $akhirBulan);
    } else {
        $stmtTanggalImamBerikutnya = $connect->prepare(
            "SELECT MIN(tanggal) AS tanggal
             FROM jadwal_imam
             WHERE tanggal > ?"
        );
        $stmtTanggalImamBerikutnya->bind_param('s', $tanggalAcuanImam);
    }

    $stmtTanggalImamBerikutnya->execute();
    $rowTanggalImamBerikutnya = $stmtTanggalImamBerikutnya->get_result()->fetch_assoc();
    $stmtTanggalImamBerikutnya->close();
    $tanggalImamBerikutnya = $rowTanggalImamBerikutnya['tanggal'] ?? null;
}

if ($tanggalImamBerikutnya !== null) {
    $stmtJadwalImamBerikutnya = $connect->prepare(
        "SELECT
            jadwal_imam.id_imam,
            jadwal_imam.tanggal,
            jadwal_imam.waktu_sholat,
            guru.nama_guru
         FROM jadwal_imam
         JOIN guru ON jadwal_imam.id_guru = guru.id_guru
         WHERE jadwal_imam.tanggal = ?
         ORDER BY FIELD(jadwal_imam.waktu_sholat, 'Dzuhur', 'Ashar'),
                  jadwal_imam.id_imam ASC"
    );
    $stmtJadwalImamBerikutnya->bind_param('s', $tanggalImamBerikutnya);
    $stmtJadwalImamBerikutnya->execute();
    $jadwalImamBerikutnya = $stmtJadwalImamBerikutnya->get_result();
}

/* =========================
   JADWAL SHOLAT
========================= */

$jadwalHariIni = null;

if ($tanggalAcuanSholat !== null) {
    $stmtJadwalHariIni = $connect->prepare(
        "SELECT
            jadwal_sholat.id_jadwal,
            jadwal_sholat.tanggal,
            jadwal_sholat.waktu_sholat,
            kelas.nama_kelas,
            kelas.tingkat,
            kelas.bagian
         FROM jadwal_sholat
         JOIN kelas ON jadwal_sholat.id_kelas = kelas.id_kelas
         WHERE jadwal_sholat.tanggal = ?
           AND jadwal_sholat.waktu_sholat IN ('Dzuhur', 'Ashar')
         ORDER BY
            FIELD(jadwal_sholat.waktu_sholat, 'Dzuhur', 'Ashar'),
            FIELD(kelas.tingkat, 'X', 'XI', 'XII'),
            kelas.nama_kelas ASC,
            kelas.id_kelas ASC"
    );

    $stmtJadwalHariIni->bind_param('s', $tanggalAcuanSholat);
    $stmtJadwalHariIni->execute();
    $jadwalHariIni = $stmtJadwalHariIni->get_result();
}

$kelasDzuhur = [];
$kelasAshar = [];

if ($jadwalHariIni !== null) {
    while ($row = $jadwalHariIni->fetch_assoc()) {
        if ($row['waktu_sholat'] === 'Dzuhur') {
            $kelasDzuhur[] = $row;
        } elseif ($row['waktu_sholat'] === 'Ashar') {
            $kelasAshar[] = $row;
        }
    }
}

$tanggalSholatBerikutnya = null;
$jadwalSholatBerikutnya = null;

if ($tanggalAcuanSholat !== null) {
    if ($filterBulanAktif) {
        $stmtTanggalSholatBerikutnya = $connect->prepare(
            "SELECT MIN(tanggal) AS tanggal
             FROM jadwal_sholat
             WHERE tanggal > ?
               AND tanggal <= ?
               AND waktu_sholat IN ('Dzuhur', 'Ashar')"
        );
        $stmtTanggalSholatBerikutnya->bind_param('ss', $tanggalAcuanSholat, $akhirBulan);
    } else {
        $stmtTanggalSholatBerikutnya = $connect->prepare(
            "SELECT MIN(tanggal) AS tanggal
             FROM jadwal_sholat
             WHERE tanggal > ?
               AND waktu_sholat IN ('Dzuhur', 'Ashar')"
        );
        $stmtTanggalSholatBerikutnya->bind_param('s', $tanggalAcuanSholat);
    }

    $stmtTanggalSholatBerikutnya->execute();
    $rowTanggalSholatBerikutnya = $stmtTanggalSholatBerikutnya->get_result()->fetch_assoc();
    $stmtTanggalSholatBerikutnya->close();
    $tanggalSholatBerikutnya = $rowTanggalSholatBerikutnya['tanggal'] ?? null;
}

if ($tanggalSholatBerikutnya !== null) {
    $stmtJadwalSholatBerikutnya = $connect->prepare(
        "SELECT
            jadwal_sholat.id_jadwal,
            jadwal_sholat.tanggal,
            jadwal_sholat.waktu_sholat,
            kelas.nama_kelas,
            kelas.tingkat,
            kelas.bagian
         FROM jadwal_sholat
         JOIN kelas ON jadwal_sholat.id_kelas = kelas.id_kelas
         WHERE jadwal_sholat.tanggal = ?
           AND jadwal_sholat.waktu_sholat IN ('Dzuhur', 'Ashar')
         ORDER BY
            FIELD(jadwal_sholat.waktu_sholat, 'Dzuhur', 'Ashar'),
            FIELD(kelas.tingkat, 'X', 'XI', 'XII'),
            kelas.nama_kelas ASC,
            kelas.id_kelas ASC"
    );
    $stmtJadwalSholatBerikutnya->bind_param('s', $tanggalSholatBerikutnya);
    $stmtJadwalSholatBerikutnya->execute();
    $jadwalSholatBerikutnya = $stmtJadwalSholatBerikutnya->get_result();
}

// Pisahkan kelas berikutnya berdasarkan waktu sholat
$kelasDzuhurBerikutnya = [];
$kelasAsharBerikutnya = [];

if ($jadwalSholatBerikutnya !== null) {
    while ($row = $jadwalSholatBerikutnya->fetch_assoc()) {
        if ($row['waktu_sholat'] === 'Dzuhur') {
            $kelasDzuhurBerikutnya[] = $row;
        } elseif ($row['waktu_sholat'] === 'Ashar') {
            $kelasAsharBerikutnya[] = $row;
        }
    }
}

// Waktu masing-masing sholat
$waktuDzuhur = '11:50 - 13:00';
$waktuAshar = '14:50 - 15:10';
?>

<!-- Hero Section -->
<section class="hero-banner">
    <div class="hero-content">
        <p class="hero-subtitle">Selamat Datang di</p>
        <h1 class="hero-title">Sistem Informasi Musholla SMK Negeri 1 Kraksaan</h1>
        <p class="hero-desc">
            mengelola seluruh informasi data guru agama dan data seluruh kelas
            <u>SMKN 1 Kraksaan</u>
        </p>
        <p class="hero-desc">
            Jadwal Sholat dan imam, Kegiatan Keagamaan, keuangan infaq dan shodaqoh,
            dan laporan musholla dalam satu sistem yang mudah di akses.
        </p>
    </div>
    <img src="<?= asset('img/musholla_logo.png') ?>" alt="Logo Musholla" class="hero-logo-large">
</section>

<!-- Filter Bulan -->
<form method="GET" class="dashboard-filter">
    <label for="filterBulan" class="dashboard-filter-label">Periode Data</label>
    <div class="dashboard-filter-select">
        <select name="bulan" id="filterBulan" onchange="this.form.submit()">
            <option value="0" <?= $bulanDipilih === 0 ? 'selected' : ''; ?>>Semua Bulan</option>
            <?php foreach ($namaBulan as $nomorBulan => $labelBulan): ?>
                <option value="<?= $nomorBulan; ?>" <?= $bulanDipilih === $nomorBulan ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($labelBulan) . ' ' . $tahunSekarang; ?>
                </option>
            <?php endforeach; ?>
        </select>
        <i class="bi bi-chevron-down filter-caret"></i>
    </div>
</form>

<!-- Total -->
<div class="row g-3">
    <div class="col-12 col-sm-6 col-xl-4">
        <div class="stat-card">
            <div>
                <div class="stat-header">
                    <div class="stat-icon green">
                        <i class="bi bi-box2-heart"></i>
                    </div>
                    <h3 class="stat-title">Total Infaq</h3>
                </div>
                <div class="stat-value">
                    Rp<?= number_format($totalInfaq, 0, ',', '.') ?>
                </div>
            </div>
            <a href="<?= url('infaq/index.php?bulan=' . $bulanDipilih) ?>" class="stat-link">
                Lihat detail &rarr;
            </a>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-4">
        <div class="stat-card">
            <div>
                <div class="stat-header">
                    <div class="stat-icon blue">
                        <i class="bi bi-coin"></i>
                    </div>
                    <h3 class="stat-title">Total Shodaqoh</h3>
                </div>
                <div class="stat-value">
                    Rp<?= number_format($totalshodaqoh, 0, ',', '.') ?>
                </div>
            </div>
            <a href="<?= url('shodaqoh/index.php?bulan=' . $bulanDipilih) ?>" class="stat-link">
                Lihat detail &rarr;
            </a>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-4">
        <div class="stat-card">
            <div>
                <div class="stat-header">
                    <div class="stat-icon orange">
                        <i class="bi bi-card-checklist"></i>
                    </div>
                    <h3 class="stat-title">Total Kegiatan</h3>
                </div>
                <div class="stat-value">
                    <?= $totalKegiatan ?>
                </div>
            </div>
            <a href="<?= url('kegiatan/index.php?bulan=' . $bulanDipilih) ?>" class="stat-link">
                Lihat detail &rarr;
            </a>
        </div>
    </div>
</div>

<!-- Keuangan -->
<div class="row g-3 mt-1">
    <div class="col-12 col-sm-6 col-xl-4">
        <div class="stat-card">
            <div>
                <div class="stat-header">
                    <div class="stat-icon purple">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <h3 class="stat-title">Saldo Keuangan</h3>
                </div>
                <div class="stat-value">
                    Rp<?= number_format($saldoKeuangan, 0, ',', '.') ?>
                </div>
                <span class="jadwal-kelas">
                    keuangan dari total infaq, shodaqoh, dan kegiatan
                </span>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-4">
        <div class="stat-card">
            <div>
                <div class="stat-header">
                    <div class="stat-icon blue">
                        <i class="bi bi-arrow-down-left-circle"></i>
                    </div>
                    <h3 class="stat-title">Pemasukan</h3>
                </div>
                <div class="stat-value">
                    Rp<?= number_format($totalPemasukanKegiatan, 0, ',', '.') ?>
                </div>
                <span class="jadwal-kelas">
                    Infaq Yang Terhubung Ke Kegiatan
                </span>
            </div>
        </div>
    </div>

    <div class="col-12 col-sm-6 col-xl-4">
        <div class="stat-card">
            <div>
                <div class="stat-header">
                    <div class="stat-icon red">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                    <h3 class="stat-title">Total Pengeluaran</h3>
                </div>
                <div class="stat-value">
                    Rp<?= number_format($totalPengeluaran, 0, ',', '.') ?>
                </div>
            </div>
            <span class="jadwal-kelas">
                Pengeluaran dari kegiatan musholla
            </span>
        </div>
    </div>
</div>

<!-- =========================================================
     JADWAL SHOLAT & IMAM
========================================================= -->

<div class="schedule-section">

    <!-- Header -->
    <div class="section-header">
        <h4 class="section-title">
            Jadwal Sholat & Imam
        </h4>

        <a href="<?= url('jadwal_imam/index.php?bulan=' . $bulanDipilih) ?>" class="section-link">
            Lihat Semua →
        </a>
    </div>


    <!-- 4 CARD DALAM SATU GRID -->
    <div class="schedule-grid">


        <!-- =====================================================
             1. JADWAL SHOLAT HARI INI
        ====================================================== -->
        <div class="schedule-card">

            <div class="schedule-card-header">

                <div class="schedule-icon green">
                    <i class="bi bi-clock"></i>
                </div>

                <div class="schedule-card-heading">
                    <span>Jadwal Sholat</span>
                    <h5><?= htmlspecialchars($judulJadwalPertama) ?></h5>
                </div>

            </div>


            <?php if ($tanggalAcuanSholat !== null): ?>

                <div class="schedule-date">
                    <i class="bi bi-calendar3"></i>

                    <?= htmlspecialchars(hari_indonesia($tanggalAcuanSholat)) ?>,
                    <?= htmlspecialchars(date('d/m/Y', strtotime($tanggalAcuanSholat))) ?>

                </div>

            <?php endif; ?>


            <?php if (
                $tanggalAcuanSholat !== null &&
                $jadwalHariIni !== null &&
                mysqli_num_rows($jadwalHariIni) > 0
            ): ?>

                <!-- Dzuhur -->
                <div class="schedule-sholat-block">

                    <div class="schedule-time-box">

                        <span class="schedule-label">
                            Waktu Sholat Dzuhur
                        </span>

                        <strong class="schedule-time">
                            <?= htmlspecialchars($waktuDzuhur) ?>
                        </strong>

                    </div>

                    <div class="schedule-divider"></div>

                    <div class="schedule-list">

                        <?php foreach ($kelasDzuhur as $barisJadwal): ?>

                            <div class="schedule-item">

                                <div class="schedule-item-icon green">
                                    <i class="bi bi-mortarboard-fill"></i>
                                </div>

                                <div class="schedule-item-content">

                                    <span>Kelas</span>

                                    <strong>
                                        <?= htmlspecialchars($barisJadwal['tingkat']) ?>
                                        <?= htmlspecialchars($barisJadwal['nama_kelas']) ?>
                                        <?= htmlspecialchars($barisJadwal['bagian']) ?>
                                    </strong>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

                <!-- Ashar -->
                <div class="schedule-sholat-block">

                    <div class="schedule-time-box">

                        <span class="schedule-label">
                            Waktu Sholat Ashar
                        </span>

                        <strong class="schedule-time">
                            <?= htmlspecialchars($waktuAshar) ?>
                        </strong>

                    </div>

                    <div class="schedule-divider"></div>

                    <div class="schedule-list">

                        <?php foreach ($kelasAshar as $barisJadwal): ?>

                            <div class="schedule-item">

                                <div class="schedule-item-icon green">
                                    <i class="bi bi-mortarboard-fill"></i>
                                </div>

                                <div class="schedule-item-content">

                                    <span>Kelas</span>

                                    <strong>
                                        <?= htmlspecialchars($barisJadwal['tingkat']) ?>
                                        <?= htmlspecialchars($barisJadwal['nama_kelas']) ?>
                                        <?= htmlspecialchars($barisJadwal['bagian']) ?>
                                    </strong>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php else: ?>

                <div class="schedule-empty">

                    <div class="schedule-empty-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>

                    <strong>
                        Belum ada jadwal
                    </strong>

                    <span>
                        <?php if ($filterBulanAktif && !$isBulanBerjalan): ?>
                            Belum ada jadwal sholat pada bulan <?= htmlspecialchars($namaBulan[$bulanDipilih]) ?>.
                        <?php else: ?>
                            Belum ada jadwal sholat untuk hari ini.
                        <?php endif; ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>



        <!-- =====================================================
             2. JADWAL SHOLAT BERIKUTNYA
        ====================================================== -->
        <div class="schedule-card">

            <div class="schedule-card-header">

                <div class="schedule-icon green">
                    <i class="bi bi-calendar-event"></i>
                </div>

                <div class="schedule-card-heading">
                    <span>Jadwal Sholat</span>
                    <h5>Jadwal Berikutnya</h5>
                </div>

            </div>


            <?php if ($tanggalSholatBerikutnya !== null): ?>

                <div class="schedule-date">
                    <i class="bi bi-calendar3"></i>

                    <?= htmlspecialchars(hari_indonesia($tanggalSholatBerikutnya)) ?>,
                    <?= htmlspecialchars(date('d/m/Y', strtotime($tanggalSholatBerikutnya))) ?>

                </div>

            <?php endif; ?>


            <?php if (
                $tanggalSholatBerikutnya !== null &&
                (count($kelasDzuhurBerikutnya) > 0 || count($kelasAsharBerikutnya) > 0)
            ): ?>

                <!-- Dzuhur -->
                <div class="schedule-sholat-block">

                    <div class="schedule-time-box">

                        <span class="schedule-label">
                            Waktu Sholat Dzuhur
                        </span>

                        <strong class="schedule-time">
                            <?= htmlspecialchars($waktuDzuhur) ?>
                        </strong>

                    </div>

                    <div class="schedule-divider"></div>

                    <div class="schedule-list">

                        <?php foreach ($kelasDzuhurBerikutnya as $barisJadwal): ?>

                            <div class="schedule-item">

                                <div class="schedule-item-icon green">
                                    <i class="bi bi-mortarboard-fill"></i>
                                </div>

                                <div class="schedule-item-content">

                                    <span>Kelas</span>

                                    <strong>
                                        <?= htmlspecialchars($barisJadwal['tingkat']) ?>
                                        <?= htmlspecialchars($barisJadwal['nama_kelas']) ?>
                                        <?= htmlspecialchars($barisJadwal['bagian']) ?>
                                    </strong>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

                <!-- Ashar -->
                <div class="schedule-sholat-block">

                    <div class="schedule-time-box">

                        <span class="schedule-label">
                            Waktu Sholat Ashar
                        </span>

                        <strong class="schedule-time">
                            <?= htmlspecialchars($waktuAshar) ?>
                        </strong>

                    </div>

                    <div class="schedule-divider"></div>

                    <div class="schedule-list">

                        <?php foreach ($kelasAsharBerikutnya as $barisJadwal): ?>

                            <div class="schedule-item">

                                <div class="schedule-item-icon green">
                                    <i class="bi bi-mortarboard-fill"></i>
                                </div>

                                <div class="schedule-item-content">

                                    <span>Kelas</span>

                                    <strong>
                                        <?= htmlspecialchars($barisJadwal['tingkat']) ?>
                                        <?= htmlspecialchars($barisJadwal['nama_kelas']) ?>
                                        <?= htmlspecialchars($barisJadwal['bagian']) ?>
                                    </strong>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    </div>

                </div>

            <?php else: ?>

                <div class="schedule-empty">

                    <div class="schedule-empty-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>

                    <strong>
                        Belum ada jadwal
                    </strong>

                    <span>
                        <?php if ($filterBulanAktif && !$isBulanBerjalan): ?>
                            Belum ada jadwal sholat berikutnya pada bulan <?= htmlspecialchars($namaBulan[$bulanDipilih]) ?>.
                        <?php else: ?>
                            Belum ada jadwal sholat berikutnya.
                        <?php endif; ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>



        <!-- =====================================================
             3. JADWAL IMAM HARI INI
        ====================================================== -->
        <div class="schedule-card">

            <div class="schedule-card-header">

                <div class="schedule-icon blue">
                    <i class="bi bi-person-badge"></i>
                </div>

                <div class="schedule-card-heading">
                    <span>Jadwal Imam</span>
                    <h5><?= htmlspecialchars($judulJadwalPertama) ?></h5>
                </div>

            </div>


            <?php if ($tanggalAcuanImam !== null): ?>

                <div class="schedule-date">
                    <i class="bi bi-calendar3"></i>

                    <?= htmlspecialchars(hari_indonesia($tanggalAcuanImam)) ?>,
                    <?= htmlspecialchars(date('d/m/Y', strtotime($tanggalAcuanImam))) ?>

                </div>

            <?php endif; ?>


            <?php if (
                $tanggalAcuanImam !== null &&
                $jadwalImamHariIni !== null &&
                mysqli_num_rows($jadwalImamHariIni) > 0
            ): ?>

                <div class="schedule-list">

                    <?php while ($barisImam = mysqli_fetch_assoc($jadwalImamHariIni)): ?>

                        <div class="schedule-item imam">

                            <div class="schedule-item-icon blue">
                                <i class="bi bi-person-fill"></i>
                            </div>

                            <div class="schedule-item-content">

                                <span>
                                    <?= htmlspecialchars($barisImam['waktu_sholat']) ?>
                                </span>

                                <strong>
                                    <?= htmlspecialchars($barisImam['nama_guru']) ?>
                                </strong>

                            </div>

                        </div>

                    <?php endwhile; ?>

                </div>


            <?php else: ?>

                <div class="schedule-empty">

                    <div class="schedule-empty-icon">
                        <i class="bi bi-person-x"></i>
                    </div>

                    <strong>
                        Belum ada jadwal imam
                    </strong>

                    <span>
                        <?php if ($filterBulanAktif && !$isBulanBerjalan): ?>
                            Belum ada jadwal imam pada bulan <?= htmlspecialchars($namaBulan[$bulanDipilih]) ?>.
                        <?php else: ?>
                            Belum ada jadwal imam untuk hari ini.
                        <?php endif; ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>



        <!-- =====================================================
             4. JADWAL IMAM BERIKUTNYA
        ====================================================== -->
        <div class="schedule-card">

            <div class="schedule-card-header">

                <div class="schedule-icon blue">
                    <i class="bi bi-person-lines-fill"></i>
                </div>

                <div class="schedule-card-heading">
                    <span>Jadwal Imam</span>
                    <h5>Jadwal Berikutnya</h5>
                </div>

            </div>


            <?php if ($tanggalImamBerikutnya !== null): ?>

                <div class="schedule-date">
                    <i class="bi bi-calendar3"></i>

                    <?= htmlspecialchars(hari_indonesia($tanggalImamBerikutnya)) ?>,
                    <?= htmlspecialchars(date('d/m/Y', strtotime($tanggalImamBerikutnya))) ?>

                </div>

            <?php endif; ?>


            <?php if (
                $tanggalImamBerikutnya !== null &&
                $jadwalImamBerikutnya !== null &&
                mysqli_num_rows($jadwalImamBerikutnya) > 0
            ): ?>

                <div class="schedule-list">

                    <?php while ($barisImam = mysqli_fetch_assoc($jadwalImamBerikutnya)): ?>

                        <div class="schedule-item imam">

                            <div class="schedule-item-icon blue">
                                <i class="bi bi-person-fill"></i>
                            </div>

                            <div class="schedule-item-content">

                                <span>
                                    <?= htmlspecialchars($barisImam['waktu_sholat']) ?>
                                </span>

                                <strong>
                                    <?= htmlspecialchars($barisImam['nama_guru']) ?>
                                </strong>

                            </div>

                        </div>

                    <?php endwhile; ?>

                </div>


            <?php else: ?>

                <div class="schedule-empty">

                    <div class="schedule-empty-icon">
                        <i class="bi bi-person-x"></i>
                    </div>

                    <strong>
                        Belum ada jadwal imam
                    </strong>

                    <span>
                        <?php if ($filterBulanAktif && !$isBulanBerjalan): ?>
                            Belum ada jadwal imam berikutnya pada bulan <?= htmlspecialchars($namaBulan[$bulanDipilih]) ?>.
                        <?php else: ?>
                            Belum ada jadwal imam berikutnya.
                        <?php endif; ?>
                    </span>

                </div>

            <?php endif; ?>

        </div>


    </div>

</div>

<?php require_once __DIR__ . '/template/footer.php'; ?>