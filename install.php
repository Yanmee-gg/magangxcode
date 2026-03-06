<?php
/**
 * Xcodehoster v11 - PHP Installer
 * Programmer: Kurniawan. kurniawanajazenfone@gmail.com. xcode.or.id
 * X-code Media - xcode.or.id / xcode.co.id
 * Converted from Bash to PHP Installer
 */

// ============================================================
// KONFIGURASI & KEAMANAN
// ============================================================
define('INSTALLER_VERSION', 'v11');
define('REQUIRED_OS', 'Ubuntu');
define('REQUIRED_VERSION', '24.04');

// Hanya bisa dijalankan via CLI
if (PHP_SAPI !== 'cli') {
    die("❌ Installer ini harus dijalankan melalui terminal:\n   php install.php\n");
}

// Harus dijalankan sebagai root
if (posix_getuid() !== 0) {
    die("❌ Installer harus dijalankan sebagai root:\n   sudo php install.php\n");
}

// ============================================================
// FUNGSI HELPER
// ============================================================

function printBanner(): void {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════╗\n";
    echo "║         XCODEHOSTER " . INSTALLER_VERSION . " - PHP INSTALLER                ║\n";
    echo "║         xcode.or.id / xcode.co.id                       ║\n";
    echo "║         Programmer: Kurniawan                            ║\n";
    echo "╚══════════════════════════════════════════════════════════╝\n";
    echo "\n";
}

function info(string $msg): void {
    echo "  [INFO]  $msg\n";
}

function success(string $msg): void {
    echo "  [✔ OK]  $msg\n";
}

function error(string $msg): void {
    echo "  [ERROR] $msg\n";
}

function step(string $msg): void {
    echo "\n──────────────────────────────────────────────────────────\n";
    echo "  ▶ $msg\n";
    echo "──────────────────────────────────────────────────────────\n";
}

function runCommand(string $cmd, bool $silent = false): bool {
    if ($silent) {
        exec($cmd . ' > /dev/null 2>&1', $output, $code);
    } else {
        passthru($cmd, $code);
    }
    return $code === 0;
}

