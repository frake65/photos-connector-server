<?php
declare(strict_types=1);
// Run against an extracted archive: php tests/package.php STAGING_PARENT.
$stage = $argv[1] ?? '';
$root = $stage . '/apple_photos_connector';
if (!is_dir($root)) throw new RuntimeException('Extract a test archive first');
$checker = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/check-package.php') . ' ' . escapeshellarg($stage);
exec($checker . ' 2>&1', $baselineOutput, $baselineCode);
if ($baselineCode !== 0) throw new RuntimeException('Valid baseline package rejected');
function rejected(string $stage): void {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/check-package.php') . ' ' . escapeshellarg($stage);
    exec($command . ' 2>&1', $output, $code);
    if ($code === 0) throw new RuntimeException('Invalid package accepted');
}
foreach (['.DS_Store', '._junk', 'signing.key', 'secret.txt', 'signing.crt', 'signing.cer', 'signing.der', 'AlbumTestResolveCommand.php', 'AlbumTestAddMembershipCommand.php', 'AlbumTestService.php', 'AlbumMembershipTestService.php', 'build-package.sh', 'check-package.sh'] as $name) {
    $path = "$root/$name";
    if (file_exists($path)) throw new RuntimeException('Fixture path already exists');
    file_put_contents($path, $name === 'secret.txt' ? '-----BEGIN PRIVATE KEY-----' : 'fixture');
    try { rejected($stage); } finally { unlink($path); }
}
mkdir("$stage/unexpected");
try { rejected($stage); } finally { rmdir("$stage/unexpected"); }
foreach (['LICENSE', 'CHANGELOG.md', 'appinfo/info.xml'] as $name) {
    $path = "$root/$name";
    $original = file_get_contents($path);
    file_put_contents($path, '');
    try { rejected($stage); } finally { file_put_contents($path, $original); }
}
$signaturePath = "$root/appinfo/signature.json";
$validSignature = json_encode([
    'appId' => 'apple_photos_connector',
    'signature' => 'base64-signature',
    'certificate' => "-----BEGIN CERTIFICATE-----\ncertificate\n-----END CERTIFICATE-----",
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
file_put_contents($signaturePath, $validSignature);
try {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../tools/check-package.php') . ' ' . escapeshellarg($stage);
    exec($command . ' 2>&1', $output, $code);
    if ($code !== 0) throw new RuntimeException('Valid signature.json rejected');
} finally { unlink($signaturePath); }
foreach ([
    '{"appId":"wrong","signature":"x","certificate":"-----BEGIN CERTIFICATE-----\\nx\\n-----END CERTIFICATE-----"}',
    '{"appId":"apple_photos_connector","signature":"","certificate":"-----BEGIN CERTIFICATE-----\\nx\\n-----END CERTIFICATE-----"}',
    '{"appId":"apple_photos_connector","signature":"x","certificate":"not-a-certificate"}',
    '{"appId":"apple_photos_connector","signature":"x"}',
] as $invalidSignature) {
    file_put_contents($signaturePath, $invalidSignature);
    try { rejected($stage); } finally { unlink($signaturePath); }
}
$path = "$root/appinfo/info.xml";
$original = file_get_contents($path);
foreach (['<id>apple_photos_connector</id>' => '<id>wrong</id>', '<version>0.8.8</version>' => '<version>9.0.0</version>'] as $from => $to) {
    file_put_contents($path, str_replace($from, $to, $original));
    try { rejected($stage); } finally { file_put_contents($path, $original); }
}
echo "PASS: invalid package scenarios and signature.json validation\n";
