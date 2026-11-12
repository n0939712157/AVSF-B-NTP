<?php
require_once __DIR__ . '/avsf-image-matcher.php';

function advancedDraftFieldKey($label) {
    $key=mb_strtolower(trim((string)$label),'UTF-8');
    $key=preg_replace('/[\s_\-\.\/]+/u','',$key);
    $key=preg_replace('/[^a-z0-9ก-๙]/u','',$key);
    $aliases=[
        'code'=>'code','productcode'=>'code','dvdcode'=>'code','jancode'=>'code','รหัส'=>'code','รหัสเรื่อง'=>'code','โค้ด'=>'code',
        'focus'=>'focus','focuskeyphrase'=>'focus','focuskeyword'=>'focus','focusphrase'=>'focus','primarykeyword'=>'focus','คีย์หลัก'=>'focus','คีย์เวิร์ดหลัก'=>'focus','คำค้นหลัก'=>'focus','คีย์เวิร์ดโฟกัส'=>'focus',
        'actor'=>'actor','actors'=>'actor','actorname'=>'actor','actornames'=>'actor','cast'=>'actor','actress'=>'actor','นักแสดง'=>'actor','นักแสดงนำ'=>'actor','ดารา'=>'actor',
        'actoren'=>'actor_en','actorenglish'=>'actor_en','actoreng'=>'actor_en','englishactor'=>'actor_en','นักแสดงอังกฤษ'=>'actor_en','ชื่อนักแสดงอังกฤษ'=>'actor_en',
        'tag'=>'tags','tags'=>'tags','posttag'=>'tags','posttags'=>'tags','tagname'=>'tags','tagnames'=>'tags','taxonomytag'=>'tags','taxonomytags'=>'tags','แท็ก'=>'tags','แทก'=>'tags','ป้ายกำกับ'=>'tags',
        'studio'=>'studio','studioname'=>'studio','label'=>'studio','maker'=>'studio','ค่าย'=>'studio',
        'category'=>'categories','categories'=>'categories','หมวด'=>'categories','หมวดหมู่'=>'categories',
        'title'=>'title','posttitle'=>'title','name'=>'title','ชื่อเรื่อง'=>'title','ชื่อโพสต์'=>'title',
        'content'=>'content','body'=>'content','postcontent'=>'content','description'=>'content','desc'=>'content','summary'=>'content','เนื้อหา'=>'content','รายละเอียด'=>'content',
        'metadescription'=>'meta_desc','metadesc'=>'meta_desc','seodescription'=>'meta_desc','คำอธิบายseo'=>'meta_desc','คำอธิบายเมตา'=>'meta_desc',
        'metatitle'=>'meta_title','seotitle'=>'meta_title','ชื่อseo'=>'meta_title',
        'duration'=>'duration','videoduration'=>'duration','runtime'=>'duration','length'=>'duration','ความยาว'=>'duration','ระยะเวลา'=>'duration',
        'videourl'=>'video_url','video'=>'video_url','embedurl'=>'video_url','url'=>'video_url','ลิงก์วิดีโอ'=>'video_url','วิดีโอ'=>'video_url',
        'slug'=>'slug',
        'cover'=>'cover','coverurl'=>'cover','coverimage'=>'cover','image'=>'cover','imageurl'=>'cover','thumbnail'=>'cover','รูปปก'=>'cover','รูปภาพ'=>'cover',
        'poster'=>'poster','posterurl'=>'poster','posterimage'=>'poster','โปสเตอร์'=>'poster',
        'gallery'=>'gallery','galleryurl'=>'gallery','galleryimages'=>'gallery','แกลเลอรี่'=>'gallery',
        'coveralt'=>'cover_alt','alt'=>'cover_alt','รูปปกalt'=>'cover_alt','altรูปปก'=>'cover_alt',
        'posteralt'=>'poster_alt','altposter'=>'poster_alt','altโปสเตอร์'=>'poster_alt',
        'galleryalt'=>'gallery_alt','altgallery'=>'gallery_alt',
        'keywords'=>'keywords','seokeywords'=>'keywords','metakeywords'=>'keywords','คีย์เวิร์ด'=>'keywords'
    ];
    if(isset($aliases[$key]))return $aliases[$key];
    if(strpos($key,'meta')!==false&&(strpos($key,'desc')!==false||strpos($key,'description')!==false))return 'meta_desc';
    if((strpos($key,'actor')!==false||strpos($key,'actress')!==false)&&(strpos($key,'en')!==false||strpos($key,'english')!==false))return 'actor_en';
    if(strpos($key,'seo')!==false&&strpos($key,'desc')!==false)return 'meta_desc';
    if(strpos($key,'seo')!==false&&strpos($key,'title')!==false)return 'meta_title';
    if(strpos($key,'focus')!==false&&strpos($key,'key')!==false)return 'focus';
    if(strpos($key,'รหัส')!==false||preg_match('/(?:^|[a-z])(?:product|item|dvd|jan)?code$/',$key))return 'code';
    if(preg_match('/(?:^|[a-z])(?:post)?title$/',$key)&&strpos($key,'meta')===false)return 'title';
    if(strpos($key,'content')!==false||strpos($key,'body')!==false)return 'content';
    if(strpos($key,'category')!==false||strpos($key,'หมวด')!==false)return 'categories';
    if(strpos($key,'tag')!==false||strpos($key,'แท็ก')!==false||strpos($key,'แทก')!==false)return 'tags';
    if(strpos($key,'studio')!==false||strpos($key,'ค่าย')!==false)return 'studio';
    if(strpos($key,'duration')!==false||strpos($key,'runtime')!==false||strpos($key,'ความยาว')!==false)return 'duration';
    if(strpos($key,'video')!==false&&(strpos($key,'url')!==false||strpos($key,'link')!==false))return 'video_url';
    if(strpos($key,'alt')!==false&&(strpos($key,'cover')!==false||strpos($key,'thumbnail')!==false||strpos($key,'รูปปก')!==false||strpos($key,'image')!==false))return 'cover_alt';
    if(strpos($key,'alt')!==false&&(strpos($key,'poster')!==false||strpos($key,'โปสเตอร์')!==false))return 'poster_alt';
    if(strpos($key,'alt')!==false&&(strpos($key,'gallery')!==false||strpos($key,'แกลเลอ')!==false))return 'gallery_alt';
    if(strpos($key,'cover')!==false||strpos($key,'thumbnail')!==false||strpos($key,'รูปปก')!==false)return 'cover';
    if(strpos($key,'poster')!==false||strpos($key,'โปสเตอร์')!==false)return 'poster';
    return '';
}

