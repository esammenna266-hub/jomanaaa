<?php

namespace App\Modules\Reports\Export;

use RuntimeException;
use ZipArchive;

/**
 * Writes a report as a real .xlsx workbook (Office Open XML) with no extra library: a right-to-left sheet with the
 * company heading, a styled header row that stays on screen and repeats on every printed page, real numbers for
 * money and counts, A4 paper fitted to the page width, and page numbers in the footer.
 *
 * @phpstan-type Column array{key: string, label: string, type?: string}
 */
final class XlsxWriter
{
    /** Style ids, in the order of cellXfs in styles(). */
    private const S_COMPANY = 1;

    private const S_TITLE = 2;

    private const S_NOTE = 3;

    private const S_HEADER = 4;

    private const S_TEXT = 5;

    private const S_MONEY = 6;

    private const S_INT = 7;

    /** Rows above the table: company, title, note, spacer. The header row is the next one. */
    private const HEADER_ROW = 5;

    /**
     * @param  list<array{key: string, label: string, type?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $notes  lines under the title (period, branch, date printed)
     */
    public function build(string $company, string $title, array $columns, array $rows, array $notes = [], bool $landscape = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('تعذر إنشاء ملف Excel');
        }

        $sheetName = $this->sheetName($title);
        $lastRow = self::HEADER_ROW + count($rows);
        $lastColumn = $this->columnName(max(count($columns), 1));

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook($sheetName, $lastColumn, $lastRow));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet($company, $title, $columns, $rows, $notes, $landscape, $lastColumn, $lastRow));
        $zip->close();

        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * @param  list<array{key: string, label: string, type?: string}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $notes
     */
    private function sheet(string $company, string $title, array $columns, array $rows, array $notes, bool $landscape, string $lastColumn, int $lastRow): string
    {
        $count = max(count($columns), 1);
        $headerRow = self::HEADER_ROW;

        $cols = '';
        foreach ($columns as $index => $column) {
            $cols .= sprintf('<col min="%1$d" max="%1$d" width="%2$s" customWidth="1"/>', $index + 1, $this->width($column, $rows));
        }

        $data = '';
        $data .= $this->row(1, [$this->text('A', 1, $company, self::S_COMPANY)], 26);
        $data .= $this->row(2, [$this->text('A', 2, $title, self::S_TITLE)], 20);
        $data .= $this->row(3, [$this->text('A', 3, implode('   |   ', $notes), self::S_NOTE)]);

        $header = [];
        foreach ($columns as $index => $column) {
            $header[] = $this->text($this->columnName($index + 1), $headerRow, $column['label'], self::S_HEADER);
        }
        $data .= $this->row($headerRow, $header, 24);

        foreach ($rows as $offset => $row) {
            $number = $headerRow + 1 + $offset;
            $cells = [];
            foreach ($columns as $index => $column) {
                $cells[] = $this->value($this->columnName($index + 1), $number, $row[$column['key']] ?? '', $column['type'] ?? 'text');
            }
            $data .= $this->row($number, $cells);
        }

        // The heading lines span the whole table; a one-column table has nothing to merge.
        $merges = $count > 1
            ? '<mergeCells count="3">'
                .sprintf('<mergeCell ref="A1:%s1"/><mergeCell ref="A2:%s2"/><mergeCell ref="A3:%s3"/>', $lastColumn, $lastColumn, $lastColumn)
                .'</mergeCells>'
            : '';

        $orientation = $landscape ? 'landscape' : 'portrait';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'
            .sprintf('<dimension ref="A1:%s%d"/>', $lastColumn, max($lastRow, $headerRow))
            .'<sheetViews><sheetView rightToLeft="1" workbookViewId="0">'
            .sprintf('<pane ySplit="%d" topLeftCell="A%d" activePane="bottomLeft" state="frozen"/>', $headerRow, $headerRow + 1)
            .'<selection pane="bottomLeft"/></sheetView></sheetViews>'
            .'<sheetFormatPr defaultRowHeight="18"/>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.$data.'</sheetData>'
            .sprintf('<autoFilter ref="A%d:%s%d"/>', $headerRow, $lastColumn, max($lastRow, $headerRow))
            .$merges
            .'<printOptions horizontalCentered="1"/>'
            .'<pageMargins left="0.4" right="0.4" top="0.6" bottom="0.7" header="0.3" footer="0.3"/>'
            .sprintf('<pageSetup paperSize="9" orientation="%s" fitToWidth="1" fitToHeight="0"/>', $orientation)
            .'<headerFooter><oddFooter>'.$this->escape('&C&"Arial,Regular"&9صفحة &P من &N').'</oddFooter></headerFooter>'
            .'</worksheet>';
    }

    /**
     * @param  list<string>  $cells
     */
    private function row(int $number, array $cells, ?int $height = null): string
    {
        $attributes = $height === null ? '' : sprintf(' ht="%d" customHeight="1"', $height);

        return sprintf('<row r="%d"%s>%s</row>', $number, $attributes, implode('', $cells));
    }

    private function text(string $column, int $row, string $value, int $style): string
    {
        return sprintf('<c r="%s%d" s="%d" t="inlineStr"><is><t xml:space="preserve">%s</t></is></c>', $column, $row, $style, $this->escape($value));
    }

    /**
     * Money and counts are written as real numbers so Excel can add and sort them.
     */
    private function value(string $column, int $row, mixed $value, string $type): string
    {
        if (($type === 'money' || $type === 'int') && is_numeric($value)) {
            return sprintf('<c r="%s%d" s="%d"><v>%s</v></c>', $column, $row, $type === 'money' ? self::S_MONEY : self::S_INT, $value + 0);
        }

        return $this->text($column, $row, (string) $value, self::S_TEXT);
    }

    /**
     * @param  array{key: string, label: string, type?: string}  $column
     * @param  list<array<string, mixed>>  $rows
     */
    private function width(array $column, array $rows): string
    {
        $longest = mb_strlen($column['label']) + 4;
        foreach ($rows as $row) {
            $longest = max($longest, mb_strlen((string) ($row[$column['key']] ?? '')) + 3);
        }

        return (string) min(max($longest, 12), 48);
    }

    private function columnName(int $index): string
    {
        $name = '';
        while ($index > 0) {
            $index--;
            $name = chr(65 + $index % 26).$name;
            $index = intdiv($index, 26);
        }

        return $name;
    }

    /**
     * Sheet names are limited to 31 characters and cannot contain []:*?/\ .
     */
    private function sheetName(string $title): string
    {
        $clean = trim(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $title) ?? '');

        return mb_substr($clean === '' ? 'Report' : $clean, 0, 31);
    }

    private function escape(string $value): string
    {
        // XML 1.0 cannot carry most control characters; drop them instead of corrupting the file.
        $clean = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $value) ?? '';

        return htmlspecialchars($clean, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function workbook(string $sheetName, string $lastColumn, int $lastRow): string
    {
        $name = $this->escape($sheetName);
        $quoted = "'".str_replace("'", "''", $sheetName)."'";
        $titles = $this->escape("{$quoted}!\$".self::HEADER_ROW.':$'.self::HEADER_ROW);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .sprintf('<sheets><sheet name="%s" sheetId="1" r:id="rId1"/></sheets>', $name)
            .sprintf('<definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">%s</definedName></definedNames>', $titles)
            .'</workbook>';
    }

    private function styles(): string
    {
        $thin = '<left style="thin"><color rgb="FF888888"/></left><right style="thin"><color rgb="FF888888"/></right>'
            .'<top style="thin"><color rgb="FF888888"/></top><bottom style="thin"><color rgb="FF888888"/></bottom><diagonal/>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            .'<fonts count="5">'
            .'<font><sz val="11"/><name val="Arial"/></font>'
            .'<font><b/><sz val="16"/><color rgb="FF1B3A5C"/><name val="Arial"/></font>'
            .'<font><b/><sz val="13"/><name val="Arial"/></font>'
            .'<font><sz val="10"/><color rgb="FF555555"/><name val="Arial"/></font>'
            .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'
            .'</fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FF1B3A5C"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border><border>'.$thin.'</border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="8">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="right" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="right" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment horizontal="right" vertical="center"/></xf>'
            .'<xf numFmtId="0" fontId="4" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top" wrapText="1"/></xf>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            .'<xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="right" vertical="top"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