function prompt(string $question, bool $isPassword = false): string {
    if ($isPassword) {
        // Sembunyikan input password
        echo "  $question";
        system('stty -echo');
        $input = trim(fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        echo "  $question";
        $input = trim(fgets(STDIN));
    }
    return $input;
}

function checkUbuntuVersion(): string|false {
    exec('lsb_release -r 2>/dev/null', $output, $code);
    if ($code !== 0 || empty($output)) return false;
    // Output: "Release:\t24.04"
    $parts = preg_split('/\s+/', $output[0]);
    return $parts[1] ?? false;
}

function generateVouchers(int $count = 1000, int $length = 8): array {
    $vouchers = [];
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    for ($i = 0; $i < $count; $i++) {
        $voucher = '';
        for ($j = 0; $j < $length; $j++) {
            $voucher .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $vouchers[] = $voucher;
    }
    return $vouchers;
}

function sedReplace(string $file, string $from, string $to): bool {
    if (!file_exists($file)) {
        error("File tidak ditemukan: $file");
        return false;
    }
    $content = file_get_contents($file);
    $content = str_replace($from, $to, $content);
    return file_put_contents($file, $content) !== false;
}

// ============================================================
// MULAI INSTALASI
// ============================================================

printBanner();

// Cek versi Ubuntu
step("Memeriksa sistem operasi");
$version = checkUbuntuVersion();

if ($version !== REQUIRED_VERSION) {
    error("Aplikasi ini tidak mendukung distro Linux Anda.");
    error("Diperlukan Ubuntu " . REQUIRED_VERSION . ", terdeteksi: " . ($version ?: 'tidak diketahui'));
    exit(1);
}

success("Ubuntu $version terdeteksi dan didukung.");

// ============================================================
// INPUT DARI USER
// ============================================================
step("Pengumpulan Informasi Instalasi");

$ipServer      = prompt("Masukkan IP publik server          : ");
$passwordMysql = prompt("Masukkan password root MySQL        : ", true);
$domain        = prompt("Masukkan nama domain                : ");
$zoneId        = prompt("Masukkan Zone ID Cloudflare        : ");
$cfEmail       = prompt("Masukkan e-mail Cloudflare          : ");
$globalApiKey  = prompt("Masukkan Global API Key Cloudflare  : ");

echo "\n";
info("IP Server   : $ipServer");
info("Domain      : $domain");
info("CF Email    : $cfEmail");
echo "\n";

$confirm = prompt("Lanjutkan instalasi? (y/n) : ");
if (strtolower($confirm) !== 'y') {
    info("Instalasi dibatalkan.");
    exit(0);
}

// ============================================================
// INSTALASI PAKET
// ============================================================
step("Update & Install paket sistem");

runCommand('apt-get update');
runCommand('apt -y install software-properties-common');
runCommand('apt install -y apache2');
runCommand('apt install -y php');
runCommand('apt install -y mysql-server');
runCommand('apt install -y phpmyadmin');
runCommand('apt-get install -y zip unzip php-zip');
runCommand('apt install -y jq');
runCommand('apt install -y imagemagick');
success("Semua paket berhasil diinstal.");

// ============================================================
// KONFIGURASI MYSQL
// ============================================================
step("Konfigurasi MySQL");

$escapedPw = addslashes($passwordMysql);
runCommand("mysql -e \"ALTER USER 'root'@'localhost' IDENTIFIED WITH mysql_native_password BY '$escapedPw';\"");
success("Password MySQL root berhasil diatur.");

// ============================================================
// KONFIGURASI APACHE
// ============================================================
step("Konfigurasi Apache");

runCommand('a2enmod ssl');
runCommand('a2enmod cgi');
runCommand('service apache2 restart');

// Backup konfigurasi lama
runCommand('cp /etc/apache2/apache2.conf /etc/apache2/apache2.conf.backup');

// Salin konfigurasi baru
if (file_exists('support/apache2.conf')) {
    runCommand('cp support/apache2.conf /etc/apache2/');
    success("apache2.conf berhasil disalin.");
} else {
    error("support/apache2.conf tidak ditemukan, lewati.");
}

// Konfigurasi SSL direktori
runCommand('mkdir -p /etc/apache2/ssl');
runCommand('chmod 777 /etc/apache2/ssl');
touch("/etc/apache2/ssl/$domain.pem");
touch("/etc/apache2/ssl/$domain.key");
success("Direktori SSL disiapkan.");

// ============================================================
// KONFIGURASI PHP
// ============================================================
step("Konfigurasi PHP");

$phpIniPath = '/etc/php/8.3/apache2/php.ini';
if (file_exists($phpIniPath) && file_exists('support/php.ini')) {
    runCommand("cp $phpIniPath {$phpIniPath}.backup");
    runCommand('cp support/php.ini /etc/php/8.3/apache2/');
    success("php.ini berhasil dikonfigurasi.");
} else {
    error("php.ini tidak ditemukan, lewati.");
}

// ============================================================
// BUAT STRUKTUR DIREKTORI
// ============================================================
step("Membuat struktur direktori");

$dirs = [
    '/home/root', '/home/pma', '/home/www', '/home/datauser',
    '/home/xcodehoster', '/home/datapengguna', '/home/domain',
    '/home/checkdata', '/home/checkdata2'
];

foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    touch("$dir/locked");
    success("Dibuat: $dir");
}

// Set permission
runCommand('chmod -R 777 /home');
runCommand('chmod 777 /usr/lib/cgi-bin');
runCommand('chmod 777 /etc/apache2/sites-available');
success("Permission direktori diatur.");

// ============================================================
// KONFIGURASI SUDOERS
// ============================================================
step("Konfigurasi sudoers untuk www-data");

$sudoersLine = "www-data ALL=(ALL) NOPASSWD: ALL\n";
$sudoersFile = '/etc/sudoers';
$sudoersContent = file_get_contents($sudoersFile);
if (strpos($sudoersContent, 'www-data ALL=(ALL) NOPASSWD: ALL') === false) {
    file_put_contents($sudoersFile, $sudoersLine, FILE_APPEND);
    success("Sudoers diperbarui.");
} else {
    info("Entri sudoers sudah ada, lewati.");
}

// ============================================================
// SALIN FILE MANAGER
// ============================================================
step("Instalasi File Manager");

if (is_dir('filemanager')) {
    runCommand('cp -r filemanager /home/filemanager');
    runCommand('chmod -R 777 /home/filemanager');
    sedReplace('/home/filemanager/index.html', 'xcodehoster.com', $domain);
    success("File manager berhasil diinstal.");
} else {
    error("Direktori 'filemanager' tidak ditemukan, lewati.");
}

// ============================================================
// KONFIGURASI FILE SUPPORT
// ============================================================
step("Konfigurasi file-file support");

$passwordParam = "-p$passwordMysql";

$supportFiles = [
    'support/run.cgi'        => ['-ppasswordmysql' => $passwordParam, 'xcodehoster.com' => $domain],
    'support/formdata.cgi'   => ['xcodehoster.com' => $domain],
    'support/aktivasi3.cgi'  => [
        'xcodehoster.com' => $domain,
        'zoneid'          => $zoneId,
        'email'           => $cfEmail,
        'globalapikey'    => $globalApiKey,
        'ipserver'        => $ipServer,
        'domain'          => $domain,
    ],
    'support/subdomain.conf' => [
        'xcodehoster.com'       => $domain,
        'xcodehoster.com.pem'   => "$domain.pem",
        'xcodehoster.com.key'   => "$domain.key",
        'sample.xcodehoster.com'=> $domain,
    ],
    'support/domain.conf'    => [
        'xcodehoster.com'       => $domain,
        'sample.xcodehoster.com'=> $domain,
        'xcodehoster.com.pem'   => "$domain.pem",
        'xcodehoster.com.key'   => "$domain.key",
    ],
    'support/index.html'     => ['xcodehoster.com' => $domain],
];

foreach ($supportFiles as $file => $replacements) {
    if (file_exists($file)) {
        foreach ($replacements as $from => $to) {
            sedReplace($file, $from, $to);
        }
        success("Dikonfigurasi: $file");
    } else {
        error("File tidak ditemukan: $file");
    }
}

// ============================================================
// SALIN FILE KE CGI-BIN
// ============================================================
step("Instalasi CGI scripts");

$cgiFiles = [
    'support/formfree.cgi'  => '/usr/lib/cgi-bin/formfree.cgi',
    'support/run.cgi'       => '/usr/lib/cgi-bin/run.cgi',
    'support/aktivasi3.cgi' => '/usr/lib/cgi-bin/aktivasi3.cgi',
    'support/formdata.cgi'  => '/usr/lib/cgi-bin/formdata.cgi',
    'support/acak.txt'      => '/usr/lib/cgi-bin/acak.txt',
];

foreach ($cgiFiles as $src => $dst) {
    if (file_exists($src)) {
        copy($src, $dst);
        success("Disalin: $src → $dst");
    } else {
        error("Tidak ditemukan: $src");
    }
}

// Update konfigurasi di CGI yang sudah disalin
$cgiConfig = [
    '/usr/lib/cgi-bin/run.cgi' => [
        '-ppasswordmysql' => $passwordParam,
        'xcodehoster.com' => $domain,
    ],
    '/usr/lib/cgi-bin/aktivasi3.cgi' => [
        'domain'     => $domain,
        'zoneid'     => $zoneId,
        'email'      => $cfEmail,
        'globalapikey'=> $globalApiKey,
        'ipserver'   => $ipServer,
    ],
];

foreach ($cgiConfig as $file => $replacements) {
    if (file_exists($file)) {
        foreach ($replacements as $from => $to) {
            sedReplace($file, $from, $to);
        }
    }
}

// File tambahan
touch('/usr/lib/cgi-bin/vouchers.txt');
runCommand('chmod 777 /usr/lib/cgi-bin/acak.txt');

// Simpan IP server
file_put_contents('/usr/lib/cgi-bin/ip.txt', $ipServer);
success("IP server disimpan: /usr/lib/cgi-bin/ip.txt");

// ============================================================
// SALIN FILE KE XCODEHOSTER & WWW
// ============================================================
step("Deploy file web");

$xcodeSupportFiles = [
    'support/domain.conf', 'support/domain2.conf', 'support/subdomain.conf',
    'support/index.html', 'support/bootstrap.min.css', 'support/hosting.jpg',
    'support/xcodehoster21x.png', 'support/coverxcodehoster.png',
];

foreach ($xcodeSupportFiles as $src) {
    if (file_exists($src)) {
        $dst = '/home/xcodehoster/' . basename($src);
        copy($src, $dst);
    }
}

// Backup index.html lama
if (file_exists('/var/www/html/index.html')) {
    copy('/var/www/html/index.html', '/var/www/html/backup1.html');
}

// Salin phpinfo.php
if (file_exists('support/phpinfo.php')) {
    copy('support/phpinfo.php', '/var/www/html/phpinfo.php');
}

// Salin semua file xcodehoster ke www/html
runCommand('cp /home/xcodehoster/* /var/www/html/');
success("File web berhasil di-deploy.");

// ============================================================
// KONFIGURASI VIRTUAL HOST APACHE
// ============================================================
step("Konfigurasi Apache Virtual Host");

$vhostSrc = "support/domain.conf";
$vhostDst = "/etc/apache2/sites-available/$domain.conf";

if (file_exists($vhostSrc)) {
    copy($vhostSrc, $vhostDst);
    // Perbaikan tambahan untuk domain.conf
    sedReplace($vhostDst, "sample.$domain", $domain);
    sedReplace($vhostDst, "sample", "xcodehoster");
    sedReplace($vhostDst, "xcodehoster.com.pem", "$domain.pem");
    sedReplace($vhostDst, "xcodehoster.com.key", "$domain.key");

    runCommand("a2ensite $domain.conf");
    success("Virtual host $domain.conf diaktifkan.");
} else {
    error("support/domain.conf tidak ditemukan.");
}

// ============================================================
// INPUT SSL CERTIFICATE (Manual)
// ============================================================
step("Konfigurasi Sertifikat SSL");

echo "\n";
info("Masukkan isi sertifikat SSL (.pem) untuk domain: $domain");
info("Ketik atau paste sertifikat, lalu tekan ENTER dua kali saat selesai:\n");

$pemContent = '';
while (true) {
    $line = fgets(STDIN);
    if ($line === "\n" && substr($pemContent, -1) === "\n") break;
    $pemContent .= $line;
}

file_put_contents("/etc/apache2/ssl/$domain.pem", trim($pemContent) . "\n");
success("File .pem disimpan.");

echo "\n";
info("Masukkan isi private key SSL (.key) untuk domain: $domain");
info("Ketik atau paste key, lalu tekan ENTER dua kali saat selesai:\n");

$keyContent = '';
while (true) {
    $line = fgets(STDIN);
    if ($line === "\n" && substr($keyContent, -1) === "\n") break;
    $keyContent .= $line;
}

file_put_contents("/etc/apache2/ssl/$domain.key", trim($keyContent) . "\n");
success("File .key disimpan.");

// ============================================================
// GENERATE VOUCHER
// ============================================================
step("Generate Vouchers");

$vouchers = generateVouchers(1000, 8);
$voucherText = implode("\n", $vouchers) . "\n";

file_put_contents('/usr/lib/cgi-bin/vouchers.txt', $voucherText);

$randomNum = str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
$voucherFilename = "vouchers{$randomNum}.txt";
copy('/usr/lib/cgi-bin/vouchers.txt', "/home/xcodehoster/$voucherFilename");

success("1000 voucher berhasil digenerate.");

// ============================================================
// RESTART APACHE & SELESAI
// ============================================================
step("Finalisasi");

runCommand('service apache2 restart');
success("Apache berhasil direstart.");

echo "\n";
echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║              INSTALASI SELESAI! ✔                        ║\n";
echo "╠══════════════════════════════════════════════════════════╣\n";
printf("║  Domain       : %-41s║\n", "https://$domain");
printf("║  IP Server    : %-41s║\n", $ipServer);
printf("║  Vouchers     : %-41s║\n", "https://$domain/$voucherFilename");
echo "╚══════════════════════════════════════════════════════════╝\n";
echo "\n";
info("Untuk pengujian, akses: https://$domain");
info("Daftar voucher  : https://$domain/$voucherFilename");
echo "\n";