function advancedDraftNormalizeRow($row) {
    if(!is_array($row))return [];
    $normalized=[];
    foreach($row as $key=>$value) {
        $field=advancedDraftFieldKey($key);
        if($field==='')continue;
        if(is_array($value)&&in_array($field,['gallery','gallery_alt'],true)) {
            $normalized[$field]=array_values(array_filter($value,function($item){return is_scalar($item)&&trim((string)$item)!=='';}));
            continue;
        } elseif(is_array($value)) {
            $value=implode(', ',array_map(function($item){return is_scalar($item)?trim((string)$item):'';},$value));
        } elseif(!is_scalar($value)&&$value!==null) {
            continue;
        }
        $value=trim((string)$value);
        if(!array_key_exists($field,$normalized)||$normalized[$field]==='') {
            $normalized[$field]=$value;
        } elseif($value!=='') {
            // Preserve values when a source contains both Tag and Tags (or duplicate aliases).
            $prior=is_array($normalized[$field])?implode(', ',$normalized[$field]):(string)$normalized[$field];
            if(in_array($field,['tags','categories','actor'],true))$normalized[$field]=$prior."\n".$value;
        }
    }
    return $normalized;
}

/** Parse taxonomy fields supplied as delimited text or JSON arrays; preserve readable labels. */
function advancedDraftTaxonomyNames($value) {
    $items=[];
    $collect=function($entry) use (&$items,&$collect) {
        if(is_array($entry)) { foreach($entry as $child)$collect($child); return; }
        if(!is_scalar($entry))return;
        $text=trim((string)$entry);
        if($text==='')return;
        $decoded=null;
        if(($text[0]??'')==='[') {
            $decoded=json_decode($text,true);
            if(is_array($decoded)) { foreach($decoded as $child)$collect($child); return; }
        }
        foreach(splitTaxonomyNames($text) as $name) {
            $name=trim($name," \t\n\r\0\x0B\"'");
            if($name==='')continue;
            $items[]=$name;
        }
    };
    $collect($value);
    $out=[];$seen=[];
    foreach($items as $name) {
        $key=function_exists('mb_strtolower')?mb_strtolower($name,'UTF-8'):strtolower($name);
        if(isset($seen[$key]))continue;
        $seen[$key]=true;$out[]=$name;
    }
    return $out;
}

