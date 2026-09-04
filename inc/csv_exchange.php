<?php
declare(strict_types=1);

function csv_exchange_send(string $filename,array $headers,array $rows): void
{
    if(headers_sent()) throw new RuntimeException('Не удалось начать выгрузку CSV: заголовки уже отправлены.');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF";
    $out=fopen('php://output','wb');
    if(!$out) throw new RuntimeException('Не удалось создать CSV.');
    fputcsv($out,$headers,';');
    foreach($rows as $row) fputcsv($out,array_map(static fn($v)=>$v===null?'':(string)$v,$row),';');
    fclose($out);
    exit;
}

function csv_exchange_normalize_header(string $value): string
{
    $value=preg_replace('/^\xEF\xBB\xBF/','',$value)??$value;
    $value=mb_strtolower(trim($value));
    $value=str_replace(['ё','_'],['е',' '],$value);
    $value=preg_replace('/\s+/u',' ',$value)??$value;
    return trim($value);
}

function csv_exchange_detect_delimiter(string $line): string
{
    $counts=[';'=>substr_count($line,';'),"\t"=>substr_count($line,"\t"),','=>substr_count($line,',')];
    arsort($counts);
    $delimiter=(string)array_key_first($counts);
    return ($counts[$delimiter]??0)>0?$delimiter:';';
}

function csv_exchange_read_upload(string $field,array $columns,int $maxBytes=5242880): array
{
    $file=$_FILES[$field]??null;
    if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Выберите CSV-файл для импорта.');
    if((int)($file['size']??0)<=0) throw new RuntimeException('Загруженный CSV-файл пустой.');
    if((int)$file['size']>$maxBytes) throw new RuntimeException('CSV-файл слишком большой. Максимум 5 МБ.');
    $path=(string)($file['tmp_name']??'');
    $fh=fopen($path,'rb');
    if(!$fh) throw new RuntimeException('Не удалось прочитать загруженный CSV-файл.');
    $first=fgets($fh);
    if($first===false){fclose($fh);throw new RuntimeException('CSV-файл пустой.');}
    $delimiter=csv_exchange_detect_delimiter($first);
    rewind($fh);
    $header=fgetcsv($fh,0,$delimiter);
    if(!is_array($header)){fclose($fh);throw new RuntimeException('Не удалось прочитать заголовок CSV.');}

    $normalized=[];
    foreach($header as $index=>$name)$normalized[(int)$index]=csv_exchange_normalize_header((string)$name);
    $indexes=[];
    foreach($columns as $key=>$aliases){
        $aliases=array_map('csv_exchange_normalize_header',$aliases);
        $found=null;
        foreach($normalized as $index=>$name){if(in_array($name,$aliases,true)){$found=$index;break;}}
        if($found===null){fclose($fh);throw new RuntimeException('В CSV нет обязательной колонки «'.($aliases[0]??$key).'». Скачайте образец и сохраните названия колонок.');}
        $indexes[$key]=$found;
    }

    $rows=[];$line=1;
    while(($raw=fgetcsv($fh,0,$delimiter))!==false){
        $line++;
        $hasValue=false;foreach($raw as $cell){if(trim((string)$cell)!==''){$hasValue=true;break;}}
        if(!$hasValue)continue;
        $row=['_row'=>$line];
        foreach($indexes as $key=>$index)$row[$key]=trim((string)($raw[$index]??''));
        $rows[]=$row;
    }
    fclose($fh);
    if(!$rows) throw new RuntimeException('В CSV нет строк для импорта.');
    return $rows;
}

function csv_exchange_number(string $value): ?float
{
    $value=trim(str_replace(["\xC2\xA0",' '],'',$value));
    if($value==='')return null;
    $value=str_replace(',','.',$value);
    if(!is_numeric($value))return null;
    $number=(float)$value;
    return is_finite($number)?$number:null;
}

function csv_exchange_unit(string $value): ?string
{
    $value=csv_exchange_normalize_header($value);
    $map=['g'=>'g','г'=>'g','гр'=>'g','kg'=>'kg','кг'=>'kg','ml'=>'ml','мл'=>'ml','l'=>'l','л'=>'l','pcs'=>'pcs','шт'=>'pcs','штук'=>'pcs','шт.'=>'pcs'];
    return $map[$value]??null;
}

function csv_exchange_key(string $value): string
{
    return mb_strtolower(trim($value));
}

function csv_exchange_error_message(array $errors): string
{
    $shown=array_slice($errors,0,8);
    $message=implode(' ',array_map(static fn($e)=>'Строка '.$e['row'].': '.$e['message'],$shown));
    if(count($errors)>count($shown))$message.=' Ещё ошибок: '.(count($errors)-count($shown)).'.';
    return $message;
}
