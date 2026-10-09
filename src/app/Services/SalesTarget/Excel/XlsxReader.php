<?php

namespace App\Services\SalesTarget\Excel;

use RuntimeException;
use SimpleXMLElement;
use ZipArchive;

/**
 * xlsx のシートのセルを読む（値と数式）。現行の販売試算Excelを1回取り込むためだけに使うので、書式や結合などは読まない。
 * 数式のセルは、Excelが保存したときの計算結果を値として返す。
 */
class XlsxReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @var list<string> */
    private array $sharedStrings = [];

    /** @var array<string, string> シート名 => シートのXMLのパス */
    private array $sheets = [];

    private ZipArchive $zip;

    public function __construct(string $path)
    {
        $this->zip = new ZipArchive;
        if ($this->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("xlsx を開けませんでした：{$path}");
        }
        $this->readSharedStrings();
        $this->readSheets();
    }

    /**
     * @return list<string>
     */
    public function sheetNames(): array
    {
        return array_keys($this->sheets);
    }

    /**
     * 名前の先頭が一致する最初のシートのセル。
     *
     * @return array<int, array<string, array{value: string|null, formula: string|null}>> [行番号 => [列 => セル]]
     */
    public function cells(string $sheetNamePrefix): array
    {
        $path = null;
        foreach ($this->sheets as $name => $sheetPath) {
            if (str_starts_with($name, $sheetNamePrefix)) {
                $path = $sheetPath;
                break;
            }
        }
        if ($path === null) {
            throw new RuntimeException("シート「{$sheetNamePrefix}」が見つかりません。");
        }

        $xml = $this->xml($path);
        $rows = [];
        foreach ($xml->children(self::MAIN_NS)->sheetData->row as $row) {
            foreach ($row->children(self::MAIN_NS)->c as $cell) {
                $reference = (string) $cell->attributes()['r'];
                if (! preg_match('/^([A-Z]+)(\d+)$/', $reference, $matches)) {
                    continue;
                }
                $rows[(int) $matches[2]][$matches[1]] = $this->cell($cell);
            }
        }

        return $rows;
    }

    /**
     * @return array{value: string|null, formula: string|null}
     */
    private function cell(SimpleXMLElement $cell): array
    {
        $children = $cell->children(self::MAIN_NS);
        $type = (string) $cell->attributes()['t'];
        $raw = isset($children->v) ? (string) $children->v : null;
        $value = match ($type) {
            's' => $raw === null ? null : ($this->sharedStrings[(int) $raw] ?? null),
            'inlineStr' => isset($children->is) ? $this->text($children->is) : null,
            default => $raw,
        };

        return ['value' => $value, 'formula' => isset($children->f) ? (string) $children->f : null];
    }

    private function readSharedStrings(): void
    {
        if ($this->zip->locateName('xl/sharedStrings.xml') === false) {
            return;
        }
        foreach ($this->xml('xl/sharedStrings.xml')->children(self::MAIN_NS)->si as $item) {
            $this->sharedStrings[] = $this->text($item);
        }
    }

    private function readSheets(): void
    {
        $targets = [];
        foreach ($this->xml('xl/_rels/workbook.xml.rels')->children() as $relationship) {
            $attributes = $relationship->attributes();
            $targets[(string) $attributes['Id']] = ltrim((string) $attributes['Target'], '/');
        }
        foreach ($this->xml('xl/workbook.xml')->children(self::MAIN_NS)->sheets->sheet as $sheet) {
            $id = (string) $sheet->attributes(self::REL_NS)['id'];
            $target = $targets[$id] ?? null;
            if ($target !== null) {
                $this->sheets[(string) $sheet->attributes()['name']] = str_starts_with($target, 'xl/') ? $target : "xl/{$target}";
            }
        }
    }

    /**
     * ふりがな（rPh）を除いた文字列。
     */
    private function text(SimpleXMLElement $item): string
    {
        $children = $item->children(self::MAIN_NS);
        if (isset($children->t)) {
            return (string) $children->t;
        }
        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::MAIN_NS)->t;
        }

        return $text;
    }

    private function xml(string $path): SimpleXMLElement
    {
        $contents = $this->zip->getFromName($path);
        if ($contents === false) {
            throw new RuntimeException("xlsx の中に {$path} がありません。");
        }

        return new SimpleXMLElement($contents);
    }
}