/** Detect comma, semicolon, tab or pipe CSV headers by recognized AVSF field names. */
function advancedDraftDetectCsvDelimiter($headerLine) {
    $best=',';$bestScore=-1;
    foreach([',',';',"\t",'|'] as $delimiter) {
        $columns=str_getcsv((string)$headerLine,$delimiter,'"','\\');
        $known=0;
        foreach($columns as $column)if(advancedDraftFieldKey($column)!=='')$known++;
        $score=($known*100)+count($columns);
        if($score>$bestScore){$bestScore=$score;$best=$delimiter;}
    }
    return $best;
}

function advancedDraftParseText($raw) {
    if(!is_string($raw)||trim($raw)==='')throw new RuntimeException('วางข้อมูลหรือเลือกไฟล์ก่อนบันทึก');
    if(strlen($raw)>5*1024*1024)throw new RuntimeException('ข้อมูลมีขนาดเกิน 5 MB');
    $raw=preg_replace('/^\xEF\xBB\xBF/','',$raw);
    $trimmed=trim($raw);
    if($trimmed!==''&&($trimmed[0]==='{'||$trimmed[0]==='[')) {
        $decoded=json_decode($trimmed,true);
        if(json_last_error()!==JSON_ERROR_NONE)throw new RuntimeException('JSON ไม่ถูกต้อง: '.json_last_error_msg());
        if(isset($decoded['posts'])&&is_array($decoded['posts']))$decoded=$decoded['posts'];
        $items=isset($decoded[0])&&is_array($decoded[0])?$decoded:[$decoded];
        $rows=[];
        foreach($items as $item){$row=advancedDraftNormalizeRow($item);if($row)$rows[]=$row;}
        if($rows)return $rows;
        throw new RuntimeException('ไม่พบหัวข้อข้อมูลที่ระบบรู้จักใน JSON');
    }

    $stream=fopen('php://temp','r+');
    if($stream===false)throw new RuntimeException('เตรียมอ่านข้อมูล CSV ไม่สำเร็จ');
    fwrite($stream,$raw);rewind($stream);
    $headerLine='';
    $linePosition=ftell($stream);
    $headerLine=(string)fgets($stream);
    fseek($stream,$linePosition);
    $csvDelimiter=advancedDraftDetectCsvDelimiter($headerLine);
    $first=fgetcsv($stream,0,$csvDelimiter,'"','\\');
    if(is_array($first)&&count($first)>1) {
        $headerKeys=array_map('advancedDraftFieldKey',$first);
        $known=count(array_filter($headerKeys));
        if($known>=2) {
            $rows=[];
            while(($values=fgetcsv($stream,0,$csvDelimiter,'"','\\'))!==false) {
                if(!array_filter($values,function($value){return trim((string)$value)!=='';}))continue;
                $entry=[];
                foreach($headerKeys as $index=>$key)if($key!==''&&array_key_exists($index,$values))$entry[$first[$index]]=$values[$index];
                $row=advancedDraftNormalizeRow($entry);
                if($row)$rows[]=$row;
            }
            fclose($stream);
            if($rows)return $rows;
        } else fclose($stream);
    } else fclose($stream);

    $rows=[];$current=[];$activeField='';
    $flush=function()use(&$rows,&$current,&$activeField){
        if($current)$rows[]=advancedDraftNormalizeRow($current);
        $current=[];$activeField='';
    };
    foreach(preg_split('/\r\n|\r|\n/',$raw) as $line) {
        if(preg_match('/^\s*([^,:;|=\t]{1,80})\s*[,;|:=\t]\s*(.*)$/u',$line,$match)) {
            $field=advancedDraftFieldKey($match[1]);
            if($field!=='') {
                if($field==='code'&&!empty(advancedDraftNormalizeRow($current)['code']))$flush();
                if(array_key_exists($match[1],$current))$current[$match[1]].="\n".$match[2];
                else $current[$match[1]]=$match[2];
                $activeField=$match[1];
                continue;
            }
            if($activeField!==''&&advancedDraftFieldKey($activeField)!=='content') {
                $activeField='';
                continue;
            }
        }
        if($activeField!=='') {
            $value=trim($line);
            if($value===''&&advancedDraftFieldKey($activeField)!=='content')continue;
            $current[$activeField].="\n".$line;
        }
    }
    $flush();
    $rows=array_values(array_filter($rows,function($row){return !empty($row['code'])||!empty($row['title']);}));
    if(!$rows)throw new RuntimeException('อ่านข้อมูลไม่พบ กรุณาตรวจชื่อหัวข้อ เช่น Code, Title และ Content');
    return $rows;
}

