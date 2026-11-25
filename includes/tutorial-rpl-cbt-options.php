<?php
/** Normalize legacy JSON lists and A-E maps without changing which option a key identifies. */
if (!function_exists('tutorialRplCbtNormalizeOptions')) {
    function tutorialRplCbtNormalizeOptions($raw): array
    {
        $options = is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : $raw;
        if (!is_array($options) || !$options || count($options) > 5) {
            throw new InvalidArgumentException('Format pilihan soal CBT tidak valid. Periksa bank soal.');
        }
        $isList = array_keys($options) === range(0, count($options) - 1);
        $out = [];
        foreach ($options as $key => $value) {
            $letter = $isList ? chr(65 + (int)$key) : strtoupper((string)$key);
            if (!in_array($letter, ['A','B','C','D','E'], true) || array_key_exists($letter, $out)) {
                throw new InvalidArgumentException('Indeks pilihan soal CBT tidak valid.');
            }
            if (is_array($value)) {
                if (!array_key_exists('text', $value)) throw new InvalidArgumentException('Teks pilihan soal CBT tidak valid.');
                $value = $value['text'];
            }
            if ($value === null) $value = '';
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                throw new InvalidArgumentException('Teks pilihan soal CBT tidak valid.');
            }
            $out[$letter] = (string)$value;
        }
        return $out;
    }
}

if (!function_exists('tutorialRplCbtShuffleOptions')) {
    function tutorialRplCbtShuffleOptions($raw, ?string $selectedKey, string $correctKey, bool $forceChange = false): array
    {
        $options = tutorialRplCbtNormalizeOptions($raw);
        $correctKey = strtoupper(trim($correctKey));
        $selectedKey = $selectedKey === null || trim($selectedKey) === '' ? null : strtoupper(trim($selectedKey));
        if (!isset($options[$correctKey]) || trim($options[$correctKey]) === '') {
            throw new InvalidArgumentException('Kunci jawaban tidak menunjuk pilihan yang terisi. Periksa bank soal CBT.');
        }
        if ($selectedKey !== null && (!isset($options[$selectedKey]) || trim($options[$selectedKey]) === '')) {
            throw new InvalidArgumentException('Jawaban tersimpan tidak sesuai pilihan soal. Perlu audit admin.');
        }
        $keys = array_keys(array_filter($options, static fn($value) => trim($value) !== ''));
        if (count($keys) < 2) throw new InvalidArgumentException('Soal CBT harus mempunyai minimal dua pilihan terisi.');
        $order = $keys;
        for ($i = count($order) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$order[$i], $order[$j]] = [$order[$j], $order[$i]];
        }
        if ($forceChange && $order === $keys) $order = array_merge(array_slice($order, 1), [$order[0]]);
        $out = [];
        $answer = null;
        $correct = null;
        foreach ($order as $i => $oldKey) {
            $letter = chr(65 + $i);
            $out[$letter] = $options[$oldKey];
            if ($oldKey === $correctKey) $correct = $letter;
            if ($oldKey === $selectedKey) $answer = $letter;
        }
        return [
            'options_json' => json_encode($out, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'answer' => $answer,
            'correct_answer' => $correct,
        ];
    }
}
