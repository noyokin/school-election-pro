<?php
declare(strict_types=1);

function xlsx_xml(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_column_name(int $index): string
{
    $name='';
    while($index>0){$index--; $name=chr(65+($index%26)).$name; $index=intdiv($index,26);}
    return $name;
}

function create_xlsx_file(array $headers, array $rows, string $sheetName='Данные'): string
{
    if(!class_exists('ZipArchive')) throw new RuntimeException('Для Excel требуется расширение PHP ZipArchive. Используйте CSV или включите extension=zip.');
    $temp=tempnam(sys_get_temp_dir(),'school-election-');if($temp===false)throw new RuntimeException('Не удалось создать временный файл.');$xlsx=$temp.'.xlsx';@unlink($temp);
    $zip=new ZipArchive();if($zip->open($xlsx,ZipArchive::CREATE)!==true)throw new RuntimeException('Не удалось создать XLSX.');
    $all=array_merge([$headers],$rows);$sheetRows='';
    foreach($all as $rIndex=>$row){$rowNum=$rIndex+1;$cells='';foreach(array_values($row) as $cIndex=>$value){$ref=xlsx_column_name($cIndex+1).$rowNum;if(is_int($value)||is_float($value)){$cells.='<c r="'.$ref.'"><v>'.$value.'</v></c>';}else{$cells.='<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.xlsx_xml($value).'</t></is></c>';}}$sheetRows.='<row r="'.$rowNum.'">'.$cells.'</row>';}
    $last=xlsx_column_name(max(1,count($headers))).max(1,count($all));
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/><cols>';for($i=1;$i<=count($headers);$i++){$sheet.='<col min="'.$i.'" max="'.$i.'" width="22" customWidth="1"/>';}$sheet.='</cols><sheetData>'.$sheetRows.'</sheetData><autoFilter ref="A1:'.$last.'"/></worksheet>';
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.xlsx_xml(mb_substr($sheetName,0,31)).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);$zip->close();return $xlsx;
}