function advancedDraftFindOrCreateTerm($conn,$table,$name) {
    $limits=['studios'=>100,'actors'=>100,'tags'=>100,'categories'=>150];
    if(!isset($limits[$table]))throw new InvalidArgumentException('Unsupported taxonomy table');
    $name=trim((string)$name);
    if($name===''||mb_strlen($name,'UTF-8')>$limits[$table])throw new RuntimeException('ชื่อรายการใน '.$table.' ว่างหรือยาวเกินกำหนด');
    $lookup=$conn->prepare("SELECT id FROM `$table` WHERE name=? LIMIT 1");
    if(!$lookup)throw new RuntimeException('ค้นหารายการใน '.$table.' ไม่สำเร็จ: '.$conn->error);
    $lookup->bind_param('s',$name);
    if(!$lookup->execute()){$error=$lookup->error;$lookup->close();throw new RuntimeException('ค้นหารายการใน '.$table.' ไม่สำเร็จ: '.$error);}
    $lookup->bind_result($id);$found=$lookup->fetch();$lookup->close();
    if($found)return (int)$id;
    $slug=uniqueSlugForName($conn,$table,$name);
    $insert=$conn->prepare("INSERT INTO `$table` (name,slug) VALUES (?,?)");
    if(!$insert)throw new RuntimeException('เตรียมบันทึกรายการใน '.$table.' ไม่สำเร็จ: '.$conn->error);
    $insert->bind_param('ss',$name,$slug);
    if(!$insert->execute()){$error=$insert->error;$insert->close();throw new RuntimeException('บันทึกรายการใน '.$table.' ไม่สำเร็จ: '.$error);}
    $id=(int)$insert->insert_id;$insert->close();
    return $id;
}

