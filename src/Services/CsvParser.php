<?php
declare(strict_types=1);

namespace SocialTurn\Services;

/**
 * CsvParser
 *
 * Parses a CSV file for bulk post import.
 * Handles BOM detection, header-row mapping, field extraction,
 * character-limit enforcement, is_recyclable parsing, and image
 * filename validation via StorageService.
 *
 * Duplicate detection and the INSERT transaction are the caller's
 * responsibility (see importProcess() in controllers/content.php).
 *
 * Depends on normalize_body() — a global function defined in
 * libraries/shared.php, which is always loaded before this class
 * is used in both web and CLI (test) contexts.
 */
class CsvParser
{
    public function __construct(
        private readonly StorageService $storage
    ) {}

    /**
     * Parse a CSV file and return structured row data.
     *
     * Opens the file, strips a UTF-8 BOM if present, locates the header
     * row, and iterates data rows. Returns a result array regardless of
     * outcome — never throws.
     *
     * @param string $filePath            Absolute path to the CSV file
     * @param int    $charLimit           Maximum body character length (PHP_INT_MAX = no limit)
     * @param string $limitPlatform       Platform name for error messages ('' when no limit applies)
     * @param int    $isRecyclableDefault Fallback value (0 or 1) when the is_recyclable column
     *                                   is absent or contains an unrecognized value
     *
     * @return array{
     *     rows:         list<array{
     *                       body:            string,
     *                       body_normalized: string,
     *                       attributed_to:   string|null,
     *                       image_filename:  string|null,
     *                       is_recyclable:   int,
     *                       internal_note:   string|null,
     *                       row_num:         int
     *                   }>,
     *     errors:       list<string>,
     *     warnings:     list<string>,
     *     skipped:      int,
     *     failed:       int,
     *     parse_error:  string|null,
     *     cap_exceeded: bool
     * }
     */
    public function parse(
        string $filePath,
        int    $charLimit,
        string $limitPlatform,
        int    $isRecyclableDefault
    ): array {
        $result = [
            'rows'         => [],
            'errors'       => [],
            'warnings'     => [],
            'skipped'      => 0,
            'failed'       => 0,
            'parse_error'  => null,
            'cap_exceeded' => false,
        ];

        $handle = @fopen($filePath, 'r');

        if ($handle === false) {
            $result['parse_error'] = 'Could not read uploaded file.';
            return $result;
        }

        // -----------------------------------------------------------------------
        // BOM detection — strip UTF-8 BOM if present
        // -----------------------------------------------------------------------

        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // -----------------------------------------------------------------------
        // Find header row — first non-comment, non-empty row
        // -----------------------------------------------------------------------

        $colBody         = null;
        $colAttributedTo = null;
        $colImage        = null;
        $colRecyclable   = null;
        $colNote         = null;
        $headerFound     = false;

        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null]) {
                continue;
            }
            $firstCell = trim((string) ($row[0] ?? ''));
            if ($firstCell === '' || str_starts_with($firstCell, '#')) {
                continue;
            }
            foreach ($row as $i => $col) {
                switch (strtolower(trim((string) $col))) {
                    case 'body':           $colBody         = $i; break;
                    case 'attributed_to':  $colAttributedTo = $i; break;
                    case 'image_filename': $colImage        = $i; break;
                    case 'is_recyclable':  $colRecyclable   = $i; break;
                    case 'internal_note':  $colNote         = $i; break;
                }
            }
            $headerFound = true;
            break;
        }

        if (!$headerFound || $colBody === null) {
            fclose($handle);
            $result['parse_error'] = 'CSV must contain a header row with a "body" column.';
            return $result;
        }

        // -----------------------------------------------------------------------
        // Parse data rows
        // -----------------------------------------------------------------------

        $dataRowNum = 0;

        while (($row = fgetcsv($handle)) !== false) {

            if ($row === [null]) {
                continue;
            }

            if (str_starts_with(trim((string) ($row[0] ?? '')), '#')) {
                continue;
            }

            // Skip all-empty rows (trailing blank lines, etc.)
            $allEmpty = true;
            foreach ($row as $cell) {
                if (trim((string) $cell) !== '') {
                    $allEmpty = false;
                    break;
                }
            }
            if ($allEmpty) {
                continue;
            }

            $dataRowNum++;

            // Row cap — stop collecting and signal the caller
            if ($dataRowNum > 5000) {
                $result['cap_exceeded'] = true;
                fclose($handle);
                return $result;
            }

            // Extract fields
            $body         = trim((string) ($row[$colBody] ?? ''));
            $attributedTo = $colAttributedTo !== null
                ? (trim((string) ($row[$colAttributedTo] ?? '')) ?: null)
                : null;
            $imageFile    = $colImage !== null
                ? trim((string) ($row[$colImage] ?? ''))
                : '';
            $recyclable   = $colRecyclable !== null
                ? trim((string) ($row[$colRecyclable] ?? ''))
                : '';
            $note         = $colNote !== null
                ? (trim((string) ($row[$colNote] ?? '')) ?: null)
                : null;

            // Empty body — skip
            if ($body === '') {
                $result['skipped']++;
                $result['errors'][] = "Row {$dataRowNum}: skipped — body is empty.";
                continue;
            }

            // Character limit — fail row
            $bodyLen = mb_strlen($body);
            if ($charLimit !== PHP_INT_MAX && $bodyLen > $charLimit) {
                $result['failed']++;
                $result['errors'][] = "Row {$dataRowNum}: body is {$bodyLen} characters, "
                    . "exceeds the {$charLimit}-character limit for {$limitPlatform}. Post not imported.";
                continue;
            }

            // Image filename — warn and clear if not found in storage
            $imageFilename = null;
            if ($imageFile !== '') {
                if ($this->storage->exists($imageFile)) {
                    $imageFilename = $imageFile;
                } else {
                    $result['warnings'][] = "Row {$dataRowNum}: image \"{$imageFile}\" not found "
                        . "in storage — post imported without image.";
                }
            }

            // is_recyclable — parse variations, fall back to form default
            $recyclableLower = strtolower($recyclable);
            if (in_array($recyclableLower, ['1', 'true', 'yes', 'on'], true)) {
                $isRecyclable = 1;
            } elseif (in_array($recyclableLower, ['0', 'false', 'no', 'off'], true)) {
                $isRecyclable = 0;
            } else {
                $isRecyclable = $isRecyclableDefault;
            }

            $result['rows'][] = [
                'body'            => $body,
                'body_normalized' => normalize_body($body),
                'attributed_to'   => $attributedTo,
                'image_filename'  => $imageFilename,
                'is_recyclable'   => $isRecyclable,
                'internal_note'   => $note,
                'row_num'         => $dataRowNum,
            ];
        }

        fclose($handle);

        return $result;
    }
}
