<?php
/**
 * Test: WhatsApp Link Generator & Country Code Sanitizer
 */
function formatNoHpTest($noHp) {
    if (empty($noHp)) return '';
    $clean = preg_replace('/[^0-9]/', '', (string)$noHp);
    if (empty($clean)) return '';

    // Jika diawali 62...
    if (substr($clean, 0, 2) === '62') {
        return $clean;
    }
    // Jika diawali 0... (contoh 08...)
    if (substr($clean, 0, 1) === '0') {
        return '62' . substr($clean, 1);
    }
    // Jika langsung diawali 8... (contoh 812...)
    if (substr($clean, 0, 1) === '8') {
        return '62' . $clean;
    }

    return $clean;
}

$testCases = [
    '81217447185'       => '6281217447185',
    '081217447185'      => '6281217447185',
    '6281217447185'     => '6281217447185',
    '+62 812-1744-7185' => '6281217447185',
    '+6281217447185'    => '6281217447185',
    '0858-5227-5961'    => '6285852275961',
    '85852275961'       => '6285852275961',
    '0'                 => '',
    '-'                 => '',
    ''                  => '',
];

foreach ($testCases as $input => $expected) {
    $actual = formatNoHpTest($input);
    assert($actual === $expected, "Failed for input: '$input'. Expected '$expected', got '$actual'");
    
    // Check validation rule (strlen >= 9)
    $isValid = (!empty($actual) && strlen($actual) >= 9);
    if (in_array($input, ['-', '', '0'])) {
        assert(!$isValid, "Expected input '$input' to be INVALID (< 9 chars), but passed validation");
    } else {
        assert($isValid, "Expected input '$input' to be VALID (>= 9 chars), but failed validation");
        $waUrl = 'https://wa.me/' . $actual;
        assert(strpos($waUrl, 'https://wa.me/628') === 0, "Invalid WhatsApp URL format: $waUrl");
    }
}

echo "ALL WHATSAPP LINK TESTS PASSED!\n";