function advancedDraftSaveRecord($conn,$row,$author,$imageAssets=[]) {
    $code=trim((string)($row['code']??''));
    $title=trim((string)($row['title']??''));
    if($code===''||mb_strlen($code,'UTF-8')>100)throw new RuntimeException('ต้องมี Code และ Code ต้องไม่เกิน 100 ตัวอักษร');
    if($title===''||mb_strlen($title,'UTF-8')>200)throw new RuntimeException('ต้องมี Title และชื่อเรื่องต้องไม่เกิน 200 ตัวอักษร');
    $content=(string)($row['content']??'');
    if(strlen($content)>65535)throw new RuntimeException('Content ยาวเกินขนาดที่ฐานข้อมูลรองรับ');
    $slug=trim(mb_substr(slugify($code),0,200,'UTF-8'),'-');
    if($slug==='')throw new RuntimeException('Code นี้สร้าง Slug ไม่ได้');
    $actorValue=trim((string)($row['actor']??''));
    $actorEnReference=trim((string)($row['actor_en']??''));
    if(mb_strlen($actorEnReference,'UTF-8')>150)throw new RuntimeException('Actor EN ยาวเกิน 150 ตัวอักษร');
    $alt=trim($code.($actorEnReference!==''?' '.$actorEnReference:''));
    $coverInput=trim((string)($row['cover']??''));
    $posterInput=trim((string)($row['poster']??''));
    $imageMatches=avsfFindImportImagesByCode($imageAssets,$code,$coverInput,$posterInput);
    $cover=avsfResolveImportImageValue($coverInput,$imageAssets,$code,'cover');
    $poster=avsfResolveImportImageValue($posterInput,$imageAssets,$code,'poster');
    foreach($imageMatches as $asset) {
        if(($asset['role']??'')==='cover' && $cover==='') { $cover=avsfStoreImportImage($asset['path'],$code,$asset['name']); break; }
    }
    foreach($imageMatches as $asset) {
        if(($asset['role']??'')==='poster' && $poster==='') { $poster=avsfStoreImportImage($asset['path'],$code,$asset['name']); break; }
    }
    $coverAlt=$alt;
    $posterAlt=$alt;
    if(!validImageReference($cover)||!validImageReference($poster))throw new RuntimeException('URL รูปภาพต้องเป็น http(s) หรือพาธรูปภายในเว็บไซต์ หรือชื่อไฟล์ที่จับคู่กับ Code ได้');
    if(mb_strlen($cover,'UTF-8')>255||mb_strlen($poster,'UTF-8')>1000)throw new RuntimeException('URL รูปภาพยาวเกินขนาดช่องรูปปกหรือโปสเตอร์');
    if(mb_strlen($coverAlt,'UTF-8')>255||mb_strlen($posterAlt,'UTF-8')>255)throw new RuntimeException('ALT รูปปกหรือโปสเตอร์ยาวเกิน 255 ตัวอักษร');
    $video=normalizeVideoInput((string)($row['video_url']??''));
    if($video['error']!=='')throw new RuntimeException($video['error']);
    $durationInput=trim((string)($row['duration']??''));
    $durationParts=$durationInput!==''?videoDurationParts($durationInput):null;
    if($durationInput!==''&&$durationParts===null)throw new RuntimeException('รูปแบบ Duration ไม่ถูกต้อง');
    $duration=$durationParts===null?'':(string)($durationParts[0]*3600+$durationParts[1]*60+$durationParts[2]);
    $galleryRaw=$row['gallery']??'';
    if(is_array($galleryRaw)) $galleryValues=$galleryRaw;
    else {
        $galleryText=trim((string)$galleryRaw);
        $galleryDecoded=$galleryText!==''?json_decode($galleryText,true):null;
        $galleryValues=is_array($galleryDecoded)?$galleryDecoded:preg_split('/\r\n|\r|\n|[,;]+/',$galleryText);
    }
    $gallery=[];
    foreach($galleryValues as $galleryValue) {
        $galleryValue=trim((string)$galleryValue);
        if($galleryValue==='')continue;
        $resolved=avsfResolveImportImageValue($galleryValue,$imageAssets,$code,'gallery');
        if($resolved==='') {
            $normalized=normalizePublicImageReference($galleryValue);
            if(!validImageReference($normalized))throw new RuntimeException('ไฟล์ Gallery "'.basename($galleryValue).'" ไม่พบหรือ URL ไม่ถูกต้อง');
            $resolved=$normalized;
        }
        if($resolved!==$cover && $resolved!==$poster)$gallery[]=$resolved;
    }
    foreach($imageMatches as $asset) {
        if(($asset['role']??'')!=='gallery')continue;
        $resolved=avsfStoreImportImage($asset['path'],$code,$asset['name']);
        if($resolved!=='' && $resolved!==$cover && $resolved!==$poster)$gallery[]=$resolved;
    }
    $gallery=array_values(array_unique($gallery));
    foreach($gallery as $image)if(!validImageReference($image))throw new RuntimeException('URL รูปใน Gallery ไม่ถูกต้อง');
    $galleryAlt=$gallery?array_fill(0,count($gallery),$alt):[];
    $galleryJson=json_encode($gallery,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
    $galleryAltJson=json_encode($galleryAlt,JSON_UNESCAPED_UNICODE);
    if($galleryJson===false||$galleryAltJson===false||strlen($galleryJson)>65535||strlen($galleryAltJson)>65535)throw new RuntimeException('Gallery หรือ ALT มีขนาดเกินที่ฐานข้อมูลรองรับ');

    $studioNames=splitTaxonomyNames((string)($row['studio']??''));
    if(count($studioNames)>1)throw new RuntimeException('หนึ่งโพสต์กำหนดได้หนึ่งค่าย กรุณาแยกค่ายก่อนบันทึก');
    $actorNames=splitTaxonomyNames($actorValue);
    $tagRaw=$row['tags']??$row['tag']??$row['post_tag']??$row['post_tags']??$row['แท็ก']??'';
    $tagNames=advancedDraftTaxonomyNames($tagRaw);
    if(trim(is_array($tagRaw)?json_encode($tagRaw,JSON_UNESCAPED_UNICODE):(string)$tagRaw)!=='' && !$tagNames)throw new RuntimeException('อ่านค่าคอลัมน์ Tag/Tags ไม่สำเร็จ กรุณาตรวจรูปแบบข้อมูลแท็ก');
    $categoryNames=splitTaxonomyNames((string)($row['categories']??''));
    $category=implode(', ',$categoryNames);
    if(mb_strlen($category,'UTF-8')>150)throw new RuntimeException('Categories รวมกันยาวเกิน 150 ตัวอักษร');
    $keywords=trim((string)($row['keywords']??implode(', ',$tagNames)));
    $focus=trim((string)($row['focus']??''));
    $metaTitle=trim((string)($row['meta_title']??''))?:$title;
    $metaDesc=trim((string)($row['meta_desc']??''));
    if($metaDesc==='') {
        $template=trim((string)setting('seo_post_meta_desc_template',''));
        if($template!=='')$metaDesc=str_replace(['{code}','{title}','{site_name}'],[$code,$title,setting('siteName','')],$template);
    }
    if(mb_strlen($focus,'UTF-8')>200||mb_strlen($metaTitle,'UTF-8')>200||mb_strlen($metaDesc,'UTF-8')>300||mb_strlen($keywords,'UTF-8')>300)throw new RuntimeException('ข้อมูล SEO บางช่องยาวเกินขนาดที่ระบบรองรับ');
    $studioId=$studioNames?advancedDraftFindOrCreateTerm($conn,'studios',$studioNames[0]):0;
    $actorIds=[];foreach($actorNames as $name)$actorIds[]=advancedDraftFindOrCreateTerm($conn,'actors',$name);
    $tagIds=[];foreach($tagNames as $name)$tagIds[]=advancedDraftFindOrCreateTerm($conn,'tags',$name);
    foreach($categoryNames as $name)advancedDraftFindOrCreateTerm($conn,'categories',$name);
    $embed='';$remote=$video['url']!==''?videoApiRemote($video['url']):['url'=>'','error'=>''];$embed=(string)($remote['url']??'');
    $date=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d');
    $createdAt=(new DateTimeImmutable('now',new DateTimeZone('Asia/Bangkok')))->format('Y-m-d H:i:s');
    $actorIdsJson=json_encode($actorIds);$tagIdsJson=json_encode($tagIds);
    if($actorIdsJson===false||$tagIdsJson===false)throw new RuntimeException('แปลงข้อมูลนักแสดงหรือแท็กเป็น JSON ไม่สำเร็จ');
    $values=[$code,$title,$slug,$content,$cover,$coverAlt,$poster,$posterAlt,$video['url'],$embed,$duration,$galleryJson,$galleryAltJson,$studioId,$actorIdsJson,'',$tagIdsJson,$category,$focus,$metaTitle,$metaDesc,$keywords,'draft',$date,$author,$createdAt,$createdAt,0];
    $existingId=0;$existingStatus='';
    $lookup=$conn->prepare('SELECT id,status,tagIds FROM posts WHERE code=? LIMIT 1');
    if(!$lookup)throw new RuntimeException('ตรวจสอบ Code ซ้ำไม่สำเร็จ: '.$conn->error);
    $lookup->bind_param('s',$code);
    if(!$lookup->execute()){$error=$lookup->error;$lookup->close();throw new RuntimeException('ตรวจสอบ Code ซ้ำไม่สำเร็จ: '.$error);}
    $existingTagIds='[]';$lookup->bind_result($existingId,$existingStatus,$existingTagIds);$found=$lookup->fetch();$lookup->close();
    if(!$found) {
        $lookup=$conn->prepare('SELECT id,status,tagIds FROM posts WHERE slug=? LIMIT 1');
        if(!$lookup)throw new RuntimeException('ตรวจสอบ Slug ซ้ำไม่สำเร็จ: '.$conn->error);
        $lookup->bind_param('s',$slug);
        if(!$lookup->execute()){$error=$lookup->error;$lookup->close();throw new RuntimeException('ตรวจสอบ Slug ซ้ำไม่สำเร็จ: '.$error);}
        $existingTagIds='[]';$lookup->bind_result($existingId,$existingStatus,$existingTagIds);$found=$lookup->fetch();$lookup->close();
    }
    if($found&&$existingStatus!=='draft')throw new RuntimeException('มีโพสต์ที่ไม่ใช่ฉบับร่างใช้ Code/Slug นี้อยู่ จึงไม่เขียนทับ');
    if($found && !$tagNames) {
        $storedTagIds=parseTaxonomyIds($existingTagIds);
        $tagIdsJson=json_encode($storedTagIds,JSON_UNESCAPED_UNICODE);
        if($tagIdsJson===false)throw new RuntimeException('รักษาแท็กเดิมของฉบับร่างไม่สำเร็จ');
        $values[16]=$tagIdsJson;
    }
    if($found) {
        $sql='UPDATE posts SET code=?,title=?,slug=?,content=?,cover=?,coverAlt=?,posterUrl=?,posterAlt=?,videoUrl=?,embedUrl=?,videoDuration=?,gallery=?,galleryAlt=?,studioId=?,actorIds=?,actorEN=?,tagIds=?,category=?,focusKeyphrase=?,metaTitle=?,metaDesc=?,keywords=?,status=?,date=?,updatedAt=? WHERE id=?';
        $stmt=$conn->prepare($sql);
        if(!$stmt)throw new RuntimeException('เตรียมอัปเดตฉบับร่างไม่สำเร็จ: '.$conn->error);
        $updateValues=array_slice($values,0,24);$updateValues[]=$createdAt;$updateValues[]=(int)$existingId;
        advancedDraftBind($stmt,$updateValues);
        if(!$stmt->execute()){$error=$stmt->error;$stmt->close();throw new RuntimeException('อัปเดตฉบับร่างไม่สำเร็จ: '.$error);}
        $stmt->close();
        return ['id'=>(int)$existingId,'status'=>'updated','remoteError'=>(string)($remote['error']??''),'coverCount'=>($cover!==''?1:0),'posterCount'=>($poster!==''?1:0),'galleryCount'=>count($gallery),'tagCount'=>count(parseTaxonomyIds($tagIdsJson))];
    }
    $sql='INSERT INTO posts (code,title,slug,content,cover,coverAlt,posterUrl,posterAlt,videoUrl,embedUrl,videoDuration,gallery,galleryAlt,studioId,actorIds,actorEN,tagIds,category,focusKeyphrase,metaTitle,metaDesc,keywords,status,date,author,createdAt,updatedAt,views) VALUES ('.implode(',',array_fill(0,count($values),'?')).')';
    $stmt=$conn->prepare($sql);
    if(!$stmt)throw new RuntimeException('เตรียมบันทึกฉบับร่างไม่สำเร็จ: '.$conn->error);
    advancedDraftBind($stmt,$values);
    if(!$stmt->execute()){$error=$stmt->error;$stmt->close();throw new RuntimeException('บันทึกฉบับร่างไม่สำเร็จ: '.$error);}
    $id=(int)$stmt->insert_id;$stmt->close();
    return ['id'=>$id,'status'=>'created','remoteError'=>(string)($remote['error']??''),'coverCount'=>($cover!==''?1:0),'posterCount'=>($poster!==''?1:0),'galleryCount'=>count($gallery),'tagCount'=>count(parseTaxonomyIds($tagIdsJson))];
}

function advancedDraftBind($stmt,&$values) {
    $types='';
    foreach($values as $value)$types.=is_int($value)?'i':'s';
    $arguments=[$types];
    foreach($values as $index=>&$value)$arguments[]=&$values[$index];
    unset($value);
    if(!call_user_func_array([$stmt,'bind_param'],$arguments))throw new RuntimeException('ผูกข้อมูลฉบับร่างเข้าฐานข้อมูลไม่สำเร็จ');
}
